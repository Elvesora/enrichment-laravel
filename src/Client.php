<?php

declare(strict_types=1);

namespace Elvesora\Enrichment\Laravel;

use Elvesora\Enrichment\Laravel\Contracts\EnrichmentClientInterface;
use Elvesora\Enrichment\Laravel\Exception\ApiException;
use Elvesora\Enrichment\Laravel\Exception\AuthenticationException;
use Elvesora\Enrichment\Laravel\Exception\IdempotencyConflictException;
use Elvesora\Enrichment\Laravel\Exception\InvalidResponseException;
use Elvesora\Enrichment\Laravel\Exception\TransportException;
use Elvesora\Enrichment\Laravel\Exception\UsageLimitException;
use Elvesora\Enrichment\Laravel\Exception\ValidationException;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameterValue;

final class Client implements EnrichmentClientInterface {
    public const DEFAULT_BASE_URL = 'https://enrichment.elvesora.com/api/v1';

    public const ENRICHMENT_PATH = '/enrichment/company';

    private readonly SensitiveParameterValue $httpClient;

    private readonly SensitiveParameterValue $apiKey;

    private readonly string $baseUrl;

    private readonly float $timeout;

    private readonly float $connectTimeout;

    private readonly string $userAgent;

    public function __construct(
        #[\SensitiveParameter] string $apiKey,
        #[\SensitiveParameter] ?ClientInterface $httpClient = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        float $timeout = 130.0,
        float $connectTimeout = 10.0,
        string $userAgent = 'Elvesora-Enrichment-Laravel',
    ) {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new InvalidArgumentException('The Enrichment API key cannot be empty.');
        }

        $this->assertValidUtf8($apiKey, 'Enrichment API key');

        if (preg_match('/[\x00-\x1F\x7F]/', $apiKey) === 1) {
            throw new InvalidArgumentException('The Enrichment API key cannot contain control characters.');
        }

        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = parse_url($baseUrl);
        if (
            filter_var($baseUrl, FILTER_VALIDATE_URL) === false
            || !is_array($parts)
            || !is_string($parts['scheme'] ?? null)
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || !is_string($parts['host'] ?? null)
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new InvalidArgumentException('The Enrichment base URL must be a valid HTTP or HTTPS URL.');
        }

        if (!is_finite($timeout) || $timeout <= 0) {
            throw new InvalidArgumentException('The request timeout must be greater than zero.');
        }

        if (!is_finite($connectTimeout) || $connectTimeout <= 0) {
            throw new InvalidArgumentException('The connection timeout must be greater than zero.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $userAgent) === 1) {
            throw new InvalidArgumentException('The user agent cannot contain control characters.');
        }

        $userAgent = trim($userAgent);
        if ($userAgent === '') {
            throw new InvalidArgumentException('The user agent cannot be empty.');
        }

        $this->assertValidUtf8($userAgent, 'user agent');

        $this->apiKey = new SensitiveParameterValue($apiKey);
        $this->httpClient = new SensitiveParameterValue($httpClient ?? new HttpClient());
        $this->baseUrl = $baseUrl;
        $this->timeout = $timeout;
        $this->connectTimeout = min($connectTimeout, $timeout);
        $this->userAgent = $userAgent;
    }

