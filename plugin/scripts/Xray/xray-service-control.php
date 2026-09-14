#!/usr/local/bin/php
<?php

set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
require_once('config.inc');

define('XRAY_BIN', '/usr/local/libexec/xray/xray');
define('HEV_BIN', '/usr/local/libexec/xray/hev-socks5-tunnel');
define('XRAY_ASSET_DIR', '/usr/local/share/opnsense-xray');
define('XRAY_CONF_DIR', '/usr/local/etc/opnsense-xray');
define('HEV_CONF_DIR', '/usr/local/etc/opnsense-xray');
define('XRAY_VERSION_FILE', '/usr/local/opnsense/mvc/app/models/OPNsense/Xray/version.txt');
define('HEV_VERSION_INFO', '/usr/local/libexec/xray/hev-upstream-release.txt');
define('XRAY_LOCK_DIR', '/var/run/xray.lock.d');
define('XRAY_LOCK_META', '/var/run/xray.lock.d/owner');
define('XRAY_LOCK_WAIT_MS', 3000);
define('XRAY_LOCK_STALE_GRACE_SEC', 5);
define('XRAY_LOG', '/var/log/xray.log');
define('XRAY_SERVICE_LOG', '/var/log/xray-service.log');


function xray_event_log(string $message): void
{
    @file_put_contents(XRAY_SERVICE_LOG, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", FILE_APPEND | LOCK_EX);
}

function xray_health_cache(string $uuid): array
{
    $path = '/var/run/xray-health-' . $uuid . '.json';
    if (!is_file($path)) {
        return ['connectivity' => 'unknown', 'health_message' => 'Not checked yet', 'health_checked_at' => null, 'health_latency_ms' => null, 'health_failures' => 0];
    }
    $data = json_decode((string)@file_get_contents($path), true);
    if (!is_array($data)) {
        return ['connectivity' => 'unknown', 'health_message' => 'Invalid health cache', 'health_checked_at' => null, 'health_latency_ms' => null, 'health_failures' => 0];
    }
    $checked = (int)($data['checked_at'] ?? 0);
    $age = $checked > 0 ? max(0, time() - $checked) : null;
    if (($data['status'] ?? '') === 'stopped') {
        $state = 'stopped';
    } elseif ($checked <= 0) {
        $state = 'unknown';
    } elseif ($age !== null && $age > 150) {
        $state = 'stale';
    } else {
        $state = !empty($data['online']) ? 'online' : 'offline';
    }
    return [
        'connectivity' => $state,
        'health_message' => (string)($data['message'] ?? ''),
        'health_checked_at' => $checked > 0 ? $checked : null,
        'health_latency_ms' => isset($data['latency_ms']) && $data['latency_ms'] !== null ? (int)$data['latency_ms'] : null,
        'health_failures' => (int)($data['consecutive_failures'] ?? 0),
    ];
}

function xray_valid_uuid(string $uuid): bool
{
    return (bool)preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $uuid);
}

function xray_valid_iface(string $iface): bool
{
    return (bool)preg_match('/^tun(?:0|[1-9][0-9]{0,2})$/D', $iface);
}

function xray_valid_cidr(string $cidr): bool
{
    $parts = explode('/', trim($cidr), 2);
    if (count($parts) !== 2 || filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return false;
    }
    return ctype_digit($parts[1]) && (int)$parts[1] >= 1 && (int)$parts[1] <= 32;
}

function xray_valid_hev_cidr(string $cidr): bool
{
    if (!xray_valid_cidr($cidr)) {
        return false;
    }
    [, $prefix] = explode('/', trim($cidr), 2);
    return (int)$prefix === 32;
}

function xray_cidr_range(string $cidr): ?array
{
    if (!xray_valid_cidr($cidr)) {
        return null;
    }
    [$ip, $prefixRaw] = explode('/', trim($cidr), 2);
    $prefix = (int)$prefixRaw;
    $value = ip2long($ip);
    if ($value === false) {
        return null;
    }
    if ($value < 0) {
        $value += 4294967296;
    }
    $mask = $prefix === 0 ? 0 : ((0xffffffff << (32 - $prefix)) & 0xffffffff);
    $network = $value & $mask;
    $broadcast = $network | (~$mask & 0xffffffff);
    return [$network, $broadcast, $prefix];
}

function xray_cidrs_overlap(string $a, string $b): bool
{
    $ra = xray_cidr_range($a);
    $rb = xray_cidr_range($b);
    if ($ra === null || $rb === null) {
        return false;
    }
    return $ra[0] <= $rb[1] && $rb[0] <= $ra[1];
}

function xray_netmask_forms(int $prefix): array
{
    if ($prefix < 1 || $prefix > 32) {
        return [];
    }
    $mask = ((0xffffffff << (32 - $prefix)) & 0xffffffff);
    $hex = sprintf('0x%08x', $mask);
    $dotted = long2ip($mask);
    return [$hex, $dotted];
}

function xray_conf_path(string $uuid): string
{
    return XRAY_CONF_DIR . '/config-' . $uuid . '.json';
}

function xray_pid_path(string $uuid): string
{
    return '/var/run/xray-' . $uuid . '.pid';
}

function xray_supervisor_pid_path(string $uuid): string
{
    return '/var/run/xray-daemon-' . $uuid . '.pid';
}

function hev_conf_path(string $uuid): string
{
    return HEV_CONF_DIR . '/hev-' . $uuid . '.yaml';
}

function hev_pid_path(string $uuid): string
{
    return '/var/run/xray-hev-' . $uuid . '.pid';
}

function hev_supervisor_pid_path(string $uuid): string
{
    return '/var/run/xray-hev-daemon-' . $uuid . '.pid';
}

function xray_stopped_flag(string $uuid): string
{
    return '/var/run/xray-stopped-' . $uuid . '.flag';
}

function xray_instance_log(string $uuid): string
{
    return '/var/log/xray-' . $uuid . '.log';
}

function xray_temp_json(string $dir, string $prefix): ?string
{
    $base = tempnam($dir, $prefix);
    if ($base === false) {
        return null;
    }
    $path = $base . '.json';
    if (!rename($base, $path)) {
        @unlink($base);
        return null;
    }
    return $path;
}

function xray_global_enabled(): bool
{
    $cfg = OPNsense\Core\Config::getInstance()->object();
    return (string)($cfg->OPNsense->xray->general->enabled ?? '0') === '1';
}

function xray_parse_instance($inst, bool $globalEnabled): array
{
    $rawLevel = (string)($inst->loglevel ?? 'warning');
    $logMap = ['e' => 'error', 'loglevel_error' => 'error'];
    $logLevel = $logMap[$rawLevel] ?? $rawLevel;
    if (!in_array($logLevel, ['debug', 'info', 'warning', 'error', 'none'], true)) {
        $logLevel = 'warning';
    }

    $iface = (string)($inst->tun_interface ?? 'tun0');
    $addr = (string)($inst->tun_address ?? '169.254.100.1/32');
    $socksListen = trim((string)($inst->socks5_listen ?? '127.0.0.1'));
    $socksPort = (int)(string)($inst->socks5_port ?? '10808');
    $mtuRaw = trim((string)($inst->mtu ?? ''));
    $mtu = $mtuRaw === '' ? 1500 : (int)$mtuRaw;

    return [
        'inst_uuid'        => (string)$inst['uuid'],
        'enabled'          => $globalEnabled && (string)($inst->enabled ?? '1') === '1',
        'instance_enabled' => (string)($inst->enabled ?? '1') === '1',
        'name'             => (string)($inst->name ?? 'default'),
        'server'            => trim((string)($inst->server ?? '')),
        'port'              => (int)(string)($inst->port ?? '443'),
        'uuid'              => trim((string)($inst->vless_uuid ?? '')),
        'vless_flow'         => trim((string)($inst->vless_flow ?? '')),
        'transport'         => (string)($inst->transport ?? 'xhttp'),
        'xhttp_mode'        => (string)($inst->xhttp_mode ?? 'auto'),
        'xhttp_path'        => (string)($inst->xhttp_path ?? '/'),
        'xhttp_host'        => trim((string)($inst->xhttp_host ?? '')),
        'xhttp_padding_enabled' => (string)($inst->xhttp_padding_enabled ?? '0') === '1',
        'xhttp_padding_bytes' => trim((string)($inst->xhttp_padding_bytes ?? '100-1000')),
        'xhttp_padding_obfs' => (string)($inst->xhttp_padding_obfs ?? '1') === '1',
        'xhttp_padding_placement' => (string)($inst->xhttp_padding_placement ?? 'header'),
        'xhttp_padding_method' => (string)($inst->xhttp_padding_method ?? 'tokenish'),
        'xhttp_uplink_method' => strtoupper(trim((string)($inst->xhttp_uplink_method ?? ''))),
        'xhttp_session_placement' => trim((string)($inst->xhttp_session_placement ?? '')),
        'xhttp_seq_placement' => trim((string)($inst->xhttp_seq_placement ?? '')),
        'grpc_service_name' => trim((string)($inst->grpc_service_name ?? '')),
        'grpc_authority' => trim((string)($inst->grpc_authority ?? '')),
        'grpc_multi_mode' => (string)($inst->grpc_multi_mode ?? '0') === '1',
        'security'          => (string)($inst->security ?? 'reality'),
        'reality_sni'       => trim((string)($inst->reality_sni ?? '')),
        'reality_public_key'=> trim((string)($inst->reality_public_key ?? '')),
        'reality_short_id'  => trim((string)($inst->reality_short_id ?? '')),
        'reality_spider_x'  => trim((string)($inst->reality_spider_x ?? '')),
        'fingerprint'       => (string)($inst->fingerprint ?? 'chrome'),
        'socks5_listen'    => $socksListen !== '' ? $socksListen : '127.0.0.1',
        'socks5_port'      => $socksPort > 0 ? $socksPort : 10808,
        'tun_iface'        => $iface !== '' ? $iface : 'tun0',
        'tun_address'      => $addr !== '' ? $addr : '169.254.100.1/32',
        'mtu'              => $mtu,
        'gateway_health_sync' => (string)($inst->gateway_health_sync ?? '0') === '1',
        'loglevel'         => $logLevel,
    ];
}

