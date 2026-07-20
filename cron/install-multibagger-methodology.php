#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Database;
use HalalPulse\Multibagger\MultibaggerMethodologyInstaller;
use HalalPulse\Multibagger\MultibaggerMethodologyValidator;

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$path = $argv[1] ?? (HALALPULSE_ROOT . '/config/multibagger-methodology.local.json');
if (!is_string($path) || trim($path) === '') {
    fwrite(STDERR, "Usage: php cron/install-multibagger-methodology.php /absolute/path/to/reviewed-methodology.json\n");exit(2);
}
$realPath=realpath($path);
if($realPath===false||!is_file($realPath)||!is_readable($realPath)){fwrite(STDERR,"Methodology file is not readable: {$path}\n");exit(2);}
$size=filesize($realPath);if(!is_int($size)||$size<2||$size>262144){fwrite(STDERR,"Methodology file must contain between 2 bytes and 256 KiB.\n");exit(2);}
try{
    $json=file_get_contents($realPath);if(!is_string($json))throw new RuntimeException('Unable to read methodology file.');
    $payload=json_decode($json,true,512,JSON_THROW_ON_ERROR);if(!is_array($payload))throw new RuntimeException('Methodology JSON must contain an object.');
    $result=(new MultibaggerMethodologyInstaller(Database::connect($config),new MultibaggerMethodologyValidator()))->installAndActivate($payload);
    fwrite(STDOUT,"Activated multibagger methodology {$result['version']}\nSHA-256: {$result['methodology_hash']}\nPrevious methodologies remain stored but inactive.\n");
}catch(Throwable $exception){fwrite(STDERR,"Methodology activation failed: {$exception->getMessage()}\n");exit(1);}
