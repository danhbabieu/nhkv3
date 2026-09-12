<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\ContentIntentRouter;
use NHK\Core\Application\Capture\EditorialCaptureCoordinator;
use NHK\Core\Application\Semantic\{ArticleComposer, ClaimRetrievalEngine, SubjectResolutionService, TextInputInterpreter};
use NHK\Core\Contracts\Capture\CaptureRepository;
use NHK\Core\Domain\Capture\CaptureRecord;
use PHPUnit\Framework\TestCase;

final class ContentIntentRouterTest extends TestCase
{
    public function test_explicit_knowledge_delta_does_not_require_an_article(): void
    {
        $route = (new ContentIntentRouter())->route(
            ['intent' => 'KNOWLEDGE_DELTA', 'text' => '36/4 mặt vuông'],
            (new TextInputInterpreter())->interpret('36/4 mặt vuông'),
            [],
        );

        self::assertSame('resolved', $route['status']);
        self::assertSame('KNOWLEDGE_DELTA', $route['intent']);
        self::assertSame('EXPLICIT', $route['source']);
        self::assertFalse($route['article_required']);
    }

    public function test_heuristic_knowledge_delta_does_not_require_an_article(): void
    {
        $text = '57 có bản 10 côn.';
        $route = (new ContentIntentRouter())->route(
            ['text' => $text],
            (new TextInputInterpreter())->interpret($text),
            [],
        );

        self::assertSame('KNOWLEDGE_DELTA', $route['intent']);
        self::assertFalse($route['article_required']);
    }

    public function test_valid_youtube_url_defaults_to_video_without_article_intent(): void
    {
        $route = (new ContentIntentRouter())->route(
            ['video' => ['url' => 'https://youtu.be/dQw4w9WgXcQ'], 'text' => 'Tư liệu đang chạy.'],
            (new TextInputInterpreter())->interpret('Tư liệu đang chạy.'),
            [],
        );

        self::assertSame('VIDEO', $route['intent']);
        self::assertSame('HEURISTIC', $route['source']);
        self::assertFalse($route['article_required']);
    }

    public function test_explicit_text_article_wins_over_a_valid_video_url(): void
    {
        $route = (new ContentIntentRouter())->route(
            ['intent' => 'TEXT_ARTICLE', 'video' => ['url' => 'https://youtu.be/dQw4w9WgXcQ'], 'text' => 'Bài viết độc lập về chiếc đồng hồ.'],
            (new TextInputInterpreter())->interpret('Bài viết độc lập về chiếc đồng hồ.'),
            [],
        );

        self::assertSame('TEXT_ARTICLE', $route['intent']);
        self::assertSame('EXPLICIT', $route['source']);
        self::assertTrue($route['article_required']);
    }

    public function test_image_with_standalone_editorial_content_defaults_to_image_article(): void
    {
        $route = (new ContentIntentRouter())->route(
            ['text' => 'Bài viết mô tả mặt số và bộ máy trong hai phần rõ ràng.'],
            (new TextInputInterpreter())->interpret('Bài viết mô tả mặt số và bộ máy trong hai phần rõ ràng.'),
            [['kind' => 'image', 'media_id' => 'media-1']],
        );

        self::assertSame('IMAGE_ARTICLE', $route['intent']);
        self::assertTrue($route['article_required']);
    }

    public function test_text_with_multiple_independent_facts_defaults_to_text_article(): void
    {
        $text = 'Chiếc đồng hồ có mặt số xanh. Bộ máy dùng cấu hình 36/4.';
        $route = (new ContentIntentRouter())->route(
            ['text' => $text],
            (new TextInputInterpreter())->interpret($text),
            [],
        );

        self::assertSame('TEXT_ARTICLE', $route['intent']);
        self::assertTrue($route['article_required']);
    }

    public function test_ambiguous_input_is_review_required_instead_of_guessed(): void
    {
        $route = (new ContentIntentRouter())->route(
            ['text' => ''],
            (new TextInputInterpreter())->interpret(''),
            [],
        );

        self::assertSame('ambiguous', $route['status']);
        self::assertNull($route['intent']);
        self::assertContains('CONTENT_INTENT_AMBIGUOUS', $route['diagnostics']);
    }

