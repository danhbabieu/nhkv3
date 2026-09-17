<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\{EditorialCaptureContinuationService, EditorialCaptureCoordinator};
use NHK\Core\Application\Semantic\{ArticleComposer, ClaimRetrievalEngine, SubjectResolutionService, TextInputInterpreter};
use NHK\Core\Contracts\Capture\{CaptureAddendumRepository, CaptureRepository};
use NHK\Core\Domain\Capture\{CaptureAddendumRecord, CaptureRecord, CaptureStage};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class CaptureMediaIdsReuseTest extends TestCase
{
    public function test_capture_to_article_reconciles_media_before_research_and_publication_gate(): void
    {
        $captures = new CaptureMediaIdsCaptureRepository();
        $events = [];
        $mediaId = UuidCodec::newV7();
        $articleUsageIds = [UuidCodec::newV7(), UuidCodec::newV7()];
        $researchUsageIds = [];
        $articleResearch = static function (array $mediaReadback) use (&$events, &$researchUsageIds): array {
            $events[] = 'article_research';
            $researchUsageIds = (array) (($mediaReadback['canonical_readback']['media_usage']['usage_ids'] ?? []));
            return ['status' => 'RESEARCHED', 'article_usage_ids' => $researchUsageIds];
        };
        $coordinator = new EditorialCaptureCoordinator(
            $captures,
            static fn (array $input): array => ['items' => [['client_file_id' => 'front', 'media_id' => $mediaId, 'attachment_id' => 77, 'attachment_readback_status' => 'verified']]],
            static fn (array $input): array => ['post_id' => 573, 'state_token' => 'state-573'],
            new TextInputInterpreter(),
            new SubjectResolutionService(static fn (string $hint): array => []),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static function (array $context) use (&$events): array {
                $events[] = 'semantic_writeback';
                return ['status' => 'SKIPPED', 'writes' => [], 'requirements' => ['semantic_delta' => ['applicability' => 'NOT_REQUIRED', 'policy' => 'VERIFY', 'state' => 'SKIPPED']]];
            },
            new ArticleComposer(),
            static function (array $context) use (&$events, $mediaId, $articleUsageIds, $articleResearch): array {
                $events[] = 'article_media_reconcile';
                self::assertSame([$mediaId], $context['capture_owned_media_ids'] ?? []);
                $mediaReadback = [
                    'status' => 'RECONCILED',
                    'canonical_readback' => [
                        'media_usage' => [
                            'state' => 'VERIFIED',
                            'endpoint_type' => 'wp_post',
                            'endpoint_key' => '1:573',
                            'roles' => ['featured_primary', 'inline_primary'],
                            'usage_ids' => $articleUsageIds,
                            'source' => 'ARTICLE_MEDIA_RECONCILIATION',
                        ],
                    ],
                ];
                // Precise test-only seam: fresh research consumes the
                // committed MediaUsage readback before the gate callback.
                $mediaReadback['article_research'] = $articleResearch($mediaReadback);
                return $mediaReadback;
            },
            static function (array $context) use (&$events, $articleUsageIds): array {
                $events[] = 'publication_gate';
                self::assertSame($articleUsageIds, $context['media']['article_research']['article_usage_ids'] ?? []);
                return ['eligible' => false, 'blockers' => ['ARTICLE_NOT_PUBLISHED']];
            },
            static fn (array $context): array => ['status' => 'verified'],
        );

        $result = $coordinator->execute([
            'idempotency_key' => 'capture-article-media-ordering',
            'intent' => 'IMAGE_ARTICLE',
            'title' => 'Bài ảnh kiểm tra thứ tự MediaUsage',
            'text' => 'Nội dung biên tập cục bộ.',
        ]);

        self::assertSame(['semantic_writeback', 'article_media_reconcile', 'article_research', 'publication_gate'], $events);
        self::assertSame($articleUsageIds, $researchUsageIds);
        self::assertSame(573, $result->articleId);
    }

    public function test_attach_assets_accepts_existing_media_ids_without_physical_files(): void
    {
        $captures = new CaptureMediaIdsCaptureRepository();
        $addenda = new CaptureMediaIdsAddendumRepository();
        $captureId = UuidCodec::newV7();
        $capture = new CaptureRecord($captureId, 'capture-media-id', hash('sha256', 'capture-media-id'), CaptureStage::READY_FOR_PUBLICATION->value, 'PARTIAL', null, null, [], ['raw_input' => 'Bài ảnh.', 'subject_hints' => ['Odo 36/8'], 'content_intent' => ['intent' => 'KNOWLEDGE_DELTA', 'article_required' => false]], ['media_adoption' => ['status' => 'verified'], 'composition' => ['title' => 'Bài ảnh.']], []);
        $captures->create($capture);
        $mediaId = UuidCodec::newV7();
        $received = null;
        $coordinator = new EditorialCaptureCoordinator(
            $captures,
            static fn (array $input): array => ['items' => []],
            static fn (array $input): array => ['post_id' => 512, 'state_token' => 'state-512'],
            new TextInputInterpreter(),
            new SubjectResolutionService(static fn (string $hint): array => []),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static fn (array $context): array => ['status' => 'PLANNED', 'writes' => []],
            new ArticleComposer(),
            static fn (array $context): array => ['status' => 'RECONCILED'],
            static fn (array $context): array => ['eligible' => false, 'blockers' => ['OWNER_PUBLICATION_REQUIRED']],
            static fn (array $context): array => ['status' => 'verified'],
            null,
            static fn (array $context): array => ['status' => 'verified', 'media_id' => (string) (($context['asset']['media_id'] ?? ''))],
        );
        $service = new EditorialCaptureContinuationService($captures, $addenda, $coordinator, static function (array $input) use (&$received, $mediaId): array {
            $received = $input['media_ids'] ?? null;
            return ['items' => [['client_file_id' => $mediaId, 'attachment_id' => 77, 'media_id' => $mediaId, 'filename' => 'safe.webp', 'attachment_readback_status' => 'verified']]];
        });

        $result = $service->execute(['capture_id' => $captureId, 'idempotency_key' => 'media-id-followup', 'followup_mode' => 'ATTACH_ASSETS', 'media_ids' => [$mediaId]]);

        self::assertSame('COMPLETED', $result['addendum']['status'], json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame([$mediaId], $received);
        self::assertSame($mediaId, $result['capture']['assets'][0]['media_id']);
        self::assertSame('KNOWLEDGE_DELTA', $result['capture']['content_intent']['intent']);
        self::assertSame('PERSISTED_CAPTURE', $result['capture']['diagnostics']['content_intent']['source']);
    }
}

final class CaptureMediaIdsCaptureRepository implements CaptureRepository
{
    /** @var array<string,CaptureRecord> */
    private array $records = [];
    public function findByIdempotencyKey(string $key): ?CaptureRecord { foreach ($this->records as $record) if ($record->idempotencyKey === $key) return $record; return null; }
    public function findById(string $captureId): ?CaptureRecord { return $this->records[$captureId] ?? null; }
    public function create(CaptureRecord $record): CaptureRecord { return $this->records[$record->captureId] = $record; }
    public function save(CaptureRecord $record): CaptureRecord { return $this->records[$record->captureId] = $record; }
}

final class CaptureMediaIdsAddendumRepository implements CaptureAddendumRepository
{
    /** @var array<string,CaptureAddendumRecord> */
    private array $records = [];
    public function findByIdempotencyKey(string $key): ?CaptureAddendumRecord { return $this->records[$key] ?? null; }
    public function create(CaptureAddendumRecord $record): CaptureAddendumRecord { return $this->records[$record->idempotencyKey] = $record; }
    public function save(CaptureAddendumRecord $record): CaptureAddendumRecord { return $this->records[$record->idempotencyKey] = $record; }
}