function xray_get_all_instances(): array
{
    $cfg = OPNsense\Core\Config::getInstance()->object();
    $instances = $cfg->OPNsense->xray->instances ?? null;
    if (!$instances) {
        return [];
    }
    $globalEnabled = (string)($cfg->OPNsense->xray->general->enabled ?? '0') === '1';
    $result = [];
    foreach ($instances->instance as $inst) {
        $uuid = (string)$inst['uuid'];
        if (!xray_valid_uuid($uuid)) {
            continue;
        }
        $parsed = xray_parse_instance($inst, $globalEnabled);
        $result[$uuid] = $parsed;
    }
    return $result;
}

function xray_get_config(string $uuid): array
{
    if (!xray_valid_uuid($uuid)) {
        return [];
    }
    $all = xray_get_all_instances();
    return $all[$uuid] ?? [];
}

function xray_build_config(array $c): array
{
    if (!xray_valid_iface($c['tun_iface'] ?? '')) {
        throw new RuntimeException('Invalid HEV TUN interface. Expected tunN.');
    }
    if (!xray_valid_hev_cidr($c['tun_address'] ?? '')) {
        throw new RuntimeException('HEV on FreeBSD configures IPv4 as /32; TUN address must use /32.');
    }
    if (filter_var($c['socks5_listen'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
        || strpos((string)$c['socks5_listen'], '127.') !== 0) {
        throw new RuntimeException('SOCKS5 listen address must be inside 127.0.0.0/8.');
    }
    $socksPort = (int)($c['socks5_port'] ?? 0);
    if ($socksPort < 1 || $socksPort > 65535) {
        throw new RuntimeException('Invalid SOCKS5 port.');
    }
    $mtu = (int)($c['mtu'] ?? 0);
    if ($mtu < 576 || $mtu > 9000) {
        throw new RuntimeException('Invalid MTU; expected 576..9000.');
    }

    $transport = (string)($c['transport'] ?? 'xhttp');
    if (!in_array($transport, ['raw', 'xhttp', 'grpc'], true)) {
        throw new RuntimeException('Unsupported VLESS transport.');
    }
    if (($c['security'] ?? '') !== 'reality') {
        throw new RuntimeException('This build supports VLESS + REALITY security.');
    }
    $server = trim((string)($c['server'] ?? ''));
    $port = (int)($c['port'] ?? 0);
    $uuid = trim((string)($c['uuid'] ?? ''));
    if ($server === '' || $port < 1 || $port > 65535) throw new RuntimeException('Invalid VLESS server/port.');
    if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $uuid)) throw new RuntimeException('Invalid VLESS UUID.');
    $flow = trim((string)($c['vless_flow'] ?? ''));
    if (!in_array($flow, ['', 'xtls-rprx-vision', 'xtls-rprx-vision-udp443'], true)) {
        throw new RuntimeException('Unsupported VLESS flow.');
    }

    $transportSettings = [];
    if ($transport === 'xhttp') {
        $path = (string)($c['xhttp_path'] ?? '/');
        if ($path === '' || $path[0] !== '/') throw new RuntimeException('XHTTP path must start with /.');
        $mode = (string)($c['xhttp_mode'] ?? 'auto');
        if (!in_array($mode, ['auto', 'stream-one', 'stream-up', 'packet-up'], true)) {
            throw new RuntimeException('Invalid XHTTP mode.');
        }
        $paddingEnabled = !empty($c['xhttp_padding_enabled']);
        $paddingBytes = trim((string)($c['xhttp_padding_bytes'] ?? '100-1000'));
        if ($paddingEnabled && !preg_match('/^[1-9][0-9]{0,7}(?:-[1-9][0-9]{0,7})?$/D', $paddingBytes)) {
            throw new RuntimeException('Invalid XHTTP padding byte range.');
        }
        $paddingPlacement = (string)($c['xhttp_padding_placement'] ?? 'header');
        if ($paddingEnabled && !in_array($paddingPlacement, ['header', 'cookie', 'queryInHeader', 'query'], true)) {
            throw new RuntimeException('Invalid XHTTP padding placement.');
        }
        $paddingMethod = (string)($c['xhttp_padding_method'] ?? 'tokenish');
        if ($paddingEnabled && !in_array($paddingMethod, ['tokenish', 'repeat-x'], true)) {
            throw new RuntimeException('Invalid XHTTP padding method.');
        }
        $uplinkMethod = strtoupper(trim((string)($c['xhttp_uplink_method'] ?? '')));
        if ($uplinkMethod === 'NONE') $uplinkMethod = '';
        if ($uplinkMethod !== '' && !in_array($uplinkMethod, ['POST', 'GET'], true)) {
            throw new RuntimeException('Invalid XHTTP uplink HTTP method.');
        }
        if ($uplinkMethod === 'GET' && $mode !== 'packet-up') {
            throw new RuntimeException('XHTTP uplink GET is valid only in packet-up mode.');
        }
        $sessionPlacement = trim((string)($c['xhttp_session_placement'] ?? ''));
        if ($sessionPlacement === 'none') $sessionPlacement = '';
        $seqPlacement = trim((string)($c['xhttp_seq_placement'] ?? ''));
        if ($seqPlacement === 'none') $seqPlacement = '';
        foreach ([$sessionPlacement, $seqPlacement] as $placement) {
            if ($placement !== '' && !in_array($placement, ['path', 'header', 'cookie', 'query'], true)) {
                throw new RuntimeException('Invalid XHTTP session/sequence placement.');
            }
        }
        $settings = [
            'path' => $path,
            'mode' => $mode,
        ];
        $xhttpHost = trim((string)($c['xhttp_host'] ?? ''));
        if ($xhttpHost !== '') {
            if (strlen($xhttpHost) > 253 || preg_match('/[\x00-\x20\x7f\/\?\#@]/', $xhttpHost)) {
                throw new RuntimeException('Invalid XHTTP host.');
            }
            $settings['host'] = $xhttpHost;
        }
        if ($uplinkMethod !== '') $settings['uplinkHTTPMethod'] = $uplinkMethod;
        if ($sessionPlacement !== '') $settings['sessionIDPlacement'] = $sessionPlacement;
        if ($seqPlacement !== '') $settings['seqPlacement'] = $seqPlacement;
        if ($paddingEnabled) {
            $settings['xPaddingBytes'] = $paddingBytes;
            $settings['xPaddingObfsMode'] = !empty($c['xhttp_padding_obfs']);
            $settings['xPaddingPlacement'] = $paddingPlacement;
            $settings['xPaddingMethod'] = $paddingMethod;
        }
        $transportSettings['xhttpSettings'] = $settings;
    } elseif ($transport === 'grpc') {
        $settings = [];
        if (($c['grpc_service_name'] ?? '') !== '') $settings['serviceName'] = (string)$c['grpc_service_name'];
        if (($c['grpc_authority'] ?? '') !== '') $settings['authority'] = (string)$c['grpc_authority'];
        if (!empty($c['grpc_multi_mode'])) $settings['multiMode'] = true;
        if (!empty($settings)) $transportSettings['grpcSettings'] = $settings;
    }

    $sni = trim((string)($c['reality_sni'] ?? ''));
    $publicKey = trim((string)($c['reality_public_key'] ?? ''));
    $shortId = trim((string)($c['reality_short_id'] ?? ''));
    $fingerprint = (string)($c['fingerprint'] ?? 'chrome');
    if ($sni === '') {
        throw new RuntimeException('REALITY SNI is required.');
    }
    if (!preg_match('/^[A-Za-z0-9_-]{43,44}$/D', $publicKey)) {
        throw new RuntimeException('Invalid REALITY public key.');
    }
    if ($shortId !== '' && !preg_match('/^(?:[0-9a-fA-F]{2}){1,8}$/D', $shortId)) {
        throw new RuntimeException('Invalid REALITY short ID.');
    }
    if (!in_array($fingerprint, ['chrome', 'firefox', 'safari', 'edge', 'ios', 'android', 'qq', 'random', 'randomized'], true)) {
        throw new RuntimeException('Unsupported TLS fingerprint.');
    }

    $reality = [
        'serverName' => $sni,
        'fingerprint' => $fingerprint,
        'show' => false,
        'publicKey' => $publicKey,
    ];
    if ($shortId !== '') $reality['shortId'] = $shortId;
    $spiderX = trim((string)($c['reality_spider_x'] ?? ''));
    if ($spiderX !== '') {
        if ($spiderX[0] !== '/' || strlen($spiderX) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $spiderX)) {
            throw new RuntimeException('Invalid REALITY SpiderX.');
        }
        $reality['spiderX'] = $spiderX;
    }
    $streamSettings = array_merge([
        'method' => $transport,
        'security' => 'reality',
        'realitySettings' => $reality,
    ], $transportSettings);
    $user = ['id' => $uuid, 'encryption' => 'none'];
    if ($flow !== '') $user['flow'] = $flow;
    $outbound = [
        'tag' => 'proxy', 'protocol' => 'vless',
        'settings' => ['vnext' => [[
            'address' => $server, 'port' => $port,
            'users' => [$user],
        ]]],
        'streamSettings' => $streamSettings,
    ];

    return [
        'log' => ['loglevel' => $c['loglevel'] ?? 'warning'],
        'inbounds' => [[
            'tag' => 'socks-in',
            'listen' => $c['socks5_listen'],
            'port' => $socksPort,
            'protocol' => 'socks',
            'settings' => [
                'auth' => 'noauth',
                'udp' => true,
                'ip' => $c['socks5_listen'],
            ],
        ]],
        'outbounds' => [$outbound],
    ];
}

