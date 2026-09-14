#!/usr/local/bin/php
<?php

set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
require_once('config.inc');

const HEALTH_HOST = 'cp.cloudflare.com';
const HEALTH_PORT = 80;
const HEALTH_PATH = '/generate_204';
const HEALTH_TIMEOUT = 3.0;
const HEALTH_STREAM_URL = 'https://speed.cloudflare.com/__down?bytes=16384';
const HEALTH_STREAM_BYTES = 16384;
const HEALTH_STREAM_TIMEOUT = 8;

function valid_uuid(string $uuid): bool
{
    return (bool)preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $uuid);
}

function output(array $data, int $rc = 0): void
{
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit($rc);
}

function cache_path(string $uuid): string
{
    return '/var/run/xray-health-' . $uuid . '.json';
}

function read_cache(string $uuid): array
{
    $path = cache_path($uuid);
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string)@file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function write_cache(string $uuid, array $data): void
{
    $path = cache_path($uuid);
    $tmp = $path . '.tmp.' . getmypid();
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
        @chmod($tmp, 0644);
        @rename($tmp, $path);
    } else {
        @unlink($tmp);
    }
}

function read_exact($fp, int $len): string|false
{
    $buf = '';
    while (strlen($buf) < $len) {
        $chunk = fread($fp, $len - strlen($buf));
        if ($chunk === false || $chunk === '') {
            return false;
        }
        $buf .= $chunk;
    }
    return $buf;
}

function tracked_pid(string $uuid, string $kind): int
{
    $hev = $kind === 'hev';
    $pidfile = '/var/run/xray-' . ($hev ? 'hev-' : '') . $uuid . '.pid';
    $raw = is_file($pidfile) ? trim((string)@file_get_contents($pidfile)) : '';
    if (!ctype_digit($raw) || (int)$raw <= 1) {
        return 0;
    }
    $pid = (int)$raw;
    $cmd = trim((string)shell_exec('/bin/ps -ww -o command= -p ' . $pid . ' 2>/dev/null'));
    $bin = $hev ? '/usr/local/libexec/xray/hev-socks5-tunnel' : '/usr/local/libexec/xray/xray';
    $conf = '/usr/local/etc/opnsense-xray/' . ($hev ? 'hev-' : 'config-') . $uuid . ($hev ? '.yaml' : '.json');
    return $cmd !== '' && strpos($cmd, $bin) !== false && strpos($cmd, $conf) !== false ? $pid : 0;
}

function update_state(string $uuid, bool $online, string $message, ?int $latencyMs, ?int $httpStatus, array $extra = []): array
{
    $old = read_cache($uuid);
    $now = time();
    $failures = $online ? 0 : ((int)($old['consecutive_failures'] ?? 0) + 1);
    $state = array_merge([
        'online' => $online,
        'status' => $online ? 'online' : 'offline',
        'message' => $message,
        'latency_ms' => $latencyMs,
        'http_status' => $httpStatus,
        'target' => HEALTH_HOST . ':' . HEALTH_PORT,
        'checked_at' => $now,
        'checked_at_iso' => date('c', $now),
        'consecutive_failures' => $failures,
        'last_ok' => $online ? $now : ($old['last_ok'] ?? null),
        'last_failure' => $online ? ($old['last_failure'] ?? null) : $now,
        'last_restart' => $old['last_restart'] ?? null,
    ], $extra);
    write_cache($uuid, $state);

    // Native gateway integration is deliberately best-effort: health results
    // remain useful even when the TUN has not yet been assigned in OPNsense.
    $syncScript = '/usr/local/opnsense/scripts/Xray/xray-gateway-sync.php';
    if (is_file($syncScript)) {
        $status = (string)($state['status'] ?? ($online ? 'online' : 'offline'));
        if ($status === 'stopped') {
            $syncState = 'stopped';
        } elseif ($online) {
            $syncState = 'online';
        } elseif ($failures >= 3) {
            $syncState = 'offline';
        } else {
            // A single transient probe failure must not flap a native gateway.
            // Keep the previous gateway state until the failure threshold is met.
            $syncState = 'unknown';
        }
        @exec('/usr/local/bin/php ' . escapeshellarg($syncScript) . ' ' . escapeshellarg($uuid) . ' ' . escapeshellarg($syncState) . ' >/dev/null 2>&1');
    }
    return $state;
}

