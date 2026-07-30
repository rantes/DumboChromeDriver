# DumboChromeDriver

Cliente PHP puro del protocolo DevTools de Chrome — sin
Composer, sin dependencias de terceros. Lanza Chrome
headless, controla la página, y verifica el resultado
con aserciones de alto nivel.

Construido para reemplazar el mecanismo `--repl`
(deprecado en Chrome moderno) usado originalmente en
`uibuilder.php` para correr tests de Jasmine, y extendido
para escribir flujos E2E completos de cualquier
aplicación web.

## Por qué existe

Chrome eliminó el soporte de `--repl` en versiones
recientes, rompiendo silenciosamente cualquier
automatización que dependiera de él — sin lanzar error,
simplemente dejaba de funcionar. `DumboChromeDriver`
habla el protocolo DevTools real (WebSocket + JSON-RPC),
que es estable y es la base sobre la que están
construidas herramientas como Puppeteer y ChromeDriver.

## Requisitos

- PHP 8.1+
- Extensión `sockets` habilitada
- Chrome o Chromium instalado (detectado automáticamente)

Sin Composer. Sin `npm install`. Sin dependencias de
terceros de ningún tipo.

## Instalación

```bash
git clone <repo> ~/web/DumboChromeDriver
sudo php ~/web/DumboChromeDriver/install.php
```

Esto copia el código fuente a `/etc/dumboChromeDriver/`,
de donde cualquier proyecto lo consume vía `require_once`
— mismo patrón que DumboPHP.

## Uso básico

```php
require_once '/etc/dumboChromeDriver/src/DevToolsException.php';
require_once '/etc/dumboChromeDriver/src/ChromeProcess.php';
require_once '/etc/dumboChromeDriver/src/DevToolsSession.php';
require_once '/etc/dumboChromeDriver/src/DevToolsClient.php';

use DumboChromeDriver\DevToolsClient;

$client = new DevToolsClient();
$client->start('https://mi-app.local/login');

$client->fill('#email', 'admin@test.com');
$client->fill('#password', 'secret');
$client->click('#btn-login');

$client->waitFor('#dashboard');
$client->assertText('h1', 'Bienvenido');

$client->stop();
```

## API

### Navegación
| Método | Descripción |
|---|---|
| `start(string $url)` | Lanza Chrome y navega a `$url` |
| `navigate(string $url)` | Navega dentro de una sesión activa |
| `stop()` | Cierra la sesión y mata el proceso Chrome |

### Interacción
| Método | Descripción |
|---|---|
| `fill(selector, value)` | Escribe en un input, dispara `input`+`change` |
| `click(selector)` | Simula click real sobre el elemento |
| `waitFor(selector, timeoutMs = 10000)` | Espera hasta que el elemento exista en el DOM |

### Lectura
| Método | Descripción |
|---|---|
| `getText(selector)` | Retorna el `textContent` del elemento |
| `count(selector)` | Cuenta elementos que coinciden con el selector |
| `evaluate(js)` | Ejecuta JS arbitrario, retorna el valor evaluado |

### Aserciones
| Método | Lanza `DevToolsException` si... |
|---|---|
| `assertText(selector, expected)` | El texto no coincide exactamente |
| `assertCount(selector, expected)` | El conteo no coincide |
| `assertVisible(selector)` | El elemento no está visible (`display`, `visibility`, `offsetParent`) |

### Sesión
| Método | Descripción |
|---|---|
| `setCookie(name, value, domain)` | Inyecta una cookie — útil para saltar el login en tests que no son sobre login |

## Escribir un flujo E2E

Extiende `E2ETestCase` para flujos con setup/teardown
consistente:

```php
require_once '/etc/dumboChromeDriver/src/E2ETestCase.php';

use DumboChromeDriver\E2ETestCase;

class LoginFlowTest extends E2ETestCase {
    public function run(): void {
        $this->loginAs(
            '/admin/login',
            '#email', '#password', '#btn-login',
            'admin@test.com', 'secret',
            '#dashboard'
        );
        $this->client->assertText('h1', 'Panel administrador');
    }
}

$test = new LoginFlowTest('http://localhost:8080');
$test->setUp();
$test->run();
$test->tearDown();
```

## Diseño

```
ChromeProcess    → lanza/mata el proceso Chrome,
                    detecta el binario del sistema
DevToolsSession  → WebSocket + framing (RFC 6455)
                    sobre la extensión sockets
DevToolsClient   → API de alto nivel (fill, click,
                    waitFor, aserciones)
E2ETestCase      → clase base opcional para flujos
                    con setup/teardown
```

Todas las capacidades de interacción (`fill`, `click`,
`waitFor`, etc.) están construidas sobre `evaluate()` —
JavaScript estándar del DOM, sin depender de dominios
específicos del protocolo Chrome DevTools más allá de
`Runtime.evaluate`. Esto significa que la capa de alto
nivel es, en principio, portable a otros navegadores si
en el futuro se agrega un transporte alternativo
(WebDriver W3C para Firefox/Safari) sin reescribir
`fill`/`click`/`waitFor`.

## Limitaciones actuales

- Solo controla Chrome/Chromium (protocolo DevTools
  es específico de la familia Chromium)
- Una sesión/tab a la vez — sin soporte de múltiples
  pestañas concurrentes
- Sin captura de screenshots todavía
- Sin interceptación de red (mockear respuestas)

## Independencia de DumboPHP

Este proyecto no depende de DumboPHP en ningún punto —
es una herramienta de automatización de navegador de
propósito general. Se instala junto a DumboPHP en `/etc/`
por convención del ecosistema, pero funciona con
cualquier proyecto PHP.

---

*Un proyecto de La Tuteca SAS.*