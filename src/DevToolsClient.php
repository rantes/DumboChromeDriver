<?php
namespace DumboChromeDriver;

class DevToolsClient {
    private ChromeProcess $_process;
    private DevToolsSession $_session;

    public function __construct(
        string $binaryPath = '', int $debugPort = 9222
    ) {
        $binary = $binaryPath !== ''
            ? $binaryPath
            : ChromeProcess::detectBinary();

        $this->_process = new ChromeProcess($binary, $debugPort);
    }

    /**
     * Lanza Chrome, conecta la sesión DevTools y navega
     * a la URL indicada.
     */
    public function start(string $url): void {
        $this->_process->start($url);
        $wsUrl = $this->_process->getWebSocketUrl();
        $this->_session = new DevToolsSession($wsUrl);
        $this->_session->connect();

        // Habilitar el dominio Runtime antes de evaluar
        $this->_session->send('Runtime.enable');
    }

    /**
     * Ejecuta JavaScript en el contexto de la página
     * y retorna el valor evaluado (decodificado de JSON
     * cuando es posible).
     */
    public function evaluate(string $expression): mixed {
        $response = $this->_session->send('Runtime.evaluate', [
            'expression'    => $expression,
            'returnByValue' => true,
            'awaitPromise'  => true,
        ]);

        if (!empty($response['result']['exceptionDetails'])):
            throw new DevToolsException(
                'Error evaluando JS: ' . json_encode(
                    $response['result']['exceptionDetails']
                )
            );
        endif;

        return $response['result']['result']['value'] ?? null;
    }

    public function stop(): void {
        $this->_session->close();
        $this->_process->stop();
    }

    /**
     * Navega a una URL asignando window.location.href vía
     * evaluate() — sin Page.navigate, reutilizando el mismo
     * mecanismo ya probado. La navegación es asíncrona, por
     * lo que se espera a que el documento cargue.
     */
    public function navigate(string $url): void {
        $this->evaluate(
            'window.location.href = ' . json_encode($url)
        );
        $this->_waitForLoad();
    }

    /**
     * Espera hasta que document.readyState sea 'complete',
     * con timeout. Necesario porque window.location.href
     * dispara la navegación de forma asíncrona.
     */
    private function _waitForLoad(int $timeoutMs = 10000): void {
        $elapsed = 0;
        $interval = 100;
        $ready = false;

        while ($elapsed < $timeoutMs && !$ready):
            $state = $this->evaluate('document.readyState');
            $ready = ($state === 'complete');
            if (!$ready):
                usleep($interval * 1000);
                $elapsed += $interval;
            endif;
        endwhile;

        if (!$ready):
            throw new DevToolsException(
                'Timeout esperando carga de página ' .
                "después de {$timeoutMs}ms."
            );
        endif;
    }

    /**
     * Llena un input/select y dispara 'input' y 'change' —
     * varios componentes DumboJS (dmb-input, dmb-select)
     * escuchan eventos nativos del DOM para actualizar su
     * estado interno; un simple .value= sin eventos no sería
     * detectado por los componentes.
     */
    public function fill(string $selector, string $value): void {
        $selectorJson = json_encode($selector);
        $valueJson = json_encode($value);

        $result = $this->evaluate(<<<JS
        (() => {
            const el = document.querySelector({$selectorJson});
            if (!el) return 'NOT_FOUND';
            el.value = {$valueJson};
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
            return 'OK';
        })()
        JS);

        if ($result === 'NOT_FOUND'):
            throw new DevToolsException(
                "No se encontró el elemento: {$selector}"
            );
        endif;
    }

    public function click(string $selector): void {
        $selectorJson = json_encode($selector);

        $result = $this->evaluate(<<<JS
        (() => {
            const el = document.querySelector({$selectorJson});
            if (!el) return 'NOT_FOUND';
            el.click();
            return 'OK';
        })()
        JS);

        if ($result === 'NOT_FOUND'):
            throw new DevToolsException(
                "No se encontró el elemento: {$selector}"
            );
        endif;
    }

