<?php

declare(strict_types=1);

namespace RepAhead;

use Laminas\Diactoros\Response;
use Laminas\Diactoros\Stream;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class Controller
{
    public function __construct(
        private Filesystem $fs,
        private Catalog $catalog,
        private ZipMetadata $zipMetadata,
        private PackagesJson $packagesJson,
        private Cache $cache,
        private string $baseUrl,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function packages(ServerRequestInterface $request): ResponseInterface
    {
        $cached = $this->cache->readIfFresh();
        if ($cached !== null) {
            return $this->jsonResponse(200, $cached);
        }

        try {
            [$entries, $hash] = $this->catalog->scan($this->fs);
        } catch (FilesystemException $e) {
            $this->logger->error('Storage listing failed', ['error' => $e->getMessage()]);
            return $this->errorResponse(503, 'storage_unavailable');
        }

        $cached = $this->cache->readIfHashMatches($hash);
        if ($cached !== null) {
            return $this->jsonResponse(200, $cached);
        }

        $json = $this->cache->rebuild($hash, function () use ($entries) {
            return $this->packagesJson->build(
                $entries,
                fn (CatalogEntry $e): ?\RepAhead\ZipMeta => $this->zipMetadata->read($this->fs, $e->path),
                $this->baseUrl,
            )->json;
        });

        return $this->jsonResponse(200, $json);
    }

    /** @param array{vendor: string, package: string, version: string} $args */
    public function dist(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $path = "{$args['vendor']}/{$args['package']}/{$args['version']}.zip";

        try {
            $exists = $this->fs->fileExists($path);
        } catch (FilesystemException $e) {
            $this->logger->error('Failed to check ZIP existence', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse(502, 'storage_unavailable');
        }
        if (!$exists) {
            return (new Response())->withStatus(404);
        }

        try {
            $stream = $this->fs->readStream($path);
        } catch (FilesystemException $e) {
            $this->logger->error('Failed to stream ZIP', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse(502, 'storage_unavailable');
        }

        $body = new Stream($stream);
        return (new Response($body))
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="' . basename($path) . '"');
    }

    public function rebuild(ServerRequestInterface $request): ResponseInterface
    {
        $start = microtime(true);
        $this->cache->invalidate();

        try {
            [$entries, $hash] = $this->catalog->scan($this->fs);
        } catch (FilesystemException $e) {
            $this->logger->error('Storage listing failed during rebuild', ['error' => $e->getMessage()]);
            return $this->errorResponse(503, 'storage_unavailable');
        }

        $result = null;
        $this->cache->rebuild($hash, function () use ($entries, &$result) {
            $result = $this->packagesJson->build(
                $entries,
                fn (CatalogEntry $e): ?\RepAhead\ZipMeta => $this->zipMetadata->read($this->fs, $e->path),
                $this->baseUrl,
            );
            return $result->json;
        });

        // invalidate() above guarantees Cache::rebuild calls the closure, so $result is set.
        \assert($result instanceof \RepAhead\PackagesJsonResult);
        $summary = [
            'packages' => $result->packagesCount,
            'versions' => $result->versionsCount,
            'skipped' => $result->skippedCount,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
        ];
        return $this->jsonResponse(200, (string) json_encode($summary, JSON_UNESCAPED_SLASHES));
    }

    private function jsonResponse(int $status, string $body): ResponseInterface
    {
        $resp = new Response();
        $resp->getBody()->write($body);
        return $resp
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }

    private function errorResponse(int $status, string $errorCode): ResponseInterface
    {
        return $this->jsonResponse(
            $status,
            (string) json_encode(['error' => $errorCode], JSON_UNESCAPED_SLASHES)
        );
    }
}
