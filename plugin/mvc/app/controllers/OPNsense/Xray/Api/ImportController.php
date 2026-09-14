<?php

namespace OPNsense\Xray\Api;

use OPNsense\Base\ApiControllerBase;

class ImportController extends ApiControllerBase
{
    public function parseAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'error', 'message' => 'POST required'];
        }

        $requestData = $this->extractRequestData();
        $link = $requestData['link'];

        if (empty($link)) {
            return ['status' => 'error', 'message' => 'No VLESS link provided'];
        }

        if (strlen($link) > 2048) {
            return ['status' => 'error', 'message' => 'Link too long'];
        }

        $data = $this->parseVless($link);
        if (isset($data['error'])) {
            return ['status' => 'error', 'message' => $data['error']];
        }

        $data['status'] = 'ok';
        return $data;
    }

    /**
     * Extracts the link from request body (JSON with link_b64, or plain POST).
     * @return array{link: string}
     */
    private function extractRequestData(): array
    {
        $result = ['link' => ''];

        // JSON body ($.ajax contentType: application/json)
        $rawBody = file_get_contents('php://input');
        if (!empty($rawBody)) {
            $json = json_decode($rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if (!empty($json['link_b64'])) {
                    $decoded = base64_decode($json['link_b64'], true);
                    if ($decoded !== false) {
                        $result['link'] = trim($decoded);
                    }
                } elseif (!empty($json['link'])) {
                    $result['link'] = trim($json['link']);
                }
                return $result;
            }
        }

        // POST param link_b64 (base64-safe, & in link is not a problem)
        $b64 = $this->request->getPost('link_b64', 'string', '');
        if (!empty($b64)) {
            $decoded = base64_decode($b64, true);
            if ($decoded !== false) {
                $result['link'] = trim($decoded);
            }
        }

        // Plain POST link
        if (empty($result['link'])) {
            $link = $this->request->getPost('link', 'string', '');
            if (!empty($link)) {
                $result['link'] = trim($link);
            }
        }

        return $result;
    }

    private function parseVless(string $link): array
    {
        $link = trim($link, " \t\n\r\0\x0B\"'");
        if (strpos($link, 'vless://') !== 0) {
            return ['error' => 'Link must start with vless://'];
        }

        $rest = substr($link, 8);
        $name = '';
        if (($hashPos = strrpos($rest, '#')) !== false) {
            $name = rawurldecode(substr($rest, $hashPos + 1));
            $name = preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?? '';
            if (strlen($name) > 128) {
                $name = substr($name, 0, 128);
            }
            $rest = substr($rest, 0, $hashPos);
        }

        $query = '';
        if (($qPos = strpos($rest, '?')) !== false) {
            $query = substr($rest, $qPos + 1);
            $rest = substr($rest, 0, $qPos);
        }

        $atPos = strrpos($rest, '@');
        if ($atPos === false) {
            return ['error' => 'Missing @ separator between UUID and host'];
        }
        $uuid = substr($rest, 0, $atPos);
        $hostport = substr($rest, $atPos + 1);

        if (substr($hostport, 0, 1) === '[') {
            $closeBracket = strpos($hostport, ']');
            if ($closeBracket === false) {
                return ['error' => 'Invalid IPv6 address format'];
            }
            $host = substr($hostport, 1, $closeBracket - 1);
            $afterBracket = substr($hostport, $closeBracket + 1);
            if ($afterBracket === '' || $afterBracket[0] !== ':') {
                return ['error' => 'Missing port after IPv6 address'];
            }
            $portStr = substr($afterBracket, 1);
        } else {
            $lastColon = strrpos($hostport, ':');
            if ($lastColon === false) {
                return ['error' => 'Missing port in host:port'];
            }
            $host = substr($hostport, 0, $lastColon);
            $portStr = substr($hostport, $lastColon + 1);
        }

        if ($portStr === '' || !ctype_digit($portStr)) {
            return ['error' => 'Invalid port: ' . $portStr];
        }
        $port = (int)$portStr;
        if ($port < 1 || $port > 65535) {
            return ['error' => 'Invalid port: ' . $portStr];
        }
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/', $uuid)) {
            return ['error' => 'Invalid UUID format (expected xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx)'];
        }
        if ($host === '' || strlen($host) > 253 || preg_match('/[\x00-\x20\x7f\/\?\#@]/', $host)) {
            return ['error' => 'Invalid host'];
        }
        if (filter_var($host, FILTER_VALIDATE_IP) === false
            && !preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/D', $host)) {
            return ['error' => 'Invalid host'];
        }

        parse_str($query, $params);
        $type = strtolower((string)($params['type'] ?? 'tcp'));
        if (!in_array($type, ['tcp', 'raw', 'xhttp', 'grpc'], true)) {
            return ['error' => 'Unsupported VLESS transport type: ' . $type];
        }
        $transport = $type === 'tcp' ? 'raw' : $type;

        $security = strtolower((string)($params['security'] ?? 'none'));
        if ($security !== 'reality') {
            return ['error' => 'This plugin supports REALITY security for imported VLESS clients.'];
        }

        $encryption = (string)($params['encryption'] ?? 'none');
        if ($encryption === '') {
            $encryption = 'none';
        }
        if ($encryption !== 'none') {
            return ['error' => 'This plugin currently supports VLESS encryption=none only.'];
        }

        $flow = (string)($params['flow'] ?? '');
        if (!in_array($flow, ['', 'xtls-rprx-vision', 'xtls-rprx-vision-udp443'], true)) {
            return ['error' => 'Unsupported VLESS flow: ' . $flow];
        }

        $fp = (string)($params['fp'] ?? '');
        $allowedFp = ['', 'chrome', 'firefox', 'safari', 'ios', 'android', 'edge', 'qq', 'random', 'randomized'];
        if (!in_array($fp, $allowedFp, true)) {
            return ['error' => 'Unsupported TLS fingerprint: ' . $fp];
        }
        if (empty($params['pbk'])) {
            return ['error' => 'REALITY link is missing public key (pbk)'];
        }
        if (!preg_match('/^[A-Za-z0-9_-]{43,44}$/D', (string)$params['pbk'])) {
            return ['error' => 'REALITY public key (pbk) has an invalid format'];
        }
        if (empty($params['sni'])) {
            return ['error' => 'REALITY link is missing server name (sni)'];
        }
        if ($fp === '') {
            return ['error' => 'REALITY link is missing TLS fingerprint (fp)'];
        }

        $shortId = (string)($params['sid'] ?? '');
        if ($shortId !== '' && !preg_match('/^(?:[0-9a-fA-F]{2}){1,8}$/D', $shortId)) {
            return ['error' => 'REALITY short ID must contain an even number of hexadecimal characters (2-16).'];
        }
        $spiderX = (string)($params['spx'] ?? '');
        if ($spiderX !== '' && ($spiderX[0] !== '/' || strlen($spiderX) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $spiderX))) {
            return ['error' => 'REALITY spiderX must be an absolute path without control characters.'];
        }

        $result = [
            'name' => $name,
            'server' => $host,
            'port' => $port,
            'vless_uuid' => $uuid,
            'vless_flow' => $flow,
            'transport' => $transport,
            'security' => 'reality',
            'reality_sni' => (string)$params['sni'],
            'reality_public_key' => (string)$params['pbk'],
            'reality_short_id' => $shortId,
            'reality_spider_x' => $spiderX,
            'fingerprint' => $fp,
        ];

        if ($transport === 'raw') {
            $headerType = strtolower((string)($params['headerType'] ?? 'none'));
            if ($headerType !== '' && $headerType !== 'none') {
                return ['error' => 'RAW import currently supports headerType=none only.'];
            }
        } elseif ($transport === 'xhttp') {
            $mode = (string)($params['mode'] ?? 'auto');
            if (!in_array($mode, ['auto', 'stream-one', 'stream-up', 'packet-up'], true)) {
                return ['error' => 'Unsupported XHTTP mode: ' . $mode];
            }
            $path = (string)($params['path'] ?? '/');
            if ($path === '' || $path[0] !== '/') {
                return ['error' => 'XHTTP path must start with /.'];
            }
            $xhttpHost = trim((string)($params['host'] ?? ''));
            if ($xhttpHost !== '' && (strlen($xhttpHost) > 253 || preg_match('/[\x00-\x20\x7f\/\?\#@]/', $xhttpHost))) {
                return ['error' => 'Invalid XHTTP host'];
            }
            $result['xhttp_mode'] = $mode;
            $result['xhttp_path'] = $path;
            $result['xhttp_host'] = $xhttpHost;
        } elseif ($transport === 'grpc') {
            $grpcMode = (string)($params['mode'] ?? '');
            if ($grpcMode !== '' && $grpcMode !== 'multi') {
                return ['error' => 'Unsupported gRPC mode: ' . $grpcMode];
            }
            $result['grpc_service_name'] = (string)($params['serviceName'] ?? '');
            $result['grpc_authority'] = (string)($params['authority'] ?? '');
            $result['grpc_multi_mode'] = $grpcMode === 'multi' ? '1' : '0';
        }

        return $result;
    }
}
