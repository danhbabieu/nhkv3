<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\{ContentPreparationOrchestrator, EditorialCaptureCoordinator};
use NHK\Core\Application\Capture\ContentIntentRouter;
use NHK\Core\Application\Semantic\{ArticleComposer, ClaimRetrievalEngine, EditorialClaimRetrievalService, EditorialKnowledgeSelector, SharedEnrichmentBoundary, SubjectResolutionService, TextInputInterpreter};
use NHK\Core\Contracts\Capture\{CaptureAddendumRepository, CaptureRepository};
use NHK\Core\Domain\Capture\CaptureAddendumRecord;
use NHK\Core\Domain\Capture\{CaptureRecord, CaptureStage, SubjectResolutionPacket};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

/**
 * PR5 seam tests: the Capture coordinator is the orchestrator, while each
 * owner result remains independently read back and aggregated truthfully.
 */
final class EditorialCaptureConvergenceE2ETest extends TestCase
{
    public function test_capture_passes_shared_enrichment_into_real_semantic_lifecycle_context(): void
    {
        $captures = new Pr5CaptureRepository();
        $calls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $seen = [];
        $events = [];
        $engine = new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => [['id' => 'claim-lifecycle', 'claim_id' => 'claim-lifecycle', 'subject_id' => '11111111-1111-4111-8111-111111111111', 'subject_type' => 'model', 'text' => 'Một Claim đủ điều kiện.', 'scope' => 'model', 'provenance' => 'CATALOG_SUPPORTED', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE']]);
        $shared = new SharedEnrichmentBoundary(new EditorialClaimRetrievalService($engine), new EditorialKnowledgeSelector());
        $resolver = new SubjectResolutionService(static fn (string $value): array => $value === '11111111-1111-4111-8111-111111111111' ? [['id' => $value, 'type' => 'model', 'name' => 'Lifecycle Model']] : []);
        $coordinator = $this->coordinator($captures, $calls, $events, subjectResolver: $resolver, shared: $shared, sharedObserver: static function (array $context) use (&$seen): void { $seen[] = (string) ($context['shared_enrichment']['profile'] ?? ''); });
        $result = $coordinator->execute(['idempotency_key' => 'shared-lifecycle-article', 'intent' => 'TEXT_ARTICLE', 'text' => 'Bài viết có Claim đủ điều kiện.', 'canonical_uuid' => '11111111-1111-4111-8111-111111111111']);

        self::assertArrayHasKey('shared_enrichment', $result->diagnostics);
        self::assertContains('claim-lifecycle', $result->diagnostics['shared_enrichment']['content']['selected_claims'] ? array_column($result->diagnostics['shared_enrichment']['content']['selected_claims'], 'id') : []);
        self::assertSame(['article'], $seen);
    }

    public function test_media_enrichment_failure_recovery_preserves_current_media_enrichment_readback(): void
    {
        $captures = new Pr5CaptureRepository();
        $calls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $events = [];
        $mediaId = UuidCodec::newV7();
        $targetId = UuidCodec::newV7();
        $usageId = UuidCodec::newV7();
        $coordinator = $this->coordinator(
            $captures,
            $calls,
            $events,
            semanticStatus: 'SKIPPED',
            media: static fn (): array => [
                'status' => 'COMPLETE',
                'media_ids' => [$mediaId],
                'media_complete' => true,
                'bindings' => [[
                    'status' => 'COMPLETE',
                    'readback' => [
                        'status' => 'verified',
                        'media_id' => $mediaId,
                        'target_type' => 'model',
                        'target_id' => $targetId,
                        'usage_id' => $usageId,
                        'role' => 'representative',
                    ],
                ]],
            ],
            subjectResolver: new SubjectResolutionService(static fn (string $hint): array => [[
                'id' => $targetId,
                'type' => 'model',
                'name' => 'Runtime Model',
            ]]),
            final: static function (array $context): array {
                throw new \RuntimeException('CAPTURE_FINAL_READBACK_UNAVAILABLE');
            },
            physical: static fn (): array => ['items' => [['media_id' => $mediaId]]],
        );

        $result = $coordinator->execute([
            'idempotency_key' => 'media-enrichment-recovery-' . bin2hex(random_bytes(4)),
            'intent' => 'MEDIA_ENRICHMENT',
            'purpose' => 'EDITORIAL',
            'text' => 'Media enrichment recovery.',
            'subject_hints' => [$targetId],
        ]);

        self::assertSame('FAILED_RETRYABLE', $result->status);
        self::assertSame('COMPLETE', $result->diagnostics['media_enrichment']['status']);
        self::assertSame('COMPLETE', $result->diagnostics['completion']['relation_or_usage_state']);
        self::assertTrue($result->diagnostics['completion']['children'][1]['canonical_readback_verified'] ?? false);
    }

    public function test_shared_enrichment_marks_deep_enrichment_complete_when_no_reuse_is_available(): void
    {
        $captures = new Pr5CaptureRepository();
        $calls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $events = [];
        $engine = new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []);
        $shared = new SharedEnrichmentBoundary(new EditorialClaimRetrievalService($engine), new EditorialKnowledgeSelector());
        $resolver = new SubjectResolutionService(static fn (string $value): array => [['id' => $value, 'type' => 'model', 'name' => 'Sparse Model']]);
        $coordinator = $this->coordinator($captures, $calls, $events, subjectResolver: $resolver, shared: $shared);

        $result = $coordinator->execute([
            'idempotency_key' => 'shared-sparse-article',
            'intent' => 'TEXT_ARTICLE',
            'text' => 'Bài viết không có claim tái sử dụng.',
            'canonical_uuid' => '11111111-1111-4111-8111-111111111111',
        ]);

        self::assertSame('COMPLETED', $result->diagnostics['deep_enrichment']['status']);
        self::assertSame('NO_ELIGIBLE_CLAIMS', $result->diagnostics['shared_enrichment']['content']['status']);
    }

    public function test_retry_rehydrates_persisted_confirmation_before_weaker_subject_resolution(): void
    {
        $captures = new Pr5CaptureRepository();
        $subjectId = '8f6c98ca-869a-4418-a8a4-1a32eb931c5e';
        $resolverCalls = [];
        $resolver = new SubjectResolutionService(static function (string $value) use (&$resolverCalls, $subjectId): array {
            $resolverCalls[] = $value;
            if ($value === $subjectId) return [['id' => $subjectId, 'type' => 'model', 'name' => 'Confirmed Model', 'stable_key' => 'nhk:model:confirmed', 'revision' => 4]];
            if ($value === 'Weak ambiguous title') return [
                ['id' => '83333333-3333-4333-8333-333333333333', 'type' => 'model', 'name' => 'Weak A', 'revision' => 1],
                ['id' => '84444444-4444-4444-8444-444444444444', 'type' => 'model', 'name' => 'Weak B', 'revision' => 1],
            ];
            return [];
        });
        $calls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $events = [];
        $coordinator = $this->coordinator($captures, $calls, $events, subjectResolver: $resolver, preparation: new ContentPreparationOrchestrator($resolver));
        $capture = new CaptureRecord(
            UuidCodec::newV7(),
            'persisted-confirmation-retry',
            hash('sha256', 'persisted-confirmation-retry'),
            CaptureStage::SEMANTICS_RECONCILED->value,
            'REVIEW_REQUIRED',
            null,
            null,
            [],
            [
                'raw_input' => 'Retry the same capture.',
                'title' => 'Weak ambiguous title',
                'subject_hints' => ['Weak ambiguous title'],
                'content_intent' => ['intent' => 'TEXT_ARTICLE', 'article_required' => true],
                'content_preparation' => ['status' => 'REVIEW_REQUIRED', 'preparation_fingerprint' => hash('sha256', 'old'), 'subject_resolution_packet' => null, 'review_reasons' => ['PRIMARY_SUBJECT_AMBIGUOUS']],
            ],
            [
                'subject_reconciliation' => ['status' => 'CONFIRMED', 'candidate_uuid' => $subjectId, 'source' => 'USER_CONFIRMED_SUBJECT_RECONCILIATION'],
                'subjects' => ['status' => 'ambiguous', 'primary' => null],
            ],
            [],
        );
        $captures->create($capture);

        $result = $coordinator->retry($capture, [
            'existing_capture_retry' => true,
            'intent' => 'TEXT_ARTICLE',
            'text' => 'Retry the same capture.',
            'title' => 'Weak ambiguous title',
            'subject_hints' => ['Weak ambiguous title'],
        ]);

        self::assertSame('PREPARED', $result->diagnostics['content_preparation']['status']);
        self::assertSame($subjectId, $result->diagnostics['content_preparation']['subject_resolution_packet']['canonical_subject_id']);
        self::assertSame('USER_CONFIRMED_SUBJECT_RECONCILIATION', $result->diagnostics['content_preparation']['subject_resolution_packet']['primary_source']);
        self::assertNotContains('SUBJECT_CONFLICT_REVIEW_REQUIRED', $result->diagnostics['content_preparation']['review_reasons'] ?? []);
        self::assertSame([$subjectId], $resolverCalls);
        self::assertSame(1, $calls['draft']);
        self::assertSame(1, $calls['semantic']);
    }

    public function test_video_retry_rehydrates_original_request_and_admits_owner_after_semantic_noop(): void
    {
        $captures = new Pr5CaptureRepository();
        $calls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $events = [];
        $videoId = UuidCodec::newV7();
        $subjectId = UuidCodec::newV7();
        $coordinator = $this->coordinator(
            $captures,
            $calls,
            $events,
            semanticStatus: 'SKIPPED',
            videoEnrichment: static function (array $context) use (&$events, $videoId): array {
                $events['video_request'] = $context['video'] ?? [];
                return ['items' => [['kind' => 'video', 'video_id' => $videoId]]];
            },
            videoPublication: static fn (array $context): array => [
                'status' => 'verified',
                'items' => [[
                    'video_id' => $videoId,
                    'completion' => [
                        'owner_type' => 'video', 'owner_id' => $videoId, 'status' => 'COMPLETE', 'complete' => true,
                        'canonical_readback' => ['canonical_id' => $videoId], 'content_state' => 'CONTENT_COMPLETE',
                        'dependency_state' => 'COMPLETE', 'relation_or_usage_state' => 'COMPLETE', 'public_state' => 'READY',
                        'frontend_state' => 'VERIFIED', 'blockers' => [],
                    ],
                ]],
                'blockers' => [],
            ],
        );
        $packet = [
            'status' => 'resolved',
            'canonical_subject_id' => $subjectId,
            'entity_type' => 'variant',
            'stable_key' => 'nhk:variant:test',
            'canonical_name' => 'Retry Variant',
            'revision' => 2,
            'primary_source' => 'USER_CONFIRMED_SUBJECT_RECONCILIATION',
        ];
        $capture = new CaptureRecord(
            UuidCodec::newV7(),
            'video-owner-admission-retry',
            hash('sha256', 'video-owner-admission-retry'),
            CaptureStage::SEMANTICS_RECONCILED->value,
            'PARTIAL',
            null,
            null,
            [],
            [
                'raw_input' => 'Video retry without caller payload.',
                'title' => 'Persisted Video title',
                'metadata' => ['compliance_note' => 'Persisted compliance note.'],
                'content_intent' => ['intent' => 'VIDEO', 'article_required' => false, 'semantic_delta' => ['status' => 'NONE']],
                'original_request' => [
                    'intent' => 'VIDEO',
                    'video' => ['url' => 'https://youtu.be/dQw4w9WgXcQ', 'user_hint' => 'Persisted Video hint'],
                ],
                'subject_resolution_packet' => $packet,
            ],
            [
                'subjects' => ['status' => 'resolved', 'primary' => ['id' => $subjectId, 'type' => 'variant', 'revision' => 2], 'resolved' => [['id' => $subjectId, 'type' => 'variant', 'revision' => 2]]],
                'content_preparation' => ['status' => 'PREPARED', 'subject_resolution_packet' => $packet],
                'semantic_write_back' => ['status' => 'SKIPPED', 'writes' => [], 'blockers' => []],
                'completion' => ['status' => 'PARTIAL', 'missing_required_owners' => [['owner_type' => 'video', 'owner_id' => '']], 'blockers' => ['REQUIRED_OWNER_READBACK_UNVERIFIED']],
                'resume_hints' => ['resume_children' => ['video']],
            ],
            ['SEMANTICS_RECONCILED' => ['status' => 'PARTIAL', 'result' => 'PARTIAL']],
        );
        $captures->create($capture);

        $result = $coordinator->retry($capture, ['resume_children' => ['video']]);

        self::assertSame(['url' => 'https://youtu.be/dQw4w9WgXcQ', 'user_hint' => 'Persisted Video hint'], $events['video_request']);
        self::assertCount(1, array_filter($result->assets, static fn (array $asset): bool => ($asset['kind'] ?? '') === 'video'));
        self::assertSame('SKIPPED', $result->diagnostics['semantic_write_back']['status']);
        self::assertSame($videoId, $result->diagnostics['completion']['required_owners'][0]['owner_id']);
        self::assertSame([], $result->diagnostics['completion']['missing_required_owners']);
        self::assertNotContains('REQUIRED_OWNER_READBACK_UNVERIFIED', $result->diagnostics['completion']['blockers']);
        self::assertSame('verified', $result->diagnostics['final_read_back']['status']);

        $replay = $coordinator->retry($result, ['resume_children' => ['video']]);
        $videoAssets = array_values(array_filter($replay->assets, static fn (array $asset): bool => ($asset['kind'] ?? '') === 'video'));
        self::assertCount(1, $videoAssets);
        self::assertSame($videoId, $videoAssets[0]['video_id']);
        self::assertSame($videoId, $replay->diagnostics['completion']['required_owners'][0]['owner_id']);
    }

    public function test_video_retry_reenters_intake_when_historical_video_projection_has_no_owner(): void
    {
        $captures = new Pr5CaptureRepository();
        $calls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $events = [];
        $videoId = UuidCodec::newV7();
        $coordinator = $this->coordinator(
            $captures,
            $calls,
            $events,
            semanticStatus: 'SKIPPED',
            videoEnrichment: static function (array $context) use (&$events, $videoId): array {
                $events['video_intake'] = ($events['video_intake'] ?? 0) + 1;
                $events['video_request'] = $context['video'] ?? [];
                return ['items' => [['kind' => 'video', 'video_id' => $videoId]]];
            },
            videoPublication: static function (array $context) use (&$events, $videoId): array {
                $videoAssets = array_values(array_filter((array) ($context['assets'] ?? []), static fn (mixed $asset): bool => is_array($asset) && ($asset['kind'] ?? '') === 'video' && trim((string) ($asset['video_id'] ?? '')) !== ''));
                $events['video_readback_owner'] = $videoAssets[0]['video_id'] ?? '';
                return [
                'status' => 'verified',
                'items' => [['video_id' => $videoId, 'completion' => ['owner_type' => 'video', 'owner_id' => $videoId, 'complete' => true, 'status' => 'COMPLETE', 'blockers' => []]]],
                'blockers' => [],
                ];
            },
        );
        $capture = new CaptureRecord(
            UuidCodec::newV7(),
            'video-historical-projection-without-owner',
            hash('sha256', 'video-historical-projection-without-owner'),
            CaptureStage::SEMANTICS_RECONCILED->value,
            'PARTIAL',
            null,
            null,
            [['kind' => 'video', 'video_preview' => ['status' => 'REVIEW_REQUIRED']]],
            [
                'raw_input' => 'Historical Video retry.',
                'title' => 'Persisted Video title',
                'content_intent' => ['intent' => 'VIDEO', 'article_required' => false, 'semantic_delta' => ['status' => 'NONE']],
                'original_request' => ['intent' => 'VIDEO', 'video' => ['url' => 'https://youtu.be/dQw4w9WgXcQ']],
            ],
            [
                'content_preparation' => ['status' => 'PREPARED'],
                'semantic_write_back' => ['status' => 'SKIPPED', 'writes' => [], 'blockers' => []],
                'completion' => ['status' => 'PARTIAL', 'missing_required_owners' => [['owner_type' => 'video', 'owner_id' => '']], 'blockers' => ['REQUIRED_OWNER_READBACK_UNVERIFIED']],
                'resume_hints' => ['resume_children' => ['video']],
            ],
            ['SEMANTICS_RECONCILED' => ['status' => 'PARTIAL', 'result' => 'PARTIAL']],
        );
        $captures->create($capture);

        $result = $coordinator->retry($capture, ['resume_children' => ['video']]);

        self::assertSame(1, $events['video_intake'] ?? 0);
        self::assertSame(['url' => 'https://youtu.be/dQw4w9WgXcQ'], $events['video_request']);
        self::assertSame($videoId, $events['video_readback_owner']);
        self::assertSame($videoId, $result->diagnostics['completion']['required_owners'][0]['owner_id']);
        self::assertSame([], $result->diagnostics['completion']['missing_required_owners']);

        $replay = $coordinator->retry($result, ['resume_children' => ['video']]);
        self::assertSame(1, $events['video_intake']);
        self::assertSame($videoId, $replay->diagnostics['completion']['required_owners'][0]['owner_id']);
    }

    /** @dataProvider nonVideoIntentProvider */
    public function test_non_video_intent_never_enters_video_callbacks(string $intent, array $input, array $physicalItems): void
    {
        $videoEnrichmentCalls = 0;
        $videoVerificationCalls = 0;
        $draftCalls = 0;
        $resolvedHints = [];
        $coordinator = new EditorialCaptureCoordinator(
            new Pr5CaptureRepository(),
            static fn (array $request): array => ['items' => $physicalItems],
            static function (array $request) use (&$draftCalls): array { ++$draftCalls; return ['post_id' => 1001, 'state_token' => 'article-token']; },
            new TextInputInterpreter(),
            new SubjectResolutionService(static function (string $hint) use (&$resolvedHints): array { $resolvedHints[] = $hint; return []; }),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static fn (array $context): array => ['status' => 'SKIPPED', 'writes' => [], 'blockers' => []],
            new ArticleComposer(),
            static fn (array $context): array => ['status' => 'RECONCILED', 'media_ids' => ['media-1']],
            static fn (array $context): array => ['eligible' => false, 'blockers' => ['OWNER_PUBLICATION_REQUIRED']],
            static fn (array $context): array => ['status' => 'verified'],
            videoEnrichment: static function (array $context) use (&$videoEnrichmentCalls): array { ++$videoEnrichmentCalls; return ['items' => []]; },
            videoPublicationVerifier: static function (array $context) use (&$videoVerificationCalls): array { ++$videoVerificationCalls; return ['status' => 'review_required', 'items' => [], 'blockers' => ['VIDEO_EDITORIAL_QUALITY_BLOCKED']]; },
        );

        $result = $coordinator->execute($input + ['idempotency_key' => 'intent-isolation-' . $intent, 'intent' => $intent]);

        self::assertSame($intent, $result->diagnostics['content_intent']['intent']);
        self::assertSame(0, $videoEnrichmentCalls);
        self::assertSame(0, $videoVerificationCalls);
        self::assertNotContains('Video only subject hint', $resolvedHints);
        self::assertSame('not_requested', $result->diagnostics['video_publication']['status'] ?? 'not_requested');
        self::assertSame(in_array($intent, ['TEXT_ARTICLE', 'IMAGE_ARTICLE'], true) ? 1 : 0, $draftCalls);
        self::assertNotContains('VIDEO_EDITORIAL_QUALITY_BLOCKED', $result->diagnostics['completion']['blockers'] ?? []);
    }

    public static function nonVideoIntentProvider(): array
    {
        $image = ['kind' => 'image', 'media_id' => 'media-1', 'attachment_id' => 11, 'attachment_readback_status' => 'verified'];
        return [
            'text article with video input' => ['TEXT_ARTICLE', ['text' => 'Bài viết về đồng hồ.', 'video' => ['url' => 'https://youtu.be/dQw4w9WgXcQ', 'user_hint' => 'Video only subject hint']], []],
            'image article' => ['IMAGE_ARTICLE', ['text' => 'Bài viết có hình ảnh.'], [$image]],
            'media enrichment' => ['MEDIA_ENRICHMENT', ['text' => 'Bổ sung hình ảnh.'], [$image]],
            'knowledge delta' => ['KNOWLEDGE_DELTA', ['text' => '36/4 mặt vuông.'], []],
            'knowledge repair' => ['KNOWLEDGE_REPAIR', ['text' => 'Sửa ghi chú đã có.', 'knowledge_repair' => ['target_uuid' => '11111111-1111-4111-8111-111111111111', 'expected_revision' => 1, 'operation' => 'retire', 'reason' => 'Internal workflow text.', 'provenance' => ['source' => 'owner_review'], 'cleanup_class' => 'INTERNAL_WORKFLOW_KNOWLEDGE']], []],
        ];
    }

    public function test_video_intent_with_video_owner_keeps_video_callbacks(): void
    {
        $videoEnrichmentCalls = 0;
        $videoVerificationCalls = 0;
        $videoId = UuidCodec::newV7();
        $calls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $events = [];
        $coordinator = $this->coordinator(
            new Pr5CaptureRepository(),
            $calls,
            $events,
            videoEnrichment: static function (array $context) use (&$videoEnrichmentCalls, $videoId): array {
                ++$videoEnrichmentCalls;
                return ['items' => [['kind' => 'video', 'video_id' => $videoId]]];
            },
            videoPublication: static function (array $context) use (&$videoVerificationCalls, $videoId): array {
                ++$videoVerificationCalls;
                return ['status' => 'verified', 'items' => [['video_id' => $videoId]], 'blockers' => []];
            },
        );

        $result = $coordinator->execute([
            'idempotency_key' => 'video-intent-isolation',
            'intent' => 'VIDEO',
            'text' => 'Video về một đồng hồ.',
            'video' => ['url' => 'https://youtu.be/dQw4w9WgXcQ'],
        ]);

        self::assertSame('VIDEO', $result->diagnostics['content_intent']['intent']);
        self::assertSame(1, $videoEnrichmentCalls);
        self::assertSame(1, $videoVerificationCalls);
        self::assertSame(0, $calls['draft']);
    }

    public function test_preparation_happens_before_draft_and_review_stops_before_article_side_effects(): void
    {
        $captures = new Pr5CaptureRepository();
        $events = [];
        $calls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $modelId = '11111111-1111-4111-8111-111111111111';
        $resolver = new SubjectResolutionService(static fn (string $value): array => $value === $modelId
            ? [['id' => $modelId, 'type' => 'model', 'name' => 'Generic Model', 'revision' => 2]]
            : []);
        $coordinator = $this->coordinator(
            $captures,
            $calls,
            $events,
            subjectResolver: $resolver,
            preparation: new ContentPreparationOrchestrator($resolver),
        );

        $prepared = $coordinator->execute([
            'idempotency_key' => 'preparation-before-draft',
            'intent' => 'TEXT_ARTICLE',
            'text' => 'Generic Model có một quan sát biên tập.',
            'canonical_uuid' => $modelId,
        ]);

        self::assertSame('PREPARED', $prepared->diagnostics['content_preparation']['status']);
        self::assertSame(['physical', 'draft', 'semantic', 'media', 'publication', 'final'], $events);
        self::assertSame(1001, $prepared->articleId);

        $reviewCalls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $reviewEvents = [];
        $ambiguousResolver = new SubjectResolutionService(static fn (string $value): array => $value === 'Ambiguous'
            ? [
                ['id' => '33333333-3333-4333-8333-333333333333', 'type' => 'model', 'name' => 'Model A', 'revision' => 1],
                ['id' => '44444444-4444-4444-8444-444444444444', 'type' => 'model', 'name' => 'Model B', 'revision' => 1],
            ]
            : []);
        $reviewCoordinator = $this->coordinator(
            new Pr5CaptureRepository(),
            $reviewCalls,
            $reviewEvents,
            subjectResolver: $ambiguousResolver,
            preparation: new ContentPreparationOrchestrator($ambiguousResolver),
        );

        $review = $reviewCoordinator->execute([
            'idempotency_key' => 'preparation-review-before-draft',
            'intent' => 'TEXT_ARTICLE',
            'text' => 'Ambiguous input.',
            'subject_hints' => ['Ambiguous'],
        ]);

        self::assertSame('REVIEW_REQUIRED', $review->status);
        self::assertSame(0, $reviewCalls['draft']);
        self::assertSame(['physical'], $reviewEvents);
    }

    public function test_safe_optional_preparation_admits_article_draft_but_stops_before_exact_semantics(): void
    {
        $calls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $events = [];
        $resolver = new SubjectResolutionService(static fn (string $value): array => []);
        $coordinator = $this->coordinator(
            new Pr5CaptureRepository(),
            $calls,
            $events,
            subjectResolver: $resolver,
            preparation: new ContentPreparationOrchestrator($resolver),
        );

        $input = [
            'idempotency_key' => 'optional-preparation-article-admission',
            'intent' => 'TEXT_ARTICLE',
            'text' => 'Một bản thảo biên tập có chủ đề đủ dùng.',
        ];
        $result = $coordinator->execute($input);
        $retry = $coordinator->execute($input);

        self::assertSame(1001, $result->articleId);
        self::assertSame('REVIEW_REQUIRED', $result->status);
        self::assertSame(1, $calls['draft']);
        self::assertSame(0, $calls['semantic']);
        self::assertSame(['physical', 'draft'], $events);
        self::assertTrue($result->diagnostics['content_preparation']['continuation_decision']['may_continue'] ?? false);
        self::assertArrayNotHasKey('subject_resolution_packet', $result->diagnostics);
        self::assertSame('SUBJECT_UNRESOLVED', $result->diagnostics['deep_enrichment']['status']);
        self::assertSame($result->captureId, $retry->captureId);
        self::assertSame(1001, $retry->articleId);
    }

    public function test_safe_optional_preparation_admits_video_owner_without_fabricating_subject_packet(): void
    {
        $calls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $events = [];
        $videoCalls = 0;
        $resolver = new SubjectResolutionService(static fn (string $value): array => []);
        $videoId = UuidCodec::newV7();
        $coordinator = $this->coordinator(
            new Pr5CaptureRepository(),
            $calls,
            $events,
            subjectResolver: $resolver,
            preparation: new ContentPreparationOrchestrator($resolver),
            videoEnrichment: static function (array $context) use (&$videoCalls, $videoId): array {
                ++$videoCalls;
                return ['items' => [['kind' => 'video', 'video_id' => $videoId, 'video_proposal' => ['payload' => ['canonical_id' => $videoId]]]]];
            },
        );

        $input = [
            'idempotency_key' => 'optional-preparation-video-admission',
            'intent' => 'VIDEO',
            'text' => 'Video có danh tính nguồn xác định.',
            'video' => ['url' => 'https://youtu.be/dQw4w9WgXcQ'],
        ];
        $result = $coordinator->execute($input);
        $retry = $coordinator->execute($input);

        self::assertSame(1, $videoCalls);
        self::assertSame($videoId, $result->assets[0]['video_id'] ?? null);
        self::assertNotSame('COMPLETE', $result->status);
        self::assertArrayNotHasKey('subject_resolution_packet', $result->diagnostics);
        self::assertSame('REVIEW_REQUIRED', $result->diagnostics['content_preparation']['status']);
        self::assertSame($result->captureId, $retry->captureId);
        self::assertSame($videoId, $retry->assets[0]['video_id'] ?? null);
    }

    public function test_interrupted_prepared_capture_reuses_enrichment_and_article_on_retry(): void
    {
        $captures = new Pr5CaptureRepository();
        $events = [];
        $calls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $enrichmentCalls = 0;
        $draftAttempts = 0;
        $modelId = '11111111-1111-4111-8111-111111111111';
        $resolver = new SubjectResolutionService(static fn (string $value): array => $value === $modelId
            ? [['id' => $modelId, 'type' => 'model', 'name' => 'Generic Model', 'revision' => 2]]
            : []);
        $preparation = new ContentPreparationOrchestrator($resolver, null, static function () use (&$enrichmentCalls, &$events): array {
            ++$enrichmentCalls;
            $events[] = 'enrichment';
            return ['status' => 'APPLIED', 'canonical_readback' => ['canonical_id' => 'classification-1', 'revision' => 2]];
        });
        $coordinator = $this->coordinator(
            $captures,
            $calls,
            $events,
            draft: static function (array $input) use (&$draftAttempts, &$events): array {
                ++$draftAttempts;
                $events[] = 'draft';
                if ($draftAttempts === 1) throw new \RuntimeException('INTERRUPTED_AFTER_PREPARATION');
                return ['post_id' => 1001, 'state_token' => 'article-token'];
            },
            subjectResolver: $resolver,
            preparation: $preparation,
        );
        $input = [
            'idempotency_key' => 'preparation-resume-same-capture',
            'intent' => 'TEXT_ARTICLE',
            'text' => 'Generic Model with a governed classification.',
            'canonical_uuid' => $modelId,
            'content_preparation' => ['enrichment_requests' => [['locator' => 'classification-1', 'evidence_supported' => true]]],
        ];

        $first = $coordinator->execute($input);
        $second = $coordinator->execute($input);

        self::assertNotSame('COMPLETE', $first->status);
        self::assertSame($first->captureId, $second->captureId);
        self::assertSame('READY_FOR_PUBLICATION', $second->stage);
        self::assertSame(1, $enrichmentCalls);
        self::assertSame(2, $draftAttempts);
        self::assertSame('PREPARED', $second->diagnostics['content_preparation']['status']);
        self::assertLessThan(array_search('draft', $events, true), array_search('enrichment', $events, true));
    }

    public function test_legacy_review_is_re_evaluated_by_new_content_preparation_without_new_capture(): void
    {
        $captures = new Pr5CaptureRepository();
        $events = [];
        $calls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $subjectId = '11111111-1111-4111-8111-111111111111';
        $packet = new SubjectResolutionPacket('resolved', $subjectId, 'model', 'nhk:model:generic', 'Generic Model', 3, 'persisted', [], 'USER_CONFIRMED_SUBJECT_RECONCILIATION');
        $resolver = new SubjectResolutionService(static function (): array {
            throw new \RuntimeException('WEAKER_RESOLUTION_MUST_NOT_RUN');
        });
        $capture = new CaptureRecord(
            '01a0cb8d-31a5-700b-8780-3793df5d0969', 'legacy-review-re-evaluate', hash('sha256', 'legacy-review-re-evaluate'),
            CaptureStage::SEMANTICS_RECONCILED->value, 'REVIEW_REQUIRED', null, null, [],
            ['raw_input' => 'Ambiguous raw hint.', 'content_intent' => ['intent' => 'TEXT_ARTICLE', 'article_required' => true], 'subject_resolution_packet' => $packet->toArray()],
            [
                'content_preparation' => ['status' => 'REVIEW_REQUIRED', 'preparation_fingerprint' => hash('sha256', 'old'), 'quality_decision' => 'READY', 'review_reasons' => ['PRIMARY_SUBJECT_AMBIGUOUS']],
                'completion' => ['status' => 'REVIEW_REQUIRED', 'blockers' => []],
            ],
            ['CONTENT_PREPARATION' => ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'VIDEO_EDITORIAL_QUALITY_BLOCKED']],
            revision: 18,
        );
        $captures->create($capture);
        $coordinator = $this->coordinator($captures, $calls, $events, subjectResolver: $resolver, preparation: new ContentPreparationOrchestrator($resolver));
        $addenda = new class implements CaptureAddendumRepository {
            /** @var array<string,CaptureAddendumRecord> */
            public array $records = [];
            public function findByIdempotencyKey(string $key): ?CaptureAddendumRecord { return $this->records[$key] ?? null; }
            public function create(CaptureAddendumRecord $record): CaptureAddendumRecord { return $this->records[$record->idempotencyKey] = $record; }
            public function save(CaptureAddendumRecord $record): CaptureAddendumRecord { return $this->records[$record->idempotencyKey] = $record; }
        };
        $service = new \NHK\Core\Application\Capture\EditorialCaptureContinuationService($captures, $addenda, $coordinator);

        $result = $service->retry(['capture_id' => $capture->captureId, 'idempotency_key' => $capture->idempotencyKey, 'resume_mode' => 'RETRY']);

        self::assertSame($capture->captureId, $result['capture']['capture_id']);
        self::assertGreaterThan(18, $result['capture']['revision']);
        self::assertSame('PREPARED', $result['capture']['diagnostics']['content_preparation']['status']);
        self::assertSame($subjectId, $result['capture']['context']['subject_resolution_packet']['canonical_subject_id']);
        self::assertSame('COMPLETED', $result['capture']['phase_receipts']['CONTENT_PREPARATION']['status']);
        self::assertContains('VIDEO_EDITORIAL_QUALITY_BLOCKED', $result['capture']['phase_receipts']['CONTENT_PREPARATION']['superseded_failure_codes']);
        self::assertNotSame('STALE_REVIEW_REEVALUATABLE', $result['retry']['code']);
    }

