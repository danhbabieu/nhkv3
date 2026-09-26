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

    public function test_exact_media_enrichment_target_does_not_require_semantic_subject(): void
    {
        $resolver = new SubjectResolutionService(static fn (string $value): array => []);
        $result = (new ContentPreparationOrchestrator($resolver))->prepare(
            [
                'intent' => 'MEDIA_ENRICHMENT',
                'media_operations' => [[
                    'operation' => 'replace',
                    'media' => ['id' => '01a0d7ee-3e33-7366-88c6-287112b34936'],
                    'target' => ['type' => 'wp_post', 'id' => '1:18'],
                    'usage_id' => '01a06e2e-73a1-7550-b0e8-168aafdc6ceb',
                    'expected_usage_revision' => 1,
                ]],
            ],
            [],
            [['media_id' => '01a0d7ee-3e33-7366-88c6-287112b34936']],
            ['content_intent' => ['intent' => 'MEDIA_ENRICHMENT']],
        );

        self::assertSame('PREPARED', $result->status);
        self::assertSame('unresolved', $result->subjectResolutionPacket?->status);
        self::assertSame('OPTIONAL_EXACT_MEDIA_TARGET', $result->diagnostics['subject_requirement'] ?? null);
        self::assertNotContains('PRIMARY_SUBJECT_NOT_RESOLVED', $result->reviewReasons);
    }

    public function test_idempotent_exact_media_replay_without_subject_remains_admissible(): void
    {
        $result = (new ContentPreparationOrchestrator(new SubjectResolutionService(static fn (string $value): array => [])))->prepare(
            ['intent' => 'MEDIA_ENRICHMENT', 'media_operations' => [[
                'operation' => 'keep',
                'media' => ['id' => '01a0d7ee-3e33-7366-88c6-287112b34936'],
                'target' => ['type' => 'wp_post', 'id' => '1:18'],
                'usage_id' => '01a06e2e-73a1-7550-b0e8-168aafdc6ceb',
                'expected_usage_revision' => 1,
            ]]],
            [],
            [['media_id' => '01a0d7ee-3e33-7366-88c6-287112b34936']],
            ['content_intent' => ['intent' => 'MEDIA_ENRICHMENT']],
        );

        self::assertSame('PREPARED', $result->status);
        self::assertNotContains('PRIMARY_SUBJECT_NOT_RESOLVED', $result->reviewReasons);
    }

    public function test_media_enrichment_without_exact_target_still_requires_subject_review(): void
    {
        $resolver = new SubjectResolutionService(static fn (string $value): array => []);
        $result = (new ContentPreparationOrchestrator($resolver))->prepare(
            ['intent' => 'MEDIA_ENRICHMENT'],
            [],
            [['media_id' => '01a0d7ee-3e33-7366-88c6-287112b34936']],
            ['content_intent' => ['intent' => 'MEDIA_ENRICHMENT']],
        );

        self::assertSame('REVIEW_REQUIRED', $result->status);
        self::assertContains('PRIMARY_SUBJECT_NOT_RESOLVED', $result->reviewReasons);
        self::assertSame('REQUIRED', $result->diagnostics['subject_requirement'] ?? null);
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
