<?php
require_once __DIR__ . '/../plugin/scripts/Xray/xray-health-probes.php';

function check(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$targets = xray_health_targets();
check(count($targets) >= 3, 'at least 3 endpoints required');
check(count(array_unique(array_map(static fn($t) => parse_url($t['url'], PHP_URL_HOST), $targets))) === count($targets), 'endpoints must have different hosts') ;

// A Cloudflare outage cannot cause a full tunnel outage when Wikipedia works.
$calls = [];
$runner = static function (string $cmd, string $url) use (&$calls): array {
    $calls[] = $url;
    if (str_contains($url, 'cloudflare.com')) return [28, 'XRAY_HEALTH:000|0|8.000'];
    return [0, 'XRAY_HEALTH:200|122421|0.420'];
};
$result = xray_health_probe_https('127.0.0.1', 10809, $runner);
check($result['ok'] === true, 'successful fallback must report online');
check(count($calls) === 2, 'stop after first successful independent target');
check($result['latency_ms'] === 420, 'record successful target latency');
check($result['stream_bytes'] === 122421, 'record actual downloaded bytes');

// A tiny response must not count as a successful stream.
$small = static fn(string $cmd, string $url): array => [0, 'XRAY_HEALTH:200|500|0.050'];
$result = xray_health_probe_https('127.0.0.1', 10809, $small);
check($result['ok'] === false, 'tiny HTTP 200 is insufficient');
check(count($result['probe_results']) === count($targets), 'try every independent endpoint before failing');

// Even an unexpected HTTP 500 with a full body is a failure.
$serverError = static fn(string $cmd, string $url): array => [0, 'XRAY_HEALTH:500|16384|0.050'];
check(xray_health_probe_https('127.0.0.1', 10809, $serverError)['ok'] === false, 'HTTP 500 must fail');

echo "OK: HTTPS stream health fallback regression tests passed\n";
