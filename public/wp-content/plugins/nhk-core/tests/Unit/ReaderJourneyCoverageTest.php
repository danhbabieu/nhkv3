<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\{EditorialContextPack, ReaderJourneyPlanner, SharedEditorialComposer};
use PHPUnit\Framework\TestCase;

final class ReaderJourneyCoverageTest extends TestCase
{
    public function test_one_knowledge_unit_can_group_multiple_supporting_claims_without_provenance_section(): void
    {
        $unit = ['unit_id' => 'unit-a', 'coverage_aspects' => ['facet:configuration'], 'supporting_claims' => [
            ['claim_id' => 'a', 'claim_revision' => 1, 'text' => 'Cấu hình có ba vách.', 'eligibility' => 'eligible', 'publicly_composable' => true],
            ['claim_id' => 'b', 'claim_revision' => 2, 'text' => 'Dấu hiệu này giúp nhận biết.', 'eligibility' => 'eligible', 'publicly_composable' => true],
        ]];
        $representative = ['claim_id' => 'a', 'claim_revision' => 1, 'text' => 'Cấu hình có ba vách.', 'eligibility' => 'eligible', 'publicly_composable' => true, 'editorial_role' => 'CORE', 'knowledge_unit' => $unit];
        $grounding = ['claim_id' => 'g', 'text' => 'Source trace only.', 'eligibility' => 'eligible', 'publicly_composable' => false, 'semantic_role' => 'PROVENANCE_ONLY'];
        $pack = new EditorialContextPack('available', ['id' => 'subject', 'type' => 'model'], 'cấu hình', ['profile' => 'article'], 'available', [$representative, $grounding], [], [], [], [], ['coverage_status' => 'PARTIAL'], 1, [$grounding], [$representative]);

        $plan = (new ReaderJourneyPlanner())->plan($pack);
        $draft = (new SharedEditorialComposer())->compose($plan);

        self::assertCount(2, $plan->sections);
        self::assertSame('unit-a', $plan->sections[1]['unit_id']);
        self::assertSame(['a', 'b'], array_column($plan->sections[1]['claims'], 'claim_id'));
        self::assertStringNotContainsString('Source trace only', $draft->body);
        self::assertSame(['coverage_status' => 'PARTIAL'], $plan->diagnostics['coverage_before']);
    }

    public function test_sparse_pack_has_only_opening_and_no_filler_section(): void
    {
        $plan = (new ReaderJourneyPlanner())->plan(new EditorialContextPack('no_useful_claims', ['id' => 'subject', 'type' => 'model'], 'subject', ['profile' => 'video'], 'available', [], []));
        self::assertCount(1, $plan->sections);
        self::assertSame([], $plan->sections[0]['claims']);
        self::assertSame([], $plan->diagnostics['uncovered_aspects']);
    }
}
