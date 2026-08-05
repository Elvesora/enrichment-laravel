<?php

declare(strict_types=1);

namespace Elvesora\Enrichment\Laravel\Tests;

use Elvesora\Enrichment\Laravel\Contracts\EnrichmentClientInterface;
use Elvesora\Enrichment\Laravel\EnrichmentResult;
use Elvesora\Enrichment\Laravel\Facades\Enrichment;

final class EnrichmentFacadeTest extends TestCase {
    protected function tearDown(): void {
        Enrichment::clearResolvedInstance(EnrichmentClientInterface::class);

        parent::tearDown();
    }

    public function test_the_facade_forwards_enrichment_calls_to_the_bound_client(): void {
        $result = EnrichmentResult::fromArray($this->payload(), 200, 'replayed');
        $fake = new RecordingEnrichmentClient($result);

        $this->appInstance()->instance(EnrichmentClientInterface::class, $fake);
        Enrichment::clearResolvedInstance(EnrichmentClientInterface::class);

        $actual = Enrichment::enrich('acme.com', 'import-row-42');

        self::assertSame($result, $actual);
        self::assertSame([['acme.com', 'import-row-42']], $fake->calls);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array {
        return [
            'success' => true,
            'result_type' => 'ENRICHED',
            'message' => 'Company enrichment completed successfully',
            'data' => ['domain' => 'acme.com'],
            'credits' => [
                'limit' => 100,
                'used' => 6,
                'remaining' => 94,
                'consumed_by_request' => 0,
                'period_started_at' => '2026-06-01',
                'period_ends_at' => '2026-07-01',
            ],
            'remaining' => 94,
            'limit' => 100,
        ];
    }
}

final class RecordingEnrichmentClient implements EnrichmentClientInterface {
    /** @var list<array{string, string|null}> */
    public array $calls = [];

    public function __construct(
        private readonly EnrichmentResult $result,
    ) {
    }

    public function enrich(string $domain, ?string $idempotencyKey = null): EnrichmentResult {
        $this->calls[] = [$domain, $idempotencyKey];

        return $this->result;
    }
}
