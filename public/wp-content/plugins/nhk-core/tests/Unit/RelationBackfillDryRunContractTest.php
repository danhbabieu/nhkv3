<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Graph\{RelationBackfillCandidate, RelationBackfillService, RelationResolutionChain};
use PHPUnit\Framework\TestCase;

final class RelationBackfillDryRunContractTest extends TestCase
{
    public function test_resolution_chain_uses_first_available_method_in_required_order(): void
    {
        $calls = [];
        $chain = new RelationResolutionChain([
            'explicit_uuid' => static function () use (&$calls): ?array { $calls[] = 'explicit_uuid'; return null; },
            'structured_metadata' => static function () use (&$calls): ?array { $calls[] = 'structured_metadata'; return null; },
            'stable_key' => static function () use (&$calls): ?array { $calls[] = 'stable_key'; return ['status' => 'MISSING_DETERMINISTIC', 'resolution_method' => 'stable_key']; },
            'intended_relations' => static function () use (&$calls): ?array { $calls[] = 'intended_relations'; return ['status' => 'AMBIGUOUS']; },
        ]);

        self::assertSame(['status' => 'MISSING_DETERMINISTIC', 'resolution_method' => 'stable_key'], $chain->resolve([]));
        self::assertSame(['explicit_uuid', 'structured_metadata', 'stable_key'], $calls);
    }

    public function test_dry_run_emits_machine_readable_statuses_and_never_invokes_apply(): void
    {
        $candidate = new RelationBackfillCandidate('r1', 'key', 'knowledge', 'knowledge', 's1', 'about', 'brand', 't1');
        $existsCalls = 0;
        $service = new RelationBackfillService(
            static fn (array $record): mixed => $record['result'] ?? null,
            static function () use (&$existsCalls): bool { $existsCalls++; return true; }
        );
        $report = $service->dryRun([
            ['result' => $candidate],
            ['result' => ['status' => 'MISSING_DETERMINISTIC', 'candidate' => $candidate->toArray()]],
            ['result' => ['status' => 'AMBIGUOUS']],
            ['result' => ['status' => 'RELATION_PENDING']],
            ['result' => ['status' => 'REGISTRY_GAP']],
            ['result' => ['status' => 'EVIDENCE_GAP']],
            ['result' => ['status' => 'ORPHAN']],
            ['result' => ['status' => 'DUPLICATE_CANDIDATE']],
            ['result' => ['status' => 'INVALID_ENDPOINT']],
            ['result' => ['status' => 'NOT_APPLICABLE']],
        ]);

        self::assertSame(10, $report->scanned);
        self::assertSame(1, $existsCalls);
        self::assertSame(1, $report->counters['EXISTING']);
        self::assertSame(1, $report->counters['MISSING_DETERMINISTIC']);
        foreach (['AMBIGUOUS', 'RELATION_PENDING', 'REGISTRY_GAP', 'EVIDENCE_GAP', 'ORPHAN', 'DUPLICATE_CANDIDATE', 'INVALID_ENDPOINT', 'NOT_APPLICABLE'] as $status) self::assertSame(1, $report->counters[$status]);
        self::assertCount(1, $report->candidates);
        self::assertArrayHasKey('counters', $report->toArray());
    }

    public function test_empty_input_uses_read_only_snapshot_provider(): void
    {
        $service = new RelationBackfillService(
            static fn (array $record): array => ['status' => isset($record['edge_uuid']) ? 'EXISTING' : 'NOT_APPLICABLE'],
            static fn (): bool => false,
            static fn (): array => [['edge_uuid' => 'edge-1'], ['type' => 'brand', 'uuid' => 'brand-1']]
        );

        $report = $service->dryRun([]);

        self::assertSame(2, $report->scanned);
        self::assertSame(1, $report->counters['EXISTING']);
        self::assertSame(1, $report->counters['NOT_APPLICABLE']);
    }
}
