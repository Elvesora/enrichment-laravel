<?php

declare(strict_types=1);

namespace Elvesora\Enrichment\Laravel\Exception;

final class ValidationException extends EnrichmentException {
    /**
     * @return array<string, mixed>
     */
    public function errors(): array {
        $errors = $this->responseBody['errors'] ?? [];

        return is_array($errors) && !array_is_list($errors) ? $errors : [];
    }
}
