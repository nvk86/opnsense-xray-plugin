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
