<?php

namespace OPNsense\Base {
    class ApiControllerBase
    {
    }
}

namespace {
    require dirname(__DIR__) . '/plugin/mvc/app/controllers/OPNsense/Xray/Api/ImportController.php';

    $controller = new \OPNsense\Xray\Api\ImportController();
    $parser = new \ReflectionMethod($controller, 'parseVless');
    $parser->setAccessible(true);

    $publicKey = str_repeat('A', 43);
    $uuid = '11111111-2222-3333-4444-555555555555';
    $base = "vless://{$uuid}@example.com:443?encryption=none&security=reality&sni=example.com&fp=chrome&pbk={$publicKey}";

    $tests = [
        'RAW Vision flow is persisted' => [
            $base . '&sid=0123456789abcdef&type=raw&flow=xtls-rprx-vision#Test',
            static fn(array $r): bool => !isset($r['error'])
                && ($r['transport'] ?? '') === 'raw'
                && ($r['vless_flow'] ?? '') === 'xtls-rprx-vision',
        ],
        'XHTTP host and SpiderX are persisted' => [
            $base . '&type=xhttp&mode=stream-up&path=%2Fapi&host=cdn.example.com&spx=%2Findex.html',
            static fn(array $r): bool => !isset($r['error'])
                && ($r['xhttp_host'] ?? '') === 'cdn.example.com'
                && ($r['xhttp_path'] ?? '') === '/api'
                && ($r['reality_spider_x'] ?? '') === '/index.html',
        ],
        'WebSocket is rejected' => [
            $base . '&type=ws',
            static fn(array $r): bool => isset($r['error'])
                && str_contains($r['error'], 'Unsupported VLESS transport'),
        ],
        'Unknown VLESS flow is rejected' => [
            $base . '&type=raw&flow=bogus',
            static fn(array $r): bool => isset($r['error'])
                && str_contains($r['error'], 'Unsupported VLESS flow'),
        ],
        'Malformed XHTTP host is rejected' => [
            $base . '&type=xhttp&host=cdn.example.com%2Fbad',
            static fn(array $r): bool => isset($r['error'])
                && str_contains($r['error'], 'Invalid XHTTP host'),
        ],
    ];

    foreach ($tests as $name => [$link, $assert]) {
        $result = $parser->invoke($controller, $link);
        if (!$assert($result)) {
            fwrite(STDERR, "FAIL: {$name}\n" . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            exit(1);
        }
        echo "PASS: {$name}\n";
    }
}
