<?php

function xray_services()
{
    $services = [];
    if ((string)(new OPNsense\Xray\General())->enabled !== '1') {
        return $services;
    }

    foreach ((new OPNsense\Xray\Instance())->instance->iterateItems() as $node) {
        if (empty((string)$node->enabled)) {
            continue;
        }
        $uuid = (string)($node->getAttributes()['uuid'] ?? '');
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $uuid)) {
            continue;
        }
        $name = (string)$node->name ?: $uuid;
        $services[] = [
            'description' => 'Xray (' . $name . ')',
            'configd' => [
                'restart' => ['xray restart_instance ' . $uuid],
                'start' => ['xray start_instance ' . $uuid],
                'stop' => ['xray stop_instance ' . $uuid],
            ],
            'name' => 'xray-' . substr($uuid, 0, 8),
            'pidfile' => '/var/run/xray-hev-' . $uuid . '.pid',
        ];
    }
    return $services;
}

function xray_devices()
{
    // HEV TUN devices are volatile: they do not exist until HEV starts. Register every configured tunN (also disabled instances) so an
    // assigned interface remains owned by this plugin across boot/reconfigure.
    $names = [];
    foreach ((new OPNsense\Xray\Instance())->instance->iterateItems() as $node) {
        $device = (string)$node->tun_interface;
        if (!preg_match('/^tun(?:0|[1-9][0-9]{0,2})$/D', $device)) {
            continue;
        }
        $name = (string)$node->name ?: 'instance';
        $names[$device] = [
            'descr' => sprintf('%s (Xray - %s)', $device, $name),
            'ifdescr' => $name,
            'name' => $device,
        ];
    }

    if (empty($names)) {
        return [];
    }

    // tunN is a generic FreeBSD namespace shared with other software. Never
    // claim every tun(4) device: restrict the provider regex to interfaces
    // explicitly configured in this plugin.
    $quoted = [];
    foreach (array_keys($names) as $device) {
        $quoted[] = preg_quote($device, '/');
    }

    return [[
        'configurable' => false,
        'pattern' => '^(?:' . implode('|', $quoted) . ')$',
        'type' => 'xray',
        'volatile' => true,
        'names' => $names,
    ]];
}

function xray_xmlrpc_sync()
{
    return [];
}

function xray_cron()
{
    return [['autocron' => ['/usr/local/sbin/configctl -d xray watchdog', '*']]];
}
