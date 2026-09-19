#!/usr/local/bin/php
<?php

set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
require_once('config.inc');

const GS_REGISTRY = '/usr/local/etc/opnsense-xray/gateway-health-sync.json';

function gs_registry_read(): array
{
    if (!is_file(GS_REGISTRY)) return [];
    $data = json_decode((string)@file_get_contents(GS_REGISTRY), true);
    return is_array($data) ? $data : [];
}

function gs_registry_write(array $data): bool
{
    $dir = dirname(GS_REGISTRY);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return false;
    $tmp = GS_REGISTRY . '.tmp.' . getmypid();
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) { @unlink($tmp); return false; }
    @chmod($tmp, 0600);
    if (!@rename($tmp, GS_REGISTRY)) { @unlink($tmp); return false; }
    return true;
}

function gs_find_persisted_gateway(OPNsense\Routing\Gateways $model, array $record): ?array
{
    $trackedUuid = trim((string)($record['uuid'] ?? ''));
    $trackedName = trim((string)($record['name'] ?? ''));

    // New ownership records are UUID-bound.  Never fall back to a gateway
    // with the same name when a UUID is known: an operator may have removed
    // the plugin-created gateway and later reused its name for another one.
    if ($trackedUuid !== '') {
        foreach ($model->gatewayIterator() as $row) {
            if (($row['uuid'] ?? '') === $trackedUuid) return $row;
        }
        return null;
    }

    // Compatibility with pre-release registry entries which did not persist
    // the generated native-gateway UUID.  These entries are upgraded in place
    // as soon as the matching gateway is observed again.
    if ($trackedName !== '') {
        foreach ($model->gatewayIterator() as $row) {
            if (($row['name'] ?? '') === $trackedName) return $row;
        }
    }
    return null;
}

function gs_release_record(string $uuid, array $record): array
{
    $gwModel = new OPNsense\Routing\Gateways();
    $row = gs_find_persisted_gateway($gwModel, $record);
    $gwName = (string)($record['name'] ?? '');

    if ($row === null) {
        if (!empty($record['created']) && !empty($record['alarm_pending']) && $gwName !== '') {
            $alarm = gs_reconfigure_routing();
            if (!$alarm['ok']) {
                return ['result'=>'warning','instance'=>$uuid,'gateway'=>$gwName,'changed'=>false,'alarm'=>$alarm,'message'=>'Plugin-created gateway is gone, but routing reconfiguration is still pending'];
            }
        }
        $registry = gs_registry_read();
        unset($registry[$uuid]);
        gs_registry_write($registry);
        return ['result'=>'ok','instance'=>$uuid,'gateway'=>$gwName,'changed'=>false,'message'=>'Tracked native gateway no longer exists'];
    }

    $gwUuid = (string)($row['uuid'] ?? '');
    $gwName = (string)($row['name'] ?? $gwName);
    if ($gwUuid === '') {
        return ['result'=>'warning','instance'=>$uuid,'gateway'=>$gwName,'message'=>'Tracked gateway has no UUID; ownership state was not changed'];
    }

    if (!empty($record['created'])) {
        $gwModel->gateway_item->del($gwUuid);
        $gwModel->serializeToConfig(false, true);
        OPNsense\Core\Config::getInstance()->save();
        $alarm = gs_reconfigure_routing();
        if (!$alarm['ok']) {
            $registry = gs_registry_read();
            if (isset($registry[$uuid])) {
                $registry[$uuid]['alarm_pending'] = true;
                gs_registry_write($registry);
            }
            return ['result'=>'warning','instance'=>$uuid,'gateway'=>$gwName,'changed'=>true,'deleted'=>true,'alarm'=>$alarm,'message'=>'Plugin-created gateway removed, but routing reconfiguration failed'];
        }
        $registry = gs_registry_read();
        unset($registry[$uuid]);
        gs_registry_write($registry);
        return ['result'=>'ok','instance'=>$uuid,'gateway'=>$gwName,'changed'=>true,'deleted'=>true,'alarm'=>$alarm,'message'=>'Plugin-created native gateway removed and routing reconfigured'];
    }

    $original = !empty($record['original_force_down']) ? '1' : '0';
    $current = !empty($row['force_down']) && (string)$row['force_down'] !== '0';
    $changed = $current !== ($original === '1');
    if ($changed) {
        $gwModel->createOrUpdateGateway(['force_down'=>$original], $gwUuid);
        OPNsense\Core\Config::getInstance()->save();
    }
    $alarm = $gwName !== '' ? gs_alarm($gwName) : ['ok'=>true,'message'=>''];
    if ($alarm['ok']) {
        $registry = gs_registry_read();
        unset($registry[$uuid]);
        gs_registry_write($registry);
    } else {
        $registry = gs_registry_read();
        if (isset($registry[$uuid])) {
            $registry[$uuid]['alarm_pending'] = true;
            gs_registry_write($registry);
        }
    }
    return ['result'=>$alarm['ok']?'ok':'warning','instance'=>$uuid,'gateway'=>$gwName,'force_down'=>$original==='1','changed'=>$changed,'released'=>true,'alarm'=>$alarm,'message'=>$alarm['ok']?'Gateway health sync released; original Force Down restored':'Force Down restored but routing alarm failed'];
}

