<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Video\VideoConstraintFinding;
use NHK\Core\Application\Video\VideoConstraintSeverity;
use NHK\Core\Application\Video\VideoDecisionTrace;
use NHK\Core\Application\Video\VideoEditorialAction;
use NHK\Core\Application\Video\VideoStatementClassification;
use PHPUnit\Framework\TestCase;

final class VideoIntelligentConstraintPrimitivesTest extends TestCase
{
    public function test_registered_vocabularies_are_complete_and_stable(): void
    {
        self::assertSame([
            'CANONICAL_SUPPORTED',
            'USER_OBSERVATION',
            'SOURCE_SUPPORTED',
            'INFERABLE_WITHIN_SCOPE',
            'UNCERTAIN',
            'CONFLICTING',
            'UNSUPPORTED_EXPANSION',
        ], VideoStatementClassification::all());
        self::assertSame(['INFO', 'REPAIRABLE', 'REVIEW_REQUIRED', 'HARD_BLOCK'], VideoConstraintSeverity::all());
        self::assertContains('NARROW_SCOPE', VideoEditorialAction::all());
        self::assertContains('REMOVE_UNSUPPORTED', VideoEditorialAction::all());
        self::assertContains('REGENERATE_SECTION', VideoEditorialAction::all());
    }

    public function test_trace_records_required_decision_fields_deterministically(): void
    {
        $trace = VideoDecisionTrace::record(
            ['text' => 'mặt số xanh', 'id' => 'claim-1'],
            VideoStatementClassification::USER_OBSERVATION,
            ['canonical' => [], 'evidence' => []],
            ['subject' => 'specimen', 'attribution' => 'trong video'],
            0.82,
            VideoEditorialAction::ATTRIBUTE_AND_SCOPE,
            'Observation is limited to the depicted specimen.',
        );

        self::assertSame([
            'statement' => ['text' => 'mặt số xanh', 'id' => 'claim-1'],
            'classification' => 'USER_OBSERVATION',
            'support' => ['canonical' => [], 'evidence' => []],
            'scope' => ['subject' => 'specimen', 'attribution' => 'trong video'],
            'confidence' => 0.82,
            'action' => 'ATTRIBUTE_AND_SCOPE',
            'reason' => 'Observation is limited to the depicted specimen.',
        ], $trace->toArray());
        self::assertSame($trace->toArray(), VideoDecisionTrace::fromArray($trace->toArray())?->toArray());
    }

    public function test_finding_serializes_claim_local_repair(): void
    {
        $finding = new VideoConstraintFinding(
            'VISUAL_SUPPORT_SECONDARY',
            VideoConstraintSeverity::REPAIRABLE,
            'claim',
            'claim-1',
            VideoEditorialAction::REMOVE_UNSUPPORTED,
            'Secondary visual detail can be omitted.',
        );

        self::assertSame([
            'code' => 'VISUAL_SUPPORT_SECONDARY',
            'severity' => 'REPAIRABLE',
            'scope' => 'claim',
            'claim_id' => 'claim-1',
            'repair' => 'REMOVE_UNSUPPORTED',
            'reason' => 'Secondary visual detail can be omitted.',
        ], $finding->toArray());
    }

    public function test_unknown_values_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new VideoConstraintFinding('BAD', 'HARD_BLOCK', 'claim', null, 'NOPE', 'bad');
    }
}
