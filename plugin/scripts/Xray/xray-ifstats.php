#!/usr/local/bin/php
<?php

set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
require_once('config.inc');

function fail_json(string $message, int $code = 1): void
{
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES) . "\n";
    exit($code);
}
function valid_uuid(string $uuid): bool { return (bool)preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $uuid); }
function valid_iface(string $iface): bool { return (bool)preg_match('/^tun(?:0|[1-9][0-9]{0,2})$/D', $iface); }
function tracked_pid(string $uuid, string $kind): int
{
    if ($kind === 'hev') {
        $pidfile = '/var/run/xray-hev-' . $uuid . '.pid';
        $binary = '/usr/local/libexec/xray/hev-socks5-tunnel';
        $conf = '/usr/local/etc/opnsense-xray/hev-' . $uuid . '.yaml';
    } else {
        $pidfile = '/var/run/xray-' . $uuid . '.pid';
        $binary = '/usr/local/libexec/xray/xray';
        $conf = '/usr/local/etc/opnsense-xray/config-' . $uuid . '.json';
    }
    $raw = is_file($pidfile) ? trim((string)@file_get_contents($pidfile)) : '';
    if (!ctype_digit($raw) || (int)$raw <= 1) return 0;
    $pid = (int)$raw;
    $cmd = trim((string)shell_exec('/bin/ps -ww -o command= -p ' . $pid . ' 2>/dev/null'));
    return $cmd !== '' && strpos($cmd, $binary) !== false && strpos($cmd, $conf) !== false ? $pid : 0;
}
function pid_uptime(int $pid): ?int
{
    if ($pid <= 1) return null;
    $elapsed = trim((string)shell_exec('/bin/ps -o etimes= -p ' . $pid . ' 2>/dev/null'));
    return ctype_digit($elapsed) ? (int)$elapsed : null;
}
function format_uptime(?int $seconds): string
{
    if ($seconds === null) return 'stopped';
    $d = intdiv($seconds, 86400); $h = intdiv($seconds % 86400, 3600); $m = intdiv($seconds % 3600, 60); $s = $seconds % 60;
    if ($d) return "{$d}d {$h}h {$m}m";
    if ($h) return "{$h}h {$m}m {$s}s";
    return "{$m}m {$s}s";
}
function format_bytes(int $bytes): string
{
    if ($bytes <= 0) return '0 B';
    $units = ['B','KiB','MiB','GiB','TiB'];
    $i = min((int)floor(log($bytes, 1024)), count($units)-1);
    return round($bytes / (1024 ** $i), 1) . ' ' . $units[$i];
}

$uuid = isset($argv[1]) ? trim((string)$argv[1]) : '';
if (!valid_uuid($uuid)) fail_json('Valid instance UUID required');
$cfg = OPNsense\Core\Config::getInstance()->object();
$inst = null;
foreach (($cfg->OPNsense->xray->instances->instance ?? []) as $candidate) {
    if ((string)$candidate['uuid'] === $uuid) { $inst = $candidate; break; }
}
if ($inst === null) fail_json('Instance not found');

$iface = (string)($inst->tun_interface ?? 'tun0');
$expectedAddr = (string)($inst->tun_address ?? '169.254.100.1/32');
if (!valid_iface($iface)) fail_json('Configured TUN interface is invalid');
[$expectedIp] = array_pad(explode('/', $expectedAddr, 2), 2, '');
$expectedMtu = (int)($inst->mtu ?? 1500);
$xrayPid = tracked_pid($uuid, 'xray');
$hevPid = tracked_pid($uuid, 'hev');

$ifOut = [];
exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' 2>/dev/null', $ifOut, $ifRc);
$ifconfig = implode("\n", $ifOut);
$tunIp = $tunNetmask = $flags = '';
$mtu = 0;
if ($ifRc === 0) {
    if (preg_match('/flags=\S+<([^>]+)>/', $ifconfig, $m)) $flags = $m[1];
    if (preg_match('/\binet\s+(\S+)(?:\s+-->\s+\S+)?\s+netmask\s+(\S+)/', $ifconfig, $m)) { $tunIp=$m[1]; $tunNetmask=strtolower($m[2]); }
    if (preg_match('/\bmtu\s+(\d+)/', $ifconfig, $m)) $mtu=(int)$m[1];
}
$tunMarked = $ifRc === 0 && preg_match('/\bdescription:\s+xray:' . preg_quote($uuid, '/') . '(?:\s|$)/m', $ifconfig) === 1;
$tunOpenedByHev = $ifRc === 0 && $hevPid > 1 && preg_match('/^\s*Opened by PID\s+' . preg_quote((string)$hevPid, '/') . '\s*$/mi', $ifconfig) === 1;
$tunOwned = $tunMarked || $tunOpenedByHev;
$addressOk = $tunOwned && $tunIp === $expectedIp && in_array($tunNetmask, ['0xffffffff','255.255.255.255'], true);
$upOk = $ifRc === 0 && in_array('UP', array_map('strtoupper', array_filter(array_map('trim', explode(',', $flags)))), true);
$mtuOk = $ifRc === 0 && $mtu === $expectedMtu;
$runtimeOk = $tunOwned && $addressOk && $upOk && $mtuOk;
$tunStatus = $ifRc !== 0 ? 'missing' : (!$tunOwned ? 'conflict' : ($runtimeOk ? 'running' : 'misconfigured'));

