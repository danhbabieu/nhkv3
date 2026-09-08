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

    public function test_public_filename_delivery_resolves_the_bound_wordpress_attachment_not_the_canonical_filename(): void
    {
        $root = sys_get_temp_dir() . '/nhk-media-' . bin2hex(random_bytes(4));
        mkdir($root);
        $fixture = dirname(__DIR__, 4) . '/uploads/integration-source-original-5.webp';
        $path = $root . '/physical-attachment-name.webp';
        self::assertFileExists($fixture);
        self::assertTrue(copy($fixture, $path));
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        self::assertSame(['width' => 16, 'height' => 304, 'mime' => 'image/webp'], ['width' => (int) getimagesize($path)[0], 'height' => (int) getimagesize($path)[1], 'mime' => (string) getimagesize($path)['mime']]);
        $mediaId = UuidCodec::newV7();
        $asset = new MediaAsset(UuidCodec::newV7(), $mediaId, 'derivative', 'uploads/2026/09/physical-attachment-name.webp', hash('sha256', $contents), 'image/webp', strlen($contents), 16, 304, 'PUBLIC', [
            'canonical_filename' => 'canonical-public-name.webp',
            'wordpress_attachment_id' => 86,
        ]);
        $media = new Media($mediaId, 'wp-attachment:1:86', 'Canonical public name', 'ready');
        try {
            $delivery = new PublicMediaAssetDelivery(
                $this->repository($asset),
                $this->mediaRepository($media),
                $root,
                static fn (MediaAsset $bound): ?string => (int) ($bound->metadata['wordpress_attachment_id'] ?? 0) === 86 ? $path : null,
            );

            self::assertIsArray($delivery->resolve($asset->assetId, true));
            $resolved = $delivery->resolveByPublicFilename('canonical-public-name.webp');

            self::assertIsArray($resolved);
            self::assertSame(realpath($path), $resolved['path']);
            self::assertStringStartsWith('RIFF', (string) file_get_contents($resolved['path'], false, null, 0, 4));
            self::assertSame('WEBP', (string) file_get_contents($resolved['path'], false, null, 8, 4));
        } finally {
            unlink($path);
            rmdir($root);
        }
    }

    public function test_public_filename_delivery_rejects_html_or_non_webp_bytes_even_when_metadata_says_webp(): void
    {
        $root = sys_get_temp_dir() . '/nhk-media-' . bin2hex(random_bytes(4));
        mkdir($root);
        $path = $root . '/not-really.webp';
        $contents = '<!DOCTYPE html><html>not an image</html>';
        file_put_contents($path, $contents);
        $mediaId = UuidCodec::newV7();
        $asset = new MediaAsset(UuidCodec::newV7(), $mediaId, 'derivative', 'wrong-storage-key.webp', hash('sha256', $contents), 'image/webp', strlen($contents), 1, 1, 'PUBLIC', [
            'canonical_filename' => 'html-disguised.webp',
            'wordpress_attachment_id' => 86,
        ]);
        $media = new Media($mediaId, 'wp-attachment:1:86', 'HTML disguised', 'ready');
        try {
            $delivery = new PublicMediaAssetDelivery(
                $this->repository($asset),
                $this->mediaRepository($media),
                $root,
                static fn (): string => $path,
            );

            self::assertNull($delivery->resolveByPublicFilename('html-disguised.webp'));
        } finally {
            unlink($path);
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
            public function listByMediaId(string $mediaId): array { return $mediaId === $this->asset->mediaId ? [$this->asset] : []; }
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
