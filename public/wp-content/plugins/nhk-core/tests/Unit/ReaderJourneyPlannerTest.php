<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Semantic\{EditorialContextPack, ReaderJourneyPlanner, SharedEditorialComposer};
use PHPUnit\Framework\TestCase;

final class ReaderJourneyPlannerTest extends TestCase
{
    public function test_grounding_is_not_a_reader_journey_section_or_public_prose(): void
    {
        $grounding = [
            'claim_id' => 'grounding',
            'claim_revision' => 1,
            'text' => 'La source identifie cette vidéo comme liée au sujet canonique résolu.',
            'eligibility' => 'eligible',
            'semantic_role' => 'PROVENANCE_ONLY',
            'state' => 'APPLICABLE',
            'publicly_composable' => false,
            'editorial_role' => 'CORE',
        ];
        $fact = [
            'claim_id' => 'fact',
            'claim_revision' => 1,
            'text' => 'Cấu hình máy gồm ba vách.',
            'eligibility' => 'eligible',
            'semantic_role' => 'READER_FACT',
            'state' => 'APPLICABLE',
            'publicly_composable' => true,
            'editorial_role' => 'CORE',
        ];
        $pack = new EditorialContextPack('available', ['id' => 'subject-1', 'type' => 'variant'], 'cấu hình máy', ['profile' => 'video'], 'available', [$grounding, $fact], [], ['raw_input' => 'Video giới thiệu mẫu A.']);

        $plan = (new ReaderJourneyPlanner())->plan($pack);
        $draft = (new SharedEditorialComposer())->compose($plan);

        self::assertSame(['opening', 'core'], array_column($plan->sections, 'id'));
        self::assertSame(['fact'], array_column($draft->claimTrace, 'claim_id'));
        self::assertStringNotContainsString('La source identifie', $draft->body);
    }
}
