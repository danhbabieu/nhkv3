<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\MediaFilenameNormalizer;
use PHPUnit\Framework\TestCase;

final class MediaFilenameNormalizerTest extends TestCase
{
    public function test_media_filename_uses_shared_vietnamese_slug_without_default_hash(): void
    {
        $normalizer = new MediaFilenameNormalizer();

        self::assertSame('nguoi-suu-tap-tuoi-tre-mat-sau-bo-may.jpg', $normalizer->normalize('Người sưu tập tuổi trẻ', 'Mặt sau bộ máy', 'DSCF8291.JPG'));
        self::assertSame('nguoi-suu-tap-tuoi-tre.webp', $normalizer->normalizeWebp('Người sưu tập tuổi trẻ', 'image', 'IMG_1234.JPG'));
    }

    public function test_media_filename_keeps_explicit_collision_suffix_only_when_supplied(): void
    {
        $normalizer = new MediaFilenameNormalizer();

        self::assertSame('dong-ho-co-mat-so-nha-kho-20260906.jpg', $normalizer->normalize('Đồng hồ cổ', 'Mặt số', 'IMG_1234.JPG', 'nha-kho-20260906'));
    }
}
