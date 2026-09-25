<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\EditorialCaptureCoordinator;
use NHK\Core\Application\Semantic\{ArticleComposer, ClaimRetrievalEngine, SubjectResolutionService, TextInputInterpreter};
use NHK\Core\Contracts\Capture\CaptureRepository;
use NHK\Core\Domain\Capture\CaptureRecord;
use PHPUnit\Framework\TestCase;

final class ImageArticleProductionFlowTest extends TestCase
{
    public function test_generic_image_article_reuses_one_subject_packet_through_publish_and_ignores_stale_media_binding(): void
    {
        $flow = new ImageArticleFlowFixture([
            ['media_id' => 'media-explicit', 'attachment_id' => 712, 'upload_status' => 'REUSED', 'sort_order' => 0],
        ], fullPath: true);

        $result = $flow->run([
            'idempotency_key' => 'generic-image-article-full-path',
            'subject_hints' => ['subject-explicit'],
            'article_media_bindings' => [
                ['media_id' => 'media-stale', 'role' => 'inline_primary', 'selection_source' => 'SYSTEM_REUSABLE'],
            ],
            'publish' => true,
        ]);

        self::assertSame(1, $result->articleId);
        self::assertSame('PUBLISHED', $result->stage);
        self::assertSame(1, $flow->draftCalls);
        self::assertSame(['media-explicit'], array_column($flow->usages, 'media_id'));
        self::assertSame('/anh-kiem-thu/', $result->diagnostics['final_read_back']['permalink']);
        self::assertSame('verified', $result->diagnostics['final_read_back']['status']);
        $packetIds = array_values(array_filter($flow->packetIds));
        self::assertNotEmpty($packetIds);
        self::assertSame(1, count(array_unique($packetIds)));
        self::assertSame('subject-explicit', $flow->subjectHintSeen);
    }

    public function test_one_existing_image_creates_one_article_and_one_usage_without_upload_or_duplicate(): void
    {
        $flow = new ImageArticleFlowFixture([
            ['media_id' => 'media-existing', 'attachment_id' => 612, 'upload_status' => 'REUSED', 'sort_order' => 0],
        ]);
        $result = $flow->run();

        self::assertSame(1, $result->articleId);
        self::assertSame(1, $flow->draftCalls);
        self::assertSame(0, $flow->uploadCalls);
        self::assertCount(1, $flow->usages);
        self::assertSame('media-existing', $flow->usages[0]['media_id']);
        self::assertSame('1:1', $flow->usages[0]['endpoint_key']);
    }

    public function test_three_image_album_preserves_exact_order_and_contextual_metadata(): void
    {
        $flow = new ImageArticleFlowFixture([
            ['media_id' => 'media-b', 'attachment_id' => 612, 'upload_status' => 'REUSED', 'sort_order' => 0, 'media_context' => ['title' => 'B', 'alt_text' => 'Alt B', 'caption' => 'Caption B']],
            ['media_id' => 'media-a', 'attachment_id' => 613, 'upload_status' => 'REUSED', 'sort_order' => 1, 'media_context' => ['title' => 'A', 'alt_text' => 'Alt A', 'caption' => 'Caption A']],
            ['media_id' => 'media-c', 'attachment_id' => 614, 'upload_status' => 'REUSED', 'sort_order' => 2, 'media_context' => ['title' => 'C', 'alt_text' => 'Alt C', 'caption' => 'Caption C']],
        ]);
        $result = $flow->run();

        self::assertSame(1, $result->articleId);
        self::assertSame(['media-b', 'media-a', 'media-c'], array_column($flow->usages, 'media_id'));
        self::assertSame([0, 1, 2], array_column($flow->usages, 'sort_order'));
        self::assertSame(['B', 'A', 'C'], array_column($flow->usages, 'title'));
        self::assertSame(['Alt B', 'Alt A', 'Alt C'], array_column($flow->usages, 'alt_text'));
        self::assertSame(['Caption B', 'Caption A', 'Caption C'], array_column($flow->usages, 'caption'));
    }

    public function test_retry_after_failure_after_article_creation_reuses_article_and_usage(): void
    {
        $flow = new ImageArticleFlowFixture([
            ['media_id' => 'media-existing', 'attachment_id' => 612, 'upload_status' => 'REUSED', 'sort_order' => 0],
        ], failMediaOnce: true);
        $first = $flow->run();
        self::assertSame(1, $first->articleId);
        self::assertSame(1, $flow->draftCalls);
        self::assertCount(0, $flow->usages);

        $second = $flow->run();
        self::assertSame(1, $second->articleId);
        self::assertSame(1, $flow->draftCalls);
        self::assertCount(1, $flow->usages);
        self::assertSame('media-existing', $flow->usages[0]['media_id']);
    }

    public function test_article_draft_is_composed_after_canonical_media_usage_reconciliation(): void
    {
        $flow = new ImageArticleFlowFixture([
            ['media_id' => 'media-featured', 'attachment_id' => 612, 'upload_status' => 'REUSED', 'sort_order' => 0],
            ['media_id' => 'media-detail', 'attachment_id' => 613, 'upload_status' => 'REUSED', 'sort_order' => 1],
        ]);

        $flow->run();

        self::assertSame(['media', 'draft_update'], $flow->events);
    }

    public function test_non_applied_article_media_disposition_keeps_capture_partial(): void
    {
        $flow = new ImageArticleFlowFixture([
            ['media_id' => 'media-featured', 'attachment_id' => 612, 'upload_status' => 'REUSED', 'sort_order' => 0],
            ['media_id' => 'media-detail', 'attachment_id' => 613, 'upload_status' => 'REUSED', 'sort_order' => 1],
        ], incompleteMediaDisposition: true);

        $result = $flow->run();

        self::assertSame('PARTIAL', $result->status);
        self::assertNotEmpty($result->diagnostics['completion']['blockers']);
    }
}