function gs_output(array $data, int $rc = 0): void
{
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit($rc);
}

function gs_valid_uuid(string $uuid): bool
{
    return (bool)preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $uuid);
}

function gs_find_instance($cfg, string $uuid)
{
    foreach (($cfg->OPNsense->xray->instances->instance ?? []) as $inst) {
        if ((string)$inst['uuid'] === $uuid) {
            return $inst;
        }
    }
    return null;
}

function gs_assignment_for_tun($cfg, string $tun): array
{
    if (!isset($cfg->interfaces)) {
        return [];
    }
    foreach ($cfg->interfaces->children() as $key => $ifcfg) {
        if ((string)($ifcfg->if ?? '') !== $tun) {
            continue;
        }
        return [
            'key' => (string)$key,
            'descr' => (string)($ifcfg->descr ?? $key),
            'enabled' => (string)($ifcfg->enable ?? '0') === '1',
            'gateway_interface' => (string)($ifcfg->gateway_interface ?? '0') === '1',
        ];
    }
    return [];
}

function gs_alarm(string $gateway): array
{
    if ($gateway === '' || !preg_match('/^[A-Za-z0-9_.:-]+$/D', $gateway)) {
        return ['ok' => false, 'message' => 'Invalid native gateway name'];
    }
    $out = [];
    $rc = 1;
    exec('/usr/local/bin/flock -n -E 0 -o /tmp/filter_reload_gateway.lock /usr/local/etc/rc.routing_configure alarm ' . escapeshellarg($gateway) . ' 2>&1', $out, $rc);
    return [
        'ok' => $rc === 0,
        'message' => trim(implode("\n", $out)),
        'rc' => $rc,
    ];
}

function gs_reconfigure_routing(): array
{
    $out = [];
    $rc = 1;
    exec('/usr/local/bin/flock -n -E 0 -o /tmp/filter_reload_gateway.lock /usr/local/etc/rc.routing_configure 2>&1', $out, $rc);
    return [
        'ok' => $rc === 0,
        'message' => trim(implode("\n", $out)),
        'rc' => $rc,
    ];
}

function gs_synthetic_gateway_for_instance($inst): ?string
{
    $cidr = trim((string)($inst->tun_address ?? ''));
    $parts = explode('/', $cidr, 2);
    if (count($parts) !== 2 || $parts[1] !== '32'
        || filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return null;
    }

    $value = ip2long($parts[0]);
    if ($value === false) {
        return null;
    }
    if ($value < 0) {
        $value += 4294967296;
    }

    // The plugin allocator uses link-local x.x.x.1/32 addresses.  A synthetic
    // adjacent address is used only as OPNsense/PF's static far-gateway token;
    // no peer is expected to answer on it.
    if (($value & 0xff) >= 254) {
        return null;
    }
    return long2ip($value + 1);
}

