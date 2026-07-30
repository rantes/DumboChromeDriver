<?php
namespace DumboChromeDriver;

class ChromeProcess {
    private $_process = null;
    private $_pipes = [];
    private string $_binaryPath;
    private int $_debugPort;
    private string $_targetUrl = '';

    public function __construct(
        string $binaryPath, int $debugPort = 9222
    ) {
        $this->_binaryPath = $binaryPath;
        $this->_debugPort  = $debugPort;
    }

    /**
     * Detecta el binario de Chrome/Chromium instalado
     * en el sistema, probando candidatos comunes.
     * Lanza DevToolsException si no encuentra ninguno.
     *
     * @param callable|null $binaryChecker Función que recibe
     * un nombre de binario y retorna su ruta o '' si no
     * existe — por defecto usa `which`. Inyectable para tests
     * (evita depender de qué esté instalado en la máquina
     * que corre la suite).
     */
    public static function detectBinary(?callable $binaryChecker = null): string {
        $checker = $binaryChecker ?? function (string $bin): string {
            return trim((string) shell_exec("which {$bin} 2>/dev/null"));
        };

        $candidates = [
            'google-chrome', 'google-chrome-stable',
            'chromium', 'chromium-browser',
        ];

        $found = '';
        foreach ($candidates as $bin):
            $path = $checker($bin);
            if ($path !== ''):
                $found = $path;
                break;
            endif;
        endforeach;

        if ($found === ''):
            throw new DevToolsException(
                "No se encontró Chrome ni Chromium instalado.\n" .
                "Instala uno con:\n" .
                "  sudo apt install chromium-browser\n" .
                "o descarga Google Chrome desde " .
                "https://www.google.com/chrome/"
            );
        endif;

        return $found;
    }

    /**
     * Lanza Chrome headless con el puerto de debugging
     * abierto. Espera hasta que el puerto responda antes
     * de retornar (con timeout).
     */
    public function start(string $url = 'about:blank'): void {
        $this->_targetUrl = $url;
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', sys_get_temp_dir()
                . '/dumbo-chromedriver-error.log', 'a'],
        ];

        $command = escapeshellarg($this->_binaryPath) . ' '
            . '--headless=new '
            . '--disable-gpu '
            . '--no-sandbox '
            . "--remote-debugging-port={$this->_debugPort} "
            . escapeshellarg($url);

        $this->_process = proc_open(
            $command, $descriptors, $this->_pipes
        );

        if (!is_resource($this->_process)):
            throw new DevToolsException(
                'No se pudo iniciar el proceso de Chrome.'
            );
        endif;

        $this->_waitForPort();
    }

    /**
     * Espera hasta que el puerto de debugging responda,
     * con timeout de 5 segundos.
     */
    private function _waitForPort(): void {
        $maxAttempts = 50;
        $attempt     = 0;
        $connected   = false;

        while ($attempt < $maxAttempts && !$connected):
            $conn = @fsockopen(
                '127.0.0.1', $this->_debugPort, $errno, $errstr, 0.1
            );
            if ($conn !== false):
                fclose($conn);
                $connected = true;
            else:
                usleep(100000);
                $attempt++;
            endif;
        endwhile;

        if (!$connected):
            $this->stop();
            throw new DevToolsException(
                'Chrome no respondió en el puerto de ' .
                "debugging después de {$maxAttempts} intentos."
            );
        endif;
    }

    /**
     * Obtiene la URL WebSocket del target/página de interés,
     * consultando el endpoint HTTP de DevTools.
     *
     * Usa un socket crudo en vez de file_get_contents(): el
     * endpoint de Chrome responde con Content-Length pero
     * mantiene la conexión keep-alive, y file_get_contents()
     * espera el cierre del socket en vez de respetar
     * Content-Length — se cuelga indefinidamente (confirmado
     * empíricamente). Leyendo manualmente hasta completar
     * Content-Length evitamos depender del cierre del socket.
     *
     * $targets[0] NO es fiable: Chrome (sin --user-data-dir)
     * reutiliza el perfil real del sistema, y /json devuelve
     * también páginas de fondo de extensiones instaladas antes
     * que la pestaña navegada — confirmado empíricamente
     * (location.href resultaba ser una extensión, nunca la URL
     * pedida). Se filtra por type=page y se prioriza la que
     * coincide con la URL solicitada en start().
     */
    public function getWebSocketUrl(): string {
        $json = $this->_httpGetJson('/json');
        $targets = json_decode($json, true);
        $pages = array_values(array_filter(
            (array) $targets,
            fn($t) => ($t['type'] ?? '') === 'page'
        ));

        if (empty($pages)):
            throw new DevToolsException(
                'No hay targets de tipo "page" disponibles en Chrome.'
            );
        endif;

        foreach ($pages as $page):
            if (($page['url'] ?? '') === $this->_targetUrl):
                return $page['webSocketDebuggerUrl'];
            endif;
        endforeach;

        return $pages[0]['webSocketDebuggerUrl'];
    }

    /**
     * GET simple sobre un socket crudo contra el endpoint HTTP
     * de DevTools, leyendo exactamente Content-Length bytes de
     * cuerpo en vez de esperar a que el servidor cierre el
     * socket (ver nota en getWebSocketUrl()).
     */
    private function _httpGetJson(string $path): string {
        $socket = @fsockopen('127.0.0.1', $this->_debugPort, $errno, $errstr, 5);

        if ($socket === false):
            throw new DevToolsException(
                'No se pudo consultar el endpoint de ' .
                "targets de Chrome: {$errstr}"
            );
        endif;

        stream_set_timeout($socket, 5);

        $request = "GET {$path} HTTP/1.1\r\n"
            . "Host: 127.0.0.1:{$this->_debugPort}\r\n"
            . "Connection: close\r\n\r\n";
        fwrite($socket, $request);

        $header = '';
        while (!feof($socket)):
            $line = fgets($socket);
            $header .= $line;
            if (trim((string)$line) === ''): break; endif;
        endwhile;

        preg_match('/Content-Length:\s*(\d+)/i', $header, $matches);
        $length = (int) ($matches[1] ?? 0);

        $body = '';
        while (strlen($body) < $length && !feof($socket)):
            $body .= fread($socket, $length - strlen($body));
        endwhile;

        fclose($socket);

        if ($body === ''):
            throw new DevToolsException(
                'No se pudo consultar el endpoint de ' .
                'targets de Chrome.'
            );
        endif;

        return $body;
    }

    /**
     * proc_terminate()/proc_close() solo alcanzan al proceso
     * directo lanzado por proc_open() (el wrapper de shell de
     * google-chrome usa sustitución de procesos de bash —
     * `> >(cat)` — que provoca un fork() real antes del exec()
     * final; el PID real de Chrome y todo su árbol de procesos
     * hijos — zygote, gpu-process, renderer — quedan huérfanos,
     * confirmado empíricamente). Se complementa matando por
     * firma de línea de comandos (--remote-debugging-port=N es
     * único por instancia y lo heredan todos los procesos del
     * árbol) para garantizar que no quede nada huérfano.
     */
    public function stop(): void {
        if (is_resource($this->_process)):
            foreach ($this->_pipes as $pipe):
                is_resource($pipe) and fclose($pipe);
            endforeach;
            proc_terminate($this->_process);
            proc_close($this->_process);
            $this->_process = null;
        endif;

        shell_exec(
            "pkill -9 -f 'remote-debugging-port={$this->_debugPort}\\b' 2>/dev/null"
        );
    }

    public function __destruct() {
        $this->stop();
    }
}