$uuid = isset($argv[1]) ? trim((string)$argv[1]) : '';
if (!valid_uuid($uuid)) {
    output(['result' => 'failed', 'message' => 'Invalid instance UUID'], 1);
}

$cfg = OPNsense\Core\Config::getInstance()->object();
$inst = null;
foreach (($cfg->OPNsense->xray->instances->instance ?? []) as $candidate) {
    if ((string)$candidate['uuid'] === $uuid) {
        $inst = $candidate;
        break;
    }
}
if ($inst === null) {
    output(['result' => 'failed', 'message' => 'Instance not found'], 1);
}

$globalEnabled = (string)($cfg->OPNsense->xray->general->enabled ?? '0') === '1';
$instanceEnabled = (string)($inst->enabled ?? '1') === '1';
$manualStopped = is_file('/var/run/xray-stopped-' . $uuid . '.flag');
if (!$globalEnabled || !$instanceEnabled || $manualStopped) {
    $state = update_state($uuid, false, $manualStopped ? 'Client is manually stopped' : 'Client is disabled', null, null, [
        'status' => 'stopped',
        'online' => false,
        'consecutive_failures' => 0,
    ]);
    output(['result' => 'failed'] + $state, 0);
}

$runtimeOut = [];
$runtimeRc = 1;
exec('/usr/local/bin/php /usr/local/opnsense/scripts/Xray/xray-service-control.php status ' . escapeshellarg($uuid) . ' 2>/dev/null', $runtimeOut, $runtimeRc);
$runtime = json_decode(implode("\n", $runtimeOut), true);
if ($runtimeRc !== 0 || !is_array($runtime) || ($runtime['status'] ?? '') !== 'ok') {
    $parts = [];
    if (is_array($runtime)) {
        if (($runtime['xray_core'] ?? '') !== 'running') $parts[] = 'Xray';
        if (($runtime['socks5'] ?? '') !== 'ready') $parts[] = 'SOCKS5';
        if (($runtime['hev'] ?? '') !== 'running') $parts[] = 'HEV';
        if (($runtime['tun'] ?? '') !== 'running') $parts[] = 'TUN';
    }
    $message = 'Runtime incomplete' . ($parts ? ': ' . implode(', ', array_unique($parts)) : '');
    $state = update_state($uuid, false, $message, null, null);
    output(['result' => 'failed'] + $state, 0);
}

$socksHost = trim((string)($inst->socks5_listen ?? '127.0.0.1'));
if ($socksHost === '') $socksHost = '127.0.0.1';
$socksPort = (int)($inst->socks5_port ?? 10808);
if ($socksPort < 1 || $socksPort > 65535) {
    $state = update_state($uuid, false, 'Invalid SOCKS5 port', null, null);
    output(['result' => 'failed'] + $state, 0);
}

$start = microtime(true);
$errno = 0;
$errstr = '';
$fp = @fsockopen($socksHost, $socksPort, $errno, $errstr, HEALTH_TIMEOUT);
if ($fp === false) {
    $state = update_state($uuid, false, 'SOCKS5 unavailable: ' . ($errstr ?: ('errno ' . $errno)), null, null);
    output(['result' => 'failed'] + $state, 0);
}
stream_set_timeout($fp, (int)HEALTH_TIMEOUT, (int)((HEALTH_TIMEOUT - (int)HEALTH_TIMEOUT) * 1000000));

$fail = function (string $message) use ($uuid, $fp): void {
    @fclose($fp);
    $state = update_state($uuid, false, $message, null, null);
    output(['result' => 'failed'] + $state, 0);
};

if (@fwrite($fp, "\x05\x01\x00") !== 3) {
    $fail('SOCKS5 greeting write failed');
}
$reply = read_exact($fp, 2);
if ($reply === false || $reply !== "\x05\x00") {
    $fail('SOCKS5 authentication negotiation failed');
}

