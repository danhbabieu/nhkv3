<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\ContentIntentRouter;
use NHK\Core\Application\Capture\EditorialCaptureCoordinator;
use NHK\Core\Application\Governance\StagingAcceptanceScopeVerifier;
use NHK\Core\Application\Semantic\{ArticleComposer, ClaimRetrievalEngine, SubjectResolutionService, TextInputInterpreter};
use NHK\Core\Contracts\Capture\CaptureRepository;
use NHK\Core\Contracts\Media\MediaBindingPort;
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class ContentIntentRouterTest extends TestCase
{
    /** @dataProvider genericArticleMediaBindingProvider */
    public function test_image_article_semantic_continuation_propagates_server_scope_to_media_binding(string $mediaId, string $targetId, string $stableKey): void
    {
        $repository = new IntentCaptureRepository();
        $binding = new OrchestrationScopeBindingPort();
        $verifier = new StagingAcceptanceScopeVerifier(
            static fn (): string => 'staging',
            'test-secret',
            static fn (): bool => true,
            can: static fn (string $capability): bool => $capability === 'nhk_internal_content_operations',
        );
        $coordinator = new EditorialCaptureCoordinator(
            $repository,
            static function (array $input) use ($mediaId): array { return ['items' => [['kind' => 'image', 'media_id' => $mediaId, 'attachment_readback_status' => 'verified']]]; },
            static fn (array $input): array => ['post_id' => 581, 'state_token' => 'state-581'],
            new TextInputInterpreter(),
            new SubjectResolutionService(static function (string $hint) use ($targetId, $stableKey): array {
                return $hint === $targetId ? [['type' => 'classification', 'id' => $targetId, 'stable_key' => $stableKey, 'revision' => 1, 'match' => 'uuid_exact']] : [];
            }),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static fn (array $context): array => ['status' => 'SKIPPED', 'writes' => [], 'blockers' => []],
            new ArticleComposer(),
            static function (array $context) use ($binding): array {
                $batch = $binding->bindMany((array) ($context['media_bindings'] ?? []), (string) ($context['capture']['capture_id'] ?? '') . ':media-binding', (array) ($context['assets'] ?? []), [
                    'capture_id' => (string) ($context['capture']['capture_id'] ?? ''),
                    'capture_fingerprint' => (string) ($context['capture_fingerprint'] ?? ''),
                    'payload_fingerprint' => (string) ($context['payload_fingerprint'] ?? ''),
                    'staging_acceptance' => $context['staging_acceptance'] ?? null,
                ]);
                return ['status' => 'RECONCILED', 'bindings' => $batch['bindings'], 'media_ids' => $batch['media_ids']];
            },
            static fn (array $context): array => ['eligible' => false, 'blockers' => ['OWNER_PUBLICATION_REQUIRED']],
            static fn (array $context): array => ['status' => 'verified'],
            contentIntentRouter: new ContentIntentRouter(),
            mediaBindingService: $binding,
            stagingScopeVerifier: $verifier,
        );

        $input = [
            'idempotency_key' => 'generic-image-article-' . substr($mediaId, 0, 8),
            'intent' => 'IMAGE_ARTICLE',
            'text' => 'Bài viết về đồng hồ.',
            'subject_hints' => [$targetId],
            'media_bindings' => [[
                'media_ref' => ['item_index' => 0],
                'target' => ['type' => 'classification', 'id' => $targetId, 'stable_key' => $stableKey, 'revision' => 1],
                'role' => 'representative',
                'selection_source' => 'USER_EXPLICIT',
                'selection_policy' => 'PINNED',
            ]],
        ];

        $result = $coordinator->execute($input);

        self::assertSame('581', (string) $result->articleId);
        self::assertCount(1, $binding->requests);
        self::assertSame($mediaId, $binding->requests[0]['media']['id']);
        self::assertSame($targetId, $binding->requests[0]['target']['id']);
        self::assertSame($stableKey, $binding->requests[0]['target']['stable_key']);
        self::assertSame(1, $binding->requests[0]['target']['revision']);
        self::assertSame('staging', $binding->requests[0]['staging_acceptance']['environment']);
        self::assertSame('IMAGE_ARTICLE', $binding->requests[0]['staging_acceptance']['intent']);
        self::assertSame('USER_EXPLICIT', $binding->requests[0]['selection_source']);
        self::assertSame('PINNED', $binding->requests[0]['selection_policy']);
    }

    public static function genericArticleMediaBindingProvider(): array
    {
        return [
            ['01a0a283-df53-79b1-be85-420cfca56d2e', '01a09f73-0aad-79b3-9aaf-5f02cb33a9d1', 'nhk:classification:clock-type.dong-ho-thap'],
            [UuidCodec::newV7(), UuidCodec::newV7(), 'nhk:classification:generic-second'],
        ];
    }

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

    public function test_explicit_media_enrichment_requires_media_but_not_an_article(): void
    {
        $route = (new ContentIntentRouter())->route(
            ['intent' => 'MEDIA_ENRICHMENT', 'text' => 'Bổ sung tư liệu hình ảnh cho thực thể.'],
            (new TextInputInterpreter())->interpret('Bổ sung tư liệu hình ảnh cho thực thể.'),
            [['kind' => 'image', 'media_id' => 'media-1']],
        );

        self::assertSame('resolved', $route['status']);
        self::assertSame('MEDIA_ENRICHMENT', $route['intent']);
        self::assertFalse($route['article_required']);
        self::assertTrue($route['media_required']);
    }

    public function test_media_enrichment_fails_closed_without_media(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MEDIA_ENRICHMENT_REQUIRES_IMAGE');

        (new ContentIntentRouter())->route(['intent' => 'MEDIA_ENRICHMENT', 'text' => 'Bổ sung tư liệu hình ảnh.'], [], []);
    }

    public function test_existing_capture_reuses_persisted_intent_instead_of_rerunning_heuristics(): void
    {
        $route = (new ContentIntentRouter())->reusePersisted(
            ['intent' => 'MEDIA_ENRICHMENT', 'article_required' => false],
            ['text' => 'Nội dung nhiều câu và có thể đọc độc lập như một bài viết.', 'intent' => ''],
            [['kind' => 'image', 'media_id' => UuidCodec::newV7()]],
        );

        self::assertSame('MEDIA_ENRICHMENT', $route['intent']);
        self::assertSame('PERSISTED_CAPTURE', $route['source']);
        self::assertTrue($route['intent_reused']);
        self::assertFalse($route['article_required']);
    }

    public function test_existing_capture_reuse_preserves_mixed_semantic_delta_handoff(): void
    {
        $route = (new ContentIntentRouter())->reusePersisted(
            [
                'intent' => 'IMAGE_ARTICLE',
                'purpose' => 'MIXED',
                'article_required' => true,
                'semantic_delta' => ['status' => 'REQUIRED'],
            ],
            ['text' => 'Bài viết tiếp tục sau khi semantic branch được duyệt.', 'intent' => 'IMAGE_ARTICLE'],
            [['kind' => 'image', 'media_id' => UuidCodec::newV7()]],
        );

        self::assertSame('MIXED', $route['purpose']);
        self::assertSame(['status' => 'REQUIRED'], $route['semantic_delta']);
    }

    public function test_existing_media_enrichment_asset_followup_preserves_intent_and_skips_article_owner(): void
    {
        $repository = new IntentCaptureRepository();
        $capture = new CaptureRecord(
            UuidCodec::newV7(),
            'persisted-media-enrichment',
            hash('sha256', 'persisted-media-enrichment'),
            'MEDIA_ADOPTED',
            'PARTIAL',
            null,
            null,
            [],
            ['raw_input' => 'Bổ sung tư liệu.', 'content_intent' => ['intent' => 'MEDIA_ENRICHMENT', 'article_required' => false]],
            [],
            [],
        );
        $repository->create($capture);
        $calls = ['draft' => 0, 'media' => 0, 'semantic' => 0];
        $coordinator = new EditorialCaptureCoordinator(
            $repository,
            static fn (array $input): array => throw new \RuntimeException('physical phase must not replay'),
            static function (array $input) use (&$calls): array { ++$calls['draft']; return ['post_id' => 999, 'state_token' => 'unexpected']; },
            new TextInputInterpreter(),
            new SubjectResolutionService(static fn (string $hint): array => []),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static function (array $context) use (&$calls): array { ++$calls['semantic']; return ['status' => 'COMPLETED', 'writes' => []]; },
            new ArticleComposer(),
            static function (array $context) use (&$calls): array { ++$calls['media']; return ['status' => 'RECONCILED', 'media_ids' => ['media-1']]; },
            static fn (array $context): array => throw new \RuntimeException('publication must not run'),
            static fn (array $context): array => ['status' => 'verified'],
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

        $result = $coordinator->continueWithAddendum($capture, [
            'existing_capture_continuation' => true,
            'followup_mode' => 'ATTACH_ASSETS',
            'asset_followup_items' => [['kind' => 'image', 'media_id' => UuidCodec::newV7(), 'attachment_readback_status' => 'verified']],
        ]);

        self::assertNull($result->articleId);
        self::assertSame('MEDIA_ENRICHMENT', $result->diagnostics['content_intent']['intent']);
        self::assertSame('PERSISTED_CAPTURE', $result->diagnostics['content_intent']['source']);
        self::assertSame('MEDIA_ENRICHMENT', $result->diagnostics['capture_intent_reused']);
        self::assertSame(['draft' => 0, 'media' => 1, 'semantic' => 0], $calls);
    }

    public function test_existing_text_article_asset_followup_reuses_same_article_without_second_draft(): void
    {
        $repository = new IntentCaptureRepository();
        $capture = new CaptureRecord(
            UuidCodec::newV7(),
            'persisted-text-article',
            hash('sha256', 'persisted-text-article'),
            'MEDIA_ADOPTED',
            'PARTIAL',
            512,
            'state-512',
            [],
            ['raw_input' => 'Bài viết ban đầu.', 'content_intent' => ['intent' => 'TEXT_ARTICLE', 'article_required' => true]],
            [],
            [],
        );
        $repository->create($capture);
        $calls = ['draft' => 0, 'media' => 0, 'semantic' => 0];
        $coordinator = new EditorialCaptureCoordinator(
            $repository,
            static fn (array $input): array => throw new \RuntimeException('physical phase must not replay'),
            static function (array $input) use (&$calls): array { ++$calls['draft']; return ['post_id' => 999, 'state_token' => 'unexpected']; },
            new TextInputInterpreter(),
            new SubjectResolutionService(static fn (string $hint): array => []),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static function (array $context) use (&$calls): array { ++$calls['semantic']; return ['status' => 'COMPLETED', 'writes' => []]; },
            new ArticleComposer(),
            static function (array $context) use (&$calls): array { ++$calls['media']; return ['status' => 'RECONCILED']; },
            static fn (array $context): array => ['eligible' => false, 'blockers' => ['OWNER_PUBLICATION_REQUIRED']],
            static fn (array $context): array => ['status' => 'verified'],
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

        $result = $coordinator->continueWithAddendum($capture, [
            'existing_capture_continuation' => true,
            'followup_mode' => 'ATTACH_ASSETS',
            'asset_followup_items' => [['kind' => 'image', 'media_id' => UuidCodec::newV7(), 'attachment_readback_status' => 'verified']],
        ]);

        self::assertSame(512, $result->articleId);
        self::assertSame('TEXT_ARTICLE', $result->diagnostics['content_intent']['intent']);
        self::assertSame('PERSISTED_CAPTURE', $result->diagnostics['content_intent']['source']);
        self::assertSame(['draft' => 0, 'media' => 1, 'semantic' => 1], $calls);
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

    public function test_media_enrichment_skips_article_stages_and_reconciles_media(): void
    {
        $calls = ['draft' => 0, 'media' => 0, 'publication' => 0, 'final' => 0, 'retrieve' => 0, 'semantic' => 0];
        $coordinator = new EditorialCaptureCoordinator(
            new IntentCaptureRepository(),
            static fn (array $input): array => ['items' => [['kind' => 'image', 'media_id' => 'media-1', 'attachment_readback_status' => 'verified']]],
            static function (array $input) use (&$calls): array { ++$calls['draft']; return ['post_id' => 902, 'state_token' => 'token']; },
            new TextInputInterpreter(),
            new SubjectResolutionService(static fn (string $hint): array => []),
            new ClaimRetrievalEngine(static function (array $subject) use (&$calls): array { ++$calls['retrieve']; return ['status' => 'available', 'items' => []]; }, static fn (array $subject, array $neighborhood): array => []),
            static function (array $context) use (&$calls): array { ++$calls['semantic']; return ['status' => 'COMPLETED', 'writes' => []]; },
            new ArticleComposer(),
            static function (array $context) use (&$calls): array { ++$calls['media']; return ['status' => 'RECONCILED', 'media_ids' => ['media-1'], 'media_complete' => true]; },
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

        $result = $coordinator->execute(['idempotency_key' => 'media-enrichment-no-article', 'intent' => 'MEDIA_ENRICHMENT', 'text' => 'Bổ sung tư liệu hình ảnh.']);

        self::assertNull($result->articleId);
        self::assertSame('MEDIA_ENRICHMENT', $result->toArray()['content_intent']['intent']);
        self::assertSame(['draft' => 0, 'media' => 1, 'publication' => 0, 'final' => 1, 'retrieve' => 0, 'semantic' => 0], $calls);
        self::assertSame('RECONCILED', $result->diagnostics['media_enrichment']['status']);
    }

    public function test_typed_media_enrichment_uses_one_binding_port_and_completes_only_after_verified_receipt(): void
    {
        $mediaId = '01a0aefd-7e93-772c-98df-33f7abbc11e8';
        $binding = new CountingMediaBindingPort([
            'status' => 'COMPLETE',
            'media_ids' => [$mediaId],
            'bindings' => [[
                'status' => 'COMPLETE',
                'media_id' => $mediaId,
                'usage' => ['id' => UuidCodec::newV7()],
                'readback' => ['status' => 'verified', 'media_id' => $mediaId, 'usage_id' => UuidCodec::newV7()],
            ]],
        ]);
        $calls = ['draft' => 0, 'media' => 0, 'publication' => 0, 'final' => 0];
        $coordinator = new EditorialCaptureCoordinator(
            new IntentCaptureRepository(),
            static fn (array $input): array => ['items' => [['kind' => 'image', 'media_id' => $mediaId, 'attachment_readback_status' => 'verified']]],
            static function (array $input) use (&$calls): array { ++$calls['draft']; throw new \RuntimeException('ARTICLE_DRAFT_MUST_NOT_RUN'); },
            new TextInputInterpreter(),
            new SubjectResolutionService(static fn (string $hint): array => throw new \RuntimeException('SUBJECT_RESOLUTION_MUST_NOT_RUN')),
            new ClaimRetrievalEngine(static function (array $subject): array { throw new \RuntimeException('CLAIMS_MUST_NOT_RUN'); }, static function (array $subject, array $neighborhood): array { throw new \RuntimeException('GRAPH_MUST_NOT_RUN'); }),
            static function (array $context): array { throw new \RuntimeException('SEMANTIC_WRITE_MUST_NOT_RUN'); },
            new ArticleComposer(),
            static function (array $context) use (&$calls): array { ++$calls['media']; throw new \RuntimeException('LEGACY_MEDIA_RECONCILE_MUST_NOT_RUN'); },
            static function (array $context) use (&$calls): array { ++$calls['publication']; throw new \RuntimeException('PUBLICATION_MUST_NOT_RUN'); },
            static function (array $context) use (&$calls): array { ++$calls['final']; self::assertSame('COMPLETE', $context['media']['status']); return ['status' => 'verified', 'frontend_verified' => true]; },
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            contentIntentRouter: new ContentIntentRouter(),
            mediaBindingService: $binding,
        );

        $result = $coordinator->execute([
            'idempotency_key' => 'typed-media-enrichment',
            'intent' => 'MEDIA_ENRICHMENT',
            'text' => 'Bổ sung ảnh đại diện.',
            'media_ids' => [$mediaId],
            'media_bindings' => [[
                'media_ref' => ['item_index' => 0],
                'target' => ['type' => 'classification', 'id' => '01a09e44-539a-7f1a-938a-d7d91bb689a3'],
                'role' => 'representative',
                'selection_source' => 'USER_EXPLICIT',
                'selection_policy' => 'PINNED',
            ]],
        ]);

        self::assertNull($result->articleId);
        self::assertSame('COMPLETE', $result->status);
        self::assertSame(1, $binding->calls);
        self::assertSame(['draft' => 0, 'media' => 0, 'publication' => 0, 'final' => 1], $calls);
        self::assertSame('not_requested', $result->diagnostics['claim_retrieval']['status']);
        self::assertSame('SKIPPED', $result->diagnostics['semantic_write_back']['status']);
    }

    public function test_typed_media_enrichment_does_not_complete_on_unverified_binding_receipt(): void
    {
        $mediaId = '01a0aefd-7e93-772c-98df-33f7abbc11e8';
        $binding = new CountingMediaBindingPort(['status' => 'COMPLETE', 'media_ids' => [$mediaId], 'bindings' => [['status' => 'COMPLETE', 'media_id' => $mediaId, 'readback' => ['status' => 'pending']]]]);
        $coordinator = $this->typedMediaCoordinator($binding);

        $result = $coordinator->execute([
            'idempotency_key' => 'typed-media-enrichment-unverified',
            'intent' => 'MEDIA_ENRICHMENT',
            'text' => 'Bổ sung ảnh đại diện.',
            'media_bindings' => [[
                'media_ref' => ['item_index' => 0],
                'target' => ['type' => 'classification', 'id' => '01a09e44-539a-7f1a-938a-d7d91bb689a3'],
                'role' => 'representative',
                'selection_source' => 'USER_EXPLICIT',
                'selection_policy' => 'PINNED',
            ]],
        ]);

        self::assertNotSame('COMPLETE', $result->status);
        self::assertSame('MEDIA_BINDING_FINAL_READBACK_REQUIRED', $result->diagnostics['failure']['code']);
    }

    public function test_system_auto_typed_capture_requires_governance_and_never_calls_direct_binding(): void
    {
        $binding = new CountingMediaBindingPort(['status' => 'COMPLETE', 'media_ids' => [], 'bindings' => []]);
        $coordinator = $this->typedMediaCoordinator($binding);

        $result = $coordinator->execute([
            'idempotency_key' => 'typed-media-enrichment-system-auto',
            'intent' => 'MEDIA_ENRICHMENT',
            'text' => 'Bổ sung ảnh đại diện tự động.',
            'media_bindings' => [[
                'media_ref' => ['item_index' => 0],
                'target' => ['type' => 'classification', 'id' => '01a09e44-539a-7f1a-938a-d7d91bb689a3'],
                'role' => 'representative',
                'selection_source' => 'SYSTEM_AUTO',
                'selection_policy' => 'AUTO',
            ]],
        ]);

        self::assertSame('MEDIA_BINDING_GOVERNANCE_REQUIRED', $result->diagnostics['failure']['code']);
        self::assertSame(0, $binding->calls);
    }

    private function typedMediaCoordinator(CountingMediaBindingPort $binding): EditorialCaptureCoordinator
    {
        return new EditorialCaptureCoordinator(
            new IntentCaptureRepository(),
            static fn (array $input): array => ['items' => [['kind' => 'image', 'media_id' => '01a0aefd-7e93-772c-98df-33f7abbc11e8', 'attachment_readback_status' => 'verified']]],
            static fn (array $input): array => throw new \RuntimeException('ARTICLE_DRAFT_MUST_NOT_RUN'),
            new TextInputInterpreter(),
            new SubjectResolutionService(static fn (string $hint): array => throw new \RuntimeException('SUBJECT_RESOLUTION_MUST_NOT_RUN')),
            new ClaimRetrievalEngine(static function (array $subject): array { throw new \RuntimeException('CLAIMS_MUST_NOT_RUN'); }, static function (array $subject, array $neighborhood): array { throw new \RuntimeException('GRAPH_MUST_NOT_RUN'); }),
            static fn (array $context): array => throw new \RuntimeException('SEMANTIC_WRITE_MUST_NOT_RUN'),
            new ArticleComposer(),
            static fn (array $context): array => throw new \RuntimeException('LEGACY_MEDIA_RECONCILE_MUST_NOT_RUN'),
            static fn (array $context): array => throw new \RuntimeException('PUBLICATION_MUST_NOT_RUN'),
            static fn (array $context): array => ['status' => 'verified'],
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            contentIntentRouter: new ContentIntentRouter(),
            mediaBindingService: $binding,
        );
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

final class CountingMediaBindingPort implements MediaBindingPort
{
    public int $calls = 0;

    public function __construct(private array $result) {}

    public function bindMany(array $bindings, string $idempotencyKey, array $assets = [], array $context = []): array
    {
        ++$this->calls;
        return $this->result;
    }
}

final class OrchestrationScopeBindingPort implements MediaBindingPort
{
    /** @var list<array<string,mixed>> */
    public array $requests = [];

    public function bindMany(array $bindings, string $idempotencyKey, array $assets = [], array $context = []): array
    {
        foreach ($bindings as $index => $binding) {
            $mediaId = (string) (($binding['media_ref']['media_id'] ?? '') ?: ($assets[(int) ($binding['media_ref']['item_index'] ?? -1)]['media_id'] ?? ''));
            $request = $binding + [
                'capture_id' => $context['capture_id'] ?? '',
                'capture_fingerprint' => $context['capture_fingerprint'] ?? '',
                'payload_fingerprint' => $context['payload_fingerprint'] ?? '',
                'operation' => 'representative_bind',
                'media' => ['id' => $mediaId],
                'staging_acceptance' => $context['staging_acceptance'] ?? null,
            ];
            $request['target'] = $binding['target'] ?? [];
            $request['role'] = $binding['role'] ?? 'representative';
            $request['selection_source'] = $binding['selection_source'] ?? 'USER_EXPLICIT';
            $request['selection_policy'] = $binding['selection_policy'] ?? 'PINNED';
            $scope = $context['staging_acceptance'] ?? null;
            if (!is_array($scope) || !$this->verifier($scope, $request)) throw new \RuntimeException('STAGING_SCOPE_REQUIRED');
            $this->requests[] = $request;
        }
        return ['status' => 'COMPLETE', 'bindings' => array_map(static fn (array $request): array => ['status' => 'COMPLETE', 'media_id' => $request['media']['id'], 'readback' => ['status' => 'verified', 'media_id' => $request['media']['id'], 'usage_id' => UuidCodec::newV7()]], $this->requests), 'media_ids' => array_values(array_unique(array_map(static fn (array $request): string => (string) $request['media']['id'], $this->requests)))];
    }

    private function verifier(array $scope, array $request): bool
    {
        static $verifier;
        $verifier ??= new StagingAcceptanceScopeVerifier(static fn (): string => 'staging', 'test-secret', static fn (): bool => true, can: static fn (): bool => true);
        return $verifier->verifyBindingRequest($scope, $request);
    }
}