function gs_gateway_base_name(array $assignment, string $tun): string
{
    $stem = strtoupper(trim((string)($assignment['descr'] ?? '')));
    if ($stem === '') {
        $stem = strtoupper(trim((string)($assignment['key'] ?? '')));
    }
    if ($stem === '') {
        $stem = strtoupper($tun);
    }
    $stem = preg_replace('/[^A-Z0-9_-]+/', '_', $stem);
    $stem = trim((string)$stem, '_-');
    if ($stem === '') {
        $stem = 'XRAY_' . strtoupper($tun);
    } elseif (strpos($stem, 'XRAY_') !== 0) {
        $stem = 'XRAY_' . $stem;
    }
    if (substr($stem, -3) !== '_GW') {
        $stem .= '_GW';
    }
    if (strlen($stem) > 32) {
        $stem = substr($stem, 0, 29) . '_GW';
    }
    return $stem;
}

function gs_gateway_name_exists(OPNsense\Routing\Gateways $model, string $name): bool
{
    foreach ($model->gatewayIterator() as $row) {
        if ((string)($row['name'] ?? '') === $name) {
            return true;
        }
    }
    return false;
}

function gs_unique_gateway_name(OPNsense\Routing\Gateways $model, string $base): ?string
{
    if (!gs_gateway_name_exists($model, $base)) {
        return $base;
    }
    for ($i = 2; $i <= 99; $i++) {
        $suffix = '_' . $i;
        $candidate = substr($base, 0, 32 - strlen($suffix)) . $suffix;
        if (!gs_gateway_name_exists($model, $candidate)) {
            return $candidate;
        }
    }
    return null;
}

function gs_find_gateway_by_address(OPNsense\Routing\Gateways $model, string $interface, string $address): ?array
{
    foreach ($model->gatewayIterator() as $row) {
        if (($row['interface'] ?? '') !== $interface || ($row['ipprotocol'] ?? 'inet') !== 'inet') {
            continue;
        }
        if (trim((string)($row['gateway'] ?? '')) === $address) {
            return $row;
        }
    }
    return null;
}

function gs_gateway_is_expected_far(array $row, string $interface, string $address): bool
{
    return ($row['interface'] ?? '') === $interface
        && ($row['ipprotocol'] ?? 'inet') === 'inet'
        && trim((string)($row['gateway'] ?? '')) === $address
        && !empty($row['fargw'])
        && (string)$row['fargw'] !== '0';
}

