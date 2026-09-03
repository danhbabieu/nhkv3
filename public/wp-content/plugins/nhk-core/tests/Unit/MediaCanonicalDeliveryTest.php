<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\{MediaFilenameNormalizer, PublicMediaAssetUrlResolver};
use PHPUnit\Framework\TestCase;

final class MediaCanonicalDeliveryTest extends TestCase
{
    public function test_managed_media_uses_seo_filename_without_hash_or_physical_upload_path(): void
    {
        $filename = (new MediaFilenameNormalizer())->normalizeWebp('Đồng hồ có mặt kính hình kim cương', 'image', 'IMG_0001.JPG');

        self::assertSame('dong-ho-co-mat-kinh-hinh-kim-cuong.webp', $filename);
        self::assertSame('/anh/dong-ho-co-mat-kinh-hinh-kim-cuong.webp', (new PublicMediaAssetUrlResolver())->path($filename));
        self::assertStringNotContainsString('wp-content/uploads', (new PublicMediaAssetUrlResolver())->path($filename));
    }

    public function test_collision_is_numbered_and_same_asset_retry_is_stable(): void
    {
        $resolver = new PublicMediaAssetUrlResolver();

        self::assertSame('dong-ho-co-mat-kinh-hinh-kim-cuong-2.webp', $resolver->collisionSafeFilename('dong-ho-co-mat-kinh-hinh-kim-cuong.webp', ['dong-ho-co-mat-kinh-hinh-kim-cuong.webp']));
        self::assertSame('dong-ho-co-mat-kinh-hinh-kim-cuong.webp', $resolver->collisionSafeFilename('dong-ho-co-mat-kinh-hinh-kim-cuong.webp', [], 'asset-1'));
        self::assertSame('dong-ho-co-mat-kinh-hinh-kim-cuong.webp', $resolver->collisionSafeFilename('dong-ho-co-mat-kinh-hinh-kim-cuong.webp', ['dong-ho-co-mat-kinh-hinh-kim-cuong.webp'], 'asset-1'));
    }
}
