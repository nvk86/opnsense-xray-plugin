<?php

namespace OPNsense\Xray\Api;

use OPNsense\Base\ApiMutableServiceControllerBase;
use OPNsense\Core\Backend;

class ServiceController extends ApiMutableServiceControllerBase
{
    protected static $internalServiceClass    = '\\OPNsense\\Xray\\General';
    protected static $internalServiceTemplate = 'OPNsense/Xray';
    protected static $internalServiceEnabled  = 'enabled';
    protected static $internalServiceName     = 'xray';

    private function uuid(string $uuid): ?string
    {
        $uuid = trim($uuid);
        if ($uuid === '') {
            return '';
        }
        return preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $uuid)
            ? $uuid : null;
    }

    private function invalidUuid(): array
    {
        return ['result' => 'failed', 'message' => 'Invalid instance UUID'];
    }

    private function runMutation(string $globalAction, string $instanceAction, string $uuid): array
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $id = $this->uuid($uuid);
        if ($id === null) {
            return $this->invalidUuid();
        }
        $cmd = $id === '' ? "xray {$globalAction}" : "xray {$instanceAction} {$id}";
        $output = trim((new Backend())->configdRun($cmd));
        $failed = $output === '' || stripos($output, 'ERROR:') !== false || stripos($output, 'failed') !== false;
        $state = $failed ? 'failed' : 'ok';
        return [
            'status' => $state,
            'result' => $state,
            'message' => $output !== '' ? $output : 'No response from configd',
        ];
    }

    public function reconfigureAction($uuid = '')
    {
        return $this->runMutation('reconfigure', 'restart_instance', (string)$uuid);
    }

    public function startAction($uuid = '')
    {
        return $this->runMutation('start', 'start_instance', (string)$uuid);
    }

    public function stopAction($uuid = '')
    {
        return $this->runMutation('stop', 'stop_instance', (string)$uuid);
    }

    public function restartAction($uuid = '')
    {
        return $this->runMutation('restart', 'restart_instance', (string)$uuid);
    }

    public function statusAction($uuid = '')
    {
        $id = $this->uuid((string)$uuid);
        if ($id === null) {
            return ['status' => 'error', 'message' => 'Invalid instance UUID'];
        }
        $cmd = $id !== '' ? 'xray status_instance ' . $id : 'xray status';
        $result = (new Backend())->configdRun($cmd);
        $decoded = json_decode($result, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : ['status' => 'error', 'message' => trim($result)];
    }

    public function statusAllAction()
    {
        $result = (new Backend())->configdRun('xray statusall');
        $decoded = json_decode($result, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : ['error' => trim($result)];
    }

    public function logAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        return ['log' => (new Backend())->configdRun('xray log')];
    }

    public function watchdoglogAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        return ['log' => (new Backend())->configdRun('xray watchdoglog')];
    }

    public function xraylogAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $id = $this->uuid((string)$uuid);
        if ($id === null || $id === '') {
            return $this->invalidUuid();
        }
        return ['log' => (new Backend())->configdRun('xray xraylog ' . $id)];
    }

    public function validateAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $id = $this->uuid((string)$uuid);
        if ($id === null) {
            return $this->invalidUuid();
        }
        $cmd = $id !== '' ? 'xray validate_instance ' . $id : 'xray validate';
        $output = trim((new Backend())->configdRun($cmd));
        $ok = $output !== '' && stripos($output, 'ERROR:') === false && preg_match('/(^|\n)OK(?:\s|\[|$)/', $output);
        return ['result' => $ok ? 'ok' : 'failed', 'message' => $output ?: 'No response from configd'];
    }

    public function versionAction()
    {
        $result = (new Backend())->configdRun('xray version');
        $decoded = json_decode($result, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : ['version' => 'unknown', 'xray' => 'unknown'];
    }

    public function diagnosticsAction($uuid = '')
    {
        $id = $this->uuid((string)$uuid);
        if ($id === null || $id === '') {
            return ['error' => 'Valid instance UUID required'];
        }
        $output = trim((new Backend())->configdRun('xray ifstats ' . $id));
        if ($output === '') {
            return ['error' => 'No response from configd'];
        }
        $data = json_decode($output, true);
        return json_last_error() === JSON_ERROR_NONE ? $data : ['error' => 'Invalid JSON from diagnostics'];
    }

    private function prometheusEscapeLabel(string $value): string
    {
        return str_replace(
            ["\\", "\n", "\""],
            ["\\\\", "\\n", "\\\""],
            $value
        );
    }

    private function prometheusLabels(array $labels): string
    {
        if (empty($labels)) {
            return '';
        }
        $parts = [];
        foreach ($labels as $key => $value) {
            $parts[] = $key . '="' . $this->prometheusEscapeLabel((string)$value) . '"';
        }
        return '{' . implode(',', $parts) . '}';
    }

    private function prometheusSample(string $name, $value, array $labels = []): string
    {
        if (is_float($value)) {
            $number = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
            if ($number === '') {
                $number = '0';
            }
        } else {
            $number = (string)(int)$value;
        }
        return $name . $this->prometheusLabels($labels) . ' ' . $number;
    }

    private function prometheusHealthState(
        array $health,
        bool $probeEnabled,
        bool $manualStopped
    ): string {
        if (!$probeEnabled) {
            return 'disabled';
        }
        if ($manualStopped || (string)($health['status'] ?? '') === 'stopped') {
            return 'stopped';
        }

        $checkedAt = (int)($health['checked_at'] ?? 0);
        if ($checkedAt <= 0 || (string)($health['status'] ?? '') === 'waiting') {
            return 'waiting';
        }
        if ((time() - $checkedAt) > 150) {
            return 'stale';
        }
        return !empty($health['online']) ? 'online' : 'offline';
    }

    /**
     * GET /api/xray/service/metrics
     *
     * Prometheus text exposition built only from runtime status and the
     * existing watchdog health cache. Scraping does not run testconnect or
     * any other active network probe and does not mutate service state.
     */
    public function metricsAction()
    {
        if (!$this->request->isGet()) {
            $this->response->setStatusCode(405, 'Method Not Allowed');
            $this->response->setHeader('Allow', 'GET');
            return "GET required\n";
        }

        $this->response->setHeader(
            'Content-Type',
            'text/plain; version=0.0.4; charset=utf-8'
        );
        $this->response->setHeader('Cache-Control', 'no-store');

        $config = \OPNsense\Core\Config::getInstance()->object();
        $general = $config->OPNsense->xray->general ?? null;
        $serviceEnabled = (string)($general->enabled ?? '0') === '1';
        $watchdogEnabled = (string)($general->watchdog_enabled ?? '0') === '1';

        $statusRaw = trim((string)(new Backend())->configdRun('xray statusall'));
        $statusAll = json_decode($statusRaw, true);
        $runtimeInventoryOk = is_array($statusAll);
        if (!$runtimeInventoryOk) {
            $statusAll = [];
        }

        $pluginVersion = trim((string)@file_get_contents(
            '/usr/local/opnsense/mvc/app/models/OPNsense/Xray/version.txt'
        ));
        if ($pluginVersion === '') {
            $pluginVersion = 'unknown';
        }

        $lines = [
            '# HELP opnsense_xray_plugin_info Plugin build information.',
            '# TYPE opnsense_xray_plugin_info gauge',
            $this->prometheusSample(
                'opnsense_xray_plugin_info',
                1,
                ['version' => $pluginVersion]
            ),
            '# HELP opnsense_xray_runtime_inventory_ok Whether the runtime inventory could be read successfully.',
            '# TYPE opnsense_xray_runtime_inventory_ok gauge',
            $this->prometheusSample('opnsense_xray_runtime_inventory_ok', $runtimeInventoryOk ? 1 : 0),
            '# HELP opnsense_xray_service_enabled Whether Xray is enabled in configuration.',
            '# TYPE opnsense_xray_service_enabled gauge',
            $this->prometheusSample('opnsense_xray_service_enabled', $serviceEnabled ? 1 : 0),
            '# HELP opnsense_xray_watchdog_enabled Whether automatic Xray client recovery is enabled.',
            '# TYPE opnsense_xray_watchdog_enabled gauge',
            $this->prometheusSample('opnsense_xray_watchdog_enabled', $watchdogEnabled ? 1 : 0),
            '# HELP opnsense_xray_client_enabled Whether the client is enabled in configuration.',
            '# TYPE opnsense_xray_client_enabled gauge',
            '# HELP opnsense_xray_client_effective_enabled Whether global and per-client configuration currently enable the client.',
            '# TYPE opnsense_xray_client_effective_enabled gauge',
            '# HELP opnsense_xray_client_up Whether Xray, SOCKS5, HEV and TUN runtime are all ready.',
            '# TYPE opnsense_xray_client_up gauge',
            '# HELP opnsense_xray_client_manual_stopped Whether the client was manually stopped.',
            '# TYPE opnsense_xray_client_manual_stopped gauge',
            '# HELP opnsense_xray_core_up Whether the Xray core process is running.',
            '# TYPE opnsense_xray_core_up gauge',
            '# HELP opnsense_xray_socks5_ready Whether the local Xray SOCKS5 listener is ready.',
            '# TYPE opnsense_xray_socks5_ready gauge',
            '# HELP opnsense_xray_hev_up Whether the HEV process is running.',
            '# TYPE opnsense_xray_hev_up gauge',
            '# HELP opnsense_xray_tun_up Whether the configured TUN interface is running.',
            '# TYPE opnsense_xray_tun_up gauge',
            '# HELP opnsense_xray_health_probe_enabled Whether the scheduled end-to-end health probe is active.',
            '# TYPE opnsense_xray_health_probe_enabled gauge',
            '# HELP opnsense_xray_gateway_health_sync_enabled Whether native gateway health synchronization is enabled.',
            '# TYPE opnsense_xray_gateway_health_sync_enabled gauge',
            '# HELP opnsense_xray_health_online Whether the cached end-to-end health result is fresh and online.',
            '# TYPE opnsense_xray_health_online gauge',
            '# HELP opnsense_xray_health_state Current cached health state represented by a single labeled sample.',
            '# TYPE opnsense_xray_health_state gauge',
            '# HELP opnsense_xray_health_latency_seconds Last end-to-end health probe latency in seconds.',
            '# TYPE opnsense_xray_health_latency_seconds gauge',
            '# HELP opnsense_xray_health_consecutive_failures Consecutive end-to-end health probe failures.',
            '# TYPE opnsense_xray_health_consecutive_failures gauge',
            '# HELP opnsense_xray_health_last_check_timestamp_seconds Unix timestamp of the last health check.',
            '# TYPE opnsense_xray_health_last_check_timestamp_seconds gauge',
            '# HELP opnsense_xray_health_last_ok_timestamp_seconds Unix timestamp of the last successful health check.',
            '# TYPE opnsense_xray_health_last_ok_timestamp_seconds gauge',
            '# HELP opnsense_xray_health_last_failure_timestamp_seconds Unix timestamp of the last failed health check.',
            '# TYPE opnsense_xray_health_last_failure_timestamp_seconds gauge',
            '# HELP opnsense_xray_health_last_restart_timestamp_seconds Unix timestamp of the last watchdog restart.',
            '# TYPE opnsense_xray_health_last_restart_timestamp_seconds gauge',
            '# HELP opnsense_xray_health_age_seconds Age of the cached health result in seconds.',
            '# TYPE opnsense_xray_health_age_seconds gauge',
            '# HELP opnsense_xray_health_http_status Last HTTP status observed by the health probe.',
            '# TYPE opnsense_xray_health_http_status gauge',
            '# HELP opnsense_xray_health_stream_bytes Bytes received by the last stream integrity probe.',
            '# TYPE opnsense_xray_health_stream_bytes gauge',
            '# HELP opnsense_xray_health_stream_expected_bytes Expected bytes for the stream integrity probe.',
            '# TYPE opnsense_xray_health_stream_expected_bytes gauge',
        ];

        foreach (($config->OPNsense->xray->instances->instance ?? []) as $inst) {
            $uuid = (string)$inst['uuid'];
            if (!preg_match('/^[0-9a-fA-F]{8}(-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}$/D', $uuid)) {
                continue;
            }

            $name = trim((string)($inst->name ?? ''));
            if ($name === '') {
                $name = 'xray';
            }
            $tun = trim((string)($inst->tun_interface ?? ''));
            if ($tun === '') {
                $tun = 'unassigned';
            }
            $labels = ['name' => $name, 'interface' => $tun];

            $enabled = (string)($inst->enabled ?? '1') === '1';
            $effectiveEnabled = $serviceEnabled && $enabled;
            $runtime = isset($statusAll[$uuid]) && is_array($statusAll[$uuid])
                ? $statusAll[$uuid]
                : [];

            if (array_key_exists('effective_enabled', $runtime)) {
                $effectiveEnabled = $runtime['effective_enabled'] === true
                    || $runtime['effective_enabled'] === 1
                    || $runtime['effective_enabled'] === '1';
            }

            $manualStopped = !empty($runtime['manual_stopped'])
                || is_file('/var/run/xray-stopped-' . $uuid . '.flag');
            $coreUp = (string)($runtime['xray_core'] ?? '') === 'running';
            $socksReady = (string)($runtime['socks5'] ?? '') === 'ready';
            $hevUp = (string)($runtime['hev'] ?? '') === 'running';
            $tunUp = (string)($runtime['tun'] ?? '') === 'running';
            $clientUp = $coreUp && $socksReady && $hevUp && $tunUp;

            $healthPath = '/var/run/xray-health-' . $uuid . '.json';
            $health = is_file($healthPath)
                ? json_decode((string)@file_get_contents($healthPath), true)
                : [];
            if (!is_array($health)) {
                $health = [];
            }

            $probeEnabled = $effectiveEnabled && !$manualStopped;
            $state = $this->prometheusHealthState($health, $probeEnabled, $manualStopped);
            $checkedAt = (int)($health['checked_at'] ?? 0);
            $healthAge = $checkedAt > 0 ? max(0, time() - $checkedAt) : 0;

            $gatewaySync = (string)($inst->gateway_health_sync ?? '0') === '1';

            $lines[] = $this->prometheusSample('opnsense_xray_client_enabled', $enabled ? 1 : 0, $labels);
            $lines[] = $this->prometheusSample('opnsense_xray_client_effective_enabled', $effectiveEnabled ? 1 : 0, $labels);
            $lines[] = $this->prometheusSample('opnsense_xray_client_up', $clientUp ? 1 : 0, $labels);
            $lines[] = $this->prometheusSample('opnsense_xray_client_manual_stopped', $manualStopped ? 1 : 0, $labels);
            $lines[] = $this->prometheusSample('opnsense_xray_core_up', $coreUp ? 1 : 0, $labels);
            $lines[] = $this->prometheusSample('opnsense_xray_socks5_ready', $socksReady ? 1 : 0, $labels);
            $lines[] = $this->prometheusSample('opnsense_xray_hev_up', $hevUp ? 1 : 0, $labels);
            $lines[] = $this->prometheusSample('opnsense_xray_tun_up', $tunUp ? 1 : 0, $labels);
            $lines[] = $this->prometheusSample('opnsense_xray_health_probe_enabled', $probeEnabled ? 1 : 0, $labels);
            $lines[] = $this->prometheusSample('opnsense_xray_gateway_health_sync_enabled', $gatewaySync ? 1 : 0, $labels);
            $lines[] = $this->prometheusSample('opnsense_xray_health_online', $state === 'online' ? 1 : 0, $labels);
            $lines[] = $this->prometheusSample('opnsense_xray_health_state', 1, $labels + ['state' => $state]);

            if (isset($health['latency_ms']) && is_numeric($health['latency_ms'])) {
                $lines[] = $this->prometheusSample(
                    'opnsense_xray_health_latency_seconds',
                    ((float)$health['latency_ms']) / 1000,
                    $labels
                );
            }
            $lines[] = $this->prometheusSample(
                'opnsense_xray_health_consecutive_failures',
                (int)($health['consecutive_failures'] ?? 0),
                $labels
            );
            $lines[] = $this->prometheusSample('opnsense_xray_health_last_check_timestamp_seconds', $checkedAt, $labels);
            $lines[] = $this->prometheusSample(
                'opnsense_xray_health_last_ok_timestamp_seconds',
                (int)($health['last_ok'] ?? 0),
                $labels
            );
            $lines[] = $this->prometheusSample(
                'opnsense_xray_health_last_failure_timestamp_seconds',
                (int)($health['last_failure'] ?? 0),
                $labels
            );
            $lines[] = $this->prometheusSample(
                'opnsense_xray_health_last_restart_timestamp_seconds',
                (int)($health['last_restart'] ?? 0),
                $labels
            );
            $lines[] = $this->prometheusSample('opnsense_xray_health_age_seconds', $healthAge, $labels);

            if (isset($health['http_status']) && is_numeric($health['http_status'])) {
                $lines[] = $this->prometheusSample(
                    'opnsense_xray_health_http_status',
                    (int)$health['http_status'],
                    $labels
                );
            }
            if (isset($health['stream_bytes']) && is_numeric($health['stream_bytes'])) {
                $lines[] = $this->prometheusSample(
                    'opnsense_xray_health_stream_bytes',
                    (int)$health['stream_bytes'],
                    $labels
                );
            }
            if (isset($health['stream_expected_bytes']) && is_numeric($health['stream_expected_bytes'])) {
                $lines[] = $this->prometheusSample(
                    'opnsense_xray_health_stream_expected_bytes',
                    (int)$health['stream_expected_bytes'],
                    $labels
                );
            }
        }

        return implode("\n", $lines) . "\n";
    }

    public function testconnectAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $id = $this->uuid((string)$uuid);
        if ($id === null || $id === '') {
            return $this->invalidUuid();
        }
        $output = trim((new Backend())->configdRun('xray testconnect ' . $id));
        $data = json_decode($output, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return ['result' => 'failed', 'message' => $output ?: 'No response from configd'];
        }
        return $data;
    }
}
