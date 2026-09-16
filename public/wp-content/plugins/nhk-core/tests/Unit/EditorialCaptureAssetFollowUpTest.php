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
    public function test_asset_followup_reuses_capture_and_article_and_is_idempotent(): void
    {
        $captures = new AssetFollowUpCaptureRepository();
        $addenda = new AssetFollowUpAddendumRepository();
        $capture = new CaptureRecord(
            UuidCodec::newV7(), 'capture-assets', hash('sha256', 'capture-assets'),
            CaptureStage::READY_FOR_PUBLICATION->value, 'PARTIAL', 512, 'state-512',
            [], ['raw_input' => 'Bài về mặt số.', 'subject_hints' => ['Odo 36/8']],
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
        self::assertSame(1, $calls['adoption']);
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
