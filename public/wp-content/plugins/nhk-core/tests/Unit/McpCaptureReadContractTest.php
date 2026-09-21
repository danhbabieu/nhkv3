<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\McpReadHandler;
use NHK\Core\Contracts\Capture\CaptureRepository;
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use PHPUnit\Framework\TestCase;

final class McpCaptureReadContractTest extends TestCase
{
    public function test_capture_readback_is_bounded_and_does_not_expose_request_secrets(): void
    {
        $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $record = new CaptureRecord(
            $id,
            'private-idempotency-key',
            hash('sha256', 'private-request'),
            'FINAL_READBACK',
            'PARTIAL',
            631,
            null,
            [['kind' => 'image', 'media_id' => 'media-631', 'attachment_id' => 630, 'attachment_readback_status' => 'verified']],
            ['purpose' => 'EDITORIAL', 'content_intent' => ['intent' => 'IMAGE_ARTICLE'], 'subject_resolution_packet' => ['status' => 'resolved', 'canonical_subject_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'entity_type' => 'model', 'canonical_name' => 'Odo 36/10'], 'raw_input' => 'private text', 'media_bindings' => [['target' => ['type' => 'wp_post', 'id' => '1:631'], 'role' => 'featured_primary', 'selection_source' => 'USER_EXPLICIT', 'selection_policy' => 'PINNED']]],
            ['completion' => ['status' => 'PARTIAL', 'blockers' => ['IMAGE_ARTICLE_MEDIA_REQUIRED'], 'required_owners' => [['owner_type' => 'wp_post', 'owner_id' => '631']], 'missing_required_owners' => [['owner_type' => 'media', 'owner_id' => 'media-631']]]],
            [],
            4,
        );
        $repository = new class($record) implements CaptureRepository {
            public function __construct(private CaptureRecord $record) {}
            public function findByIdempotencyKey(string $key): ?CaptureRecord { return null; }
            public function findById(string $captureId): ?CaptureRecord { return $captureId === $this->record->captureId ? $this->record : null; }
            public function create(CaptureRecord $record): CaptureRecord { return $record; }
            public function save(CaptureRecord $record): CaptureRecord { return $record; }
        };
        $read = new McpReadHandler(
            $this->createMock(AuthorityRepository::class), new EntityTypeRegistry(),
            $this->createMock(MediaRepository::class), $this->createMock(MediaAssetRepository::class), $this->createMock(MediaUsageRepository::class),
            $this->createMock(VideoRepository::class), $this->createMock(KnowledgeRepository::class), $this->createMock(EvidenceRepository::class),
            captures: $repository,
        );

        $projection = $read->captureGet($id);
        self::assertSame($id, $projection['capture_id']);
        self::assertSame('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $projection['subject_resolution_packet']['canonical_subject_id']);
        self::assertSame(631, $projection['article']['post_id']);
        self::assertSame('media-631', $projection['media'][0]['media_id']);
        self::assertContains('IMAGE_ARTICLE_MEDIA_REQUIRED', $projection['blockers']);
        self::assertArrayNotHasKey('idempotency_key', $projection);
        self::assertArrayNotHasKey('request_fingerprint', $projection);
        self::assertArrayNotHasKey('raw_input', $projection);
    }

    public function test_unknown_capture_returns_explicit_not_found_instead_of_null(): void
    {
        $repository = new class implements CaptureRepository {
            public function findByIdempotencyKey(string $key): ?CaptureRecord { return null; }
            public function findById(string $captureId): ?CaptureRecord { return null; }
            public function create(CaptureRecord $record): CaptureRecord { return $record; }
            public function save(CaptureRecord $record): CaptureRecord { return $record; }
        };
        $read = new McpReadHandler(
            $this->createMock(AuthorityRepository::class), new EntityTypeRegistry(),
            $this->createMock(MediaRepository::class), $this->createMock(MediaAssetRepository::class), $this->createMock(MediaUsageRepository::class),
            $this->createMock(VideoRepository::class), $this->createMock(KnowledgeRepository::class), $this->createMock(EvidenceRepository::class),
            captures: $repository,
        );

        self::assertSame([
            'status' => 'not_found',
            'reason' => 'CAPTURE_NOT_FOUND',
            'capture_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'retry' => ['eligible' => false, 'reason' => 'CAPTURE_NOT_FOUND'],
        ], $read->captureGet('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'));
    }
}
