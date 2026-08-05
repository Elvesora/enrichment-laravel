<?php

declare(strict_types=1);

namespace Elvesora\Enrichment\Laravel\Tests;

use Elvesora\Enrichment\Laravel\EnrichmentServiceProvider;
use PHPUnit\Framework\TestCase;

final class ComposerMetadataTest extends TestCase {
    public function test_composer_metadata_declares_standalone_runtime_dependencies_and_package_discovery(): void {
        $contents = file_get_contents(dirname(__DIR__).'/composer.json');
        self::assertNotFalse($contents);

        $composer = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);

        self::assertSame('elvesora/enrichment-laravel', $composer['name'] ?? null);
        self::assertSame('https://www.elvesora.com/products/data-enrichment', $composer['homepage'] ?? null);
        self::assertArrayNotHasKey('repositories', $composer);
        self::assertArrayNotHasKey('elvesora/enrichment-php', $composer['require'] ?? []);
        self::assertSame('*', $composer['require']['ext-json'] ?? null);
        self::assertSame('*', $composer['require']['ext-mbstring'] ?? null);
        self::assertSame('^7.9 || ^8.0', $composer['require']['guzzlehttp/guzzle'] ?? null);
        self::assertSame('^12.0 || ^13.0', $composer['require']['illuminate/support'] ?? null);
        self::assertSame(
            ['Elvesora\\Enrichment\\Laravel\\' => 'src/'],
            $composer['autoload']['psr-4'] ?? null,
        );
        self::assertSame(
            [EnrichmentServiceProvider::class],
            $composer['extra']['laravel']['providers'] ?? null,
        );
        self::assertArrayNotHasKey('aliases', $composer['extra']['laravel'] ?? []);
    }
}