    /**
     * Espera hasta que un selector exista en el DOM, con
     * timeout — esencial para paneles/contenido async
     * (dmb-panel con fetch, respuestas AJAX de
     * dmb-simple-form) sin lo cual el test intentaría
     * interactuar con elementos que aún no existen.
     */
    public function waitFor(
        string $selector, int $timeoutMs = 10000
    ): void {
        $selectorJson = json_encode($selector);
        $elapsed = 0;
        $interval = 100;
        $found = false;

        while ($elapsed < $timeoutMs && !$found):
            $exists = $this->evaluate(
                "document.querySelector({$selectorJson}) !== null"
            );
            $found = (bool) $exists;
            if (!$found):
                usleep($interval * 1000);
                $elapsed += $interval;
            endif;
        endwhile;

        if (!$found):
            throw new DevToolsException(
                "Timeout esperando el elemento '{$selector}' " .
                "después de {$timeoutMs}ms."
            );
        endif;
    }

    public function getText(string $selector): string {
        $selectorJson = json_encode($selector);

        $result = $this->evaluate(<<<JS
        (() => {
            const el = document.querySelector({$selectorJson});
            if (!el) return null;
            return el.textContent.trim();
        })()
        JS);

        if ($result === null):
            throw new DevToolsException(
                "No se encontró el elemento: {$selector}"
            );
        endif;

        return (string) $result;
    }

    /**
     * Cuenta coincidencias de un selector — útil para
     * verificar cantidad de elementos generados (ej: N
     * excepciones esperadas deben producir N elementos).
     */
    public function count(string $selector): int {
        $selectorJson = json_encode($selector);
        return (int) $this->evaluate(
            "document.querySelectorAll({$selectorJson}).length"
        );
    }

    /**
     * Aserción de alto nivel — falla con mensaje claro si
     * el texto no coincide, en vez de que el test tenga que
     * hacer su propio if/throw cada vez.
     */
    public function assertText(
        string $selector, string $expected
    ): void {
        $actual = $this->getText($selector);
        if ($actual !== $expected):
            throw new DevToolsException(
                "Aserción fallida en '{$selector}': " .
                "esperado '{$expected}', obtenido '{$actual}'"
            );
        endif;
    }

    public function assertCount(
        string $selector, int $expected
    ): void {
        $actual = $this->count($selector);
        if ($actual !== $expected):
            throw new DevToolsException(
                "Aserción fallida en '{$selector}': " .
                "esperados {$expected} elementos, " .
                "encontrados {$actual}"
            );
        endif;
    }

    /**
     * Verifica que un elemento sea visible (no display:none,
     * no visibility:hidden, y con caja de layout — offsetParent
     * no nulo cubre el caso de ancestros ocultos vía display:none
     * que getComputedStyle del propio elemento no detectaría).
     */
    public function assertVisible(string $selector): void {
        $selectorJson = json_encode($selector);
        $visible = $this->evaluate(<<<JS
        (() => {
            const el = document.querySelector({$selectorJson});
            if (!el) return false;
            const style = window.getComputedStyle(el);
            return style.display !== 'none'
                && style.visibility !== 'hidden'
                && el.offsetParent !== null;
        })()
        JS);

        if (!$visible):
            throw new DevToolsException(
                "El elemento '{$selector}' no está visible."
            );
        endif;
    }

    /**
     * Inyecta una cookie de sesión directo, sin pasar por el
     * formulario de login — útil para tests que prueban otra
     * cosa y no quieren pagar el costo de tiempo del login en
     * cada corrida (ej: un PHPSESSID válido de una corrida
     * previa autenticada, o de un fixture).
     */
    public function setCookie(
        string $name, string $value, string $domain
    ): void {
        $this->_session->send('Network.enable');
        $this->_session->send('Network.setCookie', [
            'name'   => $name,
            'value'  => $value,
            'domain' => $domain,
            'path'   => '/',
        ]);
    }
}
