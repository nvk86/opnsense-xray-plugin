#!/usr/local/bin/php
<?php

set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
require_once('config.inc');

define('XRAY_CTRL', '/usr/local/opnsense/scripts/Xray/xray-service-control.php');
define('XRAY_HEALTH', '/usr/local/opnsense/scripts/Xray/xray-health.php');
define('WATCHDOG_LOG', '/var/log/xray-watchdog.log');
define('SERVICE_LOG', '/var/log/xray-service.log');
define('FAILURES_BEFORE_RESTART', 3);
define('RESTART_COOLDOWN', 600);

function wlog(string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    @file_put_contents(WATCHDOG_LOG, $line, FILE_APPEND | LOCK_EX);
    @file_put_contents(SERVICE_LOG, $line, FILE_APPEND | LOCK_EX);
}

function valid_uuid(string $uuid): bool
{
    return (bool)preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $uuid);
}

function health_path(string $uuid): string
{
    return '/var/run/xray-health-' . $uuid . '.json';
}

function stamp_restart(string $uuid): void
{
    $path = health_path($uuid);
    $state = [];
    if (is_file($path)) {
        $tmp = json_decode((string)@file_get_contents($path), true);
        if (is_array($tmp)) $state = $tmp;
    }
    $state['last_restart'] = time();
    $tmpPath = $path . '.tmp.' . getmypid();
    if (@file_put_contents($tmpPath, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", LOCK_EX) !== false) {
        @chmod($tmpPath, 0644);
        @rename($tmpPath, $path);
    }
}

$cfg = OPNsense\Core\Config::getInstance()->object();
$general = $cfg->OPNsense->xray->general ?? null;
$globalEnabled = (string)($general->enabled ?? '0') === '1';
$autoRestart = (string)($general->watchdog_enabled ?? '0') === '1';
if (!$globalEnabled) {
    exit(0);
}
if (!is_executable(XRAY_CTRL) || !is_executable(XRAY_HEALTH)) {
    wlog('ERROR: watchdog dependency missing');
    exit(1);
}

$out = [];
exec('/usr/local/bin/php ' . escapeshellarg(XRAY_CTRL) . ' statusall 2>/dev/null', $out, $rc);
$status = json_decode(implode("\n", $out), true);
if ($rc !== 0 || !is_array($status)) {
    wlog('ERROR: cannot read Xray status inventory');
    exit(1);
}

$failed = false;
foreach ($status as $uuid => $row) {
    if (!is_string($uuid) || !valid_uuid($uuid) || !is_array($row)) continue;
    if (empty($row['effective_enabled']) || !empty($row['manual_stopped'])) continue;

    $name = (string)($row['name'] ?? $uuid);
    $healthOut = [];
    exec('/usr/local/bin/php ' . escapeshellarg(XRAY_HEALTH) . ' ' . escapeshellarg($uuid) . ' 2>/dev/null', $healthOut, $healthRc);
    $health = json_decode(implode("\n", $healthOut), true);
    if (!is_array($health)) {
        wlog("MONITOR [{$name}]: invalid health result");
        $failed = true;
        continue;
    }

    if (($health['result'] ?? '') === 'ok') continue;

    $detail = (string)($health['message'] ?? 'connectivity failed');
    $failures = (int)($health['consecutive_failures'] ?? 0);
    wlog("MONITOR [{$name}]: offline ({$detail}), failures={$failures}");

    if (!$autoRestart || $failures < FAILURES_BEFORE_RESTART) continue;

    $lastRestart = (int)($health['last_restart'] ?? 0);
    if ($lastRestart > 0 && (time() - $lastRestart) < RESTART_COOLDOWN) {
        wlog("WATCHDOG [{$name}]: restart suppressed by cooldown");
        continue;
    }

    wlog("WATCHDOG [{$name}]: restarting after {$failures} consecutive failures");
    $restartOut = [];
    exec('/usr/local/bin/php ' . escapeshellarg(XRAY_CTRL) . ' restart_instance ' . escapeshellarg($uuid) . ' 2>&1', $restartOut, $restartRc);
    if ($restartRc === 75) {
        wlog("WATCHDOG [{$name}]: restart skipped, lifecycle busy");
    } elseif ($restartRc !== 0) {
        stamp_restart($uuid);
        wlog("WATCHDOG [{$name}]: restart FAILED rc={$restartRc}: " . trim(implode(' ', $restartOut)));
        $failed = true;
    } else {
        stamp_restart($uuid);
        wlog("WATCHDOG [{$name}]: restart OK");
    }
}

exit($failed ? 1 : 0);
