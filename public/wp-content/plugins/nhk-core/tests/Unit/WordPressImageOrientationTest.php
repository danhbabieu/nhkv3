<?php
declare(strict_types=1);

namespace NHK\Tests\Unit {

use NHK\Core\Application\Media\PublicImageSizingPolicy;
use NHK\Core\Infrastructure\Media\WordPressImageOrientationNormalizer;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[PreserveGlobalState(false)]
final class WordPressImageOrientationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!extension_loaded('gd') || !extension_loaded('exif')) {
            self::markTestSkipped('GD and EXIF are required for image orientation regression tests.');
        }

    }

    #[RunInSeparateProcess]
    public function test_portrait_pixels_with_orientation_one_resize_to_portrait_webp(): void
    {
        $result = $this->process(1152, 1536, 1, 'portrait');

        self::assertSame(['width' => 1152, 'height' => 1536], $result['dimensions']);
        $this->assertDominantColor($result['image'], 450, 300, 'red');
        $this->assertDominantColor($result['image'], 450, 900, 'blue');
    }

    #[RunInSeparateProcess]
    public function test_landscape_pixels_with_orientation_one_resize_to_landscape_webp(): void
    {
        $result = $this->process(1536, 1152, 1, 'landscape');

        self::assertSame(['width' => 1536, 'height' => 1152], $result['dimensions']);
        $this->assertDominantColor($result['image'], 300, 450, 'red');
        $this->assertDominantColor($result['image'], 900, 450, 'blue');
    }

    #[RunInSeparateProcess]
    public function test_exif_orientation_six_is_applied_once_before_resize(): void
    {
        $result = $this->process(1536, 1152, 6, 'landscape');

        self::assertSame(['width' => 1152, 'height' => 1536], $result['dimensions']);
        $this->assertDominantColor($result['image'], 450, 300, 'red');
        $this->assertDominantColor($result['image'], 450, 900, 'blue');
        self::assertFalse($result['has_orientation_metadata']);
    }

    #[RunInSeparateProcess]
    public function test_exif_orientation_eight_is_applied_once_before_resize(): void
    {
        $result = $this->process(1536, 1152, 8, 'landscape');

        self::assertSame(['width' => 1152, 'height' => 1536], $result['dimensions']);
        $this->assertDominantColor($result['image'], 450, 300, 'blue');
        $this->assertDominantColor($result['image'], 450, 900, 'red');
        self::assertFalse($result['has_orientation_metadata']);
    }

    #[RunInSeparateProcess]
    public function test_image_without_exif_orientation_is_not_rotated(): void
    {
        $result = $this->process(1536, 1152, null, 'landscape');

        self::assertSame(['width' => 1536, 'height' => 1152], $result['dimensions']);
        $this->assertDominantColor($result['image'], 300, 450, 'red');
        $this->assertDominantColor($result['image'], 900, 450, 'blue');
        self::assertFalse($result['has_orientation_metadata']);
    }

    #[RunInSeparateProcess]
    public function test_small_image_with_orientation_one_is_not_upscaled(): void
    {
        $result = $this->process(800, 600, 1, 'landscape');

        self::assertSame(['width' => 800, 'height' => 600], $result['dimensions']);
        $this->assertDominantColor($result['image'], 200, 300, 'red');
        $this->assertDominantColor($result['image'], 600, 300, 'blue');
        self::assertFalse($result['has_orientation_metadata']);
    }

    /** @return array{dimensions:array{width:int,height:int},image:\GdImage,has_orientation_metadata:bool} */
    private function process(int $width, int $height, ?int $orientation, string $layout): array
    {
        $this->bootstrapWordPressImageEditorRuntime();
        $source = $this->createJpeg($width, $height, $orientation, $layout);
        $outputBase = tempnam(sys_get_temp_dir(), 'nhk-orientation-output-');
        self::assertIsString($outputBase);
        unlink($outputBase);
        $output = $outputBase . '.webp';

        try {
            $editor = new \WP_Image_Editor_GD($source);
            self::assertTrue($editor->load());

            (new WordPressImageOrientationNormalizer())->normalize($editor);
            $effective = $editor->get_size();
            self::assertIsArray($effective);
            $target = PublicImageSizingPolicy::constrain((int) $effective['width'], (int) $effective['height']);
            if ($target !== $effective) self::assertTrue($editor->resize($target['width'], $target['height'], false));
            self::assertTrue($editor->set_quality(86));
            $saved = $editor->save($output, 'image/webp');
            self::assertIsArray($saved);
            self::assertSame('image/webp', $saved['mime-type']);

            $info = getimagesize($output);
            self::assertIsArray($info);
            $image = imagecreatefromwebp($output);
            self::assertIsObject($image);
            $outputExif = @exif_read_data($output);

            return [
                'dimensions' => ['width' => (int) $info[0], 'height' => (int) $info[1]],
                'image' => $image,
                'has_orientation_metadata' => is_array($outputExif) && isset($outputExif['Orientation']),
            ];
        } finally {
            if (is_file($source)) unlink($source);
            if (is_file($output)) unlink($output);
        }
    }

    private function bootstrapWordPressImageEditorRuntime(): void
    {
        $root = dirname(__DIR__, 5) . '/';
        require_once dirname(__DIR__) . '/Support/WordPressImageOrientationStubs.php';
        if (!defined('ABSPATH')) define('ABSPATH', $root);
        if (!defined('WPINC')) define('WPINC', 'wp-includes');
        if (!defined('MB_IN_BYTES')) define('MB_IN_BYTES', 1048576);
        if (!defined('KB_IN_BYTES')) define('KB_IN_BYTES', 1024);
        if (!defined('WP_MAX_MEMORY_LIMIT')) define('WP_MAX_MEMORY_LIMIT', '256M');
        require_once ABSPATH . WPINC . '/class-wp-error.php';
        require_once ABSPATH . WPINC . '/plugin.php';
        require_once ABSPATH . WPINC . '/l10n.php';
        require_once ABSPATH . WPINC . '/shortcodes.php';
        require_once ABSPATH . WPINC . '/media.php';
        require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
        require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';
    }

    private function createJpeg(int $width, int $height, ?int $orientation, string $layout): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nhk-orientation-source-');
        self::assertIsString($path);
        $image = imagecreatetruecolor($width, $height);
        $red = imagecolorallocate($image, 230, 30, 30);
        $blue = imagecolorallocate($image, 30, 30, 230);
        if ($layout === 'portrait') {
            imagefilledrectangle($image, 0, 0, $width - 1, intdiv($height, 2) - 1, $red);
            imagefilledrectangle($image, 0, intdiv($height, 2), $width - 1, $height - 1, $blue);
        } else {
            imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, $red);
            imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, $blue);
        }
        self::assertTrue(imagejpeg($image, $path, 100));

        if ($orientation === null) return $path;
        $jpeg = file_get_contents($path);
        self::assertIsString($jpeg);
        $tiff = 'II' . pack('v', 42) . pack('V', 8) . pack('v', 1)
            . pack('v', 0x0112) . pack('v', 3) . pack('V', 1)
            . pack('v', $orientation) . pack('v', 0) . pack('V', 0);
        $app1 = "Exif\0\0" . $tiff;
        self::assertTrue((bool) file_put_contents(
            $path,
            substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2),
        ));
        return $path;
    }

    private function assertDominantColor(\GdImage $image, int $x, int $y, string $expected): void
    {
        $rgb = imagecolorsforindex($image, imagecolorat($image, $x, $y));
        if ($expected === 'red') {
            self::assertGreaterThan($rgb['blue'] + 80, $rgb['red']);
        } else {
            self::assertGreaterThan($rgb['red'] + 80, $rgb['blue']);
        }
    }
}
}