$bytesIn=$bytesOut=$pktsIn=$pktsOut=0;
if ($ifRc === 0) {
    $rows=[]; exec('/usr/bin/netstat -ibn -I ' . escapeshellarg($iface) . ' 2>/dev/null', $rows);
    foreach ($rows as $row) {
        $parts=preg_split('/\s+/', trim($row));
        if (!$parts || ($parts[0] ?? '') !== $iface) continue;
        // FreeBSD netstat columns vary; use the traditional Ipkts/Ibytes/Opkts/Obytes layout when present.
        if (count($parts) >= 11 && ctype_digit($parts[4]) && ctype_digit($parts[7]) && ctype_digit($parts[8]) && ctype_digit($parts[10])) {
            $pktsIn=(int)$parts[4]; $bytesIn=(int)$parts[7]; $pktsOut=(int)$parts[8]; $bytesOut=(int)$parts[10];
            break;
        }
    }
}

$assignment=$assignmentDescr=$configuredIpType=$configuredIp6Type='';
$gatewayPolicy=$assignmentEnabled=false;
if (isset($cfg->interfaces)) {
    foreach ($cfg->interfaces->children() as $key=>$ifcfg) {
        if ((string)($ifcfg->if ?? '') !== $iface) continue;
        $assignment=(string)$key; $assignmentDescr=(string)($ifcfg->descr ?? '');
        $gatewayPolicy=(string)($ifcfg->gateway_interface ?? '0') === '1';
        $assignmentEnabled=(string)($ifcfg->enable ?? '0') === '1';
        $configuredIpType=(string)($ifcfg->ipaddr ?? 'none');
        $configuredIp6Type=(string)($ifcfg->ipaddrv6 ?? 'none');
        break;
    }
}
$gatewayHealthSync=(string)($inst->gateway_health_sync ?? '0')==='1';
$gatewaySyncReady=$assignment!=='' && $assignmentEnabled && $gatewayPolicy;
$nativeGatewayName=''; $nativeGatewayForceDown=false; $nativeGatewayStatus=''; $nativeGatewayStatusText='';
if ($assignment!=='') {
    try {
        $gwModel=new OPNsense\Routing\Gateways();
        foreach ($gwModel->gatewaysIndexedByName(true, true, true) as $gwName=>$gw) {
            if (($gw['interface'] ?? '')===$assignment && ($gw['ipprotocol'] ?? 'inet')==='inet') {
                $nativeGatewayName=(string)$gwName;
                $nativeGatewayForceDown=!empty($gw['force_down']) && (string)$gw['force_down']!=='0';
                if (!empty($gw['gateway_interface'])) break;
            }
        }
        if ($nativeGatewayName!=='') {
            $gwOut=[]; $gwRc=1;
            exec('/usr/local/opnsense/scripts/routes/gateway_status.php 2>/dev/null', $gwOut, $gwRc);
            if ($gwRc===0) {
                $gwStatus=json_decode(implode("\n",$gwOut),true);
                if (is_array($gwStatus) && isset($gwStatus[$nativeGatewayName])) {
                    $nativeGatewayStatus=(string)($gwStatus[$nativeGatewayName]['status'] ?? '');
                    $nativeGatewayStatusText=(string)($gwStatus[$nativeGatewayName]['status_translated'] ?? '');
                }
            }
        }
    } catch (Throwable $e) {
        // Diagnostics must remain available even if the routing model is unavailable.
    }
}

