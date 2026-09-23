<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\{ContentPreparationOrchestrator, EditorialCaptureCoordinator};
use NHK\Core\Application\Completion\CompletionCoordinator;
use NHK\Core\Application\Capture\ContentIntentRouter;
use NHK\Core\Application\Semantic\{ArticleComposer, ClaimRetrievalEngine, SubjectResolutionService, TextInputInterpreter};
use NHK\Core\Contracts\Capture\CaptureRepository;
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

/**
 * PR5 seam tests: the Capture coordinator is the orchestrator, while each
 * owner result remains independently read back and aggregated truthfully.
 */
final class EditorialCaptureConvergenceE2ETest extends TestCase
{
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
        $preparation = new ContentPreparationOrchestrator($resolver, null, static function () use (&$enrichmentCalls): array {
            ++$enrichmentCalls;
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
        self::assertSame([['owner_type' => 'wp_post', 'owner_id' => '']], $first->diagnostics['completion']['required_owners']);
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

    public function test_video_owner_success_and_later_article_failure_are_reported_as_partial(): void
    {
        $captures = new Pr5CaptureRepository();
        $calls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $events = [];
        $videoCompletion = (new CompletionCoordinator())->finalize('video', 'video-pr5-1', [
            'canonical_readback' => ['canonical_id' => 'video-pr5-1'],
            'dependency_state' => 'COMPLETE',
            'relation_or_usage_state' => 'NOT_APPLICABLE',
            'content_quality' => 'CONTENT_COMPLETE',
            'public_eligible' => true,
            'frontend_verified' => true,
        ]);
        $coordinator = $this->coordinator(
            $captures,
            $calls,
            $events,
            videoEnrichment: static fn (array $input): array => ['items' => [['kind' => 'video', 'video_id' => 'video-pr5-1']]],
            videoPublication: static fn (array $input): array => ['status' => 'verified', 'items' => [['completion' => $videoCompletion]], 'blockers' => []],
            media: static function (array $input): array { throw new \RuntimeException('ARTICLE_RECONCILIATION_FAILED'); },
        );

        $result = $coordinator->execute([
            'idempotency_key' => 'pr5-video-article-partial',
            'intent' => 'TEXT_ARTICLE',
            'text' => 'Bài độc lập về chiếc đồng hồ. Có thêm ngữ cảnh video.',
            'video' => ['url' => 'https://youtu.be/dQw4w9WgXcQ'],
        ]);

        $completion = $result->diagnostics['completion'];
        $videoChild = array_values(array_filter($completion['children'], static fn (array $child): bool => ($child['owner_type'] ?? '') === 'video'))[0] ?? [];
        self::assertSame('FAILED_RETRYABLE', $result->status);
        self::assertSame('BLOCKED', $completion['status']);
        self::assertFalse($completion['complete']);
        self::assertContains('CANONICAL_READBACK_UNVERIFIED', $completion['blockers']);
        self::assertTrue($videoChild['complete']);
        self::assertContains('article', $completion['resume_hints']['resume_children']);
        self::assertSame('video-pr5-1', $videoChild['owner_id']);
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
    ): EditorialCaptureCoordinator {
        return new EditorialCaptureCoordinator(
            $captures,
            static function (array $input) use (&$events): array { $events[] = 'physical'; return ['items' => []]; },
            $draft ?? static function (array $input) use (&$calls, &$events): array { ++$calls['draft']; $events[] = 'draft'; return ['post_id' => 1001, 'state_token' => 'article-token']; },
            new TextInputInterpreter(),
            $subjectResolver ?? new SubjectResolutionService(static fn (string $hint): array => []),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static function (array $context) use (&$calls, &$events, $semanticStatus, $semanticExtra): array { ++$calls['semantic']; $events[] = 'semantic'; return array_merge(['status' => $semanticStatus, 'writes' => []], $semanticExtra); },
            new ArticleComposer(),
            $media ?? static function (array $context) use (&$calls, &$events): array { ++$calls['media']; $events[] = 'media'; return ['status' => 'RECONCILED']; },
            static function (array $context) use (&$calls, &$events): array { ++$calls['publication']; $events[] = 'publication'; return ['eligible' => true]; },
            static function (array $context) use (&$calls, &$events): array { ++$calls['final']; $events[] = 'final'; return ['status' => 'verified']; },
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