function gs_sync_one(string $uuid, string $healthState): array
{
    $cfgHandle = OPNsense\Core\Config::getInstance();
    $cfg = $cfgHandle->object();
    $inst = gs_find_instance($cfg, $uuid);
    if ($inst === null) {
        return ['result' => 'skipped', 'instance' => $uuid, 'message' => 'Instance not found'];
    }

    $syncEnabled = (string)($inst->gateway_health_sync ?? '0') === '1';
    $registry = gs_registry_read();
    if (!$syncEnabled) {
        if (isset($registry[$uuid]) && is_array($registry[$uuid])) {
            return gs_release_record($uuid, $registry[$uuid]);
        }
        return ['result'=>'ok','instance'=>$uuid,'enabled'=>false,'changed'=>false,'message'=>'Gateway health sync disabled'];
    }

    $tun = trim((string)($inst->tun_interface ?? ''));
    $assignment = gs_assignment_for_tun($cfg, $tun);
    if (empty($assignment)) {
        if (isset($registry[$uuid]) && is_array($registry[$uuid])) {
            return gs_release_record($uuid, $registry[$uuid]);
        }
        return ['result'=>'skipped','instance'=>$uuid,'enabled'=>true,'message'=>'TUN is not assigned in OPNsense'];
    }
    if (!$assignment['enabled']) {
        if (isset($registry[$uuid]) && is_array($registry[$uuid])) {
            return gs_release_record($uuid, $registry[$uuid]);
        }
        return ['result'=>'skipped','instance'=>$uuid,'enabled'=>true,'assignment'=>$assignment['key'],'message'=>'OPNsense interface assignment is disabled'];
    }

    // Static Far Gateway mode deliberately replaces the 1.0.0 dynamic-gateway
    // integration.  Leaving Dynamic Gateway Policy enabled can materialize a
    // second addressless gateway which OPNsense omits from gateway-group PF
    // pools, so refuse to manage routing until the assignment is unambiguous.
    if (!empty($assignment['gateway_interface'])) {
        return [
            'result'=>'skipped','instance'=>$uuid,'enabled'=>true,'assignment'=>$assignment['key'],
            'message'=>'Disable Dynamic Gateway Policy on the assigned Xray interface; Gateway Health Sync uses a static Far Gateway in 1.0.1+',
        ];
    }

    $gatewayAddress = gs_synthetic_gateway_for_instance($inst);
    if ($gatewayAddress === null) {
        return [
            'result'=>'failed','instance'=>$uuid,'enabled'=>true,'assignment'=>$assignment['key'],
            'message'=>'Cannot derive a synthetic Far Gateway from the configured TUN /32 address',
        ];
    }

    // Unknown/stale health is intentionally non-destructive. A gateway is
    // forced down only after the health producer has promoted repeated probe
    // failures to an explicit offline state.
    if ($healthState === 'unknown' || $healthState === 'stale') {
        return [
            'result'=>'skipped','instance'=>$uuid,'enabled'=>true,
            'assignment'=>$assignment['key'],'gateway_address'=>$gatewayAddress,
            'health_state'=>$healthState,'changed'=>false,
            'message'=>'Health is not conclusive; native gateway state preserved',
        ];
    }

    $gwModel = new OPNsense\Routing\Gateways();
    $preferredName = '';

    // 1.0.0 could own a persisted dynamic gateway. Once Dynamic Gateway Policy
    // has been disabled, release that legacy ownership first. Plugin-created
    // dynamic gateways are deleted; pre-existing ones merely regain their
    // original Force Down state. Preserve the old name when it becomes free so
    // existing gateway-group references continue to resolve.
    if (isset($registry[$uuid]) && is_array($registry[$uuid])) {
        $record = $registry[$uuid];
        $tracked = gs_find_persisted_gateway($gwModel, $record);
        if ($tracked === null || !gs_gateway_is_expected_far($tracked, $assignment['key'], $gatewayAddress)) {
            $preferredName = trim((string)($record['name'] ?? ''));
            $released = gs_release_record($uuid, $record);
            if (($released['result'] ?? '') !== 'ok') {
                return [
                    'result'=>'warning','instance'=>$uuid,'enabled'=>true,
                    'assignment'=>$assignment['key'],'gateway_address'=>$gatewayAddress,
                    'migration'=>$released,
                    'message'=>'Legacy gateway ownership could not be released safely',
                ];
            }
            $registry = gs_registry_read();
            $gwModel = new OPNsense\Routing\Gateways();
        }
    }

    $persisted = gs_find_gateway_by_address($gwModel, $assignment['key'], $gatewayAddress);
    if ($persisted !== null && (empty($persisted['fargw']) || (string)$persisted['fargw'] === '0')) {
        return [
            'result'=>'skipped','instance'=>$uuid,'enabled'=>true,
            'assignment'=>$assignment['key'],'gateway'=>(string)($persisted['name'] ?? ''),
            'gateway_address'=>$gatewayAddress,
            'message'=>'The matching native gateway exists but Far Gateway is disabled',
        ];
    }
    if ($persisted !== null && (empty($persisted['monitor_disable']) || (string)$persisted['monitor_disable'] === '0')) {
        return [
            'result'=>'skipped','instance'=>$uuid,'enabled'=>true,
            'assignment'=>$assignment['key'],'gateway'=>(string)($persisted['name'] ?? ''),
            'gateway_address'=>$gatewayAddress,
            'message'=>'Disable native gateway monitoring before enabling Gateway Health Sync',
        ];
    }

    $desiredForceDown = $healthState !== 'online' ? '1' : '0';

    if ($persisted === null) {
        $gatewayName = '';
        if ($preferredName !== '' && !gs_gateway_name_exists($gwModel, $preferredName)) {
            $gatewayName = $preferredName;
        }
        if ($gatewayName === '') {
            $gatewayName = gs_unique_gateway_name($gwModel, gs_gateway_base_name($assignment, $tun)) ?? '';
        }
        if ($gatewayName === '') {
            return [
                'result'=>'failed','instance'=>$uuid,'enabled'=>true,'assignment'=>$assignment['key'],
                'gateway_address'=>$gatewayAddress,'message'=>'Could not allocate a unique native gateway name',
            ];
        }

        $fields = [
            'disabled'=>'0',
            'descr'=>'Xray health-managed Far Gateway for ' . ($assignment['descr'] !== '' ? $assignment['descr'] : $assignment['key']),
            'defaultgw'=>'0',
            'ipprotocol'=>'inet',
            'interface'=>$assignment['key'],
            'gateway'=>$gatewayAddress,
            'fargw'=>'1',
            'monitor_disable'=>'1',
            'monitor_noroute'=>'0',
            'name'=>$gatewayName,
            'weight'=>'1',
            'priority'=>'255',
            'force_down'=>$desiredForceDown,
        ];

        // Persist ownership before the config mutation so an interrupted create
        // can be reconciled safely by name on the next run.
        $registry = gs_registry_read();
        $registry[$uuid] = [
            'name'=>$gatewayName,'interface'=>$assignment['key'],'uuid'=>'',
            'gateway_address'=>$gatewayAddress,'original_force_down'=>false,
            'created'=>true,'tracked_at'=>time(),'alarm_pending'=>false,
        ];
        if (!gs_registry_write($registry)) {
            return ['result'=>'failed','instance'=>$uuid,'gateway'=>$gatewayName,'message'=>'Could not persist gateway sync ownership registry'];
        }

        $gwModel->createOrUpdateGateway($fields, null);
        $cfgHandle->save();

        $freshModel = new OPNsense\Routing\Gateways();
        $createdRow = gs_find_persisted_gateway($freshModel, ['name'=>$gatewayName,'uuid'=>'']);
        $createdUuid = (string)($createdRow['uuid'] ?? '');
        if ($createdUuid !== '') {
            $registry = gs_registry_read();
            if (isset($registry[$uuid])) {
                $registry[$uuid]['uuid'] = $createdUuid;
                gs_registry_write($registry);
            }
        }

        // Creating a gateway is structural routing state, so request a complete
        // routing reconfigure rather than a status-only alarm.
        $alarm = gs_reconfigure_routing();
        if (!$alarm['ok']) {
            $registry = gs_registry_read();
            if (isset($registry[$uuid])) {
                $registry[$uuid]['alarm_pending'] = true;
                gs_registry_write($registry);
            }
        }
        return [
            'result'=>$alarm['ok'] ? 'ok' : 'warning',
            'instance'=>$uuid,'enabled'=>true,'assignment'=>$assignment['key'],
            'gateway'=>$gatewayName,'gateway_address'=>$gatewayAddress,
            'health_state'=>$healthState,'force_down'=>$desiredForceDown === '1',
            'changed'=>true,'created'=>true,'alarm'=>$alarm,
            'message'=>$alarm['ok'] ? 'Native Far Gateway created and synchronized' : 'Far Gateway saved but routing reconfiguration failed',
        ];
    }

    $gatewayName = (string)($persisted['name'] ?? '');
    $gatewayUuid = (string)($persisted['uuid'] ?? '');
    $currentForceDown = !empty($persisted['force_down']) && (string)$persisted['force_down'] !== '0';

    $registry = gs_registry_read();
    if (!isset($registry[$uuid])) {
        $registry[$uuid] = [
            'name'=>$gatewayName,'interface'=>$assignment['key'],'uuid'=>$gatewayUuid,
            'gateway_address'=>$gatewayAddress,'original_force_down'=>$currentForceDown,
            'created'=>false,'tracked_at'=>time(),'alarm_pending'=>false,
        ];
        if (!gs_registry_write($registry)) {
            return ['result'=>'failed','instance'=>$uuid,'gateway'=>$gatewayName,'message'=>'Could not persist gateway sync ownership registry'];
        }
    } elseif ($gatewayUuid !== '' && empty($registry[$uuid]['uuid'])) {
        $registry[$uuid]['uuid'] = $gatewayUuid;
        $registry[$uuid]['gateway_address'] = $gatewayAddress;
        gs_registry_write($registry);
    }

    if ($gatewayUuid === '') {
        return ['result'=>'skipped','instance'=>$uuid,'gateway'=>$gatewayName,'gateway_address'=>$gatewayAddress,'message'=>'Persisted Far Gateway has no UUID'];
    }

    $desiredBool = $desiredForceDown === '1';
    if ($currentForceDown === $desiredBool) {
        $pending = isset($registry[$uuid]) && !empty($registry[$uuid]['alarm_pending']);
        $alarm = null;
        if ($pending && $gatewayName !== '') {
            $alarm = gs_reconfigure_routing();
            if ($alarm['ok']) {
                $registry = gs_registry_read();
                if (isset($registry[$uuid])) {
                    $registry[$uuid]['alarm_pending'] = false;
                    gs_registry_write($registry);
                }
            }
        }
        return [
            'result'=>($alarm !== null && !$alarm['ok']) ? 'warning' : 'ok',
            'instance'=>$uuid,'enabled'=>true,'assignment'=>$assignment['key'],
            'gateway'=>$gatewayName,'gateway_address'=>$gatewayAddress,
            'health_state'=>$healthState,'force_down'=>$desiredBool,
            'changed'=>false,'created'=>!empty($registry[$uuid]['created']),'alarm'=>$alarm,
            'message'=>$pending
                ? (($alarm !== null && $alarm['ok']) ? 'Pending routing reconfiguration retried successfully' : 'Routing reconfiguration is still pending')
                : 'Native Far Gateway state already synchronized',
        ];
    }

    $gwModel->createOrUpdateGateway(['force_down'=>$desiredForceDown], $gatewayUuid);
    $cfgHandle->save();
    $alarm = gs_alarm($gatewayName);
    if (!$alarm['ok']) {
        $registry = gs_registry_read();
        if (isset($registry[$uuid])) {
            $registry[$uuid]['alarm_pending'] = true;
            gs_registry_write($registry);
        }
    } else {
        $registry = gs_registry_read();
        if (isset($registry[$uuid]) && !empty($registry[$uuid]['alarm_pending'])) {
            $registry[$uuid]['alarm_pending'] = false;
            gs_registry_write($registry);
        }
    }

    return [
        'result'=>$alarm['ok'] ? 'ok' : 'warning',
        'instance'=>$uuid,'enabled'=>true,'assignment'=>$assignment['key'],
        'gateway'=>$gatewayName,'gateway_address'=>$gatewayAddress,
        'health_state'=>$healthState,'force_down'=>$desiredBool,
        'changed'=>true,'created'=>!empty($registry[$uuid]['created']),'alarm'=>$alarm,
        'message'=>$alarm['ok'] ? 'Native Far Gateway Force Down synchronized' : 'Gateway saved but routing alarm failed',
    ];
}

