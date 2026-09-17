<?php
declare(strict_types=1);

namespace {
    if (!function_exists('is_wp_error')) {
        function is_wp_error(mixed $thing): bool
        {
            return $thing instanceof \WP_Error;
        }
    }
    if (!function_exists('wp_raise_memory_limit')) {
        function wp_raise_memory_limit(string $context = 'admin'): string
        {
            return ini_get('memory_limit') ?: '-1';
        }
    }
    if (!function_exists('wp_get_image_mime')) {
        function wp_get_image_mime(string $file): string|false
        {
            $info = @getimagesize($file);
            return is_array($info) ? ($info['mime'] ?? false) : false;
        }
    }
    if (!function_exists('wp_get_mime_types')) {
        function wp_get_mime_types(): array
        {
            return ['jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        }
    }
    if (!function_exists('wp_get_default_extension_for_mime_type')) {
        function wp_get_default_extension_for_mime_type(string $mimeType): string|false
        {
            return match ($mimeType) {
                'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', default => false,
            };
        }
    }
    if (!function_exists('wp_filesize')) {
        function wp_filesize(string $path): int
        {
            $size = @filesize($path);
            return $size === false ? 0 : (int) $size;
        }
    }
    if (!function_exists('wp_is_stream')) {
        function wp_is_stream(string $path): bool
        {
            return (bool) preg_match('#^[a-z][a-z0-9+.-]*://#i', $path);
        }
    }
    if (!function_exists('wp_mkdir_p')) {
        function wp_mkdir_p(string $target): bool
        {
            return is_dir($target) || mkdir($target, 0777, true);
        }
    }
    if (!function_exists('wp_basename')) {
        function wp_basename(string $path, string $suffix = ''): string
        {
            return basename($path, $suffix);
        }
    }
    if (!function_exists('trailingslashit')) {
        function trailingslashit(string $value): string
        {
            return rtrim($value, '/\\') . '/';
        }
    }
    if (!function_exists('wp_fuzzy_number_match')) {
        function wp_fuzzy_number_match(int|float $expected, int|float $actual): bool
        {
            return abs($expected - $actual) <= 1;
        }
    }
}

namespace NHK\Tests\Unit {

use NHK\Core\Application\Media\PublicImageSizingPolicy;
use NHK\Core\Infrastructure\Media\WordPressImageOrientationNormalizer;
use PHPUnit\Framework\TestCase;

final class WordPressImageOrientationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!extension_loaded('gd') || !extension_loaded('exif')) {
            self::markTestSkipped('GD and EXIF are required for image orientation regression tests.');
        }

        $root = dirname(__DIR__, 5) . '/';
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

    public function test_portrait_pixels_with_orientation_one_resize_to_portrait_webp(): void
    {
        $result = $this->process(1152, 1536, 1, 'portrait');

        self::assertSame(['width' => 900, 'height' => 1200], $result['dimensions']);
        $this->assertDominantColor($result['image'], 450, 300, 'red');
        $this->assertDominantColor($result['image'], 450, 900, 'blue');
    }

    public function test_landscape_pixels_with_orientation_one_resize_to_landscape_webp(): void
    {
        $result = $this->process(1536, 1152, 1, 'landscape');

        self::assertSame(['width' => 1200, 'height' => 900], $result['dimensions']);
        $this->assertDominantColor($result['image'], 300, 450, 'red');
        $this->assertDominantColor($result['image'], 900, 450, 'blue');
    }

    public function test_exif_orientation_six_is_applied_once_before_resize(): void
    {
        $result = $this->process(1536, 1152, 6, 'landscape');

        self::assertSame(['width' => 900, 'height' => 1200], $result['dimensions']);
        $this->assertDominantColor($result['image'], 450, 300, 'red');
        $this->assertDominantColor($result['image'], 450, 900, 'blue');
        self::assertFalse($result['has_orientation_metadata']);
    }

    public function test_exif_orientation_eight_is_applied_once_before_resize(): void
    {
        $result = $this->process(1536, 1152, 8, 'landscape');

        self::assertSame(['width' => 900, 'height' => 1200], $result['dimensions']);
        $this->assertDominantColor($result['image'], 450, 300, 'blue');
        $this->assertDominantColor($result['image'], 450, 900, 'red');
        self::assertFalse($result['has_orientation_metadata']);
    }

    public function test_image_without_exif_orientation_is_not_rotated(): void
    {
        $result = $this->process(1536, 1152, null, 'landscape');

        self::assertSame(['width' => 1200, 'height' => 900], $result['dimensions']);
        $this->assertDominantColor($result['image'], 300, 450, 'red');
        $this->assertDominantColor($result['image'], 900, 450, 'blue');
        self::assertFalse($result['has_orientation_metadata']);
    }

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
