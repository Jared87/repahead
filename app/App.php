<?php

declare(strict_types=1);

namespace RepAhead;

use Laminas\Diactoros\Response;
use Laminas\Diactoros\ResponseFactory;
use League\Flysystem\Filesystem;
use League\Route\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

final class App
{
    /**
     * Dispatch the request and translate any uncaught exception into a generic
     * 500 with a fixed JSON body. League Route's JsonStrategy default would
     * otherwise echo the exception message (including absolute file paths)
     * to the client.
     */
    public static function safeDispatch(
        Router $router,
        ServerRequestInterface $request,
        LoggerInterface $logger = new NullLogger(),
    ): ResponseInterface {
        try {
            return $router->dispatch($request);
        } catch (Throwable $e) {
            $logger->error('Uncaught exception in dispatch', [
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);
            $resp = new Response();
            $resp->getBody()->write((string) json_encode(
                ['error' => 'internal_server_error'],
                JSON_UNESCAPED_SLASHES
            ));
            return $resp
                ->withStatus(500)
                ->withHeader('Content-Type', 'application/json');
        }
    }

    public static function router(Config $config, Filesystem $fs, LoggerInterface $logger = new NullLogger()): Router
    {

        $controller = new Controller(
            fs: $fs,
            catalog: new Catalog(),
            zipMetadata: new ZipMetadata($logger),
            packagesJson: new PackagesJson($logger),
            cache: new Cache($config->cacheDir(), $config->listingTtlSeconds()),
            baseUrl: $config->baseUrl(),
            logger: $logger,
        );

        $auth = new Auth($config->authUser(), $config->authPass());

        // SafeJsonStrategy handles 404/405 as JSON responses and sanitises uncaught exceptions.
        $strategy = new SafeJsonStrategy(new ResponseFactory(), $logger);
        $router = new Router();
        $router->setStrategy($strategy);
        $router->middleware($auth);

        $router->get('/packages.json', fn (ServerRequestInterface $req): ResponseInterface => $controller->packages($req));
        $router->get(
            '/dist/{vendor}/{package}/{version}.zip',
            function (ServerRequestInterface $req, array $args) use ($controller): ResponseInterface {
                /** @var array{vendor: string, package: string, version: string} $args */
                return $controller->dist($req, $args);
            },
        );
        $router->post('/rebuild', fn (ServerRequestInterface $req): ResponseInterface => $controller->rebuild($req));

        return $router;
    }
}