    public function test_text_article_pipeline_replays_same_capture_and_owner_writes(): void
    {
        $captures = new Pr5CaptureRepository();
        $events = [];
        $calls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $coordinator = $this->coordinator($captures, $calls, $events);
        $input = [
            'idempotency_key' => 'pr5-text-article',
            'intent' => 'TEXT_ARTICLE',
            'title' => 'Bài kiểm tra hội tụ',
            'text' => 'Chiếc đồng hồ có mặt số xanh. Bộ máy dùng cấu hình 36/4.',
        ];

        $first = $coordinator->execute($input);
        $replay = $coordinator->execute($input);

        self::assertSame($first->captureId, $replay->captureId);
        self::assertSame(1001, $first->articleId);
        self::assertSame(['draft' => 1, 'semantic' => 1, 'media' => 1, 'publication' => 1, 'final' => 1], $calls);
        self::assertSame(['physical', 'draft', 'semantic', 'media', 'publication', 'final'], $events);
        self::assertSame('TEXT_ARTICLE', $first->diagnostics['content_intent']['intent']);
        self::assertSame([['owner_type' => 'wp_post', 'owner_id' => '1001']], $first->diagnostics['completion']['required_owners']);
        self::assertContains('ARTICLE_NOT_PUBLISHED', $first->diagnostics['completion']['blockers']);
        self::assertNotContains('MEDIA_REQUIRED', $first->diagnostics['completion']['blockers']);
    }

