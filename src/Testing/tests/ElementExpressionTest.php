<?php
namespace DumboChromeDriver\Testing\Tests;

use function DumboChromeDriver\Testing\{assertEquals, assertTrue};

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

};
