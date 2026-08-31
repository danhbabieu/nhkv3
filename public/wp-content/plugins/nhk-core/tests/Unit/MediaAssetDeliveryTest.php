<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\PublicMediaAssetDelivery;
use NHK\Core\Contracts\Media\MediaAssetRepository;
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
        $asset = new MediaAsset(UuidCodec::newV7(), UuidCodec::newV7(), 'original', 'image.webp', hash('sha256', $contents), 'image/webp', strlen($contents));
        try {
            $resolved = (new PublicMediaAssetDelivery($this->repository($asset), $root))->resolve($asset->assetId);
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
            self::assertNull((new PublicMediaAssetDelivery($this->repository($private), $root))->resolve($private->assetId));
            self::assertNull((new PublicMediaAssetDelivery($this->repository($mismatch), $root))->resolve($mismatch->assetId));
        } finally {
            unlink($root . '/private.webp');
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
}