    public function test_media_only_multi_image_submission_converges_to_one_image_article_with_ordered_children(): void
    {
        $assets = [
            ['client_file_id' => 'front', 'media_id' => 'media-front', 'sort_order' => 2, 'upload_status' => 'CREATED'],
            ['client_file_id' => 'dial', 'media_id' => 'media-dial', 'sort_order' => 1, 'upload_status' => 'CREATED'],
            ['client_file_id' => 'back', 'media_id' => 'media-back', 'sort_order' => 3, 'upload_status' => 'CREATED'],
        ];
        $route = (new ContentIntentRouter())->route(
            ['intent' => 'IMAGE_ARTICLE', 'title' => 'Album hội tụ', 'text' => 'Ba góc chụp của cùng hiện vật.'],
            [],
            $assets,
        );
        usort($assets, static fn (array $left, array $right): int => $left['sort_order'] <=> $right['sort_order']);

        self::assertSame('IMAGE_ARTICLE', $route['intent']);
        self::assertTrue($route['article_required']);
        self::assertCount(3, $assets);
        self::assertSame(['media-dial', 'media-front', 'media-back'], array_column($assets, 'media_id'));
        self::assertCount(3, array_unique(array_column($assets, 'client_file_id')));
    }

    public function test_capture_normalizes_physical_manifest_order_and_replays_same_order(): void
    {
        $captures = new Pr5CaptureRepository();
        $calls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $events = [];
        $coordinator = $this->coordinator(
            $captures,
            $calls,
            $events,
            semanticStatus: 'COMPLETED',
            physical: static fn (array $input): array => ['items' => [
                ['client_file_id' => 'back', 'media_id' => 'media-back', 'sort_order' => 2],
                ['client_file_id' => 'front', 'media_id' => 'media-front', 'sort_order' => 0],
                ['client_file_id' => 'dial', 'media_id' => 'media-dial', 'sort_order' => 1],
            ]],
        );

        $input = ['idempotency_key' => 'ordered-capture-manifest', 'text' => 'Ba góc chụp của cùng một hiện vật.'];
        $first = $coordinator->execute($input);
        $replay = $coordinator->execute($input);

        self::assertSame($first->captureId, $replay->captureId);
        self::assertSame(['media-front', 'media-dial', 'media-back'], array_column($first->assets, 'media_id'));
        self::assertSame([0, 1, 2], array_column($first->assets, 'sort_order'));
        self::assertSame($first->assets, $replay->assets);
        self::assertSame(1, $calls['draft']);
    }