function hev_build_config(array $c): string
{
    if (!xray_valid_iface($c['tun_iface'] ?? '')) {
        throw new RuntimeException('Invalid HEV TUN interface. Expected tunN.');
    }
    if (!xray_valid_hev_cidr($c['tun_address'] ?? '')) {
        throw new RuntimeException('HEV FreeBSD IPv4 address must use /32.');
    }
    [$tunIp] = explode('/', $c['tun_address'], 2);
    $logMap = ['debug' => 'debug', 'info' => 'info', 'warning' => 'warn', 'error' => 'error', 'none' => 'error'];
    $hevLog = $logMap[$c['loglevel'] ?? 'warning'] ?? 'warn';
    $q = static function (string $value): string {
        return "'" . str_replace("'", "''", $value) . "'";
    };

    return "tunnel:\n"
        . '  name: ' . $q($c['tun_iface']) . "\n"
        . '  mtu: ' . (int)$c['mtu'] . "\n"
        . "  multi-queue: false\n"
        . '  ipv4: ' . $q($tunIp) . "\n"
        . "\n"
        . "socks5:\n"
        . '  address: ' . $q($c['socks5_listen']) . "\n"
        . '  port: ' . (int)$c['socks5_port'] . "\n"
        . "  udp: 'udp'\n\n"
        . "misc:\n"
        . "  log-file: stderr\n"
        . '  log-level: ' . $q($hevLog) . "\n";
}

function hev_stage_config(array $c): array
{
    if (!is_dir(HEV_CONF_DIR) && !mkdir(HEV_CONF_DIR, 0750, true)) {
        return [false, '', 'cannot create ' . HEV_CONF_DIR];
    }
    $target = hev_conf_path($c['inst_uuid']);
    $tmp = tempnam(dirname($target), '.xray-hev.');
    if ($tmp === false) {
        return [false, '', 'cannot create temporary HEV config'];
    }
    try {
        $yaml = hev_build_config($c);
        if (file_put_contents($tmp, $yaml, LOCK_EX) === false || !chmod($tmp, 0640)) {
            return [false, '', 'cannot write temporary HEV config'];
        }
        if (!rename($tmp, $target)) {
            return [false, '', 'cannot atomically replace ' . $target];
        }
        $tmp = '';
        return [true, $target, ''];
    } catch (Throwable $e) {
        return [false, '', $e->getMessage()];
    } finally {
        if ($tmp !== '' && file_exists($tmp)) {
            @unlink($tmp);
        }
    }
}

function xray_validate_file(string $path): array
{
    if (!is_executable(XRAY_BIN)) {
        return [false, 'xray binary not found at ' . XRAY_BIN];
    }
    if (!is_file($path)) {
        return [false, 'config file not found: ' . $path];
    }
    $cmd = 'XRAY_LOCATION_ASSET=' . escapeshellarg(XRAY_ASSET_DIR)
        . ' ' . escapeshellarg(XRAY_BIN)
        . ' run -test -c ' . escapeshellarg($path) . ' 2>&1';
    exec($cmd, $out, $rc);
    return [$rc === 0, trim(implode("\n", $out))];
}

function xray_stage_config(array $c, ?string $targetOverride = null): array
{
    if (!is_dir(XRAY_CONF_DIR) && !mkdir(XRAY_CONF_DIR, 0750, true)) {
        return [false, '', 'cannot create ' . XRAY_CONF_DIR];
    }
    $target = $targetOverride ?? xray_conf_path($c['inst_uuid']);
    $tmpDir = dirname($target);
    $tmp = xray_temp_json($tmpDir, '.xray.');
    if ($tmp === false) {
        return [false, '', 'cannot create temporary config'];
    }
    try {
        $config = xray_build_config($c);
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($tmp, $json, LOCK_EX) === false || !chmod($tmp, 0640)) {
            return [false, '', 'cannot write temporary config'];
        }
        [$valid, $validation] = xray_validate_file($tmp);
        if (!$valid) {
            return [false, '', 'Xray config validation failed: ' . $validation];
        }
        if ($targetOverride !== null) {
            if (!rename($tmp, $target)) {
                return [false, '', 'cannot move validated config into place'];
            }
            $tmp = '';
            return [true, $target, ''];
        }
        if (!rename($tmp, $target)) {
            return [false, '', 'cannot atomically replace ' . $target];
        }
        $tmp = '';
        return [true, $target, ''];
    } catch (Throwable $e) {
        return [false, '', $e->getMessage()];
    } finally {
        if ($tmp !== '' && file_exists($tmp)) {
            @unlink($tmp);
        }
    }
}

function xray_validate_config_array(array $c): array
{
    $tmp = xray_temp_json('/tmp', 'xray-preflight-');
    if ($tmp === false) {
        return [false, 'cannot create temporary validation file'];
    }
    try {
        $config = xray_build_config($c);
        $json = json_encode(
            $config,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . "\n";
        if (file_put_contents($tmp, $json, LOCK_EX) === false || !chmod($tmp, 0600)) {
            return [false, 'cannot write temporary validation file'];
        }
        return xray_validate_file($tmp);
    } catch (Throwable $e) {
        return [false, $e->getMessage()];
    } finally {
        @unlink($tmp);
    }
}

function xray_preflight_inventory(array $all): bool
{
    $ok = true;
    $seenIfaces = [];
    $seenNetworks = [];
    $seenSocks = [];

    // Device names and transit ranges remain plugin-owned even for disabled
    // rows, so validate uniqueness across the complete configured inventory.
    foreach ($all as $id => $c) {
        $iface = (string)($c['tun_iface'] ?? '');
        $cidr = (string)($c['tun_address'] ?? '');
        $mtu = (int)($c['mtu'] ?? 0);
        if (!xray_valid_iface($iface) || !xray_valid_hev_cidr($cidr) || $mtu < 576 || $mtu > 9000) {
            echo "ERROR [{$id}]: invalid TUN interface/address/MTU.\n";
            $ok = false;
            continue;
        }
        if (isset($seenIfaces[$iface])) {
            echo "ERROR [{$id}]: duplicate TUN interface {$iface} (also used by {$seenIfaces[$iface]}).\n";
            $ok = false;
        } else {
            $seenIfaces[$iface] = $id;
        }
        $socksKey = (string)($c['socks5_listen'] ?? '') . ':' . (int)($c['socks5_port'] ?? 0);
        if (isset($seenSocks[$socksKey])) {
            echo "ERROR [{$id}]: duplicate SOCKS5 listener {$socksKey} (also used by {$seenSocks[$socksKey]}).\n";
            $ok = false;
        } else {
            $seenSocks[$socksKey] = $id;
        }
        foreach ($seenNetworks as $other) {
            if (xray_cidrs_overlap($cidr, $other['cidr'])) {
                echo "ERROR [{$id}]: TUN network {$cidr} overlaps {$other['cidr']} from {$other['id']}.\n";
                $ok = false;
            }
        }
        $seenNetworks[] = ['id' => $id, 'cidr' => $cidr];
    }
    if (!$ok) {
        return false;
    }

    // Before a bulk Save/Restart mutates any working runtime, ensure every
    // instance that should be started has a configuration accepted by Xray.
    foreach ($all as $id => $c) {
        if (empty($c['enabled'])) {
            continue;
        }
        [$valid, $message] = xray_validate_config_array($c);
        if (!$valid) {
            echo "ERROR [{$id}]: Xray preflight validation failed: {$message}\n";
            $ok = false;
        }
    }
    return $ok;
}

function xray_process_exists(int $pid): bool
{
    if ($pid <= 1) {
        return false;
    }
    exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $out, $rc);
    return $rc === 0;
}

