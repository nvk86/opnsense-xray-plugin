#!/usr/local/bin/php
<?php

$uuid = isset($argv[1]) ? trim((string)$argv[1]) : '';
if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $uuid)) {
    fwrite(STDERR, "Invalid instance UUID\n");
    exit(1);
}

$log = '/var/log/xray-' . $uuid . '.log';
if (!is_file($log)) {
    echo "No log file for instance {$uuid}.\n";
    exit(0);
}

$cmd = '/usr/bin/tail -n 200 -- ' . escapeshellarg($log) . ' 2>&1';
passthru($cmd, $rc);
exit($rc);
