<?php

declare(strict_types=1);

namespace Elvesora\Enrichment\Laravel\Tests;

use ArrayObject;
use Elvesora\Enrichment\Laravel\Client;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

final class LaravelDebugSafetyTest extends TestCase {
    public function test_symfony_debug_output_redacts_the_api_key(): void {
        $apiKey = 'symfony-debug-secret-api-key';
        $history = new ArrayObject();
        $middlewareHistory = $history;
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'result_type' => 'ENRICHED',
                'message' => 'Company enrichment completed successfully',
                'data' => [
                    'company_name' => 'Acme Corporation',
                    'domain' => 'acme.com',
                ],
                'credits' => [
                    'limit' => 100,
                    'used' => 1,
                    'remaining' => 99,
                    'consumed_by_request' => 1,
                    'period_started_at' => null,
                    'period_ends_at' => null,
                ],
                'remaining' => 99,
                'limit' => 100,
            ], JSON_THROW_ON_ERROR)),
        ]));
        $stack->push(Middleware::history($middlewareHistory));
        $client = new Client(
            $apiKey,
            httpClient: new HttpClient(['handler' => $stack]),
            baseUrl: 'https://example.test/api/v1',
        );

        $client->enrich('acme.com');
        $debugOutput = (new CliDumper())->dump((new VarCloner())->cloneVar($client), true);

        self::assertCount(1, $history);
        self::assertIsString($debugOutput);
        self::assertStringNotContainsString($apiKey, $debugOutput);
        self::assertStringContainsString('SensitiveParameterValue', $debugOutput);
    }
}
