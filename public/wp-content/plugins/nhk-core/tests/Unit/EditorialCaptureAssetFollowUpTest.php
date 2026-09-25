<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\{EditorialCaptureContinuationService, EditorialCaptureCoordinator};
use NHK\Core\Application\Semantic\{ArticleComposer, ClaimRetrievalEngine, SubjectResolutionService, TextInputInterpreter};
use NHK\Core\Contracts\Capture\{CaptureAddendumRepository, CaptureRepository};
use NHK\Core\Domain\Capture\{CaptureAddendumRecord, CaptureRecord, CaptureStage};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class EditorialCaptureAssetFollowUpTest extends TestCase
{
    public function test_asset_manifest_is_sorted_by_capture_order_and_keeps_each_child_disposition(): void
    {
        $captures = new AssetFollowUpCaptureRepository();
        $addenda = new AssetFollowUpAddendumRepository();
        $capture = new CaptureRecord(
            UuidCodec::newV7(), 'capture-ordered-assets', hash('sha256', 'capture-ordered-assets'),
            CaptureStage::READY_FOR_PUBLICATION->value, 'PARTIAL', null, null, [],
            ['raw_input' => 'Bộ ảnh.', 'subject_hints' => ['Junghans W64'], 'content_intent' => ['intent' => 'KNOWLEDGE_DELTA', 'article_required' => false]],
            ['media_adoption' => ['status' => 'verified']], [],
        );
        $captures->create($capture);
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
        );
        $service = new EditorialCaptureContinuationService($captures, $addenda, $coordinator, static fn (array $input): array => [
            'items' => [
                ['client_file_id' => 'back', 'sort_order' => 2, 'media_id' => 'media-back', 'disposition' => 'technical_detail', 'attachment_readback_status' => 'verified'],
                ['client_file_id' => 'front', 'sort_order' => 0, 'media_id' => 'media-front', 'disposition' => 'representative', 'attachment_readback_status' => 'verified'],
                ['client_file_id' => 'dial', 'sort_order' => 1, 'media_id' => 'media-dial', 'disposition' => 'gallery', 'attachment_readback_status' => 'verified'],
            ],
        ]);

        $result = $service->execute([
            'capture_id' => $capture->captureId,
            'idempotency_key' => 'ordered-assets-followup',
            'followup_mode' => 'ATTACH_ASSETS',
            'files' => [
                ['name' => 'front.webp', 'tmp_name' => '/private/tmp/front.webp', 'size' => 4, 'type' => 'image/webp'],
                ['name' => 'dial.webp', 'tmp_name' => '/private/tmp/dial.webp', 'size' => 4, 'type' => 'image/webp'],
                ['name' => 'back.webp', 'tmp_name' => '/private/tmp/back.webp', 'size' => 4, 'type' => 'image/webp'],
            ],
        ]);

        self::assertSame(['media-front', 'media-dial', 'media-back'], array_column($result['capture']['assets'], 'media_id'));
        self::assertSame(['representative', 'gallery', 'technical_detail'], array_column($result['capture']['assets'], 'disposition'));
    }

    public function test_asset_followup_reuses_capture_and_article_and_is_idempotent(): void
    {
        $captures = new AssetFollowUpCaptureRepository();
        $addenda = new AssetFollowUpAddendumRepository();
        $capture = new CaptureRecord(
            UuidCodec::newV7(), 'capture-assets', hash('sha256', 'capture-assets'),
            CaptureStage::READY_FOR_PUBLICATION->value, 'PARTIAL', 512, 'state-512',
            [], ['raw_input' => 'Bài về mặt số.', 'subject_hints' => ['Odo 36/8'], 'content_intent' => ['intent' => 'IMAGE_ARTICLE', 'article_required' => true]],
            ['media_adoption' => ['status' => 'verified'], 'composition' => ['title' => 'Bài về mặt số.']], [],
        );
        $captures->create($capture);
        $calls = ['physical' => 0, 'adoption' => 0, 'draft' => 0];
        $coordinator = new EditorialCaptureCoordinator(
            $captures,
            static function (array $input) use (&$calls): array {
                $calls['physical']++;
                return ['items' => [['client_file_id' => 'dial-1', 'attachment_id' => 77, 'media_id' => 'media-dial', 'filename' => 'dial.webp', 'attachment_readback_status' => 'verified']]];
            },
            static function (array $input) use (&$calls): array { $calls['draft']++; return ['post_id' => 512, 'state_token' => 'state-512']; },
            new TextInputInterpreter(),
            new SubjectResolutionService(static fn (string $hint): array => []),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static fn (array $context): array => ['status' => 'PLANNED', 'writes' => []],
            new ArticleComposer(),
            static fn (array $context): array => ['status' => 'RECONCILED'],
            static fn (array $context): array => ['eligible' => false, 'blockers' => ['OWNER_PUBLICATION_REQUIRED']],
            static fn (array $context): array => ['status' => 'verified'],
            null,
            static function (array $context) use (&$calls): array { $calls['adoption']++; return ['status' => 'verified', 'media_id' => (string) ($context['asset']['media_id'] ?? '')]; },
        );
        $service = new EditorialCaptureContinuationService($captures, $addenda, $coordinator, static function (array $input) use (&$calls): array {
            $calls['physical']++;
            return ['items' => [['client_file_id' => 'dial-1', 'attachment_id' => 77, 'media_id' => 'media-dial', 'filename' => 'dial.webp', 'attachment_readback_status' => 'verified']]];
        });
        $file = ['name' => 'dial.webp', 'tmp_name' => '/private/tmp/dial.webp', 'size' => 4, 'type' => 'image/webp'];

        $first = $service->execute(['capture_id' => $capture->captureId, 'idempotency_key' => 'asset-followup-1', 'followup_mode' => 'ATTACH_ASSETS', 'files' => [$file], 'items' => [['client_file_id' => 'dial-1', 'visual_context' => ['feature_key' => 'DIAL']]]]);
        $replay = $service->execute(['capture_id' => $capture->captureId, 'idempotency_key' => 'asset-followup-1', 'followup_mode' => 'ATTACH_ASSETS', 'files' => [$file], 'items' => [['client_file_id' => 'dial-1', 'visual_context' => ['feature_key' => 'DIAL']]]]);
        $governanceReplay = $service->execute(['capture_id' => $capture->captureId, 'idempotency_key' => 'asset-followup-1', 'governance' => ['approval_confirmed' => true]]);

        self::assertSame('COMPLETED', $first['addendum']['status'], json_encode($first, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        self::assertSame($capture->captureId, $first['capture']['capture_id']);
        self::assertSame(512, $first['capture']['article_id']);
        self::assertSame($first['addendum']['addendum_id'], $replay['addendum']['addendum_id']);
        self::assertSame($first['addendum']['addendum_id'], $governanceReplay['addendum']['addendum_id']);
        self::assertSame('COMPLETED', $governanceReplay['addendum']['status']);
        self::assertSame(1, $calls['physical']);
        self::assertSame(0, $calls['adoption']);
        self::assertSame(0, $calls['draft']);
        self::assertSame('media-dial', $first['capture']['assets'][0]['media_id']);
        self::assertSame('ATTACH_ASSETS', $first['addendum']['payload']['followup_mode']);
        self::assertArrayNotHasKey('tmp_name', $first['addendum']['payload']);
    }
}

final class AssetFollowUpCaptureRepository implements CaptureRepository
{
    /** @var array<string,CaptureRecord> */
    public array $records = [];
    public function findByIdempotencyKey(string $key): ?CaptureRecord { foreach ($this->records as $record) if ($record->idempotencyKey === $key) return $record; return null; }
    public function findById(string $captureId): ?CaptureRecord { return $this->records[$captureId] ?? null; }
    public function create(CaptureRecord $record): CaptureRecord { return $this->records[$record->captureId] = $record; }
    public function save(CaptureRecord $record): CaptureRecord { return $this->records[$record->captureId] = $record; }
}

final class AssetFollowUpAddendumRepository implements CaptureAddendumRepository
{
    /** @var array<string,CaptureAddendumRecord> */
    public array $records = [];
    public function findByIdempotencyKey(string $key): ?CaptureAddendumRecord { return $this->records[$key] ?? null; }
    public function create(CaptureAddendumRecord $record): CaptureAddendumRecord { return $this->records[$record->idempotencyKey] = $record; }
    public function save(CaptureAddendumRecord $record): CaptureAddendumRecord { return $this->records[$record->idempotencyKey] = $record; }
}
