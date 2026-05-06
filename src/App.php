<?php
declare(strict_types=1);

namespace Composerd;

use Laminas\Diactoros\ResponseFactory;
use League\Flysystem\Filesystem;
use League\Route\Router;
use League\Route\Strategy\JsonStrategy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class App
{
    public static function router(Config $config, Filesystem $fs, ?\Psr\Log\LoggerInterface $logger = null): Router
    {
        $logger ??= new StderrLogger();

        $controller = new Controller(
            fs: $fs,
            catalog: new Catalog(),
            zipMetadata: new ZipMetadata(),
            packagesJson: new PackagesJson($logger),
            cache: new Cache($config->cacheDir(), $config->listingTtlSeconds()),
            baseUrl: $config->baseUrl(),
        );

        $auth = new Auth($config->authUser(), $config->authPass());

        // JsonStrategy handles 404/405 as proper HTTP responses instead of bubbling exceptions.
        $strategy = new JsonStrategy(new ResponseFactory());
        $router = new Router();
        $router->setStrategy($strategy);
        $router->middleware($auth);

        $router->get('/packages.json', fn(ServerRequestInterface $req): ResponseInterface => $controller->packages($req));
        $router->get('/dist/{vendor}/{package}/{version}.zip', function (ServerRequestInterface $req, array $args) use ($controller): ResponseInterface {
            return $controller->dist($req, $args);
        });
        $router->post('/rebuild', fn(ServerRequestInterface $req): ResponseInterface => $controller->rebuild($req));

        return $router;
    }
}
