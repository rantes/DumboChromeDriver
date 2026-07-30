<?php
namespace DumboChromeDriver;

/**
 * Referencia PHP a un nodo del DOM registrado en
 * window.__dcd__ del navegador — permite encadenar
 * operaciones (find → closest → count) sobre el MISMO
 * nodo encontrado, sin re-evaluar el selector desde cero
 * en cada paso.
 */
class Element {
    private DevToolsClient $_client;
    private string $_id;

    public function __construct(DevToolsClient $client, string $id) {
        $this->_client = $client;
        $this->_id = $id;
    }

    public function getId(): string {
        return $this->_id;
    }

    /**
     * Busca el primer descendiente que coincide con el
     * selector, dentro del scope de ESTE elemento.
     */
    public function find(string $selector): ?Element {
        return $this->_client->_findWithin($this->_id, $selector);
    }

    /**
     * Busca todos los descendientes que coinciden,
     * dentro del scope de ESTE elemento.
     */
    public function findAll(string $selector): array {
        return $this->_client->_findAllWithin($this->_id, $selector);
    }

    /**
     * Sube al ancestro más cercano que coincide con
     * el selector (equivalente a Element.closest()).
     */
    public function closest(string $selector): ?Element {
        return $this->_client->_closest($this->_id, $selector);
    }

    /**
     * Cuenta descendientes que coinciden con el selector,
     * dentro del scope de este elemento.
     */
    public function count(string $selector): int {
        $matches = $this->findAll($selector);
        return count($matches);
    }

    public function text(): string {
        return $this->_client->_getElementText($this->_id);
    }

    public function click(): void {
        $this->_client->_clickElement($this->_id);
    }

    public function fill(string $value): void {
        $this->_client->_fillElement($this->_id, $value);
    }

    public function isVisible(): bool {
        return $this->_client->_isElementVisible($this->_id);
    }

    public function attr(string $name): ?string {
        return $this->_client->_getElementAttr($this->_id, $name);
    }
}
