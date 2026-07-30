#!/usr/bin/php
<?php
require_once __DIR__ . '/../src/DevToolsException.php';
require_once __DIR__ . '/../src/ChromeProcess.php';
require_once __DIR__ . '/../src/DevToolsSession.php';
require_once __DIR__ . '/../src/DevToolsClient.php';
require_once __DIR__ . '/../src/Element.php';
require_once __DIR__ . '/../src/Testing/TestRunner.php';

use DumboChromeDriver\Testing\TestRunner;

$runner = new TestRunner();

$testFiles = glob(__DIR__ . '/../src/Testing/tests/*.php');
foreach ($testFiles as $file) {
    $registerFn = require $file;
    $registerFn($runner);
}

fwrite(STDOUT, "DumboChromeDriver — suite de tests\n\n");
$success = $runner->run();

exit($success ? 0 : 1);