$host = HEALTH_HOST;
$request = "\x05\x01\x00\x03" . chr(strlen($host)) . $host . pack('n', HEALTH_PORT);
if (@fwrite($fp, $request) !== strlen($request)) {
    $fail('SOCKS5 CONNECT write failed');
}
$head = read_exact($fp, 4);
if ($head === false || strlen($head) !== 4 || ord($head[0]) !== 5) {
    $fail('SOCKS5 CONNECT response missing');
}
$rep = ord($head[1]);
if ($rep !== 0) {
    $fail('SOCKS5 CONNECT failed (reply ' . $rep . ')');
}
$atyp = ord($head[3]);
if ($atyp === 1) {
    if (read_exact($fp, 4) === false) $fail('SOCKS5 CONNECT response truncated');
} elseif ($atyp === 3) {
    $len = read_exact($fp, 1);
    if ($len === false || read_exact($fp, ord($len)) === false) $fail('SOCKS5 CONNECT response truncated');
} elseif ($atyp === 4) {
    if (read_exact($fp, 16) === false) $fail('SOCKS5 CONNECT response truncated');
} else {
    $fail('SOCKS5 CONNECT returned unknown address type');
}
if (read_exact($fp, 2) === false) {
    $fail('SOCKS5 CONNECT response truncated');
}

$http = "GET " . HEALTH_PATH . " HTTP/1.1\r\nHost: " . HEALTH_HOST . "\r\nUser-Agent: OPNsense-Xray-Health/1.0\r\nConnection: close\r\n\r\n";
if (@fwrite($fp, $http) !== strlen($http)) {
    $fail('Health request write failed');
}
$statusLine = @fgets($fp, 512);
$meta = stream_get_meta_data($fp);
@fclose($fp);
if ($statusLine === false || $statusLine === '') {
    $failMsg = !empty($meta['timed_out']) ? 'Health request timed out' : 'Health response missing';
    $state = update_state($uuid, false, $failMsg, null, null);
    output(['result' => 'failed'] + $state, 0);
}

$httpStatus = null;
if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', trim($statusLine), $m)) {
    $httpStatus = (int)$m[1];
}
$latencyMs = (int)round((microtime(true) - $start) * 1000);
if ($httpStatus === null || $httpStatus < 200 || $httpStatus >= 400) {
    $message = 'Unexpected health response: ' . trim($statusLine);
    $state = update_state($uuid, false, $message, $latencyMs, $httpStatus);
    output(['result' => 'failed'] + $state, 0);
}

// A tiny 204 only proves that the tunnel can establish a short request.
// Verify a non-trivial response body as well; this catches stateful/DPI paths
// that accept REALITY but stall after only a few kilobytes.
$curlOut = [];
$curlRc = 1;
$curlTarget = $socksHost . ':' . $socksPort;
$curlCmd = '/usr/local/bin/curl -4 -sS --connect-timeout 3 --max-time ' . HEALTH_STREAM_TIMEOUT
    . ' --socks5-hostname ' . escapeshellarg($curlTarget)
    . ' -o /dev/null -w ' . escapeshellarg('XRAY_HEALTH:%{http_code}|%{size_download}|%{time_total}')
    . ' ' . escapeshellarg(HEALTH_STREAM_URL) . ' 2>/dev/null';
exec($curlCmd, $curlOut, $curlRc);
$curlLine = trim(implode("\n", $curlOut));
$streamCode = 0; $streamBytes = 0; $streamSeconds = 0.0;
if (preg_match('/XRAY_HEALTH:(\d{3})\|(\d+)\|([0-9.]+)$/D', $curlLine, $m)) {
    $streamCode = (int)$m[1];
    $streamBytes = (int)$m[2];
    $streamSeconds = (float)$m[3];
}
if ($curlRc !== 0 || $streamCode < 200 || $streamCode >= 400 || $streamBytes < HEALTH_STREAM_BYTES) {
    $detail = $streamBytes . '/' . HEALTH_STREAM_BYTES . ' bytes';
    if ($streamCode > 0) $detail .= ', HTTP ' . $streamCode;
    if ($curlRc !== 0) $detail .= ', curl rc ' . $curlRc;
    $state = update_state($uuid, false, 'Stream health failed (' . $detail . ')', null, $streamCode > 0 ? $streamCode : null, [
        'stream_bytes' => $streamBytes,
        'stream_expected_bytes' => HEALTH_STREAM_BYTES,
    ]);
    output(['result' => 'failed'] + $state, 0);
}

$latencyMs = (int)round($streamSeconds * 1000);
$message = 'Online via proxy (HTTP ' . $streamCode . ', ' . $streamBytes . ' bytes, ' . $latencyMs . ' ms)';
$state = update_state($uuid, true, $message, $latencyMs, $streamCode, [
    'stream_bytes' => $streamBytes,
    'stream_expected_bytes' => HEALTH_STREAM_BYTES,
]);
output(['result' => 'ok'] + $state, 0);
