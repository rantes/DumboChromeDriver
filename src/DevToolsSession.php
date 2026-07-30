<?php
namespace DumboChromeDriver;

class DevToolsSession {
    private $_socket = null;
    private int $_messageId = 0;
    private string $_host;
    private int $_port;
    private string $_path;

    public function __construct(string $webSocketUrl) {
        $parsed = parse_url($webSocketUrl);
        $this->_host = $parsed['host'] ?? '127.0.0.1';
        $this->_port = $parsed['port'] ?? 9222;
        $this->_path = $parsed['path'] ?? '/';
    }

    public function connect(): void {
        $this->_socket = socket_create(
            AF_INET, SOCK_STREAM, SOL_TCP
        );

        if ($this->_socket === false):
            throw new DevToolsException(
                'No se pudo crear el socket: ' .
                socket_strerror(socket_last_error())
            );
        endif;

        socket_set_option($this->_socket, SOL_SOCKET,
            SO_RCVTIMEO, ['sec' => 10, 'usec' => 0]);
        socket_set_option($this->_socket, SOL_SOCKET,
            SO_SNDTIMEO, ['sec' => 10, 'usec' => 0]);

        $connected = @socket_connect(
            $this->_socket, $this->_host, $this->_port
        );

        if ($connected === false):
            throw new DevToolsException(
                'No se pudo conectar: ' .
                socket_strerror(socket_last_error($this->_socket))
            );
        endif;

        $this->_performHandshake();
    }

    /**
     * Handshake WebSocket estándar (RFC 6455).
     */
    private function _performHandshake(): void {
        $key = base64_encode(random_bytes(16));

        $request = "GET {$this->_path} HTTP/1.1\r\n"
            . "Host: {$this->_host}:{$this->_port}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n";

        socket_write($this->_socket, $request, strlen($request));

        $response = socket_read($this->_socket, 2048);

        if (strpos($response, '101') === false):
            throw new DevToolsException(
                'Handshake WebSocket falló: ' . $response
            );
        endif;
    }

    /**
     * Envía un mensaje JSON-RPC del protocolo DevTools
     * y espera la respuesta correspondiente por id.
     */
    public function send(string $method, array $params = []): array {
        $this->_messageId++;
        $id = $this->_messageId;

        $payload = json_encode([
            'id'     => $id,
            'method' => $method,
            'params' => $params,
        ]);

        $this->_writeFrame($payload);

        return $this->_readMatchingResponse($id);
    }

    /**
     * Codifica y envía un frame WebSocket de texto
     * (RFC 6455 — cliente debe enmascarar el payload).
     */
    private function _writeFrame(string $data): void {
        $frame = $this->encodeFrame($data);
        socket_write($this->_socket, $frame, strlen($frame));
    }

    /**
     * Codifica un frame WebSocket de texto — pura, sin I/O.
     * Extraída de _writeFrame() para poder probarla sin
     * socket real; _writeFrame() es un wrapper delgado que
     * llama a esta función y escribe el resultado.
     */
    public function encodeFrame(string $data): string {
        $length = strlen($data);
        $mask   = random_bytes(4);
        $frame  = chr(0x81); // FIN + opcode texto

        if ($length <= 125):
            $frame .= chr($length | 0x80);
        elseif ($length <= 65535):
            $frame .= chr(126 | 0x80) . pack('n', $length);
        else:
            $frame .= chr(127 | 0x80) . pack('J', $length);
        endif;

        $frame .= $mask;

        for ($i = 0; $i < $length; $i++):
            $frame .= $data[$i] ^ $mask[$i % 4];
        endfor;

        return $frame;
    }

    /**
     * Lee frames WebSocket hasta encontrar la respuesta
     * con el id correspondiente al mensaje enviado.
     * Timeout implícito via SO_RCVTIMEO del socket.
     */
    private function _readMatchingResponse(int $id): array {
        $maxFrames = 100; // límite defensivo
        $attempt   = 0;

        while ($attempt < $maxFrames):
            $frame = $this->_readFrame();
            $decoded = json_decode($frame, true);

            if (is_array($decoded)
                && ($decoded['id'] ?? null) === $id):
                return $decoded;
            endif;

            $attempt++;
        endwhile;

        throw new DevToolsException(
            "No se recibió respuesta para el mensaje id={$id} " .
            "después de {$maxFrames} frames."
        );
    }

    /**
     * Decodifica un frame WebSocket del servidor
     * (servidor NO enmascara, cliente sí al enviar).
     */
    private function _readFrame(): string {
        $header = socket_read($this->_socket, 2);

        if ($header === false || strlen($header) < 2):
            throw new DevToolsException(
                'Conexión cerrada o timeout leyendo frame.'
            );
        endif;

        $decoded = $this->decodeFrameHeader($header);
        $length = $decoded['length'];

        if ($decoded['extended']):
            if ($length === 126):
                $ext = socket_read($this->_socket, 2);
                $length = unpack('n', $ext)[1];
            else: // 127
                $ext = socket_read($this->_socket, 8);
                $length = unpack('J', $ext)[1];
            endif;
        endif;

        $payload = '';
        $remaining = $length;
        while ($remaining > 0):
            $chunk = socket_read($this->_socket, $remaining);
            if ($chunk === false || $chunk === ''):
                break;
            endif;
            $payload .= $chunk;
            $remaining -= strlen($chunk);
        endwhile;

        return $payload;
    }

    /**
     * Decodifica el header de un frame WebSocket ya leído
     * (los primeros 2 bytes) — retorna la longitud codificada
     * en los 7 bits bajos del segundo byte y si requiere
     * lectura extendida (126/127 son marcadores, no la
     * longitud real; el llamador debe leer 2 u 8 bytes más
     * del socket y decodificarlos con unpack según cuál de
     * los dos marcadores sea). Pura, sin I/O.
     */
    public function decodeFrameHeader(string $header): array {
        $byte2 = ord($header[1]);
        $length = $byte2 & 0x7F;
        return ['length' => $length, 'extended' => $length >= 126];
    }

    public function close(): void {
        if ($this->_socket !== null):
            socket_close($this->_socket);
            $this->_socket = null;
        endif;
    }

    public function __destruct() {
        $this->close();
    }
}
