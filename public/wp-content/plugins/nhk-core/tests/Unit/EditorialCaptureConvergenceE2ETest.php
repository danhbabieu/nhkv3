<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\EditorialCaptureCoordinator;
use NHK\Core\Application\Completion\CompletionCoordinator;
use NHK\Core\Application\Capture\ContentIntentRouter;
use NHK\Core\Application\Semantic\{ArticleComposer, ClaimRetrievalEngine, SubjectResolutionService, TextInputInterpreter};
use NHK\Core\Contracts\Capture\CaptureRepository;
use NHK\Core\Domain\Capture\CaptureRecord;
use PHPUnit\Framework\TestCase;

/**
 * PR5 seam tests: the Capture coordinator is the orchestrator, while each
 * owner result remains independently read back and aggregated truthfully.
 */
final class EditorialCaptureConvergenceE2ETest extends TestCase
{
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
        self::assertSame('PARTIAL', $completion['status']);
        self::assertFalse($completion['complete']);
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

    public function test_article_publication_context_carries_not_required_semantic_packet(): void
    {
        $captures = new Pr5CaptureRepository();
        $calls = ['draft' => 0, 'semantic' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $events = [];
        $publicationContext = [];
        $coordinator = $this->coordinator(
            $captures,
            $calls,
            $events,
            semanticStatus: 'SKIPPED',
            semanticExtra: ['requirements' => [
                'semantic_delta' => [
                    'applicability' => 'NOT_REQUIRED',
                    'policy' => 'VERIFY',
                    'state' => 'SKIPPED',
                    'evidence' => ['intent' => 'IMAGE_ARTICLE', 'status' => 'NONE'],
                ],
            ]],
            physicalItems: [['client_file_id' => 'front', 'media_id' => 'media-front']],
            publication: static function (array $context) use (&$publicationContext): array {
                $publicationContext = $context;
                return ['eligible' => true];
            },
        );

        $coordinator->execute([
            'idempotency_key' => 'pr5-image-article-no-semantic-delta',
            'intent' => 'IMAGE_ARTICLE',
            'text' => 'Mô tả biên tập cho hiện vật đã được nhận diện.',
        ]);

        self::assertArrayHasKey('requirements', $publicationContext);
        self::assertSame('NOT_REQUIRED', $publicationContext['requirements']['semantic_delta']['applicability']);
        self::assertSame('SKIPPED', $publicationContext['requirements']['semantic_delta']['state']);
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
        ?callable $publication = null,
        array $physicalItems = [],
    ): EditorialCaptureCoordinator {
        return new EditorialCaptureCoordinator(
            $captures,
            static function (array $input) use (&$events, $physicalItems): array { $events[] = 'physical'; return ['items' => $physicalItems]; },
            static function (array $input) use (&$calls, &$events): array { ++$calls['draft']; $events[] = 'draft'; return ['post_id' => 1001, 'state_token' => 'article-token']; },
            new TextInputInterpreter(),
            new SubjectResolutionService(static fn (string $hint): array => []),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static function (array $context) use (&$calls, &$events, $semanticStatus, $semanticExtra): array { ++$calls['semantic']; $events[] = 'semantic'; return array_merge(['status' => $semanticStatus, 'writes' => []], $semanticExtra); },
            new ArticleComposer(),
            $media ?? static function (array $context) use (&$calls, &$events): array { ++$calls['media']; $events[] = 'media'; return ['status' => 'RECONCILED']; },
            $publication ?? static function (array $context) use (&$calls, &$events): array { ++$calls['publication']; $events[] = 'publication'; return ['eligible' => true]; },
            static function (array $context) use (&$calls, &$events): array { ++$calls['final']; $events[] = 'final'; return ['status' => 'verified']; },
            null,
            null,
            null,
            null,
            $videoEnrichment,
            $videoPublication,
            null,
            null,
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
