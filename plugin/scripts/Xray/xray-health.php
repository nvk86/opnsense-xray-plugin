#!/usr/local/bin/php
<?php

set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
require_once('config.inc');

require_once __DIR__ . '/xray-health-probes.php';

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
    global $probeOnly;
    $old = read_cache($uuid);
    $now = time();
    $failures = $online ? 0 : ((int)($old['consecutive_failures'] ?? 0) + 1);
    $state = array_merge([
        'online' => $online,
        'status' => $online ? 'online' : 'offline',
        'message' => $message,
        'latency_ms' => $latencyMs,
        'http_status' => $httpStatus,
        'target' => (string)($extra['target'] ?? 'multiple:https'),
        'failure_kind' => $online ? 'none' : 'network',
        'checked_at' => $now,
        'checked_at_iso' => date('c', $now),
        'consecutive_failures' => $failures,
        'last_ok' => $online ? $now : ($old['last_ok'] ?? null),
        'last_failure' => $online ? ($old['last_failure'] ?? null) : $now,
        'last_restart' => $old['last_restart'] ?? null,
    ], $extra);
    // Manual Test must not modify counters, gateway Force Down or PF routing.
    if ($probeOnly) {
        $state['probe_only'] = true;
        return $state;
    }
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
$probeOnly = ($argv[2] ?? '') === '--probe-only';
if (!valid_uuid($uuid)) {
    output(['result' => 'failed', 'message' => 'Invalid instance UUID'], 1);
}

// Only production health producers share the per-UUID lock. Manual Test is
// strictly read-only and can run alongside the watchdog without being skipped.
// One active production probe prevents concurrent cache writes and gateway
// Force Down/UP races.
$lock = null;
if (!$probeOnly) {
    // 1.2.1 initial hotfix used ".lock" with ordinary fopen("c"). When
    // Gateway Health Sync reconfigured PF, filterlog inherited the descriptor
    // and kept flock alive after the health process exited. Switch lock
    // generations to avoid the orphaned inode without touching filterlog.
    // fopen("ce") sets FD_CLOEXEC and prevents any child exec from inheriting
    // the new lock through rc.routing_configure or other service helpers.
    $lock = @fopen('/var/run/xray-health-' . $uuid . '.lock.v2', 'ce');
    if ($lock === false || !@flock($lock, LOCK_EX | LOCK_NB)) {
        output(['result' => 'busy', 'message' => 'Health probe already running', 'online' => null], 0);
    }
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
    $state = update_state($uuid, false, $message, null, null, ['failure_kind' => 'local']);
    output(['result' => 'failed'] + $state, 0);
}

$socksHost = trim((string)($inst->socks5_listen ?? '127.0.0.1'));
if ($socksHost === '') $socksHost = '127.0.0.1';
$socksPort = (int)($inst->socks5_port ?? 10808);
if ($socksPort < 1 || $socksPort > 65535) {
    $state = update_state($uuid, false, 'Invalid SOCKS5 port', null, null, ['failure_kind' => 'local']);
    output(['result' => 'failed'] + $state, 0);
}

// A successful HTTPS response with a real body from any independent target
// proves the SOCKS5 -> Xray -> VLESS/REALITY path is usable. Do not make one
// website's reachability a hard prerequisite for VPN gateway health.
$probe = xray_health_probe_https($socksHost, $socksPort);
$details = [
    'target' => $probe['target'],
    'stream_bytes' => $probe['stream_bytes'],
    'stream_expected_bytes' => $probe['stream_expected_bytes'],
    'probe_results' => $probe['probe_results'],
    'failure_kind' => $probe['ok'] ? 'none' : 'network',
];
$state = update_state(
    $uuid,
    $probe['ok'],
    $probe['message'],
    $probe['latency_ms'],
    $probe['http_status'],
    $details
);
output(['result' => $probe['ok'] ? 'ok' : 'failed'] + $state, 0);