    public function test_shared_description_enters_semantic_capture_with_empty_features_and_one_article(): void
    {
        $captures = new Pr5CaptureRepository();
        $calls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $events = [];
        $seen = [];
        $coordinator = $this->coordinator(
            $captures,
            $calls,
            $events,
            semanticStatus: 'COMPLETED',
            physical: static fn (array $input): array => ['items' => [
                ['kind' => 'image', 'media_id' => 'media-front', 'sort_order' => 0, 'capture_asset_input' => ['name' => 'Ảnh mặt trước', 'feature_requests' => []]],
                ['kind' => 'image', 'media_id' => 'media-case', 'sort_order' => 1, 'capture_asset_input' => ['name' => 'Ảnh vỏ', 'feature_requests' => []]],
                ['kind' => 'image', 'media_id' => 'media-movement', 'sort_order' => 2, 'capture_asset_input' => ['name' => 'Ảnh bộ máy', 'feature_requests' => []]],
            ]],
            sharedObserver: static function (array $context) use (&$seen): void {
                $seen = [
                    'raw_input' => (string) ($context['raw_input'] ?? ''),
                    'editorial_copy' => (string) ($context['editorial_copy'] ?? ''),
                    'intent' => (string) ($context['content_intent']['intent'] ?? ''),
                    'asset_names' => array_map(static fn (array $asset): string => (string) (($asset['capture_asset_input']['name'] ?? '')), array_values(array_filter((array) ($context['assets'] ?? []), 'is_array'))),
                ];
            },
        );

        $result = $coordinator->execute([
            'idempotency_key' => 'shared-description-empty-features',
            'description' => 'Một chiếc đồng hồ cơ được chụp ở ba góc để nhận diện mặt trước, vỏ và bộ máy chuông.',
        ]);

        self::assertSame('IMAGE_ARTICLE', $result->diagnostics['content_intent']['intent']);
        self::assertSame(1001, $result->articleId);
        self::assertSame(1, $calls['draft']);
        self::assertStringContainsString('Một chiếc đồng hồ cơ', $seen['raw_input']);
        self::assertStringContainsString('Một chiếc đồng hồ cơ', $seen['editorial_copy']);
        self::assertSame(['Ảnh mặt trước', 'Ảnh vỏ', 'Ảnh bộ máy'], $seen['asset_names']);
    }

