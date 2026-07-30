<?php
namespace DumboChromeDriver\Testing\Tests;

use DumboChromeDriver\ChromeProcess;
use DumboChromeDriver\DevToolsException;
use function DumboChromeDriver\Testing\{assertEquals, assertThrows};

return function (\DumboChromeDriver\Testing\TestRunner $runner) {

    $runner->register('detectBinary: retorna el primer candidato disponible', function () {
        $checker = function (string $bin): string {
            return $bin === 'chromium' ? '/usr/bin/chromium' : '';
        };
        $result = ChromeProcess::detectBinary($checker);
        assertEquals('/usr/bin/chromium', $result);
    });

    $runner->register('detectBinary: respeta el orden de prioridad', function () {
        $checker = function (string $bin): string {
            // Ambos "existen" — debe ganar google-chrome
            // por ser el primero en la lista de candidatos
            if ($bin === 'google-chrome') return '/usr/bin/google-chrome';
            if ($bin === 'chromium') return '/usr/bin/chromium';
            return '';
        };
        $result = ChromeProcess::detectBinary($checker);
        assertEquals('/usr/bin/google-chrome', $result);
    });

    $runner->register('detectBinary: lanza excepción clara si no hay ninguno', function () {
        $checker = fn(string $bin): string => '';
        assertThrows(
            fn() => ChromeProcess::detectBinary($checker),
            DevToolsException::class
        );
    });

};
