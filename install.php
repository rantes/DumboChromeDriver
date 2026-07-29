#!/usr/bin/php
<?php

$systemPath = '/etc/dumboChromeDriver';
$path = dirname(__FILE__);
$pathSrc = $path.'/src';

fwrite(STDOUT, 'Installing DumboChromeDriver. Please be patient...'.PHP_EOL);

if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    fwrite(STDOUT, 'This is a server using Windows! (we recomend GNU/Linux)'.PHP_EOL);
    $systemPath = shell_exec('echo %SYSTEMROOT%');
    $systemPath = str_replace(array("\n","\r"), '', $systemPath);
    $systemPath.= '/dumboChromeDriver';
    defined('IS_WIN') or define('IS_WIN', true);
} else {
    fwrite(STDOUT, 'Great!!! this is a server not using Windows!'.PHP_EOL);
    defined('IS_WIN') or define('IS_WIN', false);
}

$systemPathSrc = $systemPath.'/src';
file_exists($systemPath) || mkdir($systemPath, 0777, TRUE);
file_exists($systemPathSrc) || mkdir($systemPathSrc, 0777, TRUE);

$d = dir($pathSrc);
while (false !== ($entry = $d->read())) {
   if($entry != '.' && $entry != '..' && !is_dir($pathSrc.'/'.$entry)){
        fwrite(STDOUT, 'copying '.$pathSrc.'/'.$entry.' to '.$systemPathSrc.'/'.$entry.PHP_EOL);
        file_exists($systemPathSrc.'/'.$entry) && unlink($systemPathSrc.'/'.$entry);
        copy($pathSrc.'/'.$entry, $systemPathSrc.'/'.$entry) or die('Could not copy file.');
   }
}
$d->close();

fwrite(STDOUT, 'Install complete'.PHP_EOL);