    public function enrich(string $domain, ?string $idempotencyKey = null): EnrichmentResult {
        $domain = trim($domain);
        $this->assertLength($domain, 'domain', 255, false);
        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);
        /** @var string $apiKey */
        $apiKey = $this->apiKey->getValue();
        /** @var ClientInterface $httpClient */
        $httpClient = $this->httpClient->getValue();

        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$apiKey,
            'Content-Type' => 'application/json',
            'User-Agent' => $this->userAgent,
        ];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        try {
            $response = $httpClient->request('POST', $this->baseUrl.self::ENRICHMENT_PATH, [
                'allow_redirects' => false,
                'connect_timeout' => $this->connectTimeout,
                'headers' => $headers,
                'http_errors' => false,
                'json' => ['domain' => $domain],
                'timeout' => $this->timeout,
            ]);
        } catch (GuzzleException) {
            throw new TransportException(
                message: 'The Enrichment request could not be completed.',
                resultType: 'TRANSPORT_ERROR',
                retryable: true,
            );
        }

        $idempotencyStatus = $this->idempotencyStatus($response);
        $payload = $this->decode($response, $idempotencyStatus);
        $statusCode = $response->getStatusCode();

        if ($statusCode >= 200 && $statusCode < 300) {
            return EnrichmentResult::fromArray($payload, $statusCode, $idempotencyStatus);
        }

        $this->throwForErrorResponse($response, $payload, $idempotencyStatus);
    }

    public function baseUrl(): string {
        return $this->baseUrl;
    }

    public function timeout(): float {
        return $this->timeout;
    }

    public function connectTimeout(): float {
        return $this->connectTimeout;
    }

    /**
     * @return array<string, float|string>
     */
    public function __debugInfo(): array {
        /** @var ClientInterface $httpClient */
        $httpClient = $this->httpClient->getValue();

        return [
            'httpClient' => get_debug_type($httpClient),
            'apiKey' => '[REDACTED]',
            'baseUrl' => $this->baseUrl,
            'timeout' => $this->timeout,
            'connectTimeout' => $this->connectTimeout,
            'userAgent' => $this->userAgent,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response, ?string $idempotencyStatus): array {
        $contents = (string) $response->getBody();

        try {
            $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidResponseException(
                message: 'The Enrichment API returned malformed JSON.',
                statusCode: $response->getStatusCode(),
                resultType: 'INVALID_RESPONSE',
                retryable: $this->isRetryableStatus($response->getStatusCode()),
                retryAfter: $this->retryAfter($response, []),
                idempotencyStatus: $idempotencyStatus,
                previous: $exception,
            );
        }

        if (!is_array($payload) || array_is_list($payload)) {
            throw new InvalidResponseException(
                message: 'The Enrichment API returned an unexpected response body.',
                statusCode: $response->getStatusCode(),
                resultType: 'INVALID_RESPONSE',
                retryable: $this->isRetryableStatus($response->getStatusCode()),
                retryAfter: $this->retryAfter($response, []),
                idempotencyStatus: $idempotencyStatus,
            );
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return never
     */
    private function throwForErrorResponse(
        ResponseInterface $response,
        array $payload,
        ?string $idempotencyStatus,
    ): never {
        $statusCode = $response->getStatusCode();
        $message = is_string($payload['message'] ?? null) && trim($payload['message']) !== ''
            ? $payload['message']
            : 'The Enrichment API returned an error.';
        $resultType = is_string($payload['result_type'] ?? null) && trim($payload['result_type']) !== ''
            ? $payload['result_type']
            : null;
        $retryAfter = $this->retryAfter($response, $payload);

        if ($resultType === 'UNAUTHORIZED' || ($resultType === null && $statusCode === 401)) {
            throw new AuthenticationException(
                message: $message,
                statusCode: $statusCode,
                resultType: $resultType ?? 'UNAUTHORIZED',
                responseBody: $payload,
                idempotencyStatus: $idempotencyStatus,
            );
        }

        if ($resultType === 'IDEMPOTENCY_KEY_CONFLICT' || ($resultType === null && $statusCode === 409)) {
            throw new IdempotencyConflictException(
                message: $message,
                statusCode: $statusCode,
                resultType: $resultType ?? 'IDEMPOTENCY_KEY_CONFLICT',
                responseBody: $payload,
                idempotencyStatus: $idempotencyStatus,
            );
        }

        if ($resultType === 'VALIDATION_ERROR' || ($resultType === null && $statusCode === 422)) {
            throw new ValidationException(
                message: $message,
                statusCode: $statusCode,
                resultType: $resultType ?? 'VALIDATION_ERROR',
                responseBody: $payload,
                idempotencyStatus: $idempotencyStatus,
            );
        }

        if ($resultType === 'LIMIT_EXCEEDED' || ($resultType === null && $statusCode === 429)) {
            throw new UsageLimitException(
                message: $message,
                statusCode: $statusCode,
                resultType: $resultType ?? 'LIMIT_EXCEEDED',
                retryAfter: $retryAfter,
                responseBody: $payload,
                idempotencyStatus: $idempotencyStatus,
            );
        }

        throw new ApiException(
            message: $message,
            statusCode: $statusCode,
            resultType: $resultType ?? 'API_ERROR',
            retryable: $this->isRetryableStatus($statusCode),
            retryAfter: $retryAfter,
            responseBody: $payload,
            idempotencyStatus: $idempotencyStatus,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function retryAfter(ResponseInterface $response, array $payload): ?int {
        foreach ([$payload['retry_after'] ?? null, $response->getHeaderLine('Retry-After')] as $retryAfter) {
            if (!is_numeric($retryAfter)) {
                continue;
            }

            $seconds = (float) $retryAfter;
            if (!is_finite($seconds)) {
                continue;
            }

            return max(1, (int) $seconds);
        }

        return null;
    }

    private function idempotencyStatus(ResponseInterface $response): ?string {
        $status = trim($response->getHeaderLine('Idempotency-Status'));
        if ($status === '') {
            return null;
        }

        if (!in_array($status, ['replayed', 'conflict'], true)) {
            throw new InvalidResponseException(
                message: 'The Enrichment API returned an invalid Idempotency-Status header.',
                statusCode: $response->getStatusCode(),
                resultType: 'INVALID_RESPONSE',
                retryable: $this->isRetryableStatus($response->getStatusCode()),
                retryAfter: $this->retryAfter($response, []),
            );
        }

        return $status;
    }

    private function isRetryableStatus(int $statusCode): bool {
        return $statusCode >= 500 || in_array($statusCode, [408, 425, 429], true);
    }

    private function normalizeIdempotencyKey(?string $idempotencyKey): ?string {
        if ($idempotencyKey === null) {
            return null;
        }

        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            return null;
        }

        $this->assertLength($idempotencyKey, 'idempotency key', 255, false);
        if (preg_match('/[\x00-\x1F\x7F]/', $idempotencyKey) === 1) {
            throw new InvalidArgumentException('The idempotency key cannot contain control characters.');
        }

        return $idempotencyKey;
    }

    private function assertLength(string $value, string $label, int $maximum, bool $allowEmpty): void {
        if (!$allowEmpty && $value === '') {
            throw new InvalidArgumentException(sprintf('The %s cannot be empty.', $label));
        }

        $this->assertValidUtf8($value, $label);

        if (mb_strlen($value, 'UTF-8') > $maximum) {
            throw new InvalidArgumentException(sprintf('The %s cannot exceed %d characters.', $label, $maximum));
        }
    }

    private function assertValidUtf8(string $value, string $label): void {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException(sprintf('The %s must be valid UTF-8.', $label));
        }
    }
}
