<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Composerd\App;
use Composerd\Config;
use Composerd\Storage;
use Dotenv\Dotenv;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;

$root = dirname(__DIR__);
chdir($root);
if (file_exists("$root/.env")) {
    Dotenv::createImmutable($root)->load();
}

$config = new Config($_ENV + $_SERVER);
$fs = (new Storage($_ENV + $_SERVER))->make($config->storageDsn());
$router = App::router($config, $fs);

$request = ServerRequestFactory::fromGlobals();
$response = $router->dispatch($request);
(new SapiEmitter())->emit($response);