function xray_read_pidfile(string $file): int
{
    if (!is_file($file)) {
        return 0;
    }

    // Reading a pidfile must never mutate it. FreeBSD daemon(8) opens/creates
    // -p/-P pidfiles before daemonizing and writes the actual PID only later.
    // During that legitimate startup window the pathname may exist while the
    // file is still empty. Unlinking it here would detach daemon(8)'s already
    // open pidfile descriptor from the pathname, so its later pidfile_write()
    // succeeds only into an unlinked inode and the controller can never track
    // the child/supervisor. Stale pidfiles are removed only by explicit
    // start/stop/cleanup paths after their state has been established.
    $raw = trim((string)@file_get_contents($file));
    if ($raw === '' || !ctype_digit($raw)) {
        return 0;
    }
    $pid = (int)$raw;
    return $pid > 1 ? $pid : 0;
}

function xray_process_matches(string $uuid, int $pid): bool
{
    if ($pid <= 1 || !xray_valid_uuid($uuid)) {
        return false;
    }
    $cmd = trim((string)shell_exec('/bin/ps -ww -o command= -p ' . $pid . ' 2>/dev/null'));
    if ($cmd === '') {
        return false;
    }
    return strpos($cmd, XRAY_BIN) !== false && strpos($cmd, xray_conf_path($uuid)) !== false;
}

function xray_supervisor_matches(string $uuid, int $pid): bool
{
    if ($pid <= 1 || !xray_valid_uuid($uuid)) {
        return false;
    }
    $cmd = trim((string)shell_exec('/bin/ps -ww -o command= -p ' . $pid . ' 2>/dev/null'));
    if ($cmd === '') {
        return false;
    }

    // New launches use a UUID-specific daemon(8) title. FreeBSD rewrites the
    // supervisor command line after fork, so matching the original -P/-p/-o
    // arguments is not reliable once the supervisor is running.
    if (strpos($cmd, 'daemon: xray:' . $uuid . '[') !== false) {
        return true;
    }

    // Compatibility with a supervisor that has not yet changed its process
    // title: require both our daemon invocation and this instance UUID.
    if (strpos($cmd, '/usr/sbin/daemon') !== false
        && strpos($cmd, 'xray:' . $uuid) !== false
        && strpos($cmd, XRAY_BIN) !== false
        && strpos($cmd, xray_conf_path($uuid)) !== false) {
        return true;
    }

    // Compatibility with legacy supervisor titles. If the tracked Xray
    // child still exists, its PPID proves which daemon supervisor owns it.
    $child = xray_read_pidfile(xray_pid_path($uuid));
    if ($child > 1 && xray_process_matches($uuid, $child)) {
        $ppidRaw = trim((string)shell_exec('/bin/ps -o ppid= -p ' . $child . ' 2>/dev/null'));
        if (ctype_digit($ppidRaw) && (int)$ppidRaw === $pid
            && strpos($cmd, 'daemon:') !== false
            && strpos($cmd, XRAY_BIN) !== false) {
            return true;
        }
    }
    return false;
}

function xray_supervisor_pid(string $uuid): int
{
    $file = xray_supervisor_pid_path($uuid);
    $pid = xray_read_pidfile($file);
    if ($pid <= 1) {
        return 0;
    }
    if (xray_supervisor_matches($uuid, $pid)) {
        return $pid;
    }
    // Do not unlink a pidfile merely because a live process has not yet
    // changed its title or because the file is stale and its PID was reused.
    // daemon(8) writes pidfiles before the child execs, so destructive matching
    // here creates a real startup race.
    if (!xray_process_exists($pid)) {
        @unlink($file);
    }
    return 0;
}

function xray_pid(string $uuid): int
{
    $file = xray_pid_path($uuid);
    $pid = xray_read_pidfile($file);
    if ($pid <= 1) {
        return 0;
    }
    if (xray_process_matches($uuid, $pid)) {
        return $pid;
    }
    // FreeBSD daemon(8) writes -p before exec(2). During that small window the
    // PID is valid but still appears as daemon(8), not Xray. Never delete that
    // live pidfile solely because the command has not transitioned yet.
    if (!xray_process_exists($pid)) {
        @unlink($file);
    }
    return 0;
}

function xray_is_running(string $uuid): bool
{
    return xray_pid($uuid) > 0;
}

function xray_kill(string $uuid): bool
{
    $pidFile = xray_pid_path($uuid);
    $rawPid = xray_read_pidfile($pidFile);
    $pid = xray_pid($uuid);
    if ($pid > 0) {
        exec('/bin/kill -TERM ' . $pid . ' 2>/dev/null');
        for ($i = 0; $i < 50; $i++) {
            usleep(100000);
            if (!xray_process_matches($uuid, $pid)) {
                break;
            }
        }
        if (xray_process_matches($uuid, $pid)) {
            exec('/bin/kill -KILL ' . $pid . ' 2>/dev/null');
            for ($i = 0; $i < 20 && xray_process_matches($uuid, $pid); $i++) {
                usleep(100000);
            }
        }
        if (xray_process_matches($uuid, $pid)) {
            return false;
        }
    } elseif ($rawPid > 1 && xray_process_exists($rawPid)) {
        echo "ERROR: refusing to kill unverified live PID {$rawPid} from {$pidFile}.\n";
        return false;
    }
    @unlink($pidFile);

    // daemon(8) normally exits with its child. Still verify the supervisor as
    // a separate owned process; a surviving daemon must not be treated as a
    // successful stop because it would block a subsequent start/upgrade.
    $supervisorFile = xray_supervisor_pid_path($uuid);
    $rawSupervisor = xray_read_pidfile($supervisorFile);
    $supervisor = xray_supervisor_pid($uuid);
    if ($supervisor > 0) {
        exec('/bin/kill -TERM ' . $supervisor . ' 2>/dev/null');
        for ($i = 0; $i < 20 && xray_supervisor_matches($uuid, $supervisor); $i++) {
            usleep(100000);
        }
        if (xray_supervisor_matches($uuid, $supervisor)) {
            exec('/bin/kill -KILL ' . $supervisor . ' 2>/dev/null');
            for ($i = 0; $i < 20 && xray_supervisor_matches($uuid, $supervisor); $i++) {
                usleep(100000);
            }
        }
        if (xray_supervisor_matches($uuid, $supervisor)) {
            return false;
        }
    } elseif ($rawSupervisor > 1 && xray_process_exists($rawSupervisor)) {
        echo "ERROR: refusing to kill unverified live daemon PID {$rawSupervisor} from {$supervisorFile}.\n";
        return false;
    }
    @unlink($supervisorFile);
    return true;
}

function hev_process_matches(string $uuid, int $pid): bool
{
    if ($pid <= 1 || !xray_valid_uuid($uuid)) {
        return false;
    }
    $cmd = trim((string)shell_exec('/bin/ps -ww -o command= -p ' . $pid . ' 2>/dev/null'));
    return $cmd !== '' && strpos($cmd, HEV_BIN) !== false && strpos($cmd, hev_conf_path($uuid)) !== false;
}

function hev_supervisor_matches(string $uuid, int $pid): bool
{
    if ($pid <= 1 || !xray_valid_uuid($uuid)) {
        return false;
    }
    $cmd = trim((string)shell_exec('/bin/ps -ww -o command= -p ' . $pid . ' 2>/dev/null'));
    if ($cmd === '') {
        return false;
    }
    if (strpos($cmd, 'daemon: xray-hev:' . $uuid . '[') !== false) {
        return true;
    }
    if (strpos($cmd, '/usr/sbin/daemon') !== false
        && strpos($cmd, 'xray-hev:' . $uuid) !== false
        && strpos($cmd, HEV_BIN) !== false
        && strpos($cmd, hev_conf_path($uuid)) !== false) {
        return true;
    }
    $child = xray_read_pidfile(hev_pid_path($uuid));
    if ($child > 1 && hev_process_matches($uuid, $child)) {
        $ppidRaw = trim((string)shell_exec('/bin/ps -o ppid= -p ' . $child . ' 2>/dev/null'));
        return ctype_digit($ppidRaw) && (int)$ppidRaw === $pid && strpos($cmd, 'daemon:') !== false;
    }
    return false;
}

function hev_pid(string $uuid): int
{
    $file = hev_pid_path($uuid);
    $pid = xray_read_pidfile($file);
    if ($pid <= 1) {
        return 0;
    }
    if (hev_process_matches($uuid, $pid)) {
        return $pid;
    }
    if (!xray_process_exists($pid)) {
        @unlink($file);
    }
    return 0;
}

function hev_supervisor_pid(string $uuid): int
{
    $file = hev_supervisor_pid_path($uuid);
    $pid = xray_read_pidfile($file);
    if ($pid <= 1) {
        return 0;
    }
    if (hev_supervisor_matches($uuid, $pid)) {
        return $pid;
    }
    if (!xray_process_exists($pid)) {
        @unlink($file);
    }
    return 0;
}

function hev_is_running(string $uuid): bool
{
    return hev_pid($uuid) > 0;
}

