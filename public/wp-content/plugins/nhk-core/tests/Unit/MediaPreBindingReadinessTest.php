<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\MediaPreBindingReadiness;
use NHK\Core\Domain\Media\{Media, MediaAsset};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class MediaPreBindingReadinessTest extends TestCase
{
    public function test_public_derivative_is_ready_without_usage_or_representative(): void
    {
        $media = $this->media();
        $asset = $this->asset($media, 'derivative', 'PUBLIC', 1200, 800);

        $result = (new MediaPreBindingReadiness())->check($media, [$asset]);

        self::assertTrue($result['ready']);
        self::assertSame($asset->assetId, $result['asset_id']);
    }

    public function test_usage_and_representative_are_not_inputs_to_asset_readiness(): void
    {
        $media = $this->media();
        $asset = $this->asset($media, 'derivative', 'PUBLIC', 640, 480);

        self::assertTrue((new MediaPreBindingReadiness())->check($media, [$asset])['ready']);
    }

    public function test_invalid_public_derivative_is_blocked(): void
    {
        $media = $this->media();
        $asset = $this->asset($media, 'derivative', 'PUBLIC', null, 480);

        self::assertSame('MEDIA_PUBLIC_DERIVATIVE_REQUIRED', (new MediaPreBindingReadiness())->check($media, [$asset])['reason']);
    }

    public function test_private_only_and_missing_derivative_are_blocked(): void
    {
        $media = $this->media();
        $private = $this->asset($media, 'derivative', 'PRIVATE', 640, 480);
        $original = $this->asset($media, 'original', 'PUBLIC', 640, 480);
        $readiness = new MediaPreBindingReadiness();

        self::assertFalse($readiness->check($media, [$private])['ready']);
        self::assertFalse($readiness->check($media, [$original])['ready']);
    }

    private function media(): Media
    {
        return new Media(UuidCodec::newV7(), 'media:readiness', 'Readiness media', 'ready');
    }

    private function asset(Media $media, string $kind, string $visibility, ?int $width, ?int $height): MediaAsset
    {
        return new MediaAsset(UuidCodec::newV7(), $media->canonicalId, $kind, 'asset.webp', str_repeat('a', 64), 'image/webp', 10, $width, $height, $visibility);
    }
}
