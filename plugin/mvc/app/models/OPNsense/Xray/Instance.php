<?php

namespace OPNsense\Xray;

use OPNsense\Base\BaseModel;
use OPNsense\Base\Messages\Message;

class Instance extends BaseModel
{
    private function parseCidr(string $cidr): ?array
    {
        $parts = explode('/', trim($cidr), 2);
        if (count($parts) !== 2 || filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
        if (!ctype_digit($parts[1]) || (int)$parts[1] < 0 || (int)$parts[1] > 32) {
            return null;
        }
        $prefix = (int)$parts[1];
        $ip = ip2long($parts[0]);
        if ($ip === false) {
            return null;
        }
        $mask = $prefix === 0 ? 0 : ((0xffffffff << (32 - $prefix)) & 0xffffffff);
        $network = $ip & $mask;
        $broadcast = $network | (~$mask & 0xffffffff);
        return [$network, $broadcast, $prefix];
    }

    public function performValidation($validateFullModel = false)
    {
        $messages = parent::performValidation($validateFullModel);
        $seenIfaces = [];
        $seenSocks = [];
        $networks = [];

        foreach ($this->instance->iterateItems() as $node) {
            // tunN is a shared FreeBSD namespace and xray_devices() registers
            // configured names even for disabled instances. Keep ownership and
            // transit subnets unique across the entire model, not only active
            // rows.
            $key = $node->__reference;
            $iface = (string)$node->tun_interface;
            $addr = (string)$node->tun_address;
            $socksListen = (string)$node->socks5_listen;
            $socksPort = (string)$node->socks5_port;

            if ($iface !== '') {
                if (isset($seenIfaces[$iface])) {
                    $messages->appendMessage(new Message(
                        sprintf(gettext('TUN interface %s is already used by another Xray instance.'), $iface),
                        $key . '.tun_interface'
                    ));
                } else {
                    $seenIfaces[$iface] = $key;
                }
            }

            if ($socksListen !== '' && $socksPort !== '') {
                $socket = $socksListen . ':' . $socksPort;
                if (isset($seenSocks[$socket])) {
                    $messages->appendMessage(new Message(
                        sprintf(gettext('SOCKS5 listener %s is already used by another Xray instance.'), $socket),
                        $key . '.socks5_port'
                    ));
                } else {
                    $seenSocks[$socket] = $key;
                }
            }

            $parsed = $this->parseCidr($addr);
            if ($parsed !== null) {
                foreach ($networks as $other) {
                    if ($parsed[0] <= $other['end'] && $other['start'] <= $parsed[1]) {
                        $messages->appendMessage(new Message(
                            sprintf(gettext('TUN network %s overlaps another Xray TUN network (%s).'), $addr, $other['cidr']),
                            $key . '.tun_address'
                        ));
                        break;
                    }
                }
                $networks[] = ['start' => $parsed[0], 'end' => $parsed[1], 'cidr' => $addr];
            }

            $server = trim((string)$node->server);
            if ($server === '' || strlen($server) > 253 || preg_match('/[\x00-\x20\x7f]/', $server)) {
                $messages->appendMessage(new Message(gettext('Server must be a valid hostname or IP address.'), $key . '.server'));
            }
            $flow = (string)$node->vless_flow;
            if (!in_array($flow, ['', 'xtls-rprx-vision', 'xtls-rprx-vision-udp443'], true)) {
                $messages->appendMessage(new Message(gettext('Unsupported VLESS flow.'), $key . '.vless_flow'));
            }

            $transport = (string)$node->transport;
            if (!in_array($transport, ['raw', 'xhttp', 'grpc'], true)) {
                $messages->appendMessage(new Message(gettext('Unsupported transport.'), $key . '.transport'));
            }

            if ($transport === 'xhttp') {
                $path = (string)$node->xhttp_path;
                if ($path === '' || $path[0] !== '/') {
                    $messages->appendMessage(new Message(gettext('XHTTP path must start with /.'), $key . '.xhttp_path'));
                }

                $host = trim((string)$node->xhttp_host);
                if ($host !== '' && (strlen($host) > 253 || preg_match('/[\x00-\x20\x7f\/\?\#@]/', $host))) {
                    $messages->appendMessage(new Message(gettext('XHTTP host contains invalid characters.'), $key . '.xhttp_host'));
                }

                $mode = (string)$node->xhttp_mode;
                $uplinkMethod = strtoupper(trim((string)$node->xhttp_uplink_method));
                if ($uplinkMethod === 'GET' && $mode !== 'packet-up') {
                    $messages->appendMessage(new Message(
                        gettext('XHTTP uplink GET is valid only in packet-up mode.'),
                        $key . '.xhttp_uplink_method'
                    ));
                }

                if ((string)$node->xhttp_padding_enabled === '1') {
                    $range = trim((string)$node->xhttp_padding_bytes);
                    if (preg_match('/^([1-9][0-9]{0,7})(?:-([1-9][0-9]{0,7}))?$/D', $range, $m)) {
                        $from = (int)$m[1];
                        $to = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : $from;
                        if ($from > $to) {
                            $messages->appendMessage(new Message(
                                gettext('XHTTP padding range start must not exceed its end.'),
                                $key . '.xhttp_padding_bytes'
                            ));
                        }
                    }
                }
            }

            $spiderX = trim((string)$node->reality_spider_x);
            if ($spiderX !== '' && ($spiderX[0] !== '/' || strlen($spiderX) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $spiderX))) {
                $messages->appendMessage(new Message(gettext('REALITY SpiderX must be an absolute path without control characters.'), $key . '.reality_spider_x'));
            }
        }
        return $messages;
    }
}
