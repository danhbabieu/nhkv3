<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\MediaFilenameNormalizer;
use NHK\Core\Shared\Text\VietnameseSlugNormalizer;
use PHPUnit\Framework\TestCase;

final class MediaFilenameNormalizerTest extends TestCase
{
    public function test_uses_shared_normalizer_for_deterministic_vietnamese_upload_filename(): void
    {
        $normalizer = new MediaFilenameNormalizer(new VietnameseSlugNormalizer(120));

        self::assertSame('odo-mat-sau-a71c.jpg', $normalizer->normalize('Ô Đô', 'Mặt sau', 'DSCF8291.JPG', 'a71c'));
        self::assertSame('odo-mat-sau-a71c.jpg', $normalizer->normalize('Ô Đô', 'Mặt sau', 'DSCF8291.JPG', 'a71c'));
    }

    public function test_preserves_supported_extension_and_bounds_empty_context(): void
    {
        $normalizer = new MediaFilenameNormalizer(new VietnameseSlugNormalizer(120));

        self::assertSame('odo-a71c.webp', $normalizer->normalize('Ô Đô', '', 'IMG_1234.WEBP', 'a71c'));
        self::assertSame('media-view-a71c.png', $normalizer->normalize('', 'View', 'IMG_1234.PNG', 'a71c'));
        self::assertSame('media-a71c.gif', $normalizer->normalize('', '', 'IMG_1234.GIF', 'a71c'));
    }
}