function hev_kill(string $uuid): bool
{
    $pidFile = hev_pid_path($uuid);
    $rawPid = xray_read_pidfile($pidFile);
    $pid = hev_pid($uuid);
    if ($pid > 0) {
        exec('/bin/kill -TERM ' . $pid . ' 2>/dev/null');
        for ($i = 0; $i < 50 && hev_process_matches($uuid, $pid); $i++) {
            usleep(100000);
        }
        if (hev_process_matches($uuid, $pid)) {
            exec('/bin/kill -KILL ' . $pid . ' 2>/dev/null');
            for ($i = 0; $i < 20 && hev_process_matches($uuid, $pid); $i++) {
                usleep(100000);
            }
        }
        if (hev_process_matches($uuid, $pid)) {
            return false;
        }
    } elseif ($rawPid > 1 && xray_process_exists($rawPid)) {
        echo "ERROR: refusing to kill unverified live HEV PID {$rawPid} from {$pidFile}.\n";
        return false;
    }
    @unlink($pidFile);

    $supervisorFile = hev_supervisor_pid_path($uuid);
    $rawSupervisor = xray_read_pidfile($supervisorFile);
    $supervisor = hev_supervisor_pid($uuid);
    if ($supervisor > 0) {
        exec('/bin/kill -TERM ' . $supervisor . ' 2>/dev/null');
        for ($i = 0; $i < 20 && hev_supervisor_matches($uuid, $supervisor); $i++) {
            usleep(100000);
        }
        if (hev_supervisor_matches($uuid, $supervisor)) {
            exec('/bin/kill -KILL ' . $supervisor . ' 2>/dev/null');
            for ($i = 0; $i < 20 && hev_supervisor_matches($uuid, $supervisor); $i++) {
                usleep(100000);
            }
        }
        if (hev_supervisor_matches($uuid, $supervisor)) {
            return false;
        }
    } elseif ($rawSupervisor > 1 && xray_process_exists($rawSupervisor)) {
        echo "ERROR: refusing to kill unverified live HEV daemon PID {$rawSupervisor} from {$supervisorFile}.\n";
        return false;
    }
    @unlink($supervisorFile);
    return true;
}

function xray_socks_ready(array $c): bool
{
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen((string)$c['socks5_listen'], (int)$c['socks5_port'], $errno, $errstr, 0.2);
    if ($fp === false) {
        return false;
    }
    fclose($fp);
    return true;
}

function xray_iface_exists(string $iface): bool
{
    if (!xray_valid_iface($iface)) {
        return false;
    }
    exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' >/dev/null 2>&1', $o, $rc);
    return $rc === 0;
}

function xray_iface_marked(string $iface, string $uuid): bool
{
    if (!xray_valid_iface($iface) || !xray_valid_uuid($uuid) || !xray_iface_exists($iface)) {
        return false;
    }
    $out = shell_exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' 2>/dev/null');
    if (!is_string($out)) {
        return false;
    }
    return preg_match('/\bdescription:\s+xray:' . preg_quote($uuid, '/') . '(?:\s|$)/m', $out) === 1;
}

function xray_iface_opened_by_pid(string $iface, int $pid): bool
{
    if (!xray_valid_iface($iface) || $pid <= 1 || !xray_iface_exists($iface)) {
        return false;
    }
    $out = shell_exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' 2>/dev/null');
    if (!is_string($out) || $out === '') {
        return false;
    }
    return preg_match('/^\s*Opened by PID\s+' . preg_quote((string)$pid, '/') . '\s*$/mi', $out) === 1;
}

function xray_iface_owned(string $iface, string $uuid): bool
{
    if (!xray_valid_iface($iface) || !xray_valid_uuid($uuid) || !xray_iface_exists($iface)) {
        return false;
    }
    $pid = hev_pid($uuid);
    $openedByHev = $pid > 1 && xray_iface_opened_by_pid($iface, $pid);
    return $openedByHev || xray_iface_marked($iface, $uuid);
}

function xray_iface_claimable_by_instance(string $iface, string $uuid): bool
{
    if (!xray_valid_iface($iface) || !xray_valid_uuid($uuid) || !xray_iface_exists($iface)) {
        return false;
    }
    if (xray_iface_marked($iface, $uuid)) {
        return true;
    }
    $pid = hev_pid($uuid);
    return $pid > 1 && xray_iface_opened_by_pid($iface, $pid);
}

function xray_find_owned_iface(string $uuid): string
{
    if (!xray_valid_uuid($uuid)) {
        return '';
    }
    $list = trim((string)shell_exec('/sbin/ifconfig -l 2>/dev/null'));
    foreach (preg_split('/\s+/', $list, -1, PREG_SPLIT_NO_EMPTY) as $iface) {
        if (xray_valid_iface($iface) && xray_iface_owned($iface, $uuid)) {
            return $iface;
        }
    }
    return '';
}

function xray_iface_runtime_ok(string $iface, string $cidr, int $mtu): bool
{
    if (!xray_valid_iface($iface) || !xray_valid_hev_cidr($cidr) || !xray_iface_exists($iface)) {
        return false;
    }
    [$ip] = explode('/', trim($cidr), 2);
    $out = (string)shell_exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' 2>/dev/null');
    if ($out === '') {
        return false;
    }
    if (!preg_match('/\binet\s+' . preg_quote($ip, '/') . '(?:\s+-->\s+\S+)?\s+netmask\s+(\S+)/i', $out, $m)) {
        return false;
    }
    if (!in_array(strtolower($m[1]), ['0xffffffff', '255.255.255.255'], true)) {
        return false;
    }
    if (!preg_match('/\bmtu\s+' . preg_quote((string)$mtu, '/') . '\b/', $out)) {
        return false;
    }
    if (!preg_match('/flags=[^<]*<([^>]+)>/', $out, $fm)
        || !in_array('UP', array_map('strtoupper', array_map('trim', explode(',', $fm[1]))), true)) {
        return false;
    }
    return true;
}

function xray_iface_address_ok(string $iface, string $cidr): bool
{
    if (!xray_valid_cidr($cidr)) {
        return false;
    }
    [$ip] = explode('/', $cidr, 2);
    $out = shell_exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' 2>/dev/null');
    return is_string($out) && preg_match('/\binet\s+' . preg_quote($ip, '/') . '\b/', $out) === 1;
}

function xray_config_conflict(array $c): string
{
    $uuid = (string)($c['inst_uuid'] ?? '');
    $iface = (string)($c['tun_iface'] ?? '');
    $cidr = (string)($c['tun_address'] ?? '');
    if (!xray_valid_uuid($uuid) || !xray_valid_iface($iface) || !xray_valid_hev_cidr($cidr)) {
        return 'invalid TUN settings';
    }
    foreach (xray_get_all_instances() as $otherUuid => $other) {
        if ($otherUuid === $uuid) {
            continue;
        }
        if (($other['tun_iface'] ?? '') === $iface) {
            return "TUN interface {$iface} is also configured by instance {$otherUuid}";
        }
        $otherCidr = (string)($other['tun_address'] ?? '');
        if (xray_valid_cidr($otherCidr) && xray_cidrs_overlap($cidr, $otherCidr)) {
            return "TUN network {$cidr} overlaps {$otherCidr} from instance {$otherUuid}";
        }
    }
    return '';
}

function xray_iface_configure(array $c): bool
{
    $iface = $c['tun_iface'];
    $uuid = $c['inst_uuid'];
    if (!xray_iface_claimable_by_instance($iface, $uuid)) {
        echo "ERROR: refusing to claim {$iface}; it is not opened by this instance's tracked HEV process.\n";
        return false;
    }
    // HEV owns the interface address and MTU. The plugin only adds a marker
    // after opener-PID ownership has been proven; it does not rewrite address,
    // MTU, routes, PF rules, or OPNsense interface configuration automatically.
    exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' description ' . escapeshellarg('xray:' . $uuid) . ' 2>/dev/null', $o, $rc);
    if ($rc !== 0) {
        echo "ERROR: could not mark HEV TUN {$iface}.\n";
        return false;
    }
    if (!xray_iface_runtime_ok($iface, $c['tun_address'], (int)$c['mtu'])) {
        echo "ERROR: HEV TUN {$iface} runtime address/MTU does not match the configured values.\n";
        return false;
    }
    return xray_iface_owned($iface, $uuid);
}

function xray_iface_destroy(string $iface, string $uuid): bool
{
    if (!xray_valid_iface($iface) || !xray_valid_uuid($uuid) || !xray_iface_exists($iface)) {
        return true;
    }
    // tunN is a generic FreeBSD namespace. Destruction is deliberately more
    // conservative than runtime ownership checks: only our UUID description
    // is sufficient after the HEV PID may already have exited. An interface
    // whose description was replaced by OPNsense assignment is expected to
    // disappear automatically when HEV closes; otherwise leave it untouched
    // for manual inspection rather than risking another application's tunN.
    if (!xray_iface_marked($iface, $uuid)) {
        return true;
    }
    exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' destroy >/dev/null 2>&1', $o, $rc);
    // HEV should destroy tun(4) when its process closes.
    return $rc === 0 || !xray_iface_exists($iface);
}

function xray_lock_holder(): string
{
    if (!is_file(XRAY_LOCK_META)) {
        return '';
    }
    $raw = trim((string)@file_get_contents(XRAY_LOCK_META));
    return preg_match('/^pid=[0-9]+\s+token=[0-9a-f]+(?:\s+.*)?$/D', $raw) ? $raw : '';
}

function xray_lock_holder_alive(string $holder): bool
{
    if (!preg_match('/^pid=([0-9]+)/D', $holder, $m)) {
        return false;
    }
    $pid = (int)$m[1];
    if ($pid <= 1) {
        return false;
    }
    $cmd = trim((string)shell_exec('/bin/ps -ww -o command= -p ' . $pid . ' 2>/dev/null'));
    return $cmd !== '' && strpos($cmd, 'xray-service-control.php') !== false;
}