    public function test_knowledge_delta_without_image_has_no_article_and_is_not_semantically_complete_when_pending(): void
    {
        $captures = new Pr5CaptureRepository();
        $calls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $events = [];
        $coordinator = $this->coordinator($captures, $calls, $events, semanticStatus: 'REVIEW_REQUIRED');

        $result = $coordinator->execute([
            'idempotency_key' => 'pr5-knowledge-delta',
            'intent' => 'KNOWLEDGE_DELTA',
            'text' => '36/4 mặt vuông',
        ]);

        self::assertNull($result->articleId);
        self::assertSame('KNOWLEDGE_DELTA', $result->diagnostics['content_intent']['intent']);
        self::assertSame('not_requested', $result->diagnostics['visual_support']['status']);
        self::assertSame(0, $calls['draft']);
        self::assertSame(0, $calls['media']);
        self::assertFalse($result->diagnostics['completion']['complete']);
        self::assertContains('REQUIRED_OWNER_READBACK_UNVERIFIED', $result->diagnostics['completion']['blockers']);
    }

    public function test_article_failure_does_not_reconcile_unrequested_video_owner(): void
    {
        $captures = new Pr5CaptureRepository();
        $calls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $events = [];
        $coordinator = $this->coordinator(
            $captures,
            $calls,
            $events,
            videoEnrichment: static fn (array $input): array => ['items' => [['kind' => 'video', 'video_id' => 'video-pr5-1']]],
            videoPublication: static fn (array $input): array => throw new \RuntimeException('UNREQUESTED_VIDEO_VERIFICATION'),
            media: static function (array $input): array { throw new \RuntimeException('ARTICLE_RECONCILIATION_FAILED'); },
        );

        $result = $coordinator->execute([
            'idempotency_key' => 'pr5-video-article-partial',
            'intent' => 'TEXT_ARTICLE',
            'text' => 'Bài độc lập về chiếc đồng hồ. Có thêm ngữ cảnh video.',
            'video' => ['url' => 'https://youtu.be/dQw4w9WgXcQ'],
        ]);

        $completion = $result->diagnostics['completion'];
        $videoChildren = array_values(array_filter($completion['children'], static fn (array $child): bool => ($child['owner_type'] ?? '') === 'video'));
        self::assertSame('FAILED_RETRYABLE', $result->status);
        self::assertSame('BLOCKED', $completion['status']);
        self::assertFalse($completion['complete']);
        self::assertContains('CANONICAL_READBACK_UNVERIFIED', $completion['blockers']);
        self::assertSame([], $videoChildren);
        self::assertSame('not_requested', $result->diagnostics['video_publication']['status']);
        self::assertContains('article', $completion['resume_hints']['resume_children']);
    }

