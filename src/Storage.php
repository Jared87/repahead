<?php
declare(strict_types=1);

namespace Composerd;

use Aws\S3\S3Client;
use InvalidArgumentException;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

final class Storage
{
    /** @param array<string,string> $awsEnv */
    public function __construct(private array $awsEnv) {}

    public function make(string $dsn): Filesystem
    {
        $colon = strpos($dsn, ':');
        if ($colon === false) {
            throw new InvalidArgumentException("Malformed STORAGE_DSN: $dsn");
        }
        $scheme = substr($dsn, 0, $colon);
        $rest = substr($dsn, $colon + 1);

        return match ($scheme) {
            'local' => new Filesystem(new LocalFilesystemAdapter($rest)),
            's3' => $this->makeS3($rest),
            default => throw new InvalidArgumentException("Unknown DSN scheme: $scheme"),
        };
    }

    private function makeS3(string $rest): Filesystem
    {
        $slash = strpos($rest, '/');
        $bucket = $slash === false ? $rest : substr($rest, 0, $slash);
        $prefix = $slash === false ? '' : substr($rest, $slash + 1);

        foreach (['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_REGION'] as $required) {
            if (empty($this->awsEnv[$required])) {
                throw new InvalidArgumentException("S3 storage requires env var: $required");
            }
        }

        $client = new S3Client([
            'version' => 'latest',
            'region' => $this->awsEnv['AWS_REGION'],
            'credentials' => [
                'key' => $this->awsEnv['AWS_ACCESS_KEY_ID'],
                'secret' => $this->awsEnv['AWS_SECRET_ACCESS_KEY'],
            ],
        ]);

        return new Filesystem(new AwsS3V3Adapter($client, $bucket, $prefix));
    }
}
