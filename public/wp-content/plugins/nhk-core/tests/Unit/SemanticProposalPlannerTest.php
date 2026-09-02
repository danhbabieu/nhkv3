<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Article\SemanticProposalPlanner;
use PHPUnit\Framework\TestCase;

final class SemanticProposalPlannerTest extends TestCase
{
    public function test_child_idempotency_key_is_deterministic_from_operation_and_slot(): void
    {
        $planner = new SemanticProposalPlanner();
        $commands = [[
            'slot' => 'brand-o-do',
            'operation' => 'update',
            'entity_type' => 'brand',
            'subject_id' => 'uuid',
            'target_uuid' => 'uuid',
            'expected_revision' => 2,
            'payload' => ['entity_payload' => ['description' => 'x']],
            'dependency_slots' => [],
        ]];

        $first = $planner->plan('operation-1', $commands)[0];
        $second = $planner->plan('operation-1', $commands)[0];

        self::assertSame('operation-1:semantic:brand-o-do', $first->idempotencyKey);
        self::assertSame($first->contentFingerprint, $second->contentFingerprint);
        self::assertSame($first->dependencySlots, $second->dependencySlots);
    }

    public function test_duplicate_slots_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SemanticProposalPlanner())->plan('operation-1', [
            ['slot' => 'duplicate', 'operation' => 'update', 'entity_type' => 'brand', 'subject_id' => 'id', 'expected_revision' => 1, 'payload' => []],
            ['slot' => 'duplicate', 'operation' => 'update', 'entity_type' => 'brand', 'subject_id' => 'id', 'expected_revision' => 1, 'payload' => []],
        ]);
    }
}
