<?php
declare(strict_types=1);

namespace Composerd;

use Laminas\Diactoros\Response;
use Laminas\Diactoros\Stream;
use League\Flysystem\Filesystem;
use League\Flysystem\UnableToReadFile;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class Controller
{
    public function __construct(
        private Filesystem $fs,
        private Catalog $catalog,
        private ZipMetadata $zipMetadata,
        private PackagesJson $packagesJson,
        private Cache $cache,
        private string $baseUrl,
    ) {}

    public function packages(ServerRequestInterface $request): ResponseInterface
    {
        // Step 0: TTL shortcut.
        $cached = $this->cache->readIfFresh();
        if ($cached !== null) {
            return $this->jsonResponse(200, $cached);
        }

        // Step 1: list + hash.
        [$entries, $hash] = $this->catalog->scan($this->fs);

        // Step 2: hash match.
        $cached = $this->cache->readIfHashMatches($hash);
        if ($cached !== null) {
            return $this->jsonResponse(200, $cached);
        }

        // Step 3: rebuild.
        $json = $this->cache->rebuild($hash, function () use ($entries) {
            return $this->packagesJson->build(
                $entries,
                fn(CatalogEntry $e) => $this->zipMetadata->read($this->fs, $e->path),
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
            $stream = $this->fs->readStream($path);
        } catch (UnableToReadFile) {
            return (new Response())->withStatus(404);
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
        [$entries, $hash] = $this->catalog->scan($this->fs);

        $result = null;
        $this->cache->rebuild($hash, function () use ($entries, &$result) {
            $result = $this->packagesJson->build(
                $entries,
                fn(CatalogEntry $e) => $this->zipMetadata->read($this->fs, $e->path),
                $this->baseUrl,
            );
            return $result->json;
        });

        $summary = [
            'packages' => $result?->packagesCount ?? 0,
            'versions' => $result?->versionsCount ?? 0,
            'skipped' => $result?->skippedCount ?? 0,
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
}
