<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use RepAhead\App;
use RepAhead\Config;
use RepAhead\Storage;

$root = dirname(__DIR__);
chdir($root);
if (file_exists("$root/.env")) {
    Dotenv::createImmutable($root)->load();
}

$handler = new StreamHandler('php://stderr', Level::Debug);
$handler->setFormatter(new JsonFormatter());
$logger = new Logger('repahead');
$logger->pushHandler($handler);

$config = new Config($_ENV + $_SERVER);
$fs = (new Storage($_ENV + $_SERVER))->make($config->storageDsn());
$router = App::router($config, $fs, $logger);

$request = ServerRequestFactory::fromGlobals();
$response = App::safeDispatch($router, $request, $logger);
(new SapiEmitter())->emit($response);
