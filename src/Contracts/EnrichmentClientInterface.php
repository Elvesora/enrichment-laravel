<?php

declare(strict_types=1);

namespace Elvesora\Enrichment\Laravel\Contracts;

use Elvesora\Enrichment\Laravel\EnrichmentResult;

interface EnrichmentClientInterface {
    public function enrich(string $domain, ?string $idempotencyKey = null): EnrichmentResult;
}
