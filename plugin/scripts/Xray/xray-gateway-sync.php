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
        return ['result' => 'skipped', 'instance' => $uuid, 'enabled' => $syncEnabled, 'message' => 'TUN is not assigned in OPNsense'];
    }
    if (!$assignment['enabled']) {
        if (isset($registry[$uuid]) && is_array($registry[$uuid])) {
            return gs_release_record($uuid, $registry[$uuid]);
        }
        return ['result' => 'skipped', 'instance' => $uuid, 'enabled' => $syncEnabled, 'assignment' => $assignment['key'], 'message' => 'OPNsense interface assignment is disabled'];
    }
    if (!$assignment['gateway_interface']) {
        if (isset($registry[$uuid]) && is_array($registry[$uuid])) {
            return gs_release_record($uuid, $registry[$uuid]);
        }
        return ['result' => 'skipped', 'instance' => $uuid, 'enabled' => $syncEnabled, 'assignment' => $assignment['key'], 'message' => 'Dynamic Gateway Policy is disabled'];
    }

    // Unknown/stale health is intentionally non-destructive.  A gateway is
    // forced down only after the health producer has promoted repeated probe
    // failures to an explicit offline state.
    if ($healthState === 'unknown' || $healthState === 'stale') {
        return [
            'result' => 'skipped', 'instance' => $uuid, 'enabled' => $syncEnabled,
            'assignment' => $assignment['key'], 'health_state' => $healthState,
            'changed' => false, 'message' => 'Health is not conclusive; native gateway state preserved',
        ];
    }

    $gwModel = new OPNsense\Routing\Gateways();
    $persisted = null;
    foreach ($gwModel->gatewayIterator() as $row) {
        if (($row['interface'] ?? '') !== $assignment['key'] || ($row['ipprotocol'] ?? 'inet') !== 'inet') {
            continue;
        }
        $gatewayValue = trim((string)($row['gateway'] ?? ''));
        if ($gatewayValue === '' || $gatewayValue === 'dynamic') {
            $persisted = $row;
            break;
        }
    }

    $candidate = null;
    foreach ($gwModel->gatewaysIndexedByName(true, true, true) as $name => $row) {
        if (($row['interface'] ?? '') === $assignment['key'] && ($row['ipprotocol'] ?? 'inet') === 'inet') {
            $row['name'] = $name;
            $candidate = $row;
            if (!empty($row['gateway_interface'])) {
                break;
            }
        }
    }

    $desiredForceDown = $healthState !== 'online' ? '1' : '0';

    if ($persisted === null) {
        if ($candidate === null || empty($candidate['name'])) {
            return ['result' => 'skipped', 'instance' => $uuid, 'enabled' => true, 'assignment' => $assignment['key'], 'message' => 'Native dynamic gateway could not be resolved'];
        }
        $gatewayName = (string)$candidate['name'];
        $fields = [
            'disabled' => '0',
            'descr' => 'Xray health-managed gateway for ' . ($assignment['descr'] !== '' ? $assignment['descr'] : $assignment['key']),
            'defaultgw' => '0',
            'ipprotocol' => 'inet',
            'interface' => $assignment['key'],
            'gateway' => 'dynamic',
            'monitor_disable' => '1',
            'monitor_noroute' => '0',
            'name' => $gatewayName,
            'weight' => (string)($candidate['weight'] ?? '1'),
            'priority' => (string)($candidate['priority'] ?? '254'),
            'force_down' => $desiredForceDown,
        ];
        $registry = gs_registry_read();
        $registry[$uuid] = [
            'name'=>$gatewayName, 'interface'=>$assignment['key'], 'uuid'=>'',
            'original_force_down'=>false, 'created'=>true, 'tracked_at'=>time(), 'alarm_pending'=>false,
        ];
        if (!gs_registry_write($registry)) {
            return ['result'=>'failed','instance'=>$uuid,'gateway'=>$gatewayName,'message'=>'Could not persist gateway sync ownership registry'];
        }
        $gwModel->createOrUpdateGateway($fields, null);
        $cfgHandle->save();

        // createOrUpdateGateway() does not return the generated model UUID.
        // Re-read the just-persisted gateway and bind the ownership record to
        // its UUID immediately; name-only tracking is retained only for
        // compatibility with older pre-release registry entries.
        $freshModel = new OPNsense\Routing\Gateways();
        $createdRow = gs_find_persisted_gateway($freshModel, ['name' => $gatewayName, 'uuid' => '']);
        $createdUuid = (string)($createdRow['uuid'] ?? '');
        if ($createdUuid !== '') {
            $registry = gs_registry_read();
            if (isset($registry[$uuid])) {
                $registry[$uuid]['uuid'] = $createdUuid;
                gs_registry_write($registry);
            }
        }

        $alarm = gs_alarm($gatewayName);
        if (!$alarm['ok']) {
            $registry = gs_registry_read();
            if (isset($registry[$uuid])) { $registry[$uuid]['alarm_pending'] = true; gs_registry_write($registry); }
        }
        return [
            'result' => $alarm['ok'] ? 'ok' : 'warning',
            'instance' => $uuid,
            'enabled' => true,
            'assignment' => $assignment['key'],
            'gateway' => $gatewayName,
            'health_state' => $healthState,
            'force_down' => $desiredForceDown === '1',
            'changed' => true,
            'created' => true,
            'alarm' => $alarm,
            'message' => $alarm['ok'] ? 'Native dynamic gateway persisted and synchronized' : 'Gateway saved but routing alarm failed',
        ];
    }

    $gatewayName = (string)($persisted['name'] ?? ($candidate['name'] ?? ''));
    $gatewayUuid = (string)($persisted['uuid'] ?? '');

    // Upgrade older ownership records which tracked plugin-created gateways by
    // name only.  Once the OPNsense model exposes the persisted UUID, bind the
    // record to it so future release/delete operations are identity-safe.
    if ($gatewayUuid !== '' && isset($registry[$uuid]) && !empty($registry[$uuid]['created'])
        && empty($registry[$uuid]['uuid'])) {
        $registry[$uuid]['uuid'] = $gatewayUuid;
        gs_registry_write($registry);
    }

    $currentForceDown = !empty($persisted['force_down']) && (string)$persisted['force_down'] !== '0';
    $desiredBool = $desiredForceDown === '1';
    if ($currentForceDown === $desiredBool) {
        $pending = isset($registry[$uuid]) && !empty($registry[$uuid]['alarm_pending']);
        $alarm = null;
        if ($pending && $gatewayName !== '') {
            $alarm = gs_alarm($gatewayName);
            if ($alarm['ok']) {
                $registry = gs_registry_read();
                if (isset($registry[$uuid])) { $registry[$uuid]['alarm_pending'] = false; gs_registry_write($registry); }
            }
        }
        return [
            'result' => ($alarm !== null && !$alarm['ok']) ? 'warning' : 'ok',
            'instance' => $uuid, 'enabled' => $syncEnabled,
            'assignment' => $assignment['key'], 'gateway' => $gatewayName,
            'health_state' => $healthState, 'force_down' => $desiredBool,
            'changed' => false, 'created' => false, 'alarm' => $alarm,
            'message' => $pending ? (($alarm !== null && $alarm['ok']) ? 'Pending routing alarm retried successfully' : 'Routing alarm is still pending') : 'Native gateway state already synchronized',
        ];
    }

    $registry = gs_registry_read();
    if (!isset($registry[$uuid])) {
        $registry[$uuid] = [
            'name'=>$gatewayName, 'interface'=>$assignment['key'], 'uuid'=>$gatewayUuid,
            'original_force_down'=>$currentForceDown, 'created'=>false, 'tracked_at'=>time(), 'alarm_pending'=>false,
        ];
        if (!gs_registry_write($registry)) {
            return ['result'=>'failed','instance'=>$uuid,'gateway'=>$gatewayName,'message'=>'Could not persist gateway sync ownership registry'];
        }
    }
    if ($gatewayUuid === '') {
        return ['result' => 'skipped', 'instance' => $uuid, 'gateway' => $gatewayName, 'message' => 'Persisted gateway has no UUID'];
    }
    $gwModel->createOrUpdateGateway(['force_down' => $desiredForceDown], $gatewayUuid);
    $cfgHandle->save();
    $alarm = gs_alarm($gatewayName);
    if (!$alarm['ok']) {
        $registry = gs_registry_read();
        if (isset($registry[$uuid])) { $registry[$uuid]['alarm_pending'] = true; gs_registry_write($registry); }
    } else {
        $registry = gs_registry_read();
        if (isset($registry[$uuid]) && !empty($registry[$uuid]['alarm_pending'])) { $registry[$uuid]['alarm_pending'] = false; gs_registry_write($registry); }
    }
    return [
        'result' => $alarm['ok'] ? 'ok' : 'warning',
        'instance' => $uuid,
        'enabled' => $syncEnabled,
        'assignment' => $assignment['key'],
        'gateway' => $gatewayName,
        'health_state' => $healthState,
        'force_down' => $desiredBool,
        'changed' => true,
        'created' => false,
        'alarm' => $alarm,
        'message' => $alarm['ok'] ? 'Native gateway Force Down synchronized' : 'Gateway saved but routing alarm failed',
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