    public function test_existing_knowledge_and_article_are_reuse_candidates_not_new_deep_content(): void
    {
        $captures = new Pr5CaptureRepository();
        $calls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $events = [];
        $coordinator = $this->coordinator(
            $captures,
            $calls,
            $events,
            semanticExtra: ['reused_claims' => [['claim_id' => 'claim-existing', 'revision' => 3]]],
            media: static fn (array $context): array => ['status' => 'RECONCILED', 'internal_link_candidates' => [['post_id' => 88, 'url' => '/bai-lien-quan/']]],
        );

        $result = $coordinator->execute([
            'idempotency_key' => 'pr5-reuse-existing',
            'intent' => 'TEXT_ARTICLE',
            'text' => 'Bài viết độc lập về cấu hình này. Có thêm một chi tiết hữu ích.',
        ]);

        self::assertSame('REUSE_EXISTING', $result->diagnostics['deep_enrichment']['status']);
        self::assertSame('claim-existing', $result->diagnostics['deep_enrichment']['knowledge_reuse'][0]['claim_id']);
        self::assertSame(88, $result->diagnostics['deep_enrichment']['article_reuse_internal_link'][0]['post_id']);
        self::assertNull($result->diagnostics['deep_enrichment']['new_deep_content_opportunity']);
    }

