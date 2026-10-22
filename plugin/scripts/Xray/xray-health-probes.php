<?php
/**
 * Pure end-to-end HTTPS reachability probe for OPNsense Xray clients.
 *
 * The runner can be injected in tests; production runs curl via SOCKS5 with
 * remote DNS. A valid streamed response from ANY independent endpoint proves
 * that the proxy path is usable. One unreachable website must not take an
 * otherwise healthy gateway out of the native PF pool.
 */
const HEALTH_STREAM_BYTES = 16384;
const HEALTH_STREAM_TIMEOUT = 8;
const HEALTH_STREAM_MAX_FILESIZE = 262144;

function xray_health_targets(): array
{
    return [
        ['name' => 'cloudflare', 'url' => 'https://speed.cloudflare.com/__down?bytes=16384'],
        ['name' => 'wikipedia', 'url' => 'https://www.wikipedia.org/'],
        ['name' => 'mozilla', 'url' => 'https://www.mozilla.org/'],
    ];
}

/**
 * @return array{ok:bool,message:string,target:string,http_status:?int,latency_ms:?int,stream_bytes:int,stream_expected_bytes:int,probe_results:array}
 */
function xray_health_probe_https(string $socksHost, int $socksPort, ?callable $runner = null): array
{
    $runner ??= static function (string $command, string $url): array {
        $out = [];
        $rc = 1;
        exec($command, $out, $rc);
        return [$rc, trim(implode("\n", $out))];
    };

    $results = [];
    foreach (xray_health_targets() as $target) {
        $url = $target['url'];
        $socks = $socksHost . ':' . $socksPort;
        // A range limits traffic on supporting servers (Mozilla returns 206).
        // Wikipedia can ignore Range and respond 200 with the full page;
        // cap that response at 256 KiB rather than requiring HTTP 206.
        $cmd = '/usr/local/bin/curl -q -4 -sS --connect-timeout 3 --max-time ' . HEALTH_STREAM_TIMEOUT
            . ' --range 0-16383 --max-filesize ' . HEALTH_STREAM_MAX_FILESIZE
            . ' --socks5-hostname ' . escapeshellarg($socks)
            . ' -o /dev/null -w ' . escapeshellarg('XRAY_HEALTH:%{http_code}|%{size_download}|%{time_total}')
            . ' ' . escapeshellarg($url) . ' 2>/dev/null';

        [$rc, $line] = $runner($cmd, $url);
        $rc = (int)$rc;
        $line = trim((string)$line);
        $http = 0;
        $bytes = 0;
        $seconds = 0.0;
        if (preg_match('/XRAY_HEALTH:(\d{3})\|(\d+)\|([0-9.]+)$/D', $line, $m)) {
            $http = (int)$m[1];
            $bytes = (int)$m[2];
            $seconds = (float)$m[3];
        }
        $ok = $rc === 0 && in_array($http, [200, 206], true) && $bytes >= HEALTH_STREAM_BYTES;
        $results[] = [
            'name' => $target['name'],
            'ok' => $ok,
            'http_status' => $http,
            'bytes' => $bytes,
            'curl_rc' => $rc,
        ];
        if ($ok) {
            $ms = (int)round($seconds * 1000);
            return [
                'ok' => true,
                'message' => "Online via {$target['name']} (HTTP {$http}, {$bytes} bytes, {$ms} ms)",
                'target' => $url,
                'http_status' => $http,
                'latency_ms' => $ms,
                'stream_bytes' => $bytes,
                'stream_expected_bytes' => HEALTH_STREAM_BYTES,
                'probe_results' => $results,
            ];
        }
    }

    return [
        'ok' => false,
        'message' => 'HTTPS stream failed through all independent health targets',
        'target' => 'multiple:https',
        'http_status' => null,
        'latency_ms' => null,
        'stream_bytes' => 0,
        'stream_expected_bytes' => HEALTH_STREAM_BYTES,
        'probe_results' => $results,
    ];
}
