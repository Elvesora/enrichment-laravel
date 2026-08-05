<?php

declare(strict_types=1);

namespace Elvesora\Enrichment\Laravel\Tests;

use Elvesora\Enrichment\Laravel\Client;
use Elvesora\Enrichment\Laravel\Contracts\EnrichmentClientInterface;
use Elvesora\Enrichment\Laravel\EnrichmentServiceProvider;
use LogicException;

final class EnrichmentServiceProviderTest extends TestCase {
    public function test_it_merges_defaults_without_resolving_credentials_during_boot(): void {
        self::assertSame(Client::DEFAULT_BASE_URL, config('enrichment.base_url'));
        self::assertSame(130.0, config('enrichment.timeout'));
        self::assertSame(10.0, config('enrichment.connect_timeout'));
        self::assertFalse($this->appInstance()->resolved(EnrichmentClientInterface::class));
    }

    public function test_it_resolves_one_configured_client_for_interface_and_concrete_types(): void {
        config()->set([
            'enrichment.api_key' => 'test-api-key',
            'enrichment.base_url' => 'https://enrichment.example/api/v1/',
            'enrichment.timeout' => 75,
            'enrichment.connect_timeout' => 6,
            'enrichment.user_agent' => 'Example-Laravel-App/1.0',
        ]);

        $client = $this->appInstance()->make(EnrichmentClientInterface::class);

        self::assertInstanceOf(Client::class, $client);
        self::assertSame($client, $this->appInstance()->make(EnrichmentClientInterface::class));
        self::assertSame($client, $this->appInstance()->make(Client::class));
        self::assertSame('https://enrichment.example/api/v1', $client->baseUrl());
        self::assertSame(75.0, $client->timeout());
        self::assertSame(6.0, $client->connectTimeout());
    }

    public function test_missing_api_key_fails_only_when_the_client_is_resolved(): void {
        config()->set('enrichment.api_key');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Set ELVESORA_ENRICHMENT_API_KEY');

        $this->appInstance()->make(EnrichmentClientInterface::class);
    }

    public function test_non_finite_numeric_configuration_is_rejected_when_the_client_is_resolved(): void {
        config()->set([
            'enrichment.api_key' => 'test-api-key',
            'enrichment.timeout' => '1e309',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('enrichment.timeout');

        $this->appInstance()->make(EnrichmentClientInterface::class);
    }

    public function test_it_registers_the_publishable_configuration(): void {
        $source = dirname(__DIR__).'/config/enrichment.php';
        $paths = EnrichmentServiceProvider::pathsToPublish(
            EnrichmentServiceProvider::class,
            'enrichment-config',
        );

        self::assertArrayHasKey($source, $paths);
        self::assertSame(config_path('enrichment.php'), $paths[$source]);
    }
}
