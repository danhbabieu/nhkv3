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
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage};
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Core\Contracts\Video\VideoRepository;
use PHPUnit\Framework\TestCase;

final class McpCaptureReadContractTest extends TestCase
{
    public function test_capture_readback_reconciles_current_post_and_media_usage_over_stale_receipt(): void
    {
        $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $mediaId = UuidCodec::newV7();
        $assetId = UuidCodec::newV7();
        $media = new Media($mediaId, 'capture-readback-media', 'Current image', 'ready');
        $asset = new MediaAsset($assetId, $mediaId, 'original', 'uploads/current.jpg', hash('sha256', 'current'), 'image/jpeg', 12, 1200, 800, 'PUBLIC');
        $usage = new MediaUsage(UuidCodec::newV7(), $mediaId, 'wp_post', '1:690', 'featured_primary');
        $record = new CaptureRecord(
            $id,
            'capture-current-canonical-state',
            hash('sha256', 'capture-current-canonical-state'),
            'FINAL_READBACK',
            'PARTIAL',
            690,
            null,
            [['kind' => 'image', 'media_id' => $mediaId, 'attachment_id' => 686, 'attachment_readback_status' => 'verified']],
            ['content_intent' => ['intent' => 'IMAGE_ARTICLE']],
            ['completion' => [
                'required_owners' => [['owner_type' => 'wp_post', 'owner_id' => '']],
                'children' => [
                    ['completion' => [
                        'owner_type' => 'wp_post', 'owner_id' => '', 'status' => 'PARTIAL', 'complete' => false,
                        'canonical_state' => 'BLOCKED', 'canonical_readback' => null, 'dependency_state' => 'COMPLETE',
                        'relation_or_usage_state' => 'PARTIAL', 'public_state' => 'NOT_APPLICABLE', 'frontend_state' => 'NOT_APPLICABLE',
                        'blockers' => ['MEDIAUSAGE_INCOMPLETE', 'ARTICLE_MEDIA_FEATURED_MISSING', 'REQUIRED_OWNER_READBACK_UNVERIFIED'],
                    ]],
                    ['completion' => [
                        'owner_type' => 'media', 'owner_id' => $mediaId, 'status' => 'PARTIAL', 'complete' => false,
                        'canonical_state' => 'COMPLETE', 'canonical_readback' => ['canonical_id' => $mediaId],
                        'dependency_state' => 'COMPLETE', 'relation_or_usage_state' => 'PARTIAL', 'public_state' => 'READY',
                        'frontend_state' => 'VERIFIED', 'blockers' => ['MEDIAUSAGE_INCOMPLETE'],
                    ]],
                ],
            ]],
            [],
            2,
        );
        $captures = new class($record) implements CaptureRepository {
            public function __construct(private CaptureRecord $record) {}
            public function findByIdempotencyKey(string $key): ?CaptureRecord { return null; }
            public function findById(string $captureId): ?CaptureRecord { return $captureId === $this->record->captureId ? $this->record : null; }
            public function create(CaptureRecord $record): CaptureRecord { return $record; }
            public function save(CaptureRecord $record): CaptureRecord { return $record; }
        };
        $mediaRepository = new class($media) implements MediaRepository {
            public function __construct(private Media $media) {}
            public function findByCanonicalId(string $id): ?Media { return $id === $this->media->canonicalId ? $this->media : null; }
            public function findByStableKey(string $key): ?Media { return null; }
            public function create(Media $media): Media { return $media; }
            public function update(Media $media, int $expectedRevision): Media { return $media; }
            public function list(bool $includeRetired = false): array { return [$this->media]; }
        };
        $assetRepository = new class($asset) implements MediaAssetRepository {
            public function __construct(private MediaAsset $asset) {}
            public function findByAssetId(string $id): ?MediaAsset { return $id === $this->asset->assetId ? $this->asset : null; }
            public function create(MediaAsset $asset): MediaAsset { return $asset; }
            public function update(MediaAsset $asset, int $expectedRevision = 1): MediaAsset { return $asset; }
            public function listByMediaId(string $mediaId): array { return $mediaId === $this->asset->mediaId ? [$this->asset] : []; }
            public function findByChecksum(string $checksum): array { return []; }
        };
        $usageRepository = new class($usage) implements MediaUsageRepository {
            public function __construct(private MediaUsage $usage) {}
            public function create(MediaUsage $usage): MediaUsage { return $usage; }
            public function listByMediaId(string $mediaId, ?string $role = null): array { return $mediaId === $this->usage->mediaId && ($role === null || $role === $this->usage->role) ? [$this->usage] : []; }
            public function listByEndpoint(string $endpointType, string $endpointKey, ?string $role = null): array { return $endpointType === $this->usage->endpointType && $endpointKey === $this->usage->endpointKey && ($role === null || $role === $this->usage->role) ? [$this->usage] : []; }
        };
        $read = new McpReadHandler(
            $this->createMock(AuthorityRepository::class), new EntityTypeRegistry(), $mediaRepository, $assetRepository, $usageRepository,
            $this->createMock(VideoRepository::class), $this->createMock(KnowledgeRepository::class), $this->createMock(EvidenceRepository::class), captures: $captures,
        );

        $projection = $read->captureGet($id);

        self::assertSame([['owner_type' => 'wp_post', 'owner_id' => '690'], ['owner_type' => 'media', 'owner_id' => $mediaId]], $projection['required_owners']);
        self::assertSame([], $projection['missing_required_owners']);
        self::assertNotContains('MEDIAUSAGE_INCOMPLETE', $projection['blockers']);
        self::assertNotContains('ARTICLE_MEDIA_FEATURED_MISSING', $projection['blockers']);
        self::assertNotContains('REQUIRED_OWNER_READBACK_UNVERIFIED', $projection['blockers']);
    }

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
            ['completion' => ['status' => 'PARTIAL', 'blockers' => ['IMAGE_ARTICLE_MEDIA_REQUIRED'], 'children' => [['owner_type' => 'video', 'owner_id' => 'video-631', 'status' => 'PARTIAL'], ['owner_type' => 'video', 'owner_id' => 'video-631', 'status' => 'COMPLETE'], ['owner_type' => 'wp_post', 'owner_id' => '631', 'status' => 'INCOMPLETE']], 'required_owners' => [['owner_type' => 'wp_post', 'owner_id' => '631']], 'missing_required_owners' => [['owner_type' => 'media', 'owner_id' => 'media-631']]]],
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
        self::assertSame('found', $projection['status']);
        self::assertSame('PARTIAL', $projection['capture_status']);
        self::assertSame('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $projection['subject_resolution_packet']['canonical_subject_id']);
        self::assertSame(631, $projection['article']['post_id']);
        self::assertSame('media-631', $projection['media'][0]['media_id']);
        self::assertContains('IMAGE_ARTICLE_MEDIA_REQUIRED', $projection['blockers']);
        self::assertSame([
            ['owner_type' => 'video', 'owner_id' => 'video-631', 'status' => 'COMPLETE'],
            ['owner_type' => 'wp_post', 'owner_id' => '631', 'status' => 'INCOMPLETE'],
        ], $projection['owners']);
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

    public function test_capture_read_retry_projection_matches_shared_executor_gate_for_non_resumable_block(): void
    {
        $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $record = new CaptureRecord($id, 'retry-parity', hash('sha256', 'retry-parity'), 'SEMANTICS_RECONCILED', 'SYSTEM_BLOCKED', diagnostics: [
            'completion' => ['status' => 'COMPLETE', 'children' => [['owner_type' => 'video', 'complete' => true, 'status' => 'COMPLETE']]],
            'resume_hints' => ['resume_children' => ['video']],
        ]);
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

        self::assertSame(['eligible' => false, 'reason' => 'CAPTURE_RETRY_NOT_ALLOWED', 'capture_id' => $id], $read->captureGet($id)['retry']);
    }
}