    public function test_video_capture_composition_keeps_category_review_separate_from_owner_readback(): void
    {
        $captures = new Pr5CaptureRepository();
        $calls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $events = [];
        $videoId = UuidCodec::newV7();
        $proposalId = UuidCodec::newV7();
        $coordinator = $this->coordinator(
            $captures,
            $calls,
            $events,
            semanticExtra: [
                'status' => 'APPLIED',
                'writes' => [[
                    'entity_type' => 'video',
                    'operation' => 'ingest',
                    'proposal_id' => $proposalId,
                    'status' => 'APPLIED',
                    'canonical_id' => $videoId,
                    'canonical_readback' => ['canonical_id' => $videoId, 'entity_type' => 'video', 'active' => true, 'revision' => 2],
                    'completion' => ['owner_type' => 'video', 'owner_id' => $videoId, 'canonical_state' => 'COMPLETE', 'canonical_readback_verified' => true, 'dependency_state' => 'COMPLETE', 'relation_or_usage_state' => 'COMPLETE', 'content_state' => 'CONTENT_COMPLETE', 'public_state' => 'BLOCKED', 'frontend_state' => 'BLOCKED', 'complete' => false, 'status' => 'PARTIAL', 'blockers' => ['CATEGORY_UNRESOLVED']],
                    'metadata' => ['category' => ['primary' => null], 'completeness' => ['publishable' => false, 'blockers' => ['CATEGORY_UNRESOLVED']]],
                ]],
            ],
            videoEnrichment: static function (array $input) use ($videoId): array {
                return [
                'status' => 'verified',
                'items' => [[
                    'kind' => 'video',
                    'video_proposal' => [
                        'entity_type' => 'video',
                        'operation' => 'ingest',
                        'subject_id' => $videoId,
                        'payload' => ['canonical_id' => $videoId, 'metadata' => ['category' => ['primary' => null], 'completeness' => ['publishable' => false, 'blockers' => ['CATEGORY_UNRESOLVED']]]],
                    ],
                ]],
                ];
            },
            videoPublication: static function (array $input) use ($videoId): array {
                return [
                'status' => 'review_required',
                'items' => [['video_id' => $videoId]],
                'blockers' => ['CATEGORY_UNRESOLVED'],
                ];
            },
        );

        $result = $coordinator->execute([
            'idempotency_key' => 'production-shaped-video-category-' . bin2hex(random_bytes(4)),
            'intent' => 'VIDEO',
            'purpose' => 'EDITORIAL',
            'approval_confirmed' => true,
            'publish' => false,
            'title' => 'Production-shaped Video review',
            'text' => 'Video semantic owner remains canonical while publication classification is unresolved.',
            'video' => ['url' => 'https://youtu.be/' . substr(bin2hex(random_bytes(6)), 0, 11)],
        ]);

        self::assertSame('REVIEW_REQUIRED', $result->status);
        self::assertSame($videoId, $result->diagnostics['semantic_write_back']['writes'][0]['canonical_readback']['canonical_id']);
        self::assertSame($videoId, $result->diagnostics['completion']['required_owners'][0]['owner_id']);
        self::assertSame([], $result->diagnostics['completion']['missing_required_owners']);
        self::assertNotContains('REQUIRED_OWNER_READBACK_UNVERIFIED', $result->diagnostics['completion']['blockers']);
        self::assertSame('verified', $result->diagnostics['final_read_back']['status']);
        self::assertContains('CATEGORY_UNRESOLVED', $result->diagnostics['video_publication']['blockers']);
        self::assertNull($result->assets[0]['video_proposal']['payload']['metadata']['category']['primary']);
    }

