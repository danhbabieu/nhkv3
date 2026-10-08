<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Presentation\PublicTemplateFamilyAssetManifest;
use PHPUnit\Framework\TestCase;

final class PublicTemplateFamilyAssetManifestTest extends TestCase
{
    public function test_video_detail_declares_video_family_styles_and_player(): void
    {
        $manifest = PublicTemplateFamilyAssetManifest::forContext(['family' => 'video', 'mode' => 'detail']);

        self::assertSame(['nhk-v3-style', 'nhk-v3-presentation', 'nhk-v3-media-video'], $manifest['styles']);
        self::assertSame(['nhk-v3-navigation', 'nhk-v3-video-player'], $manifest['scripts']);
    }

    public function test_comparison_declares_comparison_styles_without_entity_dependency(): void
    {
        $manifest = PublicTemplateFamilyAssetManifest::forContext(['family' => 'comparison']);

        self::assertSame(['nhk-v3-style', 'nhk-v3-presentation', 'nhk-v3-comparison'], $manifest['styles']);
        self::assertSame(['nhk-v3-navigation'], $manifest['scripts']);
    }

    public function test_dictionary_declares_only_base_shared_and_dictionary_styles(): void
    {
        $manifest = PublicTemplateFamilyAssetManifest::forContext(['family' => 'dictionary', 'mode' => 'detail']);

        self::assertSame(['nhk-v3-style', 'nhk-v3-presentation', 'nhk-v3-dictionary'], $manifest['styles']);
        self::assertNotContains('nhk-v3-entity', $manifest['styles']);
        self::assertNotContains('nhk-v3-media-video', $manifest['styles']);
        self::assertNotContains('nhk-v3-knowledge', $manifest['styles']);
    }

    public function test_comparison_rules_live_in_the_comparison_family_stylesheet(): void
    {
        $theme = dirname(__DIR__, 4) . '/themes/nhk-v3';
        $entity = (string) file_get_contents($theme . '/entity.css');
        $comparison = (string) file_get_contents($theme . '/comparison.css');

        self::assertStringNotContainsString('.comparison-shell', $entity);
        self::assertStringContainsString('.comparison-shell', $comparison);
        self::assertSame(['nhk-v3-style', 'nhk-v3-presentation'], PublicTemplateFamilyAssetManifest::dependencies()['nhk-v3-comparison']);
    }

    public function test_music_entity_detail_declares_the_music_controller_without_changing_generic_entity_assets(): void
    {
        $music = PublicTemplateFamilyAssetManifest::forContext(['family' => 'entity', 'entity_type' => 'music']);
        $generic = PublicTemplateFamilyAssetManifest::forContext(['family' => 'entity', 'entity_type' => 'brand']);

        self::assertContains('nhk-v3-music-dossier', $music['scripts']);
        self::assertNotContains('nhk-v3-music-dossier', $generic['scripts']);
        self::assertSame($generic['styles'], $music['styles']);
    }
}
