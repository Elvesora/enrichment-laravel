<?php

declare(strict_types=1);

namespace Elvesora\Enrichment\Laravel\Facades;

use Elvesora\Enrichment\Laravel\Contracts\EnrichmentClientInterface;
use Elvesora\Enrichment\Laravel\EnrichmentResult;
use Illuminate\Support\Facades\Facade;

/**
 * @method static EnrichmentResult enrich(string $domain, ?string $idempotencyKey = null)
 *
 * @see EnrichmentClientInterface
 */
final class Enrichment extends Facade {
    protected static function getFacadeAccessor(): string {
        return EnrichmentClientInterface::class;
    }
}
