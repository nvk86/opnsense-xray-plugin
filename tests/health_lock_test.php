<?php
// Regress the inherited-flock failure observed on FreeBSD when Gateway Health
// Sync launched rc.routing_configure -> filterlog while the health producer
// held the health lock without FD_CLOEXEC.
function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$healthSource = (string)file_get_contents(__DIR__ . '/../plugin/scripts/Xray/xray-health.php');
check(
    str_contains($healthSource, "'.lock.v2', 'ce'"),
    'production health lock uses new generation and close-on-exec'
);
check(str_contains($healthSource, "if (!\$probeOnly)"), 'manual test bypasses production lock');

if (!function_exists('pcntl_fork') || !function_exists('pcntl_exec') ||
    !function_exists('posix_kill')) {
    echo "SKIP: pcntl unavailable; static close-on-exec invariant verified\n";
    exit(0);
}

function survivesExec(string $mode): bool
{
    $file = tempnam(sys_get_temp_dir(), 'xray-flock-');
    check($file !== false, 'create temporary test lock');
    $fp = fopen($file, $mode);
    check($fp !== false && flock($fp, LOCK_EX), 'acquire test lock');

    $pid = pcntl_fork();
    check($pid >= 0, 'fork test child');
    if ($pid === 0) {
        pcntl_exec('/bin/sleep', ['2']);
        exit(127);
    }

    // Allow the child to exec; a still-running child is used to reproduce
    // filterlog inheriting a lock descriptor from a parent PHP process.
    usleep(350000);
    fclose($fp);
    $second = fopen($file, 'c');
    check($second !== false, 'open lock from independent file description');
    $free = flock($second, LOCK_EX | LOCK_NB);
    if ($free) flock($second, LOCK_UN);
    fclose($second);
    posix_kill($pid, SIGTERM);
    pcntl_waitpid($pid, $status);
    unlink($file);
    return !$free;
}

check(survivesExec('c'), 'ordinary fopen must reproduce inherited stale flock');
check(!survivesExec('ce'), 'close-on-exec must release inherited flock');
echo "OK: health lock FD_CLOEXEC regression tests passed\n";