function xray_clear_stale_lifecycle_lock(): void
{
    $holder = xray_lock_holder();
    if ($holder !== '') {
        if (xray_lock_holder_alive($holder)) {
            return;
        }
    } else {
        // mkdir() is the atomic acquisition primitive and the owner metadata is
        // written immediately afterwards. Do not let a concurrent contender
        // delete a freshly-created directory during that tiny publication gap.
        $mtime = @filemtime(XRAY_LOCK_DIR);
        if ($mtime !== false && (time() - $mtime) < XRAY_LOCK_STALE_GRACE_SEC) {
            return;
        }
    }
    @unlink(XRAY_LOCK_META);
    @rmdir(XRAY_LOCK_DIR);
}

function xray_acquire_lifecycle_lock()
{
    $deadline = microtime(true) + (XRAY_LOCK_WAIT_MS / 1000);
    do {
        if (@mkdir(XRAY_LOCK_DIR, 0750)) {
            try {
                $token = bin2hex(random_bytes(16));
            } catch (Throwable $e) {
                $token = hash('sha256', getmypid() . ':' . microtime(true) . ':' . mt_rand());
            }
            $meta = sprintf(
                'pid=%d token=%s action=%s since=%s',
                getmypid(),
                $token,
                $GLOBALS['action'] ?? 'unknown',
                date('c')
            );
            if (@file_put_contents(XRAY_LOCK_META, $meta . "\n") === false || !@chmod(XRAY_LOCK_META, 0640)) {
                @unlink(XRAY_LOCK_META);
                @rmdir(XRAY_LOCK_DIR);
                return false;
            }
            return $token;
        }

        xray_clear_stale_lifecycle_lock();
        usleep(100000);
    } while (microtime(true) < $deadline);

    return false;
}

function xray_release_lifecycle_lock(string $token): void
{
    if ($token === '') {
        return;
    }
    $holder = xray_lock_holder();
    if ($holder !== '' && preg_match('/\btoken=' . preg_quote($token, '/') . '\b/', $holder) === 1) {
        @unlink(XRAY_LOCK_META);
        @rmdir(XRAY_LOCK_DIR);
    }
}

function xray_start_instance(array $c): bool
{
    $uuid = $c['inst_uuid'] ?? '';
    $iface = $c['tun_iface'] ?? '';
    if (!xray_valid_uuid($uuid)) {
        echo "ERROR: invalid instance UUID.\n";
        return false;
    }
    if (!$c['enabled']) {
        echo "ERROR: Xray instance [{$uuid}] is disabled.\n";
        return false;
    }
    if (!is_executable(XRAY_BIN)) {
        echo "ERROR: Xray binary not found at " . XRAY_BIN . ".\n";
        return false;
    }
    if (!is_executable(HEV_BIN)) {
        echo "ERROR: HEV binary not found at " . HEV_BIN . ".\n";
        return false;
    }
    if (!xray_valid_iface($iface) || !xray_valid_hev_cidr($c['tun_address'])
        || (int)$c['mtu'] < 576 || (int)$c['mtu'] > 9000) {
        echo "ERROR: invalid HEV TUN settings for {$uuid}; FreeBSD HEV requires an IPv4 /32.\n";
        return false;
    }

    $conflict = xray_config_conflict($c);
    if ($conflict !== '') {
        echo "ERROR: {$conflict}.\n";
        return false;
    }
    [$valid, $message] = xray_validate_config_array($c);
    if (!$valid) {
        echo "ERROR: Xray config validation failed: {$message}\n";
        return false;
    }

    $xrayRunning = xray_is_running($uuid);
    $hevRunning = hev_is_running($uuid);
    if ($xrayRunning && $hevRunning && xray_socks_ready($c)
        && xray_iface_owned($iface, $uuid)
        && xray_iface_runtime_ok($iface, $c['tun_address'], (int)$c['mtu'])) {
        @unlink(xray_stopped_flag($uuid));
        echo "OK: Xray+HEV [{$uuid}] already running on {$iface}.\n";
        return true;
    }
    if ($xrayRunning || $hevRunning || xray_find_owned_iface($uuid) !== '') {
        echo "ERROR: partial runtime exists for [{$uuid}]; use Restart/Stop before starting it again.\n";
        return false;
    }
    if (xray_iface_exists($iface)) {
        echo "ERROR: TUN interface {$iface} already exists and is not owned by this stopped instance.\n";
        return false;
    }

    [$ok, $conf, $err] = xray_stage_config($c);
    if (!$ok) {
        echo "ERROR: {$err}.\n";
        return false;
    }
    [$hevOk, $hevConf, $hevErr] = hev_stage_config($c);
    if (!$hevOk) {
        echo "ERROR: {$hevErr}.\n";
        return false;
    }

    $log = xray_instance_log($uuid);
    if (@file_put_contents($log, '', FILE_APPEND) === false || !@chmod($log, 0640)) {
        echo "ERROR: cannot create Xray/HEV log {$log}.\n";
        return false;
    }

    foreach ([xray_pid_path($uuid), xray_supervisor_pid_path($uuid), hev_pid_path($uuid), hev_supervisor_pid_path($uuid)] as $pidFile) {
        $candidate = xray_read_pidfile($pidFile);
        if ($candidate > 1 && xray_process_exists($candidate)) {
            echo "ERROR: stale runtime pidfile references live PID {$candidate}: {$pidFile}.\n";
            return false;
        }
        @unlink($pidFile);
    }

    $xrayCmd = 'XRAY_LOCATION_ASSET=' . escapeshellarg(XRAY_ASSET_DIR)
        . ' /usr/sbin/daemon -f -H'
        . ' -t ' . escapeshellarg('xray:' . $uuid)
        . ' -P ' . escapeshellarg(xray_supervisor_pid_path($uuid))
        . ' -p ' . escapeshellarg(xray_pid_path($uuid))
        . ' -o ' . escapeshellarg($log)
        . ' ' . escapeshellarg(XRAY_BIN)
        . ' run -c ' . escapeshellarg($conf);
    exec($xrayCmd, $out, $rc);
    if ($rc !== 0) {
        echo "ERROR: failed to launch Xray [{$uuid}].\n";
        return false;
    }
    for ($i = 0; $i < 50 && !xray_is_running($uuid); $i++) usleep(100000);
    if (!xray_is_running($uuid)) {
        xray_kill($uuid);
        echo "ERROR: Xray [{$uuid}] did not reach a tracked running state. See {$log}.\n";
        return false;
    }
    for ($i = 0; $i < 50 && !xray_socks_ready($c); $i++) {
        if (!xray_is_running($uuid)) break;
        usleep(100000);
    }
    if (!xray_is_running($uuid) || !xray_socks_ready($c)) {
        xray_kill($uuid);
        echo "ERROR: Xray SOCKS5 {$c['socks5_listen']}:{$c['socks5_port']} did not become ready. See {$log}.\n";
        return false;
    }

    $hevCmd = '/usr/sbin/daemon -f -H'
        . ' -t ' . escapeshellarg('xray-hev:' . $uuid)
        . ' -P ' . escapeshellarg(hev_supervisor_pid_path($uuid))
        . ' -p ' . escapeshellarg(hev_pid_path($uuid))
        . ' -o ' . escapeshellarg($log)
        . ' ' . escapeshellarg(HEV_BIN)
        . ' ' . escapeshellarg($hevConf);
    exec($hevCmd, $hevOut, $hevRc);
    if ($hevRc !== 0) {
        xray_kill($uuid);
        echo "ERROR: failed to launch HEV [{$uuid}].\n";
        return false;
    }
    for ($i = 0; $i < 50 && !hev_is_running($uuid); $i++) usleep(100000);
    if (!hev_is_running($uuid)) {
        hev_kill($uuid);
        xray_kill($uuid);
        echo "ERROR: HEV [{$uuid}] did not reach a tracked running state. See {$log}.\n";
        return false;
    }

    for ($i = 0; $i < 100; $i++) {
        if (!hev_is_running($uuid) || !xray_is_running($uuid)) break;
        if (xray_iface_claimable_by_instance($iface, $uuid)) break;
        usleep(100000);
    }
    if (!xray_iface_claimable_by_instance($iface, $uuid)) {
        hev_kill($uuid);
        xray_kill($uuid);
        echo xray_iface_exists($iface)
            ? "ERROR: {$iface} appeared but is not opened by the tracked HEV process.\n"
            : "ERROR: HEV TUN {$iface} did not appear within 10 seconds.\n";
        return false;
    }
    if (!xray_iface_configure($c)) {
        hev_kill($uuid);
        for ($i = 0; $i < 30 && xray_iface_exists($iface); $i++) usleep(100000);
        xray_iface_destroy($iface, $uuid);
        xray_kill($uuid);
        return false;
    }

    @unlink(xray_stopped_flag($uuid));
    echo "OK: Xray+HEV [{$uuid}] running on {$iface} ({$c['tun_address']}) via SOCKS5 {$c['socks5_listen']}:{$c['socks5_port']}.\n";
    return true;
}