    public function test_invalid_explicit_intent_fails_closed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CONTENT_INTENT_INVALID');

        (new ContentIntentRouter())->route(['intent' => 'PODCAST', 'text' => 'Nội dung.'], [], []);
    }

    public function test_image_article_requires_an_image_when_explicit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('IMAGE_ARTICLE_REQUIRES_IMAGE');

        (new ContentIntentRouter())->route(['intent' => 'IMAGE_ARTICLE', 'text' => 'Bài viết đủ nội dung.'], [], []);
    }

    public function test_knowledge_delta_skips_draft_and_article_specific_stages(): void
    {
        $calls = ['draft' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $coordinator = new EditorialCaptureCoordinator(
            new IntentCaptureRepository(),
            static fn (array $input): array => ['items' => []],
            static function (array $input) use (&$calls): array { ++$calls['draft']; return ['post_id' => 901, 'state_token' => 'token']; },
            new TextInputInterpreter(),
            new SubjectResolutionService(static fn (string $hint): array => []),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static fn (array $context): array => ['status' => 'REVIEW_REQUIRED', 'writes' => []],
            new ArticleComposer(),
            static function (array $context) use (&$calls): array { ++$calls['media']; return ['status' => 'RECONCILED']; },
            static function (array $context) use (&$calls): array { ++$calls['publication']; return ['eligible' => true]; },
            static function (array $context) use (&$calls): array { ++$calls['final']; return ['status' => 'verified']; },
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            new ContentIntentRouter(),
        );

        $result = $coordinator->execute(['idempotency_key' => 'knowledge-delta-no-article', 'intent' => 'KNOWLEDGE_DELTA', 'text' => '36/4 mặt vuông']);

        self::assertNull($result->articleId);
        self::assertSame('KNOWLEDGE_DELTA', $result->toArray()['content_intent']['intent']);
        self::assertSame(['draft' => 0, 'media' => 0, 'publication' => 0, 'final' => 1], $calls);
    }

    public function test_video_replay_keeps_one_capture_and_does_not_create_an_article(): void
    {
        $repository = new IntentCaptureRepository();
        $calls = ['draft' => 0, 'video' => 0];
        $coordinator = new EditorialCaptureCoordinator(
            $repository,
            static fn (array $input): array => ['items' => []],
            static function (array $input) use (&$calls): array { ++$calls['draft']; return ['post_id' => 902, 'state_token' => 'token']; },
            new TextInputInterpreter(),
            new SubjectResolutionService(static fn (string $hint): array => []),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static fn (array $context): array => ['status' => 'REVIEW_REQUIRED', 'writes' => []],
            new ArticleComposer(),
            static fn (array $context): array => ['status' => 'RECONCILED'],
            static fn (array $context): array => ['eligible' => false, 'blockers' => ['VIDEO_NOT_ARTICLE']],
            static fn (array $context): array => ['status' => 'verified'],
            null,
            null,
            null,
            null,
            static function (array $context) use (&$calls): array { ++$calls['video']; return ['items' => [['kind' => 'video', 'video_id' => 'dQw4w9WgXcQ']]]; },
            null,
            null,
            null,
            new ContentIntentRouter(),
        );

        $input = ['idempotency_key' => 'video-only-capture', 'text' => 'Tư liệu đang chạy.', 'video' => ['url' => 'https://youtu.be/dQw4w9WgXcQ']];
        $first = $coordinator->execute($input);
        $replay = $coordinator->execute($input);

        self::assertSame($first->captureId, $replay->captureId);
        self::assertNull($first->articleId);
        self::assertSame([['kind' => 'video', 'video_id' => 'dQw4w9WgXcQ']], $repository->findById($first->captureId)?->assets);
        self::assertSame(['draft' => 0, 'video' => 1], $calls);
    }
}

final class IntentCaptureRepository implements CaptureRepository
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
