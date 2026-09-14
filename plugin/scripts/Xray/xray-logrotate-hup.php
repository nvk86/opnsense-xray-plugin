#!/usr/local/bin/php
<?php

// Called by newsyslog after rotating /var/log/xray-*.log. Both Xray and HEV
// are wrapped by FreeBSD daemon(8) with -H and share the instance log. HUP only
// supervisors whose rewritten daemon title proves xray ownership.

function valid_uuid(string $uuid): bool
{
    return (bool)preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $uuid);
}

foreach (['/var/run/xray-daemon-*.pid', '/var/run/xray-hev-daemon-*.pid'] as $pattern) {
    foreach (glob($pattern) ?: [] as $pidfile) {
        $base = basename($pidfile);
        if (!preg_match('/^xray-(hev-)?daemon-([0-9a-fA-F-]{36})\.pid$/D', $base, $m) || !valid_uuid($m[2])) {
            continue;
        }
        $kind = $m[1] !== '' ? 'hev' : 'xray';
        $uuid = $m[2];
        $raw = trim((string)@file_get_contents($pidfile));
        if (!ctype_digit($raw) || (int)$raw <= 1) {
            continue;
        }
        $pid = (int)$raw;
        $command = trim((string)shell_exec('/bin/ps -ww -o command= -p ' . $pid . ' 2>/dev/null'));
        $title = $kind === 'hev' ? 'daemon: xray-hev:' . $uuid . '[' : 'daemon: xray:' . $uuid . '[';
        if ($command === '' || strpos($command, $title) === false) {
            continue;
        }
        exec('/bin/kill -HUP ' . $pid . ' >/dev/null 2>&1');
    }
}
