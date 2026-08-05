<?php

declare(strict_types=1);

namespace Elvesora\Enrichment\Laravel;

use DateTimeImmutable;
use JsonSerializable;
use UnexpectedValueException;

final class CreditMetadata implements JsonSerializable {
    /**
     * @param array<string, mixed> $raw
     */
    private function __construct(
        public readonly int $limit,
        public readonly int $used,
        public readonly int $remaining,
        public readonly int $consumedByRequest,
        public readonly ?string $periodStartedAt,
        public readonly ?string $periodEndsAt,
        private readonly array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self {
        $limit = self::nonNegativeInteger($data, 'limit');
        $used = self::nonNegativeInteger($data, 'used');
        $remaining = self::nonNegativeInteger($data, 'remaining');
        $consumedByRequest = self::nonNegativeInteger($data, 'consumed_by_request');

        return new self(
            limit: $limit,
            used: $used,
            remaining: $remaining,
            consumedByRequest: $consumedByRequest,
            periodStartedAt: self::nullableDate($data, 'period_started_at'),
            periodEndsAt: self::nullableDate($data, 'period_ends_at'),
            raw: $data,
        );
    }

    public static function tryFrom(mixed $data): ?self {
        if (!is_array($data) || (array_is_list($data) && $data !== [])) {
            return null;
        }

        try {
            /** @var array<string, mixed> $data */
            return self::fromArray($data);
        } catch (UnexpectedValueException) {
            return null;
        }
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
     * @param array<string, mixed> $data
     */
    private static function nonNegativeInteger(array $data, string $key): int {
        $value = $data[$key] ?? null;
        if (!is_int($value) || $value < 0) {
            throw new UnexpectedValueException(sprintf(
                'The credit metadata field "%s" must be a non-negative integer.',
                $key,
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function nullableDate(array $data, string $key): ?string {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }

        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new UnexpectedValueException(sprintf(
                'The credit metadata field "%s" must be a date in YYYY-MM-DD format or null.',
                $key,
            ));
        }

        return $value;
    }
}
