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
}
