<?php
namespace DumboChromeDriver\Testing\Tests;

use DumboChromeDriver\DevToolsSession;
use function DumboChromeDriver\Testing\{assertEquals, assertTrue, assertFalse};

return function (\DumboChromeDriver\Testing\TestRunner $runner) {

    $runner->register('encodeFrame: frame corto (< 126 bytes)', function () {
        $session = new DevToolsSession('ws://localhost:9222/x');
        $frame = $session->encodeFrame('hola');

        // Byte 0: FIN + opcode texto = 0x81
        assertEquals(0x81, ord($frame[0]));

        // Byte 1: máscara activa (0x80) + longitud (4)
        assertEquals(0x84, ord($frame[1]));

        // Total: 2 bytes header + 4 bytes máscara + 4 bytes payload
        assertEquals(10, strlen($frame));
    });

    $runner->register('encodeFrame: longitud exacta 125 usa 1 byte', function () {
        $session = new DevToolsSession('ws://localhost:9222/x');
        $data = str_repeat('a', 125);
        $frame = $session->encodeFrame($data);

        assertEquals(0xFD, ord($frame[1])); // 125 | 0x80
        // 2 header + 4 mask + 125 payload
        assertEquals(131, strlen($frame));
    });

    $runner->register('encodeFrame: longitud 126+ usa extensión de 2 bytes', function () {
        $session = new DevToolsSession('ws://localhost:9222/x');
        $data = str_repeat('a', 200);
        $frame = $session->encodeFrame($data);

        assertEquals(0xFE, ord($frame[1])); // 126 | 0x80
        // 2 header + 2 ext-length + 4 mask + 200 payload
        assertEquals(208, strlen($frame));
    });

    $runner->register('encodeFrame: el payload queda enmascarado (XOR)', function () {
        $session = new DevToolsSession('ws://localhost:9222/x');
        $frame = $session->encodeFrame('A'); // 0x41

        $mask = substr($frame, 2, 4);
        $maskedByte = ord($frame[6]);
        $expectedByte = ord('A') ^ ord($mask[0]);

        assertEquals($expectedByte, $maskedByte);
    });

    $runner->register('decodeFrameHeader: longitud corta se lee directo', function () {
        $session = new DevToolsSession('ws://localhost:9222/x');
        // FIN+texto, sin máscara (servidor), longitud 10
        $header = chr(0x81) . chr(10);
        $result = $session->decodeFrameHeader($header);

        assertEquals(10, $result['length']);
        assertFalse($result['extended']);
    });

    $runner->register('decodeFrameHeader: longitud 126 marca extended', function () {
        $session = new DevToolsSession('ws://localhost:9222/x');
        $header = chr(0x81) . chr(126);
        $result = $session->decodeFrameHeader($header);

        assertTrue($result['extended']);
    });

};
