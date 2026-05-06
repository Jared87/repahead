<?php

declare(strict_types=1);

namespace Composerd\Tests;

use Composerd\Auth;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AuthTest extends TestCase
{
    private function handler(string $body = 'OK'): RequestHandlerInterface
    {
        return new class ($body) implements RequestHandlerInterface {
            public function __construct(private readonly string $body)
            {
            }
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $r = new Response();
                $r->getBody()->write($this->body);
                return $r;
            }
        };
    }

    private function requestWithAuth(?string $user, ?string $pass): ServerRequestInterface
    {
        $r = new ServerRequest();
        if ($user !== null) {
            return $r->withHeader('Authorization', 'Basic ' . base64_encode("$user:$pass"));
        }
        return $r;
    }

    public function testReturns401WhenHeaderMissing(): void
    {
        $mw = new Auth('ci', 'secret');
        $resp = $mw->process(new ServerRequest(), $this->handler());
        self::assertSame(401, $resp->getStatusCode());
        self::assertSame('Basic realm="composer"', $resp->getHeaderLine('WWW-Authenticate'));
    }

    public function testReturns401OnWrongPassword(): void
    {
        $mw = new Auth('ci', 'secret');
        $resp = $mw->process($this->requestWithAuth('ci', 'nope'), $this->handler());
        self::assertSame(401, $resp->getStatusCode());
    }

    public function testReturns401OnWrongUser(): void
    {
        $mw = new Auth('ci', 'secret');
        $resp = $mw->process($this->requestWithAuth('attacker', 'secret'), $this->handler());
        self::assertSame(401, $resp->getStatusCode());
    }

    public function testCallsNextHandlerOnValidCredentials(): void
    {
        $mw = new Auth('ci', 'secret');
        $resp = $mw->process($this->requestWithAuth('ci', 'secret'), $this->handler('PASSED'));
        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('PASSED', (string) $resp->getBody());
    }

    public function testIgnoresMalformedAuthHeader(): void
    {
        $mw = new Auth('ci', 'secret');
        $r = (new ServerRequest())->withHeader('Authorization', 'Bearer xxx');
        $resp = $mw->process($r, $this->handler());
        self::assertSame(401, $resp->getStatusCode());
    }
}
