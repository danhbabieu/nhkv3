<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\PublicMediaAssetDelivery;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository};
use NHK\Core\Domain\Media\{Media, MediaAsset};
use NHK\Core\Infrastructure\Http\PublicMediaAssetRoutes;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class PublicMediaAssetRoutesTest extends TestCase
{
    public function test_canonical_filename_returns_a_direct_webp_response_without_html(): void
    {
        [$root, $path, $asset, $media] = $this->fixture();
        try {
            $routes = new PublicMediaAssetRoutes($this->delivery($root, $asset, $media, $path));

            $response = $routes->responseForFilename('example.webp');

            self::assertSame(200, $response['status'] ?? null);
            self::assertSame('image/webp', $response['content_type'] ?? null);
            self::assertSame(filesize($path), $response['size'] ?? null);
            $body = is_array($response) ? (string) file_get_contents((string) $response['path']) : '';
            self::assertStringStartsWith('RIFF', $body);
            self::assertSame('WEBP', substr($body, 8, 4));
            self::assertStringNotContainsString('<!DOCTYPE html>', $body);
            self::assertStringNotContainsString('<html', strtolower($body));
        } finally {
            unlink($path);
            rmdir($root);
        }
    }

    public function test_unknown_private_and_missing_physical_assets_have_no_response(): void
    {
        [$root, $path, $asset, $media] = $this->fixture();
        try {
            $routes = new PublicMediaAssetRoutes($this->delivery($root, $asset, $media, $path));
            self::assertNull($routes->responseForFilename('unknown.webp'));

            $private = new MediaAsset($asset->assetId, $asset->mediaId, $asset->kind, $asset->storageKey, $asset->checksum, $asset->mimeType, $asset->byteSize, $asset->width, $asset->height, 'PRIVATE', $asset->metadata);
            self::assertNull((new PublicMediaAssetRoutes($this->delivery($root, $private, $media, $path)))->responseForFilename('example.webp'));

            unlink($path);
            self::assertNull($routes->responseForFilename('example.webp'));
        } finally {
            if (is_file($path)) unlink($path);
            if (is_dir($root)) rmdir($root);
        }
    }

    public function test_route_streams_before_theme_and_uses_the_canonical_anh_rewrite(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/PublicMediaAssetRoutes.php');

        self::assertStringContainsString("add_rewrite_rule('^anh/([^/]+\\.webp)/?$'", $source);
        self::assertStringContainsString("add_action('template_redirect', [\$this, 'serve'], 0)", $source);
        self::assertStringContainsString('readfile($response[\'path\'])', $source);
        self::assertStringContainsString('exit;', $source);
        self::assertStringNotContainsString('template_include', $source);

        $plugin = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Plugin.php');
        self::assertStringContainsString('PublicMediaAssetDelivery::fromEnvironment($publicAssets, $publicMedia)', $plugin);
    }

    /** @return array{0:string,1:string,2:MediaAsset,3:Media} */
    private function fixture(): array
    {
        $root = sys_get_temp_dir() . '/nhk-media-route-' . bin2hex(random_bytes(4));
        mkdir($root);
        $source = dirname(__DIR__, 4) . '/uploads/integration-source-original-5.webp';
        $path = $root . '/physical-attachment-name.webp';
        self::assertTrue(copy($source, $path));
        $bytes = file_get_contents($path);
        self::assertIsString($bytes);
        $mediaId = UuidCodec::newV7();
        $asset = new MediaAsset(UuidCodec::newV7(), $mediaId, 'derivative', 'uploads/physical-attachment-name.webp', hash('sha256', $bytes), 'image/webp', strlen($bytes), 16, 304, 'PUBLIC', ['canonical_filename' => 'example.webp', 'wordpress_attachment_id' => 86]);
        return [$root, $path, $asset, new Media($mediaId, 'wp-attachment:1:86', 'Example', 'ready')];
    }

    private function delivery(string $root, MediaAsset $asset, Media $media, string $path): PublicMediaAssetDelivery
    {
        return new PublicMediaAssetDelivery(
            new class($asset) implements MediaAssetRepository {
                public function __construct(private MediaAsset $asset) {}
                public function findByAssetId(string $id): ?MediaAsset { return $id === $this->asset->assetId ? $this->asset : null; }
                public function create(MediaAsset $asset): MediaAsset { return $asset; }
                public function update(MediaAsset $asset, int $expectedRevision = 1): MediaAsset { return $asset; }
                public function listByMediaId(string $mediaId): array { return $mediaId === $this->asset->mediaId ? [$this->asset] : []; }
                public function findByChecksum(string $checksum): array { return []; }
            },
            new class($media) implements MediaRepository {
                public function __construct(private Media $media) {}
                public function findByCanonicalId(string $id): ?Media { return $id === $this->media->canonicalId ? $this->media : null; }
                public function findByStableKey(string $stableKey): ?Media { return null; }
                public function create(Media $media): Media { return $media; }
                public function update(Media $media, int $expectedRevision): Media { return $media; }
                public function list(bool $includeRetired = false): array { return [$this->media]; }
            },
            $root,
            static fn (MediaAsset $bound): ?string => (int) ($bound->metadata['wordpress_attachment_id'] ?? 0) === 86 ? $path : null,
        );
    }
}
