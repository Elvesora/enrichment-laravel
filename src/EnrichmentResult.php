<?php

declare(strict_types=1);

namespace Elvesora\Enrichment\Laravel;

use Elvesora\Enrichment\Laravel\Exception\InvalidResponseException;
use JsonSerializable;
use UnexpectedValueException;

final class EnrichmentResult implements JsonSerializable {
    public const ENRICHED = 'ENRICHED';

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $raw
     */
    private function __construct(
        public readonly bool $success,
        public readonly string $resultType,
        public readonly string $message,
        public readonly array $data,
        public readonly ?string $domain,
        public readonly CreditMetadata $credits,
        public readonly int $statusCode,
        public readonly ?int $responseCode,
        public readonly ?string $idempotencyStatus,
        private readonly array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(
        array $payload,
        int $statusCode,
        ?string $idempotencyStatus = null,
    ): self {
        $success = $payload['success'] ?? null;
        if (!is_bool($success)) {
            throw self::invalid('The response field "success" must be a boolean.', $statusCode, $payload, $idempotencyStatus);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw self::invalid('An enrichment result must come from a successful HTTP response.', $statusCode, $payload, $idempotencyStatus);
        }

        if (!$success) {
            throw self::invalid('A successful HTTP response must contain success=true.', $statusCode, $payload, $idempotencyStatus);
        }

        $resultType = self::requiredString($payload, 'result_type', $statusCode, $idempotencyStatus);
        if ($resultType !== self::ENRICHED) {
            throw self::invalid('A successful enrichment response must contain result_type=ENRICHED.', $statusCode, $payload, $idempotencyStatus);
        }

        $message = self::requiredString($payload, 'message', $statusCode, $idempotencyStatus);

        $data = $payload['data'] ?? [];
        if (!is_array($data) || (array_is_list($data) && $data !== [])) {
            throw self::invalid('The response field "data" must be an object when present.', $statusCode, $payload, $idempotencyStatus);
        }

        /** @var array<string, mixed> $data */
        $domain = $data['domain'] ?? null;
        if ($domain !== null && (!is_string($domain) || trim($domain) === '')) {
            throw self::invalid('The response field "data.domain" must be a non-empty string or null.', $statusCode, $payload, $idempotencyStatus);
        }

        $creditPayload = $payload['credits'] ?? null;
        if (!is_array($creditPayload) || (array_is_list($creditPayload) && $creditPayload !== [])) {
            throw self::invalid('A successful enrichment response must contain a credit metadata object.', $statusCode, $payload, $idempotencyStatus);
        }

        try {
            /** @var array<string, mixed> $creditPayload */
            $credits = CreditMetadata::fromArray($creditPayload);
        } catch (UnexpectedValueException $exception) {
            throw self::invalid($exception->getMessage(), $statusCode, $payload, $idempotencyStatus, $exception);
        }

        self::validateLegacyCreditAliases($payload, $credits, $statusCode, $idempotencyStatus);

        $responseCode = $payload['response_code'] ?? null;
        if ($responseCode !== null && !is_int($responseCode)) {
            throw self::invalid('The response field "response_code" must be an integer or null.', $statusCode, $payload, $idempotencyStatus);
        }

        $idempotencyStatus = self::normalizedIdempotencyStatus($idempotencyStatus, $statusCode, $payload);
        if ($idempotencyStatus === 'conflict') {
            throw self::invalid(
                'A successful enrichment result cannot have a conflicting idempotency status.',
                $statusCode,
                $payload,
                $idempotencyStatus,
            );
        }

        return new self(
            success: $success,
            resultType: $resultType,
            message: $message,
            data: $data,
            domain: $domain,
            credits: $credits,
            statusCode: $statusCode,
            responseCode: $responseCode,
            idempotencyStatus: $idempotencyStatus,
            raw: $payload,
        );
    }

    public function isEnriched(): bool {
        return $this->success && $this->resultType === self::ENRICHED;
    }

    public function wasReplayed(): bool {
        return $this->idempotencyStatus === 'replayed';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array {
        return $this->raw;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array {
        return $this->toArray();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function requiredString(
        array $payload,
        string $key,
        int $statusCode,
        ?string $idempotencyStatus,
    ): string {
        $value = $payload[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw self::invalid(
                sprintf('The response field "%s" must be a non-empty string.', $key),
                $statusCode,
                $payload,
                $idempotencyStatus,
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function validateLegacyCreditAliases(
        array $payload,
        CreditMetadata $credits,
        int $statusCode,
        ?string $idempotencyStatus,
    ): void {
        foreach (['remaining', 'limit'] as $key) {
            if (!array_key_exists($key, $payload)) {
                throw self::invalid(
                    sprintf('A successful enrichment response must contain the "%s" credit alias.', $key),
                    $statusCode,
                    $payload,
                    $idempotencyStatus,
                );
            }

            $value = $payload[$key];
            if (!is_int($value) || $value < 0) {
                throw self::invalid(
                    sprintf('The response field "%s" must be a non-negative integer.', $key),
                    $statusCode,
                    $payload,
                    $idempotencyStatus,
                );
            }

            $creditValue = $key === 'remaining' ? $credits->remaining : $credits->limit;
            if ($value !== $creditValue) {
                throw self::invalid(
                    sprintf('The response field "%s" must match credits.%s.', $key, $key),
                    $statusCode,
                    $payload,
                    $idempotencyStatus,
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function normalizedIdempotencyStatus(
        ?string $value,
        int $statusCode,
        array $payload,
    ): ?string {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = strtolower(trim($value));
        if (!in_array($value, ['replayed', 'conflict'], true)) {
            throw self::invalid(
                'The Idempotency-Status header contains an unsupported value.',
                $statusCode,
                $payload,
                $value,
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function invalid(
        string $message,
        int $statusCode,
        array $payload,
        ?string $idempotencyStatus,
        ?\Throwable $previous = null,
    ): InvalidResponseException {
        return new InvalidResponseException(
            message: $message,
            statusCode: $statusCode,
            resultType: 'INVALID_RESPONSE',
            retryable: $statusCode >= 500,
            responseBody: $payload,
            idempotencyStatus: $idempotencyStatus,
            previous: $previous,
        );
    }
}