    public function test_missing_video_owner_cannot_be_reported_as_verified_final_readback(): void
    {
        $captures = new Pr5CaptureRepository();
        $calls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $events = [];
        $videoId = UuidCodec::newV7();
        $coordinator = $this->coordinator(
            $captures,
            $calls,
            $events,
            videoEnrichment: static function (array $input) use ($videoId): array {
                return ['items' => [['kind' => 'video', 'video_id' => $videoId]]];
            },
            videoPublication: static function (array $input) use ($videoId): array {
                return ['status' => 'review_required', 'items' => [['video_id' => $videoId]], 'blockers' => ['CATEGORY_UNRESOLVED']];
            },
        );

        $result = $coordinator->execute([
            'idempotency_key' => 'production-shaped-video-missing-owner-' . bin2hex(random_bytes(4)),
            'intent' => 'VIDEO',
            'purpose' => 'EDITORIAL',
            'text' => 'Review must not masquerade as owner verification.',
            'video' => ['url' => 'https://youtu.be/' . substr(bin2hex(random_bytes(6)), 0, 11)],
        ]);

        self::assertSame('REVIEW_REQUIRED', $result->status);
        self::assertSame('blocked', $result->diagnostics['final_read_back']['status']);
        self::assertSame('REQUIRED_OWNER_READBACK_UNVERIFIED', $result->diagnostics['final_read_back']['failure_code']);
        self::assertContains('REQUIRED_OWNER_READBACK_UNVERIFIED', $result->diagnostics['completion']['blockers']);
    }

    public function test_video_child_readback_is_registered_when_retry_result_is_only_in_video_children(): void
    {
        $captures = new Pr5CaptureRepository();
        $calls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $events = [];
        $videoId = UuidCodec::newV7();
        $coordinator = $this->coordinator(
            $captures,
            $calls,
            $events,
            semanticStatus: 'APPLIED',
            semanticExtra: [
                'video_children' => [[
                    'status' => 'APPLIED',
                    'canonical_id' => $videoId,
                    'canonical_readback' => ['canonical_id' => $videoId, 'entity_type' => 'video', 'active' => true, 'revision' => 2],
                ]],
            ],
            videoEnrichment: static fn (): array => ['items' => [[
                'kind' => 'video',
                'video_id' => $videoId,
                'video_proposal' => ['operation' => 'ingest', 'payload' => ['canonical_id' => $videoId]],
            ]]],
            videoPublication: static fn (): array => ['status' => 'not_requested', 'items' => [], 'blockers' => []],
        );

        $result = $coordinator->execute([
            'idempotency_key' => 'video-child-readback-registration-' . bin2hex(random_bytes(4)),
            'intent' => 'VIDEO',
            'purpose' => 'EDITORIAL',
            'text' => 'Retry result retains the canonical Video child read-back.',
            'video' => ['url' => 'https://youtu.be/' . substr(bin2hex(random_bytes(6)), 0, 11)],
        ]);

        self::assertSame([['owner_type' => 'video', 'owner_id' => $videoId]], $result->diagnostics['completion']['required_owners']);
        self::assertSame([], $result->diagnostics['completion']['missing_required_owners']);
        self::assertSame($videoId, $result->diagnostics['completion']['children'][0]['owner_id']);
        self::assertSame($videoId, $result->diagnostics['completion']['children'][0]['canonical_readback']['canonical_id']);
    }

    /**
     * @param array<string,int> $calls
     * @param list<string> $events
     */
    private function coordinator(
        Pr5CaptureRepository $captures,
        array &$calls,
        array &$events,
        string $semanticStatus = 'REVIEW_REQUIRED',
        ?callable $videoEnrichment = null,
        ?callable $videoPublication = null,
        ?callable $media = null,
        array $semanticExtra = [],
        ?SubjectResolutionService $subjectResolver = null,
        ?ContentPreparationOrchestrator $preparation = null,
        ?callable $draft = null,
        ?callable $final = null,
        ?callable $physical = null,
        ?SharedEnrichmentBoundary $shared = null,
        ?callable $sharedObserver = null,
    ): EditorialCaptureCoordinator {
        return new EditorialCaptureCoordinator(
            $captures,
            $physical ?? static function (array $input) use (&$events): array { $events[] = 'physical'; return ['items' => []]; },
            $draft ?? static function (array $input) use (&$calls, &$events): array { ++$calls['draft']; $events[] = 'draft'; return ['post_id' => 1001, 'state_token' => 'article-token']; },
            new TextInputInterpreter(),
            $subjectResolver ?? new SubjectResolutionService(static fn (string $hint): array => []),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static function (array $context) use (&$calls, &$events, $sharedObserver, $semanticStatus, $semanticExtra): array { ++$calls['semantic']; $events[] = 'semantic'; if ($sharedObserver !== null) $sharedObserver($context); return array_merge(['status' => $semanticStatus, 'writes' => []], $semanticExtra); },
            new ArticleComposer(),
            $media ?? static function (array $context) use (&$calls, &$events): array { ++$calls['media']; $events[] = 'media'; return ['status' => 'RECONCILED']; },
            static function (array $context) use (&$calls, &$events): array { ++$calls['publication']; $events[] = 'publication'; return ['eligible' => true]; },
            $final ?? static function (array $context) use (&$calls, &$events): array { ++$calls['final']; $events[] = 'final'; return ['status' => 'verified']; },
            null,
            null,
            null,
            null,
            $videoEnrichment,
            $videoPublication,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $preparation,
            $shared,
        );
    }
}

final class Pr5CaptureRepository implements CaptureRepository
{
    /** @var array<string,CaptureRecord> */
    private array $records = [];

    public function findByIdempotencyKey(string $key): ?CaptureRecord { return $this->records[$key] ?? null; }

    public function findById(string $captureId): ?CaptureRecord
    {
        foreach ($this->records as $record) if ($record->captureId === $captureId) return $record;
        return null;
    }

    public function create(CaptureRecord $record): CaptureRecord { return $this->records[$record->idempotencyKey] ??= $record; }

    public function save(CaptureRecord $record): CaptureRecord { return $this->records[$record->idempotencyKey] = $record; }
}
