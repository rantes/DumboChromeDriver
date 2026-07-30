<?php
namespace DumboChromeDriver;

/**
 * Clase base opcional para organizar flujos E2E completos
 * sobre DevToolsClient, con setUp/tearDown consistente y un
 * helper de login real vía UI.
 */
abstract class E2ETestCase {
    protected DevToolsClient $client;
    protected string $baseUrl;

    public function __construct(string $baseUrl) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->client = new DevToolsClient();
    }

    public function setUp(): void {
        $this->client->start($this->baseUrl);
    }

    public function tearDown(): void {
        $this->client->stop();
    }

    /**
     * Login real vía UI — llena el form y espera a que la
     * navegación post-login complete.
     */
    protected function loginAs(
        string $loginUrl, string $userSelector,
        string $passSelector, string $submitSelector,
        string $user, string $password,
        string $expectAfterLogin
    ): void {
        $this->client->navigate($this->baseUrl . $loginUrl);
        $this->client->fill($userSelector, $user);
        $this->client->fill($passSelector, $password);
        $this->client->click($submitSelector);
        $this->client->waitFor($expectAfterLogin);
    }

    abstract public function run(): void;
}
