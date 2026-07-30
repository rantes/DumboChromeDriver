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

        // _waitForPort() (dentro de _process->start()) solo espera a
        // que el puerto de debugging responda — NO a que la navegación
        // inicial (la URL pasada por línea de comandos a Chrome) haya
        // terminado. Sin esto, un navigate()/evaluate() inmediato puede
        // ganarle la carrera a esa navegación todavía en curso: la
        // reasignación de window.location.href se pierde cuando la
        // navegación original (a la URL de start()) termina de cargar
        // después y sobrescribe lo que acabamos de hacer — confirmado
        // empíricamente contra el login real de Komodo (start() a la
        // URL base + navigate() inmediato al login quedaba varado en
        // la URL base con el body vacío).
        $this->_waitForLoad();
        $this->_initElementRegistry();
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
        // Una navegación real recarga la página — borra
        // window.__dcd__ y todos los ids registrados. Los
        // Element de la página anterior dejan de ser válidos.
        $this->_initElementRegistry();
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
     * Llena un input/select y dispara 'input', 'change' y
     * 'blur' — varios componentes DumboJS (dmb-input,
     * dmb-select) escuchan eventos nativos del DOM para
     * actualizar su estado interno; un simple .value= sin
     * eventos no sería detectado por los componentes.
     *
     * 'blur' es imprescindible, no cosmético: dmb-input solo
     * marca el campo con el atributo `valid` en su listener de
     * blur (ver dmb-input.directive.js), y dmb-form.validateForm()
     * exige ese atributo antes de permitir el submit — sin
     * disparar blur, cualquier formulario con validate="required"
     * queda "inválido" en silencio (reportValidity()/focus(), sin
     * excepción visible) y submit() nunca invoca su callback.
     * Confirmado empíricamente contra el login real de Komodo.
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
            el.dispatchEvent(new Event('blur', { bubbles: true }));
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
     * Reintenta una acción hasta que la condición de éxito se
     * cumpla, o se agote el timeout. Agnóstico al framework —
     * $action y $condition son closures que el llamador define
     * en términos de comportamiento observable (ej: "la URL
     * cambió"), no de implementación interna de ningún framework.
     *
     * Útil para acciones cuyo efecto depende de lógica JS que
     * puede no estar conectada todavía aunque el elemento ya sea
     * visualmente interactuable (ej: un formulario cuyo callback
     * de submit se conecta en un módulo JS separado del que
     * renderiza el input) — waitUntilInteractable() verifica que
     * el elemento SE VE listo, no que su lógica de negocio ya
     * esté conectada; ese es un límite genuino de cualquier
     * verificación basada en DOM/CSS, sin importar el framework.
     *
     * RIESGO A TENER EN CUENTA — no es un reemplazo universal de
     * click(): si la primera ejecución de $action() SÍ tuvo efecto
     * pero $condition() tardó en reflejarlo, la siguiente iteración
     * repite la acción, pudiendo ejecutarla dos veces. Para un
     * login esto es inofensivo (la segunda vez ya hay sesión
     * iniciada y redirige igual). Para un formulario que crea un
     * registro (ej: un POST que inserta una fila), reintentar el
     * submit puede crear un duplicado — se debe usar con criterio
     * en cada E2E, evaluando si la acción es idempotente, no
     * aplicarlo ciegamente en cualquier submit.
     */
    public function retryUntil(
        callable $action,
        callable $condition,
        int $timeoutMs = 15000,
        int $retryIntervalMs = 500
    ): void {
        $elapsed = 0;
        $success = false;

        while ($elapsed < $timeoutMs && !$success):
            $action();
            usleep(300000); // 300ms — da tiempo a que la
                             // acción tenga efecto antes
                             // de verificar la condición
            $success = (bool) $condition();

            if (!$success):
                usleep($retryIntervalMs * 1000);
                $elapsed += $retryIntervalMs + 300;
            endif;
        endwhile;

        if (!$success):
            throw new DevToolsException(
                "retryUntil() agotó el timeout de " .
                "{$timeoutMs}ms sin que la condición " .
                "se cumpliera."
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

    /**
     * Espera hasta que un elemento exista Y sea interactuable —
     * visible, con dimensiones reales, no deshabilitado. Criterio
     * 100% estándar del DOM, sin depender de ningún framework o
     * convención de atributos custom (no 'rendered' ni nada
     * específico de DumboJS/React/Vue/Angular). Resuelve el caso
     * de componentes con renderizado asíncrono (Web Components o
     * cualquier framework) cuyo tag existe en el HTML servido
     * antes de que su contenido interno esté listo — dimensiones
     * 0x0 capturan ese estado indirectamente, sin necesitar saber
     * nada sobre cómo el framework marca "ya terminé de montar".
     */
    public function waitUntilInteractable(
        string $selector, int $timeoutMs = 10000
    ): void {
        $selectorJson = json_encode($selector);
        $elapsed = 0;
        $interval = 100;
        $ready = false;

        while ($elapsed < $timeoutMs && !$ready):
            $ready = (bool) $this->evaluate(<<<JS
            (() => {
                const el = document.querySelector({$selectorJson});
                if (!el) return false;
                const style = window.getComputedStyle(el);
                const rect = el.getBoundingClientRect();
                return style.display !== 'none'
                    && style.visibility !== 'hidden'
                    && rect.width > 0
                    && rect.height > 0
                    && !el.disabled;
            })()
            JS);
            if (!$ready):
                usleep($interval * 1000);
                $elapsed += $interval;
            endif;
        endwhile;

        if (!$ready):
            throw new DevToolsException(
                "Timeout esperando que '{$selector}' esté " .
                "interactuable (visible, con dimensiones, " .
                "habilitado) después de {$timeoutMs}ms."
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

    /**
     * Inyecta la tabla window.__dcd__ que mapea ids lógicos
     * a nodos reales del DOM — permite que Element encadene
     * operaciones (closest → count) sobre el MISMO nodo
     * encontrado, en vez de que cada paso re-evalúe el
     * selector desde cero. Idempotente: tolera llamadas
     * repetidas sin duplicar el registro.
     */
    private function _initElementRegistry(): void {
        $this->evaluate(<<<'JS'
        window.__dcd__ = window.__dcd__ || {
            _store: new Map(),
            _nextId: 1,
            register(node) {
                if (!node) return null;
                const id = 'el_' + (this._nextId++);
                this._store.set(id, node);
                return id;
            },
            get(id) {
                return this._store.get(id) || null;
            }
        };
        undefined
        JS);
    }

    /**
     * Busca el primer elemento en todo el documento.
     * Retorna null si no existe (a diferencia de los
     * métodos v2 que lanzaban excepción — find() es
     * la versión "segura" para permitir chequear
     * existencia antes de actuar).
     */
    public function find(string $selector): ?Element {
        $id = $this->evaluate(
            'window.__dcd__.register(document.querySelector(' .
            json_encode($selector) . '))'
        );
        return $id !== null ? new Element($this, $id) : null;
    }

    public function findAll(string $selector): array {
        $ids = $this->evaluate(<<<JS
        Array.from(document.querySelectorAll({$this->_json($selector)}))
            .map(el => window.__dcd__.register(el))
        JS);
        return array_map(fn($id) => new Element($this, $id), $ids ?? []);
    }

    /**
     * Busca el primer elemento de tipo $tag cuyo
     * textContent incluye $text.
     */
    public function findByText(string $tag, string $text): ?Element {
        $id = $this->evaluate(<<<JS
        (() => {
            const els = Array.from(document.querySelectorAll(
                {$this->_json($tag)}
            ));
            const match = els.find(
                el => el.textContent.includes({$this->_json($text)})
            );
            return window.__dcd__.register(match || null);
        })()
        JS);
        return $id !== null ? new Element($this, $id) : null;
    }

    /**
     * Métodos internos — prefijo _ señala que son parte del
     * contrato con Element, no API pública para el test.
     */
    public function _findWithin(string $parentId, string $selector): ?Element {
        $id = $this->evaluate(
            'window.__dcd__.register(window.__dcd__.get(' .
            json_encode($parentId) . ')?.querySelector(' .
            json_encode($selector) . '))'
        );
        return $id !== null ? new Element($this, $id) : null;
    }

    public function _findAllWithin(string $parentId, string $selector): array {
        $ids = $this->evaluate(<<<JS
        (() => {
            const parent = window.__dcd__.get({$this->_json($parentId)});
            if (!parent) return [];
            return Array.from(
                parent.querySelectorAll({$this->_json($selector)})
            ).map(el => window.__dcd__.register(el));
        })()
        JS);
        return array_map(fn($id) => new Element($this, $id), $ids ?? []);
    }

    public function _closest(string $elId, string $selector): ?Element {
        $id = $this->evaluate(
            'window.__dcd__.register(window.__dcd__.get(' .
            json_encode($elId) . ')?.closest(' .
            json_encode($selector) . '))'
        );
        return $id !== null ? new Element($this, $id) : null;
    }

    public function _getElementText(string $elId): string {
        $text = $this->evaluate(
            'window.__dcd__.get(' . json_encode($elId) .
            ')?.textContent?.trim() ?? \'\''
        );
        return (string) $text;
    }

    public function _clickElement(string $elId): void {
        $this->evaluate(
            'window.__dcd__.get(' . json_encode($elId) . ')?.click()'
        );
    }

    public function _fillElement(string $elId, string $value): void {
        $this->evaluate(<<<JS
        (() => {
            const el = window.__dcd__.get({$this->_json($elId)});
            if (!el) return;
            el.value = {$this->_json($value)};
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        })()
        JS);
    }

    public function _isElementVisible(string $elId): bool {
        $visible = $this->evaluate(<<<JS
        (() => {
            const el = window.__dcd__.get({$this->_json($elId)});
            if (!el) return false;
            const s = window.getComputedStyle(el);
            return s.display !== 'none' && s.visibility !== 'hidden'
                && el.offsetParent !== null;
        })()
        JS);
        return (bool) $visible;
    }

    public function _getElementAttr(string $elId, string $name): ?string {
        return $this->evaluate(
            'window.__dcd__.get(' . json_encode($elId) .
            ')?.getAttribute(' . json_encode($name) . ') ?? null'
        );
    }

    /**
     * Helper interno — json_encode abreviado para
     * interpolación en heredocs JS.
     */
    private function _json($value): string {
        return json_encode($value);
    }
}
