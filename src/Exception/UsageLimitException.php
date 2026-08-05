<?php

declare(strict_types=1);

namespace Elvesora\Enrichment\Laravel\Exception;

final class UsageLimitException extends EnrichmentException {
    public function limit(): ?int {
        if ($this->credits !== null) {
            return $this->credits->limit;
        }

        $limit = $this->responseBody['limit'] ?? null;

        return is_int($limit) ? $limit : null;
    }

    public function remaining(): ?int {
        if ($this->credits !== null) {
            return $this->credits->remaining;
        }

        $remaining = $this->responseBody['remaining'] ?? null;

        return is_int($remaining) ? $remaining : null;
    }
}
