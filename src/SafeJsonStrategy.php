<?php

declare(strict_types=1);

namespace Composerd;

use League\Route\Http;
use League\Route\Strategy\JsonStrategy;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * JsonStrategy variant whose throwable handler logs uncaught exceptions and
 * returns a fixed JSON body. The default JsonStrategy handler echoes the
 * exception message (often containing absolute file paths) to clients.
 */
final class SafeJsonStrategy extends JsonStrategy
{
    public function __construct(
        ResponseFactoryInterface $responseFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        parent::__construct($responseFactory);
    }

    public function getThrowableHandler(): MiddlewareInterface
    {
        return new class ($this->logger, $this->responseFactory) implements MiddlewareInterface {
            public function __construct(
                private readonly LoggerInterface $logger,
                private readonly ResponseFactoryInterface $responseFactory,
            ) {
            }

            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                try {
                    return $handler->handle($request);
                } catch (Http\Exception $http) {
                    return $http->buildJsonResponse($this->responseFactory->createResponse());
                } catch (Throwable $e) {
                    $this->logger->error('Uncaught exception in dispatch', [
                        'error' => $e->getMessage(),
                        'class' => $e::class,
                    ]);
                    $response = $this->responseFactory->createResponse(500);
                    $response->getBody()->write((string) json_encode(
                        ['error' => 'internal_server_error'],
                        JSON_UNESCAPED_SLASHES
                    ));
                    return $response->withHeader('Content-Type', 'application/json');
                }
            }
        };
    }
}
