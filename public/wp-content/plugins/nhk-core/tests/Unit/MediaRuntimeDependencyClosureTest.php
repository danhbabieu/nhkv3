<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\{PublicImageSizingPolicy, PublicMediaAssetSelector};
use NHK\Core\Infrastructure\Media\WordPressMediaAttachmentIngestor;
use PHPUnit\Framework\TestCase;

final class MediaRuntimeDependencyClosureTest extends TestCase
{
    public function test_capture_media_dependency_closure_autoloads_from_case_exact_package_paths(): void
    {
        $root = dirname(__DIR__, 2);
        $classes = [
            'NHK\\Core\\Application\\Media\\MediaFilenameNormalizer' => 'src/Application/Media/MediaFilenameNormalizer.php',
            'NHK\\Core\\Application\\Media\\MediaService' => 'src/Application/Media/MediaService.php',
            'NHK\\Core\\Application\\Media\\PublicImageSizingPolicy' => 'src/Application/Media/PublicImageSizingPolicy.php',
            'NHK\\Core\\Application\\Media\\PublicMediaAssetSelector' => 'src/Application/Media/PublicMediaAssetSelector.php',
            'NHK\\Core\\Infrastructure\\Media\\WordPressMediaAttachmentBridge' => 'src/Infrastructure/Media/WordPressMediaAttachmentBridge.php',
            'NHK\\Core\\Infrastructure\\Media\\WordPressMediaAttachmentIngestor' => 'src/Infrastructure/Media/WordPressMediaAttachmentIngestor.php',
            'NHK\\Core\\Infrastructure\\Media\\WordPressImageOrientationNormalizer' => 'src/Infrastructure/Media/WordPressImageOrientationNormalizer.php',
        ];

        foreach ($classes as $class => $relativePath) {
            self::assertTrue(class_exists($class), $class . ' must autoload before Media Capture reaches it.');
            $reflection = new \ReflectionClass($class);
            $expectedPath = $root . '/' . $relativePath;
            self::assertFileExists($expectedPath);
            self::assertSame($expectedPath, $reflection->getFileName(), $class . ' must resolve from its case-exact PSR-4 path.');
        }
    }

    public function test_existing_attachment_adoption_imports_canonical_media_dependencies(): void
    {
        $bridge = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Media/WordPressMediaAttachmentBridge.php');

        self::assertStringContainsString(
            'use NHK\\Core\\Application\\Media\\{MediaFilenameNormalizer, MediaService, PublicImageSizingPolicy, PublicMediaAssetSelector};',
            $bridge,
        );
        self::assertStringContainsString('use RuntimeException;', $bridge);
    }

    /** @dataProvider canonicalDimensions */
    public function test_canonical_sizing_is_proportional_and_never_upscales(int $width, int $height, int $expectedWidth, int $expectedHeight): void
    {
        $result = PublicImageSizingPolicy::constrain($width, $height);

        self::assertSame(['width' => $expectedWidth, 'height' => $expectedHeight], $result);
        self::assertSame($width * $expectedHeight, $height * $expectedWidth);
        self::assertLessThanOrEqual(PublicImageSizingPolicy::MAX_LONG_EDGE, max($result['width'], $result['height']));
        self::assertLessThanOrEqual($width, $result['width']);
        self::assertLessThanOrEqual($height, $result['height']);
    }

    /** @return array<string,array{int,int,int,int}> */
    public static function canonicalDimensions(): array
    {
        return [
            'portrait source' => [1920, 2560, 900, 1200],
            'small landscape source' => [800, 600, 800, 600],
            'large landscape source' => [2400, 1600, 1200, 800],
        ];
    }

    public function test_all_media_adapters_share_the_same_public_profile(): void
    {
        self::assertSame(PublicImageSizingPolicy::MAX_LONG_EDGE, WordPressMediaAttachmentIngestor::MAX_LONG_EDGE);
        self::assertSame(PublicImageSizingPolicy::MAX_LONG_EDGE, WordPressMediaAttachmentIngestor::constrainDimensions(1920, 2560)['height']);
        self::assertSame(86, PublicMediaAssetSelector::DEFAULT_WEBP_QUALITY);
        self::assertGreaterThanOrEqual(82, PublicMediaAssetSelector::DEFAULT_WEBP_QUALITY);
        self::assertLessThanOrEqual(88, PublicMediaAssetSelector::DEFAULT_WEBP_QUALITY);
    }
}