function xray_stop_instance(string $uuid, bool $manual = true, ?array $knownConfig = null): bool
{
    if (!xray_valid_uuid($uuid)) {
        echo "ERROR: invalid instance UUID.\n";
        return false;
    }
    $c = $knownConfig ?? xray_get_config($uuid);
    $iface = $c['tun_iface'] ?? '';
    $ok = hev_kill($uuid);

    // HEV's FreeBSD backend destroys the TUN on close. Wait for that clean
    // teardown first; explicitly destroy only a still-marked plugin TUN.
    if ($iface !== '' && xray_valid_iface($iface)) {
        for ($i = 0; $i < 30 && xray_iface_exists($iface); $i++) {
            usleep(100000);
        }
        if (xray_iface_exists($iface) && xray_iface_marked($iface, $uuid)) {
            $ok = xray_iface_destroy($iface, $uuid) && $ok;
        } elseif (xray_iface_exists($iface)) {
            echo "ERROR: {$iface} remained after HEV stop but is not marked as this instance; refusing to destroy it.\n";
            $ok = false;
        }
    }

    // Handle an edited interface name: only remove devices carrying our UUID
    // marker. Never destroy an arbitrary tunN.
    for ($i = 0; $i < 8; $i++) {
        $ownedIface = xray_find_owned_iface($uuid);
        if ($ownedIface === '') break;
        if (!xray_iface_destroy($ownedIface, $uuid)) {
            $ok = false;
            break;
        }
    }

    $ok = xray_kill($uuid) && $ok;
    if ($manual) {
        @file_put_contents(xray_stopped_flag($uuid), date('c') . "\n", LOCK_EX);
    }
    echo $ok ? "OK: Xray+HEV [{$uuid}] stopped.\n" : "ERROR: Xray+HEV [{$uuid}] teardown could not be confirmed.\n";
    return $ok;
}

function xray_orphan_uuids(array $configured): array
{
    $orphans = [];
    foreach (array_merge(glob('/var/run/xray-*.pid') ?: [], glob('/var/run/xray-hev-*.pid') ?: []) as $pidfile) {
        $base = basename($pidfile);
        if (preg_match('/^xray-(?:daemon-)?([0-9a-fA-F-]{36})\.pid$/D', $base, $m)
            || preg_match('/^xray-hev-(?:daemon-)?([0-9a-fA-F-]{36})\.pid$/D', $base, $m)) {
            $uuid = $m[1];
            if (xray_valid_uuid($uuid) && !isset($configured[$uuid])) $orphans[$uuid] = true;
        }
    }
    $list = trim((string)shell_exec('/sbin/ifconfig -l 2>/dev/null'));
    foreach (preg_split('/\s+/', $list, -1, PREG_SPLIT_NO_EMPTY) as $iface) {
        if (!xray_valid_iface($iface)) continue;
        $out = (string)shell_exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' 2>/dev/null');
        if (preg_match('/\bdescription:\s+xray:([0-9a-fA-F-]{36})(?:\s|$)/m', $out, $m)
            && xray_valid_uuid($m[1]) && !isset($configured[$m[1]])) $orphans[$m[1]] = true;
    }
    return array_keys($orphans);
}

function xray_stop_orphans(array $configured): bool
{
    $ok = true;
    foreach (xray_orphan_uuids($configured) as $uuid) {
        $thisOk = hev_kill($uuid);
        if (!xray_kill($uuid)) $thisOk = false;
        for ($i = 0; $i < 8; $i++) {
            $iface = xray_find_owned_iface($uuid);
            if ($iface === '') break;
            if (!xray_iface_destroy($iface, $uuid)) {
                $thisOk = false;
                break;
            }
        }
        if ($thisOk) @unlink(xray_stopped_flag($uuid)); else $ok = false;
    }
    return $ok;
}

function xray_cleanup_derived(array $configured): void
{
    if (is_dir(XRAY_CONF_DIR)) {
        foreach (['config-*.json', 'hev-*.yaml'] as $pattern) {
            foreach (glob(XRAY_CONF_DIR . '/' . $pattern) ?: [] as $path) {
                $base = basename($path);
                if (!preg_match('/^(?:config-|hev-)([0-9a-fA-F-]{36})\.(?:json|yaml)$/D', $base, $m) || !xray_valid_uuid($m[1])) continue;
                $uuid = $m[1];
                if (isset($configured[$uuid]) && !empty($configured[$uuid]['instance_enabled'])) continue;
                @unlink($path);
            }
        }

        // Pre-release builds used config-<uuid>.json.auto as an intermediate
        // generated file.  It is not consumed by 1.0.0 and is safe to remove
        // from the plugin-owned configuration directory.
        foreach (glob(XRAY_CONF_DIR . '/config-*.json.auto') ?: [] as $path) {
            $base = basename($path);
            if (preg_match('/^config-([0-9a-fA-F-]{36})\.json\.auto$/D', $base, $m) && xray_valid_uuid($m[1])) {
                @unlink($path);
            }
        }
    }
    foreach (glob('/var/run/xray-health-*.json') ?: [] as $path) {
        if (!preg_match('/^xray-health-([0-9a-fA-F-]{36})\.json$/D', basename($path), $m) || !xray_valid_uuid($m[1])) continue;
        if (!isset($configured[$m[1]])) @unlink($path);
    }
    foreach (glob('/var/run/xray-stopped-*.flag') ?: [] as $path) {
        if (!preg_match('/^xray-stopped-([0-9a-fA-F-]{36})\.flag$/D', basename($path), $m) || !xray_valid_uuid($m[1])) continue;
        if (!isset($configured[$m[1]])) @unlink($path);
    }
}

function xray_status_instance(string $uuid, array $c = []): array
{
    if (empty($c)) $c = xray_get_config($uuid);
    $xrayRunning = xray_is_running($uuid);
    $hevRunning = hev_is_running($uuid);
    $socksReady = $xrayRunning && !empty($c) && xray_socks_ready($c);
    $iface = $c['tun_iface'] ?? '';
    $tunExists = $iface !== '' && xray_iface_exists($iface);
    $tunOwned = $tunExists && xray_iface_owned($iface, $uuid);
    $addrOk = $tunOwned && isset($c['tun_address'], $c['mtu']) && xray_iface_runtime_ok($iface, $c['tun_address'], (int)$c['mtu']);
    $ok = $xrayRunning && $hevRunning && $socksReady && $tunOwned && $addrOk;
    $health = xray_health_cache($uuid);
    $manualStopped = file_exists(xray_stopped_flag($uuid));
    if ($manualStopped || empty($c['enabled'])) {
        $health['connectivity'] = 'stopped';
    } elseif (!$ok) {
        $health['connectivity'] = 'offline';
        $health['health_message'] = 'Local runtime is not ready';
    }
    return [
        'name' => $c['name'] ?? $uuid,
        'instance_enabled' => (bool)($c['instance_enabled'] ?? false),
        'effective_enabled' => (bool)($c['enabled'] ?? false),
        'status' => $ok ? 'ok' : 'stopped',
        'xray_core' => $xrayRunning ? 'running' : 'stopped',
        'socks5' => $socksReady ? 'ready' : 'stopped',
        'hev' => $hevRunning ? 'running' : 'stopped',
        'tun' => !$tunExists ? 'missing' : (!$tunOwned ? 'conflict' : ($addrOk ? 'running' : 'misconfigured')),
        'tun_owned' => $tunOwned,
        'tun_interface' => $iface,
        'tun_address' => $c['tun_address'] ?? '',
        'manual_stopped' => $manualStopped,
    ] + $health;
}

$action = $argv[1] ?? 'status';
$uuid = isset($argv[2]) ? trim((string)$argv[2]) : '';
if ($uuid !== '' && !xray_valid_uuid($uuid)) {
    echo "ERROR: invalid instance UUID.\n";
    exit(1);
}

$mutating = in_array($action, ['start', 'stop', 'restart', 'reconfigure', 'start_instance', 'stop_instance', 'restart_instance', 'cleanup'], true);
$lockToken = '';
if ($mutating) {
    $lockToken = xray_acquire_lifecycle_lock();
    if ($lockToken === false) {
        $holder = xray_lock_holder();
        $detail = $holder !== '' ? " (holder: {$holder})" : '';
        echo 'ERROR: Xray lifecycle is busy; another mutation still owns the lock' . $detail . ".\n";
        exit(75);
    }
}

