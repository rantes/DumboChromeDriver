<?php
namespace DumboChromeDriver\Testing;

class TestFailure extends \Exception {}

class TestRunner {
    private array $_tests = [];
    private int $_passed = 0;
    private int $_failed = 0;
    private array $_failures = [];

    public function register(string $name, callable $fn): void {
        $this->_tests[$name] = $fn;
    }

    public function run(): bool {
        foreach ($this->_tests as $name => $fn):
            try {
                $fn();
                $this->_passed++;
                fwrite(STDOUT, "  ✓ {$name}\n");
            } catch (TestFailure $e) {
                $this->_failed++;
                $this->_failures[] = "{$name}: {$e->getMessage()}";
                fwrite(STDOUT, "  ✗ {$name}: {$e->getMessage()}\n");
            } catch (\Throwable $e) {
                $this->_failed++;
                $this->_failures[] = "{$name}: ERROR — {$e->getMessage()}";
                fwrite(STDOUT, "  ✗ {$name}: ERROR — {$e->getMessage()}\n");
            }
        endforeach;

        $total = $this->_passed + $this->_failed;
        fwrite(STDOUT, "\n{$this->_passed}/{$total} tests pasaron.\n");

        return $this->_failed === 0;
    }
}

/**
 * Aserciones — funciones libres, no métodos de clase,
 * para poder hacer `use function ...;` limpio en los
 * archivos de test.
 */
function assertEquals($expected, $actual, string $message = ''): void {
    if ($expected !== $actual):
        $msg = $message !== '' ? $message : (
            'Esperado: ' . var_export($expected, true) .
            ', obtenido: ' . var_export($actual, true)
        );
        throw new TestFailure($msg);
    endif;
}

function assertTrue($value, string $message = ''): void {
    if ($value !== true):
        throw new TestFailure(
            $message !== '' ? $message : 'Esperado true, obtenido false'
        );
    endif;
}

function assertFalse($value, string $message = ''): void {
    if ($value !== false):
        throw new TestFailure(
            $message !== '' ? $message : 'Esperado false, obtenido true'
        );
    endif;
}

function assertNull($value, string $message = ''): void {
    if ($value !== null):
        throw new TestFailure(
            $message !== '' ? $message : 'Esperado null'
        );
    endif;
}

function assertNotNull($value, string $message = ''): void {
    if ($value === null):
        throw new TestFailure(
            $message !== '' ? $message : 'No se esperaba null'
        );
    endif;
}

function assertThrows(callable $fn, string $exceptionClass, string $message = ''): void {
    try {
        $fn();
    } catch (\Throwable $e) {
        if ($e instanceof $exceptionClass):
            return;
        endif;
        throw new TestFailure(
            "Se esperaba {$exceptionClass}, se lanzó " . get_class($e)
        );
    }
    throw new TestFailure(
        $message !== '' ? $message : "Se esperaba que lanzara {$exceptionClass}"
    );
}
