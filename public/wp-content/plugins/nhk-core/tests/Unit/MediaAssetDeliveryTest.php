<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\PublicMediaAssetDelivery;
use NHK\Core\Contracts\Media\MediaAssetRepository;
use NHK\Core\Contracts\Media\MediaRepository;
use NHK\Core\Domain\Media\Media;
use NHK\Core\Domain\Media\MediaAsset;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class MediaAssetDeliveryTest extends TestCase
{
    public function test_public_asset_requires_safe_in_root_file_with_matching_metadata(): void
    {
        $root = sys_get_temp_dir() . '/nhk-media-' . bin2hex(random_bytes(4));
        mkdir($root);
        $contents = 'public-image';
        file_put_contents($root . '/image.webp', $contents);
        $asset = new MediaAsset(UuidCodec::newV7(), UuidCodec::newV7(), 'original', 'image.webp', hash('sha256', $contents), 'image/webp', strlen($contents), null, null, 'PUBLIC');
        try {
            $resolved = (new PublicMediaAssetDelivery($this->repository($asset), $this->mediaRepository(new Media($asset->mediaId, 'ready-media', 'Ready media', 'ready')), $root))->resolve($asset->assetId);
            self::assertIsArray($resolved);
            self::assertSame(realpath($root . '/image.webp'), $resolved['path']);
        } finally {
            unlink($root . '/image.webp');
            rmdir($root);
        }
    }

    public function test_private_outside_root_and_integrity_mismatch_assets_fail_closed(): void
    {
        $root = sys_get_temp_dir() . '/nhk-media-' . bin2hex(random_bytes(4));
        mkdir($root);
        file_put_contents($root . '/private.webp', 'private');
        $private = new MediaAsset(UuidCodec::newV7(), UuidCodec::newV7(), 'original', 'private.webp', hash('sha256', 'private'), 'image/webp', 7, null, null, 'PRIVATE');
        $mismatch = new MediaAsset(UuidCodec::newV7(), UuidCodec::newV7(), 'original', 'private.webp', hash('sha256', 'wrong'), 'image/webp', 7);
        try {
            $media = new Media($private->mediaId, 'ready-media', 'Ready media', 'ready');
            self::assertNull((new PublicMediaAssetDelivery($this->repository($private), $this->mediaRepository($media), $root))->resolve($private->assetId));
            self::assertNull((new PublicMediaAssetDelivery($this->repository($mismatch), $this->mediaRepository($media), $root))->resolve($mismatch->assetId));
        } finally {
            unlink($root . '/private.webp');
            rmdir($root);
        }
    }

    public function test_asset_delivery_fails_closed_for_draft_or_retired_parent_media(): void
    {
        $root = sys_get_temp_dir() . '/nhk-media-' . bin2hex(random_bytes(4));
        mkdir($root);
        $contents = 'public-image';
        file_put_contents($root . '/image.webp', $contents);
        $mediaId = UuidCodec::newV7();
        $asset = new MediaAsset(UuidCodec::newV7(), $mediaId, 'original', 'image.webp', hash('sha256', $contents), 'image/webp', strlen($contents), null, null, 'PUBLIC');
        try {
            foreach ([new Media($mediaId, 'draft-media', 'Draft media'), new Media($mediaId, 'retired-media', 'Retired media', 'ready', [], false)] as $media) {
                self::assertNull((new PublicMediaAssetDelivery($this->repository($asset), $this->mediaRepository($media), $root))->resolve($asset->assetId));
            }
        } finally {
            unlink($root . '/image.webp');
            rmdir($root);
        }
    }

    private function repository(MediaAsset $asset): MediaAssetRepository
    {
        return new class($asset) implements MediaAssetRepository {
            public function __construct(private MediaAsset $asset) {}
            public function findByAssetId(string $id): ?MediaAsset { return $id === $this->asset->assetId ? $this->asset : null; }
            public function create(MediaAsset $asset): MediaAsset { return $asset; }
            public function update(MediaAsset $asset, int $expectedRevision = 1): MediaAsset { return $asset; }
            public function listByMediaId(string $mediaId): array { return []; }
            public function findByChecksum(string $checksum): array { return []; }
        };
    }

    private function mediaRepository(Media $media): MediaRepository
    {
        return new class($media) implements MediaRepository {
            public function __construct(private Media $media) {}
            public function findByCanonicalId(string $id): ?Media { return $id === $this->media->canonicalId ? $this->media : null; }
            public function findByStableKey(string $stableKey): ?Media { return $stableKey === $this->media->stableKey ? $this->media : null; }
            public function create(Media $media): Media { return $media; }
            public function update(Media $media, int $expectedRevision): Media { return $media; }
            public function list(bool $includeRetired = false): array { return [$this->media]; }
        };
    }
}