$exitCode = 0;
if ($mutating) {
    xray_event_log('ACTION ' . $action . ($uuid !== '' ? ' [' . $uuid . ']' : '') . ' begin');
}
try {
    switch ($action) {
        case 'start_instance':
            $c = xray_get_config($uuid);
            if (empty($c)) {
                echo "ERROR: instance not found.\n";
                $exitCode = 1;
                break;
            }
            $ok = xray_start_instance($c);
            $exitCode = $ok ? 0 : 1;
            break;

        case 'stop_instance':
            $ok = xray_stop_instance($uuid, true);
            $exitCode = $ok ? 0 : 1;
            break;

        case 'restart_instance':
            $c = xray_get_config($uuid);
            if (empty($c)) {
                echo "ERROR: instance not found.\n";
                $exitCode = 1;
                break;
            }
            $ok = true;
            if ($c['enabled']) {
                [$valid, $message] = xray_validate_config_array($c);
                if (!$valid) {
                    echo "ERROR [{$uuid}]: Xray preflight validation failed: {$message}\n";
                    $ok = false;
                }
            }
            if ($ok) {
                $ok = xray_stop_instance($uuid, false, $c);
            }
            if ($ok && $c['enabled']) {
                $ok = xray_start_instance($c);
            }
            $exitCode = $ok ? 0 : 1;
            break;

        case 'cleanup':
            $all = xray_get_all_instances();
            xray_cleanup_derived($all);
            echo "OK: derived Xray runtime/config artifacts cleaned.\n";
            $exitCode = 0;
            break;

        case 'start':
            if (!xray_global_enabled()) {
                echo "ERROR: Xray is globally disabled.\n";
                $exitCode = 1;
                break;
            }
            $all = xray_get_all_instances();
            if (!xray_preflight_inventory($all)) {
                $exitCode = 1;
                break;
            }
            $ok = xray_stop_orphans($all);
            foreach ($all as $id => $c) {
                if ($c['enabled'] && !xray_start_instance($c)) {
                    $ok = false;
                }
            }
            xray_cleanup_derived($all);
            $exitCode = $ok ? 0 : 1;
            if ($exitCode === 0) {
                echo "OK\n";
            }
            break;

        case 'stop':
            $all = xray_get_all_instances();
            $ok = true;
            foreach ($all as $id => $c) {
                if (!xray_stop_instance($id, true, $c)) {
                    $ok = false;
                }
            }
            if (!xray_stop_orphans($all)) {
                $ok = false;
            }
            $exitCode = $ok ? 0 : 1;
            if ($exitCode === 0) {
                echo "OK\n";
            }
            break;

        case 'restart':
        case 'reconfigure':
            $all = xray_get_all_instances();
            if (xray_global_enabled() && !xray_preflight_inventory($all)) {
                $exitCode = 1;
                break;
            }
            $preserveManualStops = $action === 'reconfigure';
            $manuallyStopped = [];
            if ($preserveManualStops) {
                foreach ($all as $id => $c) {
                    if (file_exists(xray_stopped_flag($id))) {
                        $manuallyStopped[$id] = true;
                    }
                }
            }
            $ok = true;
            foreach ($all as $id => $c) {
                if (!xray_stop_instance($id, false, $c)) {
                    $ok = false;
                }
            }
            if (!xray_stop_orphans($all)) {
                $ok = false;
            }
            xray_cleanup_derived($all);
            if (xray_global_enabled()) {
                foreach ($all as $id => $c) {
                    if ($c['enabled'] && !isset($manuallyStopped[$id]) && !xray_start_instance($c)) {
                        $ok = false;
                    }
                }
            }
            $exitCode = $ok ? 0 : 1;
            if ($exitCode === 0) {
                echo "OK\n";
            }
            break;

        case 'status':
            if ($uuid !== '') {
                echo json_encode(xray_status_instance($uuid), JSON_UNESCAPED_SLASHES) . "\n";
                break;
            }
            $all = xray_get_all_instances();
            $rows = [];
            $any = false;
            $allOk = true;
            foreach ($all as $id => $c) {
                $s = xray_status_instance($id, $c);
                $rows[$id] = $s;
                if ($s['xray_core'] === 'running') {
                    $any = true;
                }
                if ($c['enabled'] && $s['status'] !== 'ok') {
                    $allOk = false;
                }
            }
            echo json_encode(['status' => ($any && $allOk) ? 'ok' : 'stopped', 'instances' => $rows], JSON_UNESCAPED_SLASHES) . "\n";
            break;

        case 'statusall':
            $all = xray_get_all_instances();
            $rows = [];
            foreach ($all as $id => $c) {
                $rows[$id] = xray_status_instance($id, $c);
            }
            echo json_encode($rows, JSON_UNESCAPED_SLASHES) . "\n";
            break;

        case 'validate':
            $all = xray_get_all_instances();
            if ($uuid !== '') {
                $all = isset($all[$uuid]) ? [$uuid => $all[$uuid]] : [];
            }
            if (empty($all)) {
                if ($uuid !== '') {
                    echo "ERROR: no instance found to validate.\n";
                    $exitCode = 1;
                } else {
                    echo "OK: no instances configured.\n";
                }
                break;
            }
            $seenIfaces = [];
            $seenNetworks = [];
            foreach ($all as $id => $c) {
                if (isset($seenIfaces[$c['tun_iface']])) {
                    echo "ERROR: duplicate TUN interface {$c['tun_iface']}.\n";
                    $exitCode = 1;
                    continue;
                }
                $seenIfaces[$c['tun_iface']] = true;
                foreach ($seenNetworks as $otherCidr) {
                    if (xray_cidrs_overlap($c['tun_address'], $otherCidr)) {
                        echo "ERROR: TUN network {$c['tun_address']} overlaps {$otherCidr}.\n";
                        $exitCode = 1;
                        continue 2;
                    }
                }
                $seenNetworks[] = $c['tun_address'];
                if ($uuid !== '') {
                    $conflict = xray_config_conflict($c);
                    if ($conflict !== '') {
                        echo "ERROR [{$id}]: {$conflict}.\n";
                        $exitCode = 1;
                        continue;
                    }
                }
                $tmp = xray_temp_json('/tmp', 'xray-validate-');
                if ($tmp === false) {
                    echo "ERROR: cannot create validation file.\n";
                    $exitCode = 1;
                    continue;
                }
                try {
                    $config = xray_build_config($c);
                    file_put_contents($tmp, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n", LOCK_EX);
                    chmod($tmp, 0600);
                    [$valid, $msg] = xray_validate_file($tmp);
                    if (!$valid) {
                        echo "ERROR [{$id}]: {$msg}\n";
                        $exitCode = 1;
                    } else {
                        echo "OK [{$id}]\n";
                    }
                } catch (Throwable $e) {
                    echo "ERROR [{$id}]: {$e->getMessage()}\n";
                    $exitCode = 1;
                } finally {
                    @unlink($tmp);
                }
            }
            break;

        case 'version':
            $plugin = is_file(XRAY_VERSION_FILE) ? trim((string)file_get_contents(XRAY_VERSION_FILE)) : 'unknown';
            $xray = 'missing';
            if (is_executable(XRAY_BIN)) {
                exec(escapeshellarg(XRAY_BIN) . ' version 2>/dev/null', $vout, $vrc);
                if ($vrc === 0 && !empty($vout)) {
                    $xray = trim($vout[0]);
                }
            }
            $hev = 'missing';
            if (is_executable(HEV_BIN)) {
                $hev = 'installed';
                if (is_file(HEV_VERSION_INFO)) {
                    foreach (file(HEV_VERSION_INFO, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                        if (strpos($line, 'version=') === 0) {
                            $hev = 'HevSocks5Tunnel ' . substr($line, 8);
                            break;
                        }
                    }
                }
            }
            echo json_encode(['version' => $plugin, 'xray' => $xray, 'hev' => $hev], JSON_UNESCAPED_SLASHES) . "\n";
            break;

        default:
            echo "ERROR: unknown action: {$action}\n";
            $exitCode = 1;
    }
} finally {
    if ($mutating) {
        xray_event_log('ACTION ' . $action . ($uuid !== '' ? ' [' . $uuid . ']' : '') . ' ' . ($exitCode === 0 ? 'ok' : 'failed rc=' . $exitCode));
    }
    if (is_string($lockToken) && $lockToken !== '') {
        // Release the lifecycle lock before asking configd to rebuild PF.
        // The lock is a filesystem directory, not an open flock(2) descriptor,
        // so daemon/Xray cannot inherit it across fork/exec.
        xray_release_lifecycle_lock($lockToken);
    }
    if ($mutating) {
        // Reconcile releases/stopped clients first. For a start-like mutation,
        // fail closed until a fresh end-to-end probe proves the restarted path.
        // Run probes detached instead of invoking the watchdog, avoiding an
        // auto-restart recursion when the watchdog itself requested a restart.
        $gwSync = '/usr/local/opnsense/scripts/Xray/xray-gateway-sync.php';
        $healthScript = '/usr/local/opnsense/scripts/Xray/xray-health.php';
        if (is_file($gwSync)) {
            @exec('/usr/local/bin/php ' . escapeshellarg($gwSync) . ' reconcile >/dev/null 2>&1');
        }
        if (in_array($action, ['start', 'restart', 'reconfigure', 'start_instance', 'restart_instance'], true)) {
            $targets = [];
            if ($uuid !== '') {
                $targets[] = $uuid;
            } else {
                foreach (xray_get_all_instances() as $id => $c) {
                    if (!empty($c['enabled']) && !file_exists(xray_stopped_flag($id))) $targets[] = $id;
                }
            }
            foreach ($targets as $id) {
                if (is_file($gwSync)) {
                    @exec('/usr/local/bin/php ' . escapeshellarg($gwSync) . ' ' . escapeshellarg($id) . ' unknown >/dev/null 2>&1');
                }
                if ($exitCode === 0 && is_file($healthScript)) {
                    @exec('/usr/sbin/daemon /usr/local/bin/php ' . escapeshellarg($healthScript) . ' ' . escapeshellarg($id) . ' >/dev/null 2>&1');
                }
            }
        }
    }
}

exit($exitCode);
