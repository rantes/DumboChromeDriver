<?php
namespace DumboChromeDriver\Testing\Tests;

use function DumboChromeDriver\Testing\{assertEquals, assertTrue, assertFalse};

return function (\DumboChromeDriver\Testing\TestRunner $runner) {

    $runner->register('json encoding escapa selectores con comillas', function () {
        $selector = 'input[name="test"]';
        $encoded = json_encode($selector);

        assertTrue(str_contains($encoded, '\\"'));
    });

    $runner->register('json encoding escapa texto con caracteres especiales', function () {
        $text = "texto con 'comillas' y \"dobles\"";
        $encoded = json_encode($text);

        // No debe romper la sintaxis JS resultante —
        // json_encode garantiza esto por contrato,
        // verificamos que produce una cadena válida
        $decoded = json_decode($encoded);
        assertEquals($text, $decoded);
    });

    $runner->register(
        'waitUntilInteractable no depende de atributos de framework',
        function () {
            // Verifica que el código fuente del método no contiene
            // referencias hardcodeadas a convenciones de un
            // framework específico (ej: el atributo 'rendered' de
            // DumboJS) — el criterio debe ser 100% estándar del DOM
            // (visible, con dimensiones, no deshabilitado), válido
            // sin importar qué framework generó el elemento.
            $source = file_get_contents(
                __DIR__ . '/../../DevToolsClient.php'
            );
            $method = substr(
                $source,
                strpos($source, 'function waitUntilInteractable'),
                1000
            );
            assertFalse(
                str_contains($method, "hasAttribute('rendered')"),
                'waitUntilInteractable no debe depender de ' .
                'atributos custom de un framework específico'
            );
        }
    );

};
