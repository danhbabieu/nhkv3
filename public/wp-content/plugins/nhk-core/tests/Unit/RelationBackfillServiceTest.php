<?php
declare(strict_types=1);
namespace NHK\Tests\Unit;

use NHK\Core\Application\Graph\{RelationBackfillCandidate, RelationBackfillService};
use PHPUnit\Framework\TestCase;

final class RelationBackfillServiceTest extends TestCase
{
    public function test_dry_run_separates_deterministic_existing_and_ambiguous_rows(): void
    {
        $candidate = new RelationBackfillCandidate('r1', 'nhk:knowledge:one', 'knowledge', 'knowledge', 's1', 'about', 'variant', 't1');
        $newCandidate = new RelationBackfillCandidate('r3', 'nhk:knowledge:three', 'knowledge', 'knowledge', 's3', 'about', 'variant', 't3');
        $service = new RelationBackfillService(static function (array $record) use ($candidate, $newCandidate): mixed { return $record['kind'] === 'ambiguous' ? ['status' => 'ambiguous', 'record_uuid' => 'r2', 'reason' => 'MULTIPLE_EXACT_CANDIDATES'] : ($record['kind'] === 'existing' ? $candidate : $newCandidate); }, static fn (RelationBackfillCandidate $value): bool => $value->recordUuid === 'r1');
        $report = $service->dryRun([['kind' => 'existing'], ['kind' => 'new'], ['kind' => 'ambiguous']]);
        self::assertSame(3, $report->scanned); self::assertSame(1, $report->existing); self::assertCount(1, $report->proposed); self::assertCount(1, $report->ambiguous);
    }

    public function test_apply_is_idempotent_on_second_run(): void
    {
        $created = false;
        $candidate = new RelationBackfillCandidate('r1', 'k', 'knowledge', 'knowledge', 's1', 'about', 'brand', 't1');
        $service = new RelationBackfillService(static fn (): RelationBackfillCandidate => $candidate, static function () use (&$created): bool { return $created; });
        $first = $service->apply([$candidate], static function () use (&$created): string { $created = true; return 'created'; });
        $second = $service->apply([$candidate], static fn (): string => 'created');
        self::assertSame(1, $first->created); self::assertTrue($second->zeroChangeOnSecondRun); self::assertSame(1, $second->skippedIdempotent);
    }
}
