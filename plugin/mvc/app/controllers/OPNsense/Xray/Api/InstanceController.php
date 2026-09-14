<?php

namespace OPNsense\Xray\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Base\UserException;
use OPNsense\Core\Backend;
use OPNsense\Core\Config;

class InstanceController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = '\OPNsense\Xray\Instance';
    protected static $internalModelName  = 'instance';

    public function searchItemAction()
    {
        $response = $this->searchBase('instance', ['enabled', 'name', 'server', 'port', 'transport']);
        if (!empty($response['rows'])) {
            foreach ($response['rows'] as &$row) {
                $row['server_address'] = $row['server'] ?? '';
                $row['server_port'] = isset($row['port']) ? (string)$row['port'] : '';
            }
            unset($row);
        }
        return $response;
    }

    public function toggleItemAction($uuid, $enabled = null)
    {
        return $this->toggleBase('instance', $uuid, $enabled);
    }

    private function configuredInterfaceDevices(): array
    {
        $used = [];
        $cfg = Config::getInstance()->object();
        $interfaces = $cfg->interfaces ?? null;
        if ($interfaces !== null) {
            foreach ($interfaces->children() as $ifcfg) {
                $device = trim((string)($ifcfg->if ?? ''));
                if ($device !== '') {
                    $used[$device] = true;
                }
            }
        }
        return $used;
    }

    private function runtimeInterfaces(): array
    {
        $used = [];
        $out = [];
        @exec('/sbin/ifconfig -l 2>/dev/null', $out, $rc);
        if ($rc === 0 && !empty($out)) {
            foreach (preg_split('/\s+/', trim(implode(' ', $out))) as $iface) {
                if ($iface !== '') {
                    $used[$iface] = true;
                }
            }
        }
        return $used;
    }

    private function runtimeListeningPorts(): array
    {
        $ports = [];
        $out = [];
        @exec('/usr/bin/sockstat -46l 2>/dev/null', $out, $rc);
        if ($rc !== 0) {
            return $ports;
        }
        foreach ($out as $line) {
            // FreeBSD sockstat columns: USER COMMAND PID FD PROTO LOCAL FOREIGN.
            // Parsing the LOCAL token is safer than guessing IPv4/IPv6 syntax.
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 7 || !isset($parts[5])) {
                continue;
            }
            if (preg_match('/:(\d+)$/D', $parts[5], $m)) {
                $ports[(int)$m[1]] = true;
            }
        }
        return $ports;
    }

    private function ipv4Range(string $address, int $prefix): ?array
    {
        if ($prefix < 0 || $prefix > 32 || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
        $ip = ip2long($address);
        if ($ip === false) {
            return null;
        }
        $ip = (int)sprintf('%u', $ip);
        $mask = $prefix === 0 ? 0 : ((0xffffffff << (32 - $prefix)) & 0xffffffff);
        $network = $ip & $mask;
        $broadcast = $network | (~$mask & 0xffffffff);
        return [$network, $broadcast];
    }

    private function occupiedIpv4Ranges(): array
    {
        $ranges = [];
        $cfg = Config::getInstance()->object();
        $interfaces = $cfg->interfaces ?? null;
        if ($interfaces !== null) {
            foreach ($interfaces->children() as $ifcfg) {
                $ip = trim((string)($ifcfg->ipaddr ?? ''));
                $prefix = trim((string)($ifcfg->subnet ?? ''));
                if (ctype_digit($prefix)) {
                    $r = $this->ipv4Range($ip, (int)$prefix);
                    if ($r !== null) {
                        $ranges[] = $r;
                    }
                }
            }
        }

        // Also inspect live interfaces so addresses created outside config.xml
        // (including manually-created TUNs) reserve their actual IPv4 network.
        $out = [];
        @exec('/sbin/ifconfig -a inet 2>/dev/null', $out, $rc);
        if ($rc === 0) {
            foreach ($out as $line) {
                // FreeBSD point-to-point interfaces are commonly printed as:
                //   inet 169.254.100.1 --> 169.254.100.1 netmask 0xffffffff
                // while ordinary interfaces omit the peer portion.  Accept both.
                if (!preg_match('/\binet\s+(\d+\.\d+\.\d+\.\d+)(?:\s+-->\s+\d+\.\d+\.\d+\.\d+)?\s+netmask\s+(0x[0-9a-fA-F]+|\d+\.\d+\.\d+\.\d+)/', $line, $m)) {
                    continue;
                }
                $mask = $m[2];
                if (str_starts_with($mask, '0x')) {
                    $bits = substr_count(str_pad(base_convert(substr($mask, 2), 16, 2), 32, '0', STR_PAD_LEFT), '1');
                } else {
                    $long = ip2long($mask);
                    if ($long === false) {
                        continue;
                    }
                    $bits = substr_count(str_pad(decbin((int)sprintf('%u', $long)), 32, '0', STR_PAD_LEFT), '1');
                }
                $r = $this->ipv4Range($m[1], $bits);
                if ($r !== null) {
                    $ranges[] = $r;
                }
            }
        }
        return $ranges;
    }

    private function ipOccupied(string $ip, array $ranges): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return true;
        }
        $value = (int)sprintf('%u', $long);
        foreach ($ranges as $range) {
            if ($value >= $range[0] && $value <= $range[1]) {
                return true;
            }
        }
        return false;
    }

    public function getItemAction($uuid = null)
    {
        $response = $this->getBase('instance', 'instance', $uuid);
        if ($uuid !== null && $uuid !== '') {
            return $response;
        }

        $usedIfaces = $this->configuredInterfaceDevices() + $this->runtimeInterfaces();
        $usedPorts = $this->runtimeListeningPorts();
        $usedAddrs = [];
        $occupiedRanges = $this->occupiedIpv4Ranges();

        // Every persisted instance reserves its local resources.  Do not
        // infer validity from __reference here: OPNsense ArrayField references
        // are an implementation detail and are not guaranteed to look like
        // RFC 4122 UUIDs.  Skipping a row here can silently reallocate an
        // already-used TUN address when creating another client.
        foreach ($this->getModel()->instance->iterateItems() as $node) {
            $iface = trim((string)$node->tun_interface);
            if ($iface !== '') {
                $usedIfaces[$iface] = true;
            }
            $port = (int)(string)$node->socks5_port;
            if ($port > 0) {
                $usedPorts[$port] = true;
            }
            $addr = trim((string)$node->tun_address);
            if ($addr !== '') {
                $usedAddrs[$addr] = true;
                [$ip] = explode('/', $addr, 2);
                $r = $this->ipv4Range($ip, 32);
                if ($r !== null) {
                    $occupiedRanges[] = $r;
                }
            }
        }

        $iface = null;
        for ($n = 0; $n <= 999; $n++) {
            $candidate = 'tun' . $n;
            if (!isset($usedIfaces[$candidate])) {
                $iface = $candidate;
                break;
            }
        }

        $port = null;
        for ($candidate = 10808; $candidate <= 65535; $candidate++) {
            if (!isset($usedPorts[$candidate])) {
                $port = (string)$candidate;
                break;
            }
        }

        $addr = null;
        for ($third = 100; $third <= 254; $third++) {
            $ip = '169.254.' . $third . '.1';
            $candidate = $ip . '/32';
            if (!isset($usedAddrs[$candidate]) && !$this->ipOccupied($ip, $occupiedRanges)) {
                $addr = $candidate;
                break;
            }
        }

        if (isset($response['instance']) && is_array($response['instance'])) {
            $response['instance']['socks5_listen'] = '127.0.0.1';
            if ($port !== null) {
                $response['instance']['socks5_port'] = $port;
            }
            if ($iface !== null) {
                $response['instance']['tun_interface'] = $iface;
            }
            if ($addr !== null) {
                $response['instance']['tun_address'] = $addr;
            }
            if (($response['instance']['name'] ?? '') === 'default') {
                $suffix = $iface !== null && preg_match('/^tun(\d+)$/', $iface, $m) ? ((int)$m[1] + 1) : 1;
                $response['instance']['name'] = 'client-' . $suffix;
            }
        }
        return $response;
    }

    public function addItemAction()
    {
        return $this->addBase('instance', 'instance');
    }

    public function validateDraftAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }

        $body = $this->request->getJsonRawBody(true);
        $c = is_array($body) && isset($body['instance']) && is_array($body['instance'])
            ? $body['instance'] : [];

        $server = trim((string)($c['server'] ?? ''));
        $port = (int)($c['port'] ?? 0);
        $uuid = trim((string)($c['vless_uuid'] ?? ''));
        $flow = trim((string)($c['vless_flow'] ?? ''));
        $transport = (string)($c['transport'] ?? 'xhttp');
        $mode = (string)($c['xhttp_mode'] ?? 'auto');
        $path = (string)($c['xhttp_path'] ?? '/');
        $xhttpHost = trim((string)($c['xhttp_host'] ?? ''));
        $paddingEnabled = (string)($c['xhttp_padding_enabled'] ?? '0') === '1';
        $paddingBytes = trim((string)($c['xhttp_padding_bytes'] ?? '100-1000'));
        $paddingObfs = (string)($c['xhttp_padding_obfs'] ?? '1') === '1';
        $paddingPlacement = (string)($c['xhttp_padding_placement'] ?? 'header');
        $paddingMethod = (string)($c['xhttp_padding_method'] ?? 'tokenish');
        $uplinkMethod = strtoupper(trim((string)($c['xhttp_uplink_method'] ?? '')));
        if ($uplinkMethod === 'NONE') $uplinkMethod = '';
        $sessionPlacement = trim((string)($c['xhttp_session_placement'] ?? ''));
        if ($sessionPlacement === 'none') $sessionPlacement = '';
        $seqPlacement = trim((string)($c['xhttp_seq_placement'] ?? ''));
        if ($seqPlacement === 'none') $seqPlacement = '';
        $grpcServiceName = trim((string)($c['grpc_service_name'] ?? ''));
        $grpcAuthority = trim((string)($c['grpc_authority'] ?? ''));
        $grpcMultiMode = (string)($c['grpc_multi_mode'] ?? '0') === '1';
        $security = (string)($c['security'] ?? 'reality');
        $sni = trim((string)($c['reality_sni'] ?? ''));
        $publicKey = trim((string)($c['reality_public_key'] ?? ''));
        $shortId = trim((string)($c['reality_short_id'] ?? ''));
        $spiderX = trim((string)($c['reality_spider_x'] ?? ''));
        $fingerprint = (string)($c['fingerprint'] ?? 'chrome');
        $socksListen = trim((string)($c['socks5_listen'] ?? '127.0.0.1'));
        $socksPort = (int)($c['socks5_port'] ?? 0);
        $tun = trim((string)($c['tun_interface'] ?? ''));
        $tunAddress = trim((string)($c['tun_address'] ?? ''));
        $mtu = (int)($c['mtu'] ?? 0);
        $loglevel = (string)($c['loglevel'] ?? 'warning');

        if ($server === '' || $port < 1 || $port > 65535) {
            return ['result' => 'failed', 'message' => 'Invalid VLESS server/port.'];
        }
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $uuid)) {
            return ['result' => 'failed', 'message' => 'Invalid VLESS UUID.'];
        }
        if (!in_array($flow, ['', 'xtls-rprx-vision', 'xtls-rprx-vision-udp443'], true)) {
            return ['result' => 'failed', 'message' => 'Unsupported VLESS flow.'];
        }
        if (!in_array($transport, ['raw', 'xhttp', 'grpc'], true) || $security !== 'reality') {
            return ['result' => 'failed', 'message' => 'Supported REALITY transports are RAW, XHTTP and gRPC.'];
        }
        if ($transport === 'xhttp') {
            if (!in_array($mode, ['auto', 'stream-one', 'stream-up', 'packet-up'], true)) {
                return ['result' => 'failed', 'message' => 'Invalid XHTTP mode.'];
            }
            if ($path === '' || $path[0] !== '/') {
                return ['result' => 'failed', 'message' => 'XHTTP path must start with /.'];
            }
            if ($xhttpHost !== '' && (strlen($xhttpHost) > 253 || preg_match('/[\x00-\x20\x7f\/\?\#@]/', $xhttpHost))) {
                return ['result' => 'failed', 'message' => 'Invalid XHTTP host.'];
            }
            if ($paddingEnabled && !preg_match('/^[1-9][0-9]{0,7}(?:-[1-9][0-9]{0,7})?$/D', $paddingBytes)) {
                return ['result' => 'failed', 'message' => 'Invalid XHTTP padding byte range.'];
            }
            if ($paddingEnabled && !in_array($paddingPlacement, ['header', 'cookie', 'queryInHeader', 'query'], true)) {
                return ['result' => 'failed', 'message' => 'Invalid XHTTP padding placement.'];
            }
            if ($paddingEnabled && !in_array($paddingMethod, ['tokenish', 'repeat-x'], true)) {
                return ['result' => 'failed', 'message' => 'Invalid XHTTP padding method.'];
            }
            if ($uplinkMethod !== '' && !in_array($uplinkMethod, ['POST', 'GET'], true)) {
                return ['result' => 'failed', 'message' => 'Invalid XHTTP uplink HTTP method.'];
            }
            if ($uplinkMethod === 'GET' && $mode !== 'packet-up') {
                return ['result' => 'failed', 'message' => 'XHTTP uplink GET is valid only in packet-up mode.'];
            }
            foreach ([$sessionPlacement, $seqPlacement] as $placement) {
                if ($placement !== '' && !in_array($placement, ['path', 'header', 'cookie', 'query'], true)) {
                    return ['result' => 'failed', 'message' => 'Invalid XHTTP session/sequence placement.'];
                }
            }
        }
        if ($sni === '') {
            return ['result' => 'failed', 'message' => 'REALITY SNI is required.'];
        }
        if (!preg_match('/^[A-Za-z0-9_-]{43,44}$/D', $publicKey)) {
            return ['result' => 'failed', 'message' => 'Invalid REALITY Public Key.'];
        }
        if ($shortId !== '' && !preg_match('/^(?:[0-9a-fA-F]{2}){1,8}$/D', $shortId)) {
            return ['result' => 'failed', 'message' => 'REALITY Short ID must contain an even number of hexadecimal characters (2-16).'];
        }
        if ($spiderX !== '' && ($spiderX[0] !== '/' || strlen($spiderX) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $spiderX))) {
            return ['result' => 'failed', 'message' => 'REALITY SpiderX must be an absolute path without control characters.'];
        }
        if (!in_array($fingerprint, ['chrome', 'firefox', 'safari', 'edge', 'ios', 'android', 'qq', 'random', 'randomized'], true)) {
            return ['result' => 'failed', 'message' => 'Unsupported TLS fingerprint.'];
        }
        if (filter_var($socksListen, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || strpos($socksListen, '127.') !== 0) {
            return ['result' => 'failed', 'message' => 'SOCKS5 listen address must be inside 127.0.0.0/8.'];
        }
        if ($socksPort < 1 || $socksPort > 65535) {
            return ['result' => 'failed', 'message' => 'Invalid SOCKS5 port.'];
        }
        if (!preg_match('/^tun(?:0|[1-9][0-9]{0,2})$/D', $tun)) {
            return ['result' => 'failed', 'message' => 'Invalid HEV TUN interface. Expected tunN.'];
        }
        if (!preg_match('/^(?:\d{1,3}\.){3}\d{1,3}\/32$/D', $tunAddress)) {
            return ['result' => 'failed', 'message' => 'HEV FreeBSD TUN address must be an IPv4 /32.'];
        }
        [$tunIp] = explode('/', $tunAddress, 2);
        if (filter_var($tunIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return ['result' => 'failed', 'message' => 'Invalid HEV TUN IPv4 address.'];
        }
        if ($mtu < 576 || $mtu > 9000) {
            return ['result' => 'failed', 'message' => 'Invalid MTU; expected 576..9000.'];
        }
        if (!in_array($loglevel, ['debug', 'info', 'warning', 'error', 'none'], true)) {
            $loglevel = 'warning';
        }

        $reality = [
            'serverName' => $sni,
            'fingerprint' => $fingerprint,
            'show' => false,
            'publicKey' => $publicKey,
        ];
        if ($shortId !== '') {
            $reality['shortId'] = $shortId;
        }
        if ($spiderX !== '') {
            $reality['spiderX'] = $spiderX;
        }
        $transportSettings = [];
        if ($transport === 'xhttp') {
            $transportSettings['xhttpSettings'] = array_filter([
                'path' => $path,
                'host' => $xhttpHost !== '' ? $xhttpHost : null,
                'mode' => $mode,
                'uplinkHTTPMethod' => $uplinkMethod !== '' ? $uplinkMethod : null,
                'sessionIDPlacement' => $sessionPlacement !== '' ? $sessionPlacement : null,
                'seqPlacement' => $seqPlacement !== '' ? $seqPlacement : null,
                'xPaddingBytes' => $paddingEnabled ? $paddingBytes : null,
                'xPaddingObfsMode' => $paddingEnabled ? $paddingObfs : null,
                'xPaddingPlacement' => $paddingEnabled ? $paddingPlacement : null,
                'xPaddingMethod' => $paddingEnabled ? $paddingMethod : null,
            ], static fn($v) => $v !== null);
        } elseif ($transport === 'grpc') {
            $grpc = [];
            if ($grpcServiceName !== '') $grpc['serviceName'] = $grpcServiceName;
            if ($grpcAuthority !== '') $grpc['authority'] = $grpcAuthority;
            if ($grpcMultiMode) $grpc['multiMode'] = true;
            if (!empty($grpc)) $transportSettings['grpcSettings'] = $grpc;
        }
        $draftStreamSettings = array_merge([
            'method' => $transport,
            'security' => 'reality',
            'realitySettings' => $reality,
        ], $transportSettings);

        $user = ['id' => $uuid, 'encryption' => 'none'];
        if ($flow !== '') {
            $user['flow'] = $flow;
        }

        $config = [
            'log' => ['loglevel' => $loglevel],
            'inbounds' => [[
                'tag' => 'socks-in',
                'listen' => $socksListen,
                'port' => $socksPort,
                'protocol' => 'socks',
                'settings' => ['auth' => 'noauth', 'udp' => true, 'ip' => $socksListen],
            ]],
            'outbounds' => [[
                'tag' => 'proxy',
                'protocol' => 'vless',
                'settings' => ['vnext' => [[
                    'address' => $server,
                    'port' => $port,
                    'users' => [$user],
                ]]],
                'streamSettings' => $draftStreamSettings,
            ]],
        ];

        $base = tempnam('/tmp', 'xray-draft-');
        $tmp = $base !== false ? $base . '.json' : false;
        if ($base !== false && $tmp !== false && !@rename($base, $tmp)) {
            @unlink($base);
            $tmp = false;
        }
        if ($tmp === false) {
            return ['result' => 'failed', 'message' => 'Cannot create temporary validation file.'];
        }
        try {
            $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            if (file_put_contents($tmp, $json, LOCK_EX) === false || !chmod($tmp, 0600)) {
                return ['result' => 'failed', 'message' => 'Cannot write temporary validation file.'];
            }
            $cmd = 'XRAY_LOCATION_ASSET=' . escapeshellarg('/usr/local/share/opnsense-xray')
                . ' ' . escapeshellarg('/usr/local/libexec/xray/xray')
                . ' run -test -c ' . escapeshellarg($tmp) . ' 2>&1';
            exec($cmd, $out, $rc);
            $message = trim(implode("\n", $out));
            return [
                'result' => $rc === 0 ? 'ok' : 'failed',
                'message' => $rc === 0 ? 'Configuration OK.' : ($message !== '' ? $message : 'Xray validation failed.'),
            ];
        } catch (\Throwable $e) {
            return ['result' => 'failed', 'message' => $e->getMessage()];
        } finally {
            @unlink($tmp);
        }
    }

    private function assignedInterface(string $device): string
    {
        if ($device === '') {
            return '';
        }
        $cfg = Config::getInstance()->object();
        $interfaces = $cfg->interfaces ?? null;
        if ($interfaces === null) {
            return '';
        }
        foreach ($interfaces->children() as $key => $ifcfg) {
            if ((string)($ifcfg->if ?? '') === $device) {
                $descr = trim((string)($ifcfg->descr ?? ''));
                return $descr !== '' ? $descr : (string)$key;
            }
        }
        return '';
    }

    private function configuredTun(string $uuid): string
    {
        $node = $this->getModel()->getNodeByReference('instance.' . $uuid);
        return $node !== null ? (string)$node->tun_interface : '';
    }

    private function instanceRunning(string $uuid): bool
    {
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $uuid)) {
            return false;
        }
        $raw = (new Backend())->configdRun('xray status_instance ' . $uuid);
        $data = json_decode($raw, true);
        return is_array($data) && (
            ($data['xray_core'] ?? '') === 'running'
            || ($data['hev'] ?? '') === 'running'
            || !empty($data['tun_owned'])
        );
    }

    public function setItemAction($uuid)
    {
        if ($this->request->isPost() && $this->request->hasPost('instance')) {
            $oldTun = $this->configuredTun((string)$uuid);
            $post = $this->request->getPost('instance');
            $newTun = is_array($post) && array_key_exists('tun_interface', $post)
                ? trim((string)$post['tun_interface']) : $oldTun;
            if ($oldTun !== '' && $newTun !== $oldTun) {
                $assignment = $this->assignedInterface($oldTun);
                if ($assignment !== '') {
                    throw new UserException(
                        sprintf(
                            gettext('Cannot change TUN interface while %s is assigned as OPNsense interface %s. Unassign it first.'),
                            $oldTun,
                            $assignment
                        ),
                        gettext('Xray interface is assigned')
                    );
                }
            }
        }
        return $this->setBase('instance', 'instance', $uuid);
    }

    private function releaseGatewayHealthOwnership(string $uuid): void
    {
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $uuid)) {
            throw new UserException(gettext('Invalid Xray instance UUID.'), gettext('Cannot delete Xray instance'));
        }

        $registry = '/usr/local/etc/opnsense-xray/gateway-health-sync.json';
        if (!is_file($registry)) {
            return;
        }
        $script = '/usr/local/opnsense/scripts/Xray/xray-gateway-sync.php';
        if (!is_file($script)) {
            throw new UserException(
                gettext('Gateway Health Sync ownership is present but the release helper is missing.'),
                gettext('Cannot delete Xray instance')
            );
        }

        $out = [];
        $rc = 1;
        exec('/usr/local/bin/php ' . escapeshellarg($script) . ' release ' . escapeshellarg($uuid) . ' 2>&1', $out, $rc);
        $payload = json_decode(trim(implode("\n", $out)), true);
        if ($rc !== 0 || !is_array($payload) || ($payload['result'] ?? '') !== 'ok') {
            $message = is_array($payload) && !empty($payload['message'])
                ? (string)$payload['message']
                : trim(implode("\n", $out));
            if ($message === '') {
                $message = gettext('Gateway Health Sync ownership could not be released safely.');
            }
            throw new UserException($message, gettext('Cannot delete Xray instance'));
        }
    }

    public function delItemAction($uuid)
    {
        foreach (explode(',', (string)$uuid) as $id) {
            $id = trim($id);
            $tun = $this->configuredTun($id);
            if ($tun !== '') {
                $assignment = $this->assignedInterface($tun);
                if ($assignment !== '') {
                    throw new UserException(
                        sprintf(
                            gettext('Cannot delete this Xray instance while %s is assigned as OPNsense interface %s. Unassign it first.'),
                            $tun,
                            $assignment
                        ),
                        gettext('Xray interface is assigned')
                    );
                }
            }
            if ($this->instanceRunning($id)) {
                throw new UserException(
                    gettext('Stop this Xray instance before deleting it.'),
                    gettext('Xray instance is running')
                );
            }
            $this->releaseGatewayHealthOwnership($id);
        }
        return $this->delBase('instance', $uuid);
    }
}