$pfRoutePresent=false; $pfLines=[]; $pfSources=[];
foreach ([['/sbin/pfctl -sr 2>/dev/null','pfctl -sr'], ['/sbin/pfctl -vvsr 2>/dev/null','pfctl -vvsr']] as $probe) {
    $rules=[]; $pfRc=1; exec($probe[0], $rules, $pfRc);
    if ($pfRc !== 0) continue;
    foreach ($rules as $rule) {
        if (stripos($rule,'route-to') !== false && preg_match('/\b'.preg_quote($iface,'/').'\b/i', $rule)) {
            $pfRoutePresent=true;
            if (count($pfLines)<5) $pfLines[]=trim($rule);
            $pfSources[$probe[1]]=true;
        }
    }
}
if (!$pfRoutePresent && is_readable('/tmp/rules.debug')) {
    foreach (file('/tmp/rules.debug', FILE_IGNORE_NEW_LINES) ?: [] as $rule) {
        if (stripos($rule,'route-to') !== false && preg_match('/\b'.preg_quote($iface,'/').'\b/i', $rule)) {
            $pfRoutePresent=true;
            if (count($pfLines)<5) $pfLines[]=trim($rule);
            $pfSources['rules.debug']=true;
        }
    }
}
$server=(string)($inst->server ?? '');
$port=(int)($inst->port ?? 0);
$health=[]; $healthPath='/var/run/xray-health-'.$uuid.'.json';
if (is_file($healthPath)) {
    $tmp=json_decode((string)@file_get_contents($healthPath), true);
    if (is_array($tmp)) $health=$tmp;
}
$healthChecked=(int)($health['checked_at'] ?? 0);
$healthAge=$healthChecked>0 ? max(0,time()-$healthChecked) : null;
if (($health['status'] ?? '') === 'stopped') $healthState='stopped';
elseif ($healthChecked<=0) $healthState='unknown';
elseif ($healthAge!==null && $healthAge>150) $healthState='stale';
else $healthState=!empty($health['online']) ? 'online' : 'offline';

$result=[
    'instance_uuid'=>$uuid, 'instance_name'=>(string)($inst->name ?? ''), 'instance_enabled'=>(string)($inst->enabled ?? '1')==='1',
    'xray_running'=>$xrayPid>1, 'hev_running'=>$hevPid>1,
    'tun_interface'=>$iface, 'tun_status'=>$tunStatus, 'tun_ip'=>$tunIp, 'tun_netmask'=>$tunNetmask, 'tun_expected'=>$expectedAddr,
    'tun_owned'=>$tunOwned, 'tun_address_ok'=>$addressOk, 'tun_up_ok'=>$upOk, 'tun_mtu_ok'=>$mtuOk, 'tun_runtime_ok'=>$runtimeOk,
    'tun_flags'=>$flags, 'mtu'=>$mtu, 'mtu_expected'=>$expectedMtu,
    'bytes_in'=>$bytesIn, 'bytes_out'=>$bytesOut, 'pkts_in'=>$pktsIn, 'pkts_out'=>$pktsOut,
    'bytes_in_hr'=>format_bytes($bytesIn), 'bytes_out_hr'=>format_bytes($bytesOut),
    'xray_uptime_secs'=>pid_uptime($xrayPid), 'xray_uptime'=>format_uptime(pid_uptime($xrayPid)),
    'hev_uptime_secs'=>pid_uptime($hevPid), 'hev_uptime'=>format_uptime(pid_uptime($hevPid)),
    'server_address'=>$server, 'server_port'=>$port,
    'assigned'=>$assignment!=='', 'assignment_enabled'=>$assignmentEnabled, 'assignment'=>$assignment, 'assignment_descr'=>$assignmentDescr,
    'dynamic_gateway_policy'=>$gatewayPolicy, 'interface_ip_config'=>$configuredIpType, 'interface_ipv6_config'=>$configuredIp6Type,
    'gateway_health_sync_enabled'=>$gatewayHealthSync, 'gateway_sync_ready'=>$gatewaySyncReady,
    'native_gateway_name'=>$nativeGatewayName, 'native_gateway_force_down'=>$nativeGatewayForceDown,
    'native_gateway_status'=>$nativeGatewayStatus, 'native_gateway_status_text'=>$nativeGatewayStatusText,
    'pf_route_to_present'=>$pfRoutePresent, 'pf_route_to_rules'=>$pfLines, 'pf_route_to_sources'=>array_keys($pfSources),
    'connectivity'=>$healthState, 'health_message'=>(string)($health['message'] ?? ''), 'health_checked_at'=>$healthChecked>0?$healthChecked:null,
    'health_latency_ms'=>isset($health['latency_ms'])&&$health['latency_ms']!==null?(int)$health['latency_ms']:null, 'health_failures'=>(int)($health['consecutive_failures'] ?? 0),
];
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