final class ImageArticleFlowFixture
{
    public int $draftCalls = 0;
    public int $physicalCalls = 0;
    public int $uploadCalls = 0;
    /** @var list<array<string,mixed>> */
    public array $usages = [];
    public array $packetIds = [];
    public array $events = [];
    public string $subjectHintSeen = '';
    private ImageArticleTestCaptureRepository $captures;
    private bool $failMediaOnce;
    private EditorialCaptureCoordinator $coordinator;

    /** @param list<array<string,mixed>> $assets */
    public function __construct(private array $assets, bool $failMediaOnce = false, private bool $fullPath = false, private bool $incompleteMediaDisposition = false)
    {
        $this->failMediaOnce = $failMediaOnce;
        $this->captures = new ImageArticleTestCaptureRepository();
        $this->coordinator = new EditorialCaptureCoordinator(
            $this->captures,
            function (array $input): array { $this->physicalCalls++; return ['items' => $this->assets]; },
            function (array $input): array { $this->draftCalls++; return ['post_id' => 1, 'state_token' => 'article-state-1', 'post' => ['post_id' => 1, 'post_modified_gmt' => '2026-09-19 00:00:01']]; },
            new TextInputInterpreter(),
            new SubjectResolutionService(function (string $hint): array {
                if ($this->fullPath && $hint === 'subject-explicit') {
                    $this->subjectHintSeen = $hint;
                    return [['id' => '11111111-1111-4111-8111-111111111111', 'type' => 'model', 'canonical_name' => 'Generic Model', 'revision' => 1]];
                }
                return [];
            }),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            function (array $context): array {
                $this->packetIds[] = (string) (($context['subject_resolution_packet']['id'] ?? ''));
                return ['status' => 'COMPLETED', 'writes' => []];
            },
            new ArticleComposer(),
            function (array $context): array {
                $this->events[] = 'media';
                $this->packetIds[] = (string) (($context['subject_resolution_packet']['id'] ?? ''));
                if ($this->failMediaOnce) { $this->failMediaOnce = false; throw new \RuntimeException('IMAGE_ARTICLE_MEDIA_TEST_FAILURE'); }
                $this->usages = [];
                foreach ((array) ($context['assets'] ?? []) as $asset) {
                    if (!is_array($asset) || trim((string) ($asset['media_id'] ?? '')) === '') continue;
                    $seo = is_array($asset['media_context'] ?? null) ? $asset['media_context'] : [];
                    $this->usages[] = ['media_id' => (string) $asset['media_id'], 'endpoint_key' => '1:' . (string) ($context['article_id'] ?? ''), 'sort_order' => (int) ($asset['sort_order'] ?? 0), 'title' => (string) ($seo['title'] ?? ''), 'alt_text' => (string) ($seo['alt_text'] ?? ''), 'caption' => (string) ($seo['caption'] ?? '')];
                }
                usort($this->usages, static fn (array $left, array $right): int => $left['sort_order'] <=> $right['sort_order']);
                return ['status' => 'RECONCILED', 'media_ids' => array_column($this->usages, 'media_id'), 'media_complete' => !$this->incompleteMediaDisposition, 'media_usage' => $this->usages, 'media_dispositions' => $this->incompleteMediaDisposition ? [['media_id' => 'media-detail', 'role' => 'inline_primary', 'status' => 'REVIEW_REQUIRED']] : array_map(static fn (array $usage): array => ['media_id' => $usage['media_id'], 'role' => 'inline_supporting', 'status' => 'APPLIED'], $this->usages)];
            },
            function (array $context): array {
                $this->packetIds[] = (string) (($context['subject_resolution_packet']['id'] ?? ''));
                return $this->fullPath ? ['eligible' => true, 'blockers' => [], 'state_token' => 'article-state-1'] : ['eligible' => false, 'blockers' => ['OWNER_PUBLICATION_REQUIRED']];
            },
            function (array $context): array {
                $this->packetIds[] = (string) (($context['subject_resolution_packet']['id'] ?? ''));
                return $this->fullPath ? ['status' => 'verified', 'permalink' => '/anh-kiem-thu/', 'rendered_public_verification' => 'verified'] : ['status' => 'verified'];
            },
            publisher: function (array $context): array {
                $this->packetIds[] = (string) (($context['subject_resolution_packet']['id'] ?? ''));
                return ['ok' => true, 'status' => 'publish', 'post' => ['status' => 'publish', 'permalink' => '/anh-kiem-thu/'], 'state_token' => 'published-token'];
            },
            draftUpdater: function (array $context): array {
                $this->events[] = 'draft_update';
                return ['ok' => true, 'state_token' => 'article-state-2'];
            },
        );
    }

    public function run(array $overrides = []): CaptureRecord
    {
        return $this->coordinator->execute(array_replace(['idempotency_key' => 'image-flow-test', 'intent' => 'IMAGE_ARTICLE', 'title' => 'Ảnh kiểm thử', 'text' => 'Bài viết kiểm thử IMAGE_ARTICLE.'], $overrides));
    }
}

final class ImageArticleTestCaptureRepository implements CaptureRepository
{
    /** @var array<string,CaptureRecord> */
    private array $records = [];
    public function findByIdempotencyKey(string $key): ?CaptureRecord { return $this->records[$key] ?? null; }
    public function findById(string $captureId): ?CaptureRecord { foreach ($this->records as $record) if ($record->captureId === $captureId) return $record; return null; }
    public function create(CaptureRecord $record): CaptureRecord { return $this->records[$record->idempotencyKey] = $record; }
    public function save(CaptureRecord $record): CaptureRecord { return $this->records[$record->idempotencyKey] = $record; }
}
