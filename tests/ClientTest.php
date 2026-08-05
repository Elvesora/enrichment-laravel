<?php

declare(strict_types=1);

namespace Elvesora\Enrichment\Laravel\Tests;

use ArrayObject;
use Elvesora\Enrichment\Laravel\Client;
use Elvesora\Enrichment\Laravel\Exception\ApiException;
use Elvesora\Enrichment\Laravel\Exception\AuthenticationException;
use Elvesora\Enrichment\Laravel\Exception\IdempotencyConflictException;
use Elvesora\Enrichment\Laravel\Exception\InvalidResponseException;
use Elvesora\Enrichment\Laravel\Exception\TransportException;
use Elvesora\Enrichment\Laravel\Exception\UsageLimitException;
use Elvesora\Enrichment\Laravel\Exception\ValidationException;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class ClientTest extends TestCase {
    public function test_it_sends_the_public_api_contract_and_maps_an_enriched_result(): void {
        $history = new HistoryContainer();
        $client = $this->client([
            $this->jsonResponse(200, $this->enrichedResponse(), [
                'Idempotency-Status' => 'replayed',
            ]),
        ], $history);

        $result = $client->enrich('  acme.com  ', '  import-row-42  ');

        self::assertTrue($result->success);
        self::assertTrue($result->isEnriched());
        self::assertSame('ENRICHED', $result->resultType);
        self::assertSame('acme.com', $result->domain);
        self::assertSame(200, $result->statusCode);
        self::assertNull($result->responseCode);
        self::assertSame('replayed', $result->idempotencyStatus);
        self::assertTrue($result->wasReplayed());
        self::assertSame(100, $result->credits->limit);
        self::assertSame(6, $result->credits->used);
        self::assertSame(94, $result->credits->remaining);
        self::assertSame(0, $result->credits->consumedByRequest);
        self::assertSame('2026-06-01', $result->credits->periodStartedAt);
        self::assertSame($this->enrichedResponse(), $result->toArray());
        self::assertSame(
            json_encode($this->enrichedResponse(), JSON_THROW_ON_ERROR),
            json_encode($result, JSON_THROW_ON_ERROR),
        );

        self::assertCount(1, $history);
        $entry = $history->entry(0);
        $request = $entry['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://example.test/api/v1/enrichment/company', (string) $request->getUri());
        self::assertSame('Bearer secret-api-key', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('import-row-42', $request->getHeaderLine('Idempotency-Key'));
        self::assertSame('Elvesora-Enrichment-Laravel', $request->getHeaderLine('User-Agent'));
        self::assertSame('', $request->getHeaderLine('X-Request-Id'));
        self::assertSame(
            ['domain' => 'acme.com'],
            json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertSame(12.0, $entry['options']['timeout']);
        self::assertSame(3.0, $entry['options']['connect_timeout']);
        self::assertFalse($entry['options']['http_errors']);
    }

    public function test_it_omits_an_empty_optional_idempotency_key(): void {
        $history = new HistoryContainer();
        $client = $this->client([$this->jsonResponse(200, $this->enrichedResponse())], $history);

        $result = $client->enrich('acme.com', '   ');

        self::assertNull($result->idempotencyStatus);
        self::assertSame('', $history->entry(0)['request']->getHeaderLine('Idempotency-Key'));
    }

    public function test_http_400_business_outcomes_are_api_exceptions_with_contract_metadata(): void {
        $history = new HistoryContainer();
        $payload = [
            'success' => false,
            'result_type' => 'NOT_FOUND',
            'message' => 'No company was found for this domain.',
            'credits' => $this->credits(consumedByRequest: 0),
            'remaining' => 94,
            'limit' => 100,
        ];
        $client = $this->client([$this->jsonResponse(400, $payload)], $history);

        try {
            $client->enrich('unknown.example');
            self::fail('Expected an API exception.');
        } catch (ApiException $exception) {
            self::assertSame(400, $exception->statusCode);
            self::assertSame('NOT_FOUND', $exception->resultType);
            self::assertFalse($exception->retryable);
            self::assertSame(0, $exception->credits?->consumedByRequest);
            self::assertSame($payload, $exception->responseBody);
        }
    }

    public function test_it_throws_a_typed_authentication_exception(): void {
        $history = new HistoryContainer();
        $client = $this->client([
            $this->jsonResponse(401, [
                'success' => false,
                'result_type' => 'UNAUTHORIZED',
                'message' => 'Unauthorized',
            ]),
        ], $history);

        try {
            $client->enrich('acme.com');
            self::fail('Expected an authentication exception.');
        } catch (AuthenticationException $exception) {
            self::assertSame(401, $exception->statusCode);
            self::assertSame('UNAUTHORIZED', $exception->resultType);
            self::assertFalse($exception->retryable);
        }
    }

    public function test_a_bare_401_response_uses_the_status_fallback(): void {
        $history = new HistoryContainer();
        $client = $this->client([
            $this->jsonResponse(401, ['message' => 'Unauthorized']),
        ], $history);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Unauthorized');

        $client->enrich('acme.com');
    }

    public function test_it_exposes_server_validation_errors(): void {
        $history = new HistoryContainer();
        $client = $this->client([
            $this->jsonResponse(422, [
                'success' => false,
                'result_type' => 'VALIDATION_ERROR',
                'message' => 'Validation failed.',
                'errors' => ['domain' => ['The domain field is required.']],
            ]),
        ], $history);

        try {
            $client->enrich('acme.com');
            self::fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            self::assertSame(422, $exception->statusCode);
            self::assertSame('VALIDATION_ERROR', $exception->resultType);
            self::assertSame(['domain' => ['The domain field is required.']], $exception->errors());
            self::assertFalse($exception->retryable);
        }
    }

    public function test_it_exposes_usage_limit_metadata(): void {
        $history = new HistoryContainer();
        $client = $this->client([
            $this->jsonResponse(429, [
                'success' => false,
                'result_type' => 'LIMIT_EXCEEDED',
                'message' => 'Request limit exceeded. Please upgrade your plan.',
                'retry_after' => 'not-a-number',
                'credits' => $this->credits(used: 100, remaining: 0, consumedByRequest: 0),
                'remaining' => 0,
                'limit' => 100,
            ], ['Retry-After' => '120']),
        ], $history);

        try {
            $client->enrich('acme.com');
            self::fail('Expected a usage limit exception.');
        } catch (UsageLimitException $exception) {
            self::assertSame(100, $exception->limit());
            self::assertSame(0, $exception->remaining());
            self::assertSame(120, $exception->retryAfter);
            self::assertFalse($exception->retryable);
        }
    }

    public function test_it_exposes_idempotency_conflicts_and_replay_metadata(): void {
        $history = new HistoryContainer();
        $client = $this->client([
            $this->jsonResponse(409, [
                'success' => false,
                'result_type' => 'IDEMPOTENCY_KEY_CONFLICT',
                'message' => 'This Idempotency-Key was already used with a different domain.',
                'credits' => $this->credits(consumedByRequest: 0),
                'remaining' => 94,
                'limit' => 100,
            ], ['Idempotency-Status' => 'conflict']),
        ], $history);

        try {
            $client->enrich('different.example', 'import-row-42');
            self::fail('Expected an idempotency conflict exception.');
        } catch (IdempotencyConflictException $exception) {
            self::assertSame(409, $exception->statusCode);
            self::assertSame('IDEMPOTENCY_KEY_CONFLICT', $exception->resultType);
            self::assertSame('conflict', $exception->idempotencyStatus);
            self::assertSame(0, $exception->credits?->consumedByRequest);
        }
    }

    public function test_result_type_takes_precedence_over_preserved_upstream_statuses(): void {
        foreach ([401, 409, 422, 429] as $statusCode) {
            $history = new HistoryContainer();
            $client = $this->client([
                $this->jsonResponse($statusCode, [
                    'success' => false,
                    'result_type' => 'UPSTREAM_ERROR',
                    'message' => 'Enrichment request failed',
                    'response_code' => $statusCode,
                    'credits' => $this->credits(consumedByRequest: 0),
                    'remaining' => 94,
                    'limit' => 100,
                ], $statusCode === 429 ? ['Retry-After' => '45'] : []),
            ], $history);

            try {
                $client->enrich('acme.com');
                self::fail('Expected an API exception.');
            } catch (ApiException $exception) {
                self::assertSame($statusCode, $exception->statusCode);
                self::assertSame('UPSTREAM_ERROR', $exception->resultType);
                self::assertSame($statusCode === 429, $exception->retryable);
                self::assertSame($statusCode === 429 ? 45 : null, $exception->retryAfter);
            }
        }
    }

    public function test_server_failures_are_retryable_but_are_not_retried_automatically(): void {
        $history = new HistoryContainer();
        $client = $this->client([
            $this->jsonResponse(502, [
                'success' => false,
                'result_type' => 'UPSTREAM_ERROR',
                'message' => 'Enrichment request failed',
                'response_code' => 502,
                'credits' => $this->credits(consumedByRequest: 0),
                'remaining' => 94,
                'limit' => 100,
            ]),
            $this->jsonResponse(200, $this->enrichedResponse()),
        ], $history);

        try {
            $client->enrich('acme.com');
            self::fail('Expected an API exception.');
        } catch (ApiException $exception) {
            self::assertSame(502, $exception->statusCode);
            self::assertSame('UPSTREAM_ERROR', $exception->resultType);
            self::assertTrue($exception->retryable);
            self::assertCount(1, $history);
        }
    }

    public function test_it_does_not_follow_redirects_away_from_the_configured_api_origin(): void {
        $history = new HistoryContainer();
        $client = $this->client([
            $this->jsonResponse(307, [
                'success' => false,
                'result_type' => 'REDIRECT_RESPONSE',
                'message' => 'Unexpected redirect.',
            ], ['Location' => 'https://third-party.test/collect']),
            $this->jsonResponse(200, $this->enrichedResponse()),
        ], $history);

        try {
            $client->enrich('customer.example');
            self::fail('Expected the redirect response to be surfaced.');
        } catch (ApiException $exception) {
            self::assertSame(307, $exception->statusCode);
            self::assertSame('REDIRECT_RESPONSE', $exception->resultType);
            self::assertFalse($exception->retryable);
        }

        self::assertCount(1, $history);
        self::assertFalse($history->entry(0)['options']['allow_redirects']);
        self::assertSame(
            'https://example.test/api/v1/enrichment/company',
            (string) $history->entry(0)['request']->getUri(),
        );
    }

    public function test_it_rejects_malformed_json_and_invalid_success_shapes(): void {
        $history = new HistoryContainer();
        $invalidCredits = $this->enrichedResponse();
        $invalidCredits['credits']['remaining'] = '94';
        $client = $this->client([
            new Response(200, [], '{not-json'),
            $this->jsonResponse(200, $invalidCredits),
        ], $history);

        try {
            $client->enrich('acme.com');
            self::fail('Expected malformed JSON to fail.');
        } catch (InvalidResponseException $exception) {
            self::assertSame('INVALID_RESPONSE', $exception->resultType);
        }

        $this->expectException(InvalidResponseException::class);
        $client->enrich('acme.com');
    }

    public function test_malformed_gateway_responses_preserve_retry_guidance(): void {
        foreach ([408, 425, 429, 502] as $statusCode) {
            $history = new HistoryContainer();
            $client = $this->client([
                new Response($statusCode, ['Retry-After' => '60'], '<html>gateway error</html>'),
            ], $history);

            try {
                $client->enrich('acme.com');
                self::fail('Expected an invalid response exception.');
            } catch (InvalidResponseException $exception) {
                self::assertSame($statusCode, $exception->statusCode);
                self::assertTrue($exception->retryable);
                self::assertSame(60, $exception->retryAfter);
            }
        }
    }

    public function test_it_rejects_non_enriched_success_types_and_impossible_credit_dates(): void {
        $history = new HistoryContainer();
        $wrongResultType = $this->enrichedResponse();
        $wrongResultType['result_type'] = 'NOT_FOUND';
        $invalidDate = $this->enrichedResponse();
        $invalidDate['credits']['period_started_at'] = '2026-02-30';
        $client = $this->client([
            $this->jsonResponse(200, $wrongResultType),
            $this->jsonResponse(200, $invalidDate),
        ], $history);

        try {
            $client->enrich('acme.com');
            self::fail('Expected a non-ENRICHED success type to fail.');
        } catch (InvalidResponseException $exception) {
            self::assertStringContainsString('result_type=ENRICHED', $exception->getMessage());
        }

        try {
            $client->enrich('acme.com');
            self::fail('Expected an impossible credit date to fail.');
        } catch (InvalidResponseException $exception) {
            self::assertStringContainsString('YYYY-MM-DD', $exception->getMessage());
        }
    }

    public function test_it_rejects_an_invalid_idempotency_status_header(): void {
        $history = new HistoryContainer();
        $client = $this->client([
            $this->jsonResponse(200, $this->enrichedResponse(), ['Idempotency-Status' => 'new']),
        ], $history);

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessage('Idempotency-Status');

        $client->enrich('acme.com');
    }

    public function test_it_rejects_a_conflict_idempotency_status_on_a_successful_response(): void {
        $history = new HistoryContainer();
        $client = $this->client([
            $this->jsonResponse(200, $this->enrichedResponse(), ['Idempotency-Status' => 'conflict']),
        ], $history);

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessage('conflicting idempotency status');

        $client->enrich('acme.com');
    }

    public function test_it_wraps_transport_failures_without_exposing_the_api_key(): void {
        $history = new HistoryContainer();
        $request = new Request('POST', 'https://example.test/api/v1/enrichment/company', [
            'Authorization' => 'Bearer secret-api-key',
        ]);
        $client = $this->client([new ConnectException('Connection failed', $request)], $history);

        try {
            $client->enrich('acme.com');
            self::fail('Expected a transport exception.');
        } catch (TransportException $exception) {
            self::assertTrue($exception->retryable);
            self::assertSame('TRANSPORT_ERROR', $exception->resultType);
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('secret-api-key', $exception->getMessage());
        }
    }

    public function test_it_validates_configuration_before_sending_a_request(): void {
        $this->expectException(InvalidArgumentException::class);

        new Client('');
    }

    public function test_it_rejects_header_unsafe_api_keys_without_exposing_them(): void {
        $apiKey = "safe-prefix\r\nsecret-suffix";
        $apiKeyParameter = new \ReflectionParameter([Client::class, '__construct'], 'apiKey');
        $httpClientParameter = new \ReflectionParameter([Client::class, '__construct'], 'httpClient');

        self::assertCount(1, $apiKeyParameter->getAttributes(\SensitiveParameter::class));
        self::assertCount(1, $httpClientParameter->getAttributes(\SensitiveParameter::class));

        try {
            new Client($apiKey);
            self::fail('Expected a header-unsafe API key to fail.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString($apiKey, $exception->getMessage());
            self::assertStringNotContainsString('secret-suffix', $exception->getMessage());
        }
    }

    public function test_debug_output_redacts_the_api_key(): void {
        $apiKey = 'debug-secret-api-key';
        $client = new Client($apiKey);

        ob_start();
        var_dump($client);
        $debugOutput = ob_get_clean();

        self::assertIsString($debugOutput);
        self::assertStringNotContainsString($apiKey, $debugOutput);
        self::assertStringContainsString('[REDACTED]', $debugOutput);
    }

    public function test_it_rejects_non_utf8_header_values(): void {
        foreach ([
            static fn () => new Client(chr(255)),
            static fn () => new Client('secret-api-key', userAgent: chr(255)),
        ] as $operation) {
            try {
                $operation();
                self::fail('Expected an invalid UTF-8 header value to fail.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_it_rejects_header_unsafe_user_agents_before_the_api_key_enters_a_request_trace(): void {
        $apiKey = 'trace-secret-api-key';

        try {
            new Client($apiKey, userAgent: "Example-App/1.0\r\nInjected: value");
            self::fail('Expected a header-unsafe user agent to fail.');
        } catch (InvalidArgumentException $exception) {
            $trace = print_r($exception->getTrace(), true);

            self::assertStringNotContainsString($apiKey, $exception->getMessage());
            self::assertStringNotContainsString($apiKey, $trace);
        }
    }

    public function test_it_rejects_non_finite_timeouts(): void {
        foreach ([
            static fn () => new Client('secret-api-key', timeout: INF),
            static fn () => new Client('secret-api-key', timeout: NAN),
            static fn () => new Client('secret-api-key', connectTimeout: INF),
            static fn () => new Client('secret-api-key', connectTimeout: NAN),
        ] as $operation) {
            try {
                $operation();
                self::fail('Expected a non-finite timeout to fail.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_it_rejects_base_urls_that_cannot_be_safely_extended_with_api_paths(): void {
        foreach ([
            'https://example.test/api?tenant=one',
            'https://example.test/api#fragment',
            'https://user:password@example.test/api',
        ] as $baseUrl) {
            try {
                new Client('secret-api-key', baseUrl: $baseUrl);
                self::fail(sprintf('Expected base URL "%s" to be rejected.', $baseUrl));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_it_accepts_case_insensitive_http_schemes(): void {
        $client = new Client('secret-api-key', baseUrl: 'HTTPS://example.test/api/v1/');

        self::assertSame('HTTPS://example.test/api/v1', $client->baseUrl());
    }

    public function test_input_limits_count_unicode_characters_and_reject_bad_headers_locally(): void {
        $history = new HistoryContainer();
        $client = $this->client([$this->jsonResponse(200, $this->enrichedResponse())], $history);
        $domain = str_repeat("\u{00E9}", 255);

        $client->enrich($domain);

        self::assertSame(
            $domain,
            json_decode(
                (string) $history->entry(0)['request']->getBody(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            )['domain'],
        );

        foreach ([
            static fn () => $client->enrich('   '),
            static fn () => $client->enrich(str_repeat("\u{00E9}", 256)),
            static fn () => $client->enrich(chr(255)),
            static fn () => $client->enrich('acme.com', str_repeat('a', 256)),
            static fn () => $client->enrich('acme.com', chr(255)),
            static fn () => $client->enrich('acme.com', "unsafe\r\nkey"),
        ] as $operation) {
            try {
                $operation();
                self::fail('Expected invalid input to fail.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertCount(1, $history);
    }

    /**
     * @param list<Response|\Throwable> $responses
     */
    private function client(array $responses, HistoryContainer $history): Client {
        $mock = new MockHandler($responses);
        $handler = static function (RequestInterface $request, array $options) use ($mock, $history): PromiseInterface {
            $history[] = [
                'request' => $request,
                'options' => $options,
            ];

            return $mock($request, $options);
        };
        $stack = HandlerStack::create($handler);

        return new Client(
            apiKey: 'secret-api-key',
            httpClient: new HttpClient(['handler' => $stack]),
            baseUrl: 'https://example.test/api/v1/',
            timeout: 12,
            connectTimeout: 3,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    private function jsonResponse(int $status, array $data, array $headers = []): Response {
        return new Response(
            $status,
            array_merge(['Content-Type' => 'application/json'], $headers),
            json_encode($data, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function enrichedResponse(): array {
        return [
            'success' => true,
            'result_type' => 'ENRICHED',
            'message' => 'Company enrichment completed successfully',
            'data' => [
                'company_name' => 'Acme Corporation',
                'domain' => 'acme.com',
            ],
            'credits' => $this->credits(consumedByRequest: 0),
            'remaining' => 94,
            'limit' => 100,
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function credits(
        int $used = 6,
        int $remaining = 94,
        int $consumedByRequest = 1,
    ): array {
        return [
            'limit' => 100,
            'used' => $used,
            'remaining' => $remaining,
            'consumed_by_request' => $consumedByRequest,
            'period_started_at' => '2026-06-01',
            'period_ends_at' => '2026-07-01',
        ];
    }
}

/**
 * @extends ArrayObject<int, array{request: RequestInterface, options: array<mixed>}>
 */
final class HistoryContainer extends ArrayObject {
    /**
     * @return array{request: RequestInterface, options: array<mixed>}
     */
    public function entry(int $index): array {
        if (!$this->offsetExists($index)) {
            throw new OutOfBoundsException(sprintf('History entry %d does not exist.', $index));
        }

        $entry = $this->offsetGet($index);
        if ($entry === null) {
            throw new OutOfBoundsException(sprintf('History entry %d is empty.', $index));
        }

        return $entry;
    }
}
