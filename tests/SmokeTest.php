<?php

declare(strict_types=1);

namespace RepAhead\Tests;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testAutoloaderIsWired(): void
    {
        self::assertTrue(class_exists(\PHPUnit\Framework\TestCase::class));
        self::assertSame('RepAhead\\Tests', __NAMESPACE__);
    }
}
