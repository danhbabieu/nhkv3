<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Video\VideoEditorialFailureDiagnostics;
use NHK\Core\Domain\Video\VideoException;
use NHK\Core\Application\Semantic\EditorialContextPack;
use PHPUnit\Framework\TestCase;

final class VideoEditorialFailureDiagnosticsTest extends TestCase
{
    public function test_projects_bounded_causal_diagnostics_without_public_copy_or_raw_payloads(): void
    {
        $result = VideoEditorialFailureDiagnostics::project([
            'content' => [
                'semantic_needs' => [
                    ['need_id' => 'need-1', 'facet_key' => 'configuration', 'concept_key' => 'wall'],
                    ['need_id' => 'need-2', 'facet_key' => 'material', 'concept_key' => 'brass'],
                ],
                'retrieval' => [
                    'status' => 'partial',
                    'items' => array_fill(0, 100, ['claim_id' => 'claim-x', 'text' => 'raw_secret_should_not_escape']),
                    'retrieval_diagnostics' => [
                        'candidate_count' => 12,
                        'selected_count' => 2,
                        'stop_reason' => 'coverage_saturated',
                        'need_diagnostics' => [
                            ['need_id' => 'need-1', 'coverage_kind' => 'exact', 'candidate_count' => 7],
                        ],
                    ],
                ],
                'pack' => null,
            ],
            'quality_report' => [
                'readiness' => 'BLOCKED',
                'blockers' => ['SEO_NOT_READY', 'PUBLIC_INTERNAL_JARGON_LEAK'],
                'warnings' => ['INSUFFICIENT_READER_COVERAGE'],
                'diagnostics' => ['evaluation' => ['round' => 3]],
            ],
            'quality_decision' => 'REVIEW_REQUIRED',
            'repair_rounds' => 3,
            'constraint_findings' => [
                ['code' => 'PUBLIC_INTERNAL_JARGON_LEAK', 'severity' => 'HARD_BLOCK', 'offending_span' => 'secret phrase'],
            ],
        ]);

        self::assertSame('BLOCKED', $result['quality']['readiness']);
        self::assertSame(['SEO_NOT_READY', 'PUBLIC_INTERNAL_JARGON_LEAK'], $result['quality']['blockers']);
        self::assertSame(2, $result['semantic']['need_count']);
        self::assertSame(12, $result['retrieval']['candidate_count']);
        self::assertSame('coverage_saturated', $result['retrieval']['stop_reason']);
        self::assertSame(3, $result['repair']['rounds']);
        self::assertSame(['SEO_NOT_READY', 'PUBLIC_INTERNAL_JARGON_LEAK'], $result['terminal']['codes']);
        self::assertStringNotContainsString('raw_secret', json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString('secret phrase', json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertLessThanOrEqual(20, count($result['semantic']['needs']));
        self::assertLessThanOrEqual(20, count($result['retrieval']['needs']));
    }

    public function test_video_exception_can_carry_bounded_editorial_diagnostics_to_capture_readback(): void
    {
        $error = new VideoException('VIDEO_EDITORIAL_QUALITY_BLOCKED', 0, null, ['quality' => ['readiness' => 'BLOCKED']]);

        self::assertSame(['quality' => ['readiness' => 'BLOCKED']], $error->diagnostics);
    }

    public function test_projects_the_canonical_editorial_context_pack_object_without_array_access(): void
    {
        $pack = new EditorialContextPack(
            'partial',
            ['id' => 'subject-1', 'type' => 'model'],
            'Subject',
            ['profile' => 'video'],
            'partial',
            [],
            [],
            [],
            [],
            [],
            ['coverage_status' => 'PARTIAL', 'exact_selected_count' => 1, 'selected_unit_count' => 2],
        );

        $result = VideoEditorialFailureDiagnostics::project([
            'content' => ['pack' => $pack, 'semantic_needs' => [
                ['need_id' => 'need-1', 'facet_key' => 'configuration', 'concept_key' => 'wall'],
            ]],
        ]);

        self::assertSame('PARTIAL', $result['semantic']['coverage_status']);
        self::assertSame(1, $result['semantic']['exact_count']);
        self::assertSame(2, $result['semantic']['selected_unit_count']);
    }
}