function gs_state_from_cache($cfg, $inst): string
{
    $uuid = (string)$inst['uuid'];
    $globalEnabled = (string)($cfg->OPNsense->xray->general->enabled ?? '0') === '1';
    $instanceEnabled = (string)($inst->enabled ?? '1') === '1';
    if (!$globalEnabled || !$instanceEnabled || is_file('/var/run/xray-stopped-' . $uuid . '.flag')) {
        return 'stopped';
    }
    $path = '/var/run/xray-health-' . $uuid . '.json';
    $cache = is_file($path) ? json_decode((string)@file_get_contents($path), true) : [];
    if (!is_array($cache)) {
        return 'unknown';
    }
    $checked = (int)($cache['checked_at'] ?? 0);
    if ($checked <= 0 || time() - $checked > 150) {
        return 'stale';
    }
    return !empty($cache['online']) ? 'online' : (string)($cache['status'] ?? 'offline');
}

$lockFp = @fopen('/var/run/xray-gateway-sync.lock', 'c');
if ($lockFp === false || !@flock($lockFp, LOCK_EX)) {
    gs_output(['result' => 'failed', 'message' => 'Could not acquire gateway sync lock'], 1);
}

$arg1 = isset($argv[1]) ? trim((string)$argv[1]) : 'reconcile';
if ($arg1 === 'release') {
    $uuid = isset($argv[2]) ? trim((string)$argv[2]) : '';
    if (!gs_valid_uuid($uuid)) {
        @flock($lockFp, LOCK_UN); @fclose($lockFp);
        gs_output(['result' => 'failed', 'message' => 'Invalid instance UUID'], 1);
    }
    $registry = gs_registry_read();
    if (!isset($registry[$uuid]) || !is_array($registry[$uuid])) {
        @flock($lockFp, LOCK_UN); @fclose($lockFp);
        gs_output(['result'=>'ok','instance'=>$uuid,'changed'=>false,'message'=>'No gateway health ownership is tracked']);
    }
    $result = gs_release_record($uuid, $registry[$uuid]);
    @flock($lockFp, LOCK_UN); @fclose($lockFp);
    gs_output($result, ($result['result'] ?? '') === 'ok' ? 0 : 1);
}
if ($arg1 === 'release_all') {
    $registry = gs_registry_read();
    $rows = []; $ok = true;
    foreach ($registry as $uuid=>$record) {
        if (!gs_valid_uuid((string)$uuid) || !is_array($record)) continue;
        $rows[$uuid] = gs_release_record((string)$uuid, $record);
        if (!in_array(($rows[$uuid]['result'] ?? ''), ['ok'], true)) $ok = false;
    }
    @flock($lockFp, LOCK_UN); @fclose($lockFp);
    gs_output(['result'=>$ok?'ok':'failed','instances'=>$rows], $ok?0:1);
}
if ($arg1 === '' || $arg1 === 'reconcile') {
    $cfg = OPNsense\Core\Config::getInstance()->object();
    $rows = [];
    $configured = [];
    foreach (($cfg->OPNsense->xray->instances->instance ?? []) as $inst) {
        $uuid = (string)$inst['uuid'];
        if (!gs_valid_uuid($uuid)) {
            continue;
        }
        $configured[$uuid] = true;
        $state = gs_state_from_cache($cfg, $inst);
        $rows[$uuid] = gs_sync_one($uuid, $state);
    }

    // Recover ownership left behind by an interrupted/older client deletion.
    // Missing instances cannot legitimately keep a plugin-managed gateway.
    foreach (gs_registry_read() as $uuid => $record) {
        if (!gs_valid_uuid((string)$uuid) || !is_array($record) || isset($configured[$uuid])) {
            continue;
        }
        $rows[$uuid] = gs_release_record((string)$uuid, $record);
    }

    $overall = 'ok';
    foreach ($rows as $row) {
        if (!in_array(($row['result'] ?? ''), ['ok', 'skipped'], true)) {
            $overall = 'warning';
            break;
        }
    }
    @flock($lockFp, LOCK_UN);
    @fclose($lockFp);
    gs_output(['result' => $overall, 'instances' => $rows]);
}

$uuid = $arg1;
$state = isset($argv[2]) ? strtolower(trim((string)$argv[2])) : 'unknown';
if (!gs_valid_uuid($uuid)) {
    gs_output(['result' => 'failed', 'message' => 'Invalid instance UUID'], 1);
}
if (!in_array($state, ['online', 'offline', 'stopped', 'unknown', 'stale'], true)) {
    $state = 'unknown';
}
$result = gs_sync_one($uuid, $state);
@flock($lockFp, LOCK_UN);
@fclose($lockFp);
gs_output($result, 0);
