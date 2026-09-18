<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\MediaService;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Domain\Media\{Media, MediaAsset};
use NHK\Core\Infrastructure\Media\WordPressMediaAttachmentBridge;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class WordPressMediaAttachmentReadbackConsistencyTest extends TestCase
{
    public function test_binding_is_inconsistent_when_current_physical_checksum_differs(): void
    {
        $mediaId = UuidCodec::newV7();
        $assetId = UuidCodec::newV7();
        $asset = new MediaAsset($assetId, $mediaId, 'original', 'private/source.png', hash('sha256', 'canonical'), 'image/png', 9, 640, 480, 'PRIVATE');
        $media = new Media($mediaId, 'wp-attachment:1:77', 'Readback', 'ready');
        $bridge = $this->bridge($media, $asset, $mediaId, $assetId, 'private/source.png');

        $result = $bridge->bindingForAttachment(77, [
            'checksum' => hash('sha256', 'edited'), 'storage_key' => 'private/source.png',
            'byte_size' => 9, 'width' => 640, 'height' => 480, 'mime_type' => 'image/png',
        ]);

        self::assertSame('INCONSISTENT', $result['readback_state']);
        self::assertSame('ATTACHMENT_PHYSICAL_CHECKSUM_MISMATCH', $result['error_code']);
    }

    public function test_binding_is_verified_only_when_media_mapping_storage_and_physical_facts_agree(): void
    {
        $mediaId = UuidCodec::newV7();
        $assetId = UuidCodec::newV7();
        $checksum = hash('sha256', 'canonical');
        $asset = new MediaAsset($assetId, $mediaId, 'original', 'private/source.png', $checksum, 'image/png', 9, 640, 480, 'PRIVATE');
        $media = new Media($mediaId, 'wp-attachment:1:78', 'Readback', 'ready');
        $bridge = $this->bridge($media, $asset, $mediaId, $assetId, 'private/source.png');

        $result = $bridge->bindingForAttachment(78, [
            'checksum' => $checksum, 'storage_key' => 'private/source.png',
            'byte_size' => 9, 'width' => 640, 'height' => 480, 'mime_type' => 'image/png',
        ]);

        self::assertSame('VERIFIED', $result['readback_state']);
        self::assertSame($assetId, $result['asset_id']);
    }

    /** @dataProvider incompletePhysicalFactsProvider */
    public function test_binding_fails_closed_when_any_required_physical_fact_is_missing(string $missingFact): void
    {
        $mediaId = UuidCodec::newV7();
        $assetId = UuidCodec::newV7();
        $asset = new MediaAsset($assetId, $mediaId, 'derivative', 'uploads/public.webp', hash('sha256', 'canonical'), 'image/webp', 9, 640, 480, 'PUBLIC');
        $media = new Media($mediaId, 'wp-attachment:1:79', 'Readback', 'ready');
        $bridge = $this->bridge($media, $asset, $mediaId, $assetId, 'uploads/public.webp');
        $facts = [
            'checksum' => $asset->checksum,
            'byte_size' => $asset->byteSize,
            'width' => $asset->width,
            'height' => $asset->height,
            'mime_type' => $asset->mimeType,
        ];
        unset($facts[$missingFact]);

        $result = $bridge->bindingForAttachment(79, $facts);

        self::assertSame('INCONSISTENT', $result['readback_state']);
        self::assertSame('ATTACHMENT_PHYSICAL_READBACK_REQUIRED', $result['error_code']);
    }

    /** @return array<string,array{string}> */
    public static function incompletePhysicalFactsProvider(): array
    {
        return [
            'checksum' => ['checksum'],
            'byte size' => ['byte_size'],
            'width' => ['width'],
            'height' => ['height'],
            'mime' => ['mime_type'],
        ];
    }

    private function bridge(Media $media, MediaAsset $asset, string $mediaId, string $assetId, string $storageKey): WordPressMediaAttachmentBridge
    {
        $mediaRepository = new class($media) implements MediaRepository {
            public function __construct(private Media $media) {}
            public function findByCanonicalId(string $id): ?Media { return $id === $this->media->canonicalId ? $this->media : null; }
            public function findByStableKey(string $stableKey): ?Media { return $stableKey === $this->media->stableKey ? $this->media : null; }
            public function create(Media $media): Media { return $media; }
            public function update(Media $media, int $expectedRevision = 1): Media { return $media; }
            public function list(bool $includeRetired = false): array { return [$this->media]; }
        };
        $assetRepository = new class($asset) implements MediaAssetRepository {
            public function __construct(private MediaAsset $asset) {}
            public function findByAssetId(string $id): ?MediaAsset { return $id === $this->asset->assetId ? $this->asset : null; }
            public function create(MediaAsset $asset): MediaAsset { return $asset; }
            public function update(MediaAsset $asset, int $expectedRevision = 1): MediaAsset { return $asset; }
            public function listByMediaId(string $mediaId): array { return $mediaId === $this->asset->mediaId ? [$this->asset] : []; }
            public function findByChecksum(string $checksum): array { return $checksum === $this->asset->checksum ? [$this->asset] : []; }
        };
        $database = new class($mediaId, $assetId, $storageKey) {
            public string $prefix = 'wp_';
            public function __construct(private string $mediaId, private string $assetId, private string $storageKey) {}
            public function prepare(string $query, mixed ...$arguments): string { return $query; }
            public function get_var(string $query): string { return UuidCodec::toBinary($this->mediaId); }
            public function get_row(string $query, mixed $output): array { return ['media_uuid' => UuidCodec::toBinary($this->mediaId), 'asset_uuid' => UuidCodec::toBinary($this->assetId), 'attachment_id' => 77, 'storage_key' => $this->storageKey]; }
            public function query(string $query): int { return 1; }
        };
        return new WordPressMediaAttachmentBridge($database, new MediaService($mediaRepository, $assetRepository, new class implements MediaUsageRepository {
            public function listByMediaId(string $mediaId, ?string $role = null): array { return []; }
            public function listByEndpoint(string $endpointType, string $endpointKey, ?string $role = null): array { return []; }
            public function create(\NHK\Core\Domain\Media\MediaUsage $usage): \NHK\Core\Domain\Media\MediaUsage { return $usage; }
            public function update(\NHK\Core\Domain\Media\MediaUsage $usage, int $expectedRevision = 1): \NHK\Core\Domain\Media\MediaUsage { return $usage; }
        }), $mediaRepository, $assetRepository);
    }
}
