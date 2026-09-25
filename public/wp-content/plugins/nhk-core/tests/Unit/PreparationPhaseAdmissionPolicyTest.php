<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\{ContentPreparationOrchestrator, ContentPreparationResult, PreparationContinuationDecision, PreparationPhaseAdmissionPolicy};
use NHK\Core\Application\Semantic\SubjectResolutionService;
use NHK\Core\Domain\Capture\SubjectResolutionPacket;
use PHPUnit\Framework\TestCase;

final class PreparationPhaseAdmissionPolicyTest extends TestCase
{
    public function test_resolved_subject_is_preserved_and_admitted_when_only_enrichment_review_remains(): void
    {
        $subjectId = '55555555-5555-4555-8555-555555555555';
        $resolver = new SubjectResolutionService(static fn (string $value): array => $value === $subjectId
            ? [['id' => $subjectId, 'type' => 'model', 'name' => 'Resolved Image Subject', 'revision' => 2]]
            : []);
        $result = (new ContentPreparationOrchestrator($resolver))->prepare(
            ['intent' => 'IMAGE_ARTICLE', 'canonical_uuid' => $subjectId],
            [],
            [],
            [
                'content_intent' => ['intent' => 'IMAGE_ARTICLE'],
                'server_dependency_requirements' => [[
                    'code' => 'OPTIONAL_ENRICHMENT_REVIEW',
                    'kind' => 'OPTIONAL_ENRICHMENT',
                    'readiness' => 'INCOMPLETE',
                    'escalation' => 'REVIEW_REQUIRED',
                    'required' => false,
                    'reason' => 'Optional enrichment requires review.',
                ]],
            ],
        );

        self::assertSame('REVIEW_REQUIRED', $result->status);
        self::assertNotNull($result->subjectResolutionPacket);
        self::assertSame($subjectId, $result->subjectResolutionPacket?->canonicalSubjectId);
        self::assertTrue((new PreparationPhaseAdmissionPolicy())->mayAdmitMinimumOwner(
            ['intent' => 'IMAGE_ARTICLE'],
            $result,
        ));
    }

    public function test_unresolved_subject_remains_not_admissible_and_keeps_machine_readable_reason(): void
    {
        $resolver = new SubjectResolutionService(static fn (string $value): array => $value === 'Ambiguous'
            ? [
                ['id' => '33333333-3333-4333-8333-333333333333', 'type' => 'model', 'name' => 'Model A', 'revision' => 1],
                ['id' => '44444444-4444-4444-8444-444444444444', 'type' => 'model', 'name' => 'Model B', 'revision' => 1],
            ]
            : []);
        $result = (new ContentPreparationOrchestrator($resolver))->prepare(
            ['intent' => 'IMAGE_ARTICLE', 'subject_hints' => ['Ambiguous']],
            [],
            [],
            ['content_intent' => ['intent' => 'IMAGE_ARTICLE']],
        );

        self::assertSame('REVIEW_REQUIRED', $result->status);
        self::assertNull($result->subjectResolutionPacket);
        self::assertContains('PRIMARY_SUBJECT_AMBIGUOUS', $result->reviewReasons);
        self::assertFalse((new PreparationPhaseAdmissionPolicy())->mayAdmitMinimumOwner(
            ['intent' => 'IMAGE_ARTICLE'],
            $result,
        ));
        self::assertNotSame([], $result->candidates);
    }

    public function test_video_owner_is_admitted_when_optional_subject_is_unresolved(): void
    {
        $result = new ContentPreparationResult(
            'REVIEW_REQUIRED',
            hash('sha256', 'optional-subject'),
            null,
            [],
            [],
            [],
            [],
            [],
            [],
            ['PRIMARY_SUBJECT_NOT_RESOLVED'],
            [],
            [],
            [],
            'READY',
            0,
            [],
            new PreparationContinuationDecision(false, [], [], 'BLOCKING_DEPENDENCY_REQUIRES_REVIEW'),
        );

        self::assertSame('REVIEW_REQUIRED', $result->status);
        self::assertContains('PRIMARY_SUBJECT_NOT_RESOLVED', $result->reviewReasons);
        self::assertNull($result->subjectResolutionPacket);
        self::assertTrue((new PreparationPhaseAdmissionPolicy())->mayAdmitMinimumOwner(
            ['intent' => 'VIDEO'],
            $result,
        ));
    }

    public function test_video_owner_admission_does_not_downgrade_mandatory_subject_review(): void
    {
        $result = new ContentPreparationResult(
            'REVIEW_REQUIRED',
            hash('sha256', 'mandatory-subject'),
            null,
            [],
            [],
            [],
            [],
            [],
            [],
            ['PRIMARY_SUBJECT_AMBIGUOUS'],
            [],
            [],
            [],
            'READY',
            0,
            [],
            new PreparationContinuationDecision(false, ['PRIMARY_SUBJECT_AMBIGUOUS'], [], 'BLOCKING_DEPENDENCY_REQUIRES_REVIEW'),
        );

        self::assertFalse((new PreparationPhaseAdmissionPolicy())->mayAdmitMinimumOwner(
            ['intent' => 'VIDEO'],
            $result,
        ));
    }
}
