<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FrontendPresentationContractTest extends TestCase
{
    private string $theme;

    protected function setUp(): void
    {
        $this->theme = dirname(__DIR__, 2) . '/../../themes/nhk-v3';
    }

    public function test_article_cards_always_have_a_display_visual(): void
    {
        $source = $this->read('template-parts/article-card.php');
        self::assertStringContainsString('default-archive.svg', $source);
        self::assertStringContainsString('card-image', $source);
        self::assertStringContainsString('has_post_thumbnail()', $source);
    }

    public function test_homepage_exposes_visual_media_video_knowledge_and_dictionary_modules(): void
    {
        $source = $this->read('front-page.php');
        foreach (['image_url', 'thumbnail_url', "['knowledge']", "['dictionary']", '/thu-vien/', '/video/', '/tu-dien/'] as $needle) self::assertStringContainsString($needle, $source);
    }

    public function test_entity_detail_renders_dossier_knowledge_gallery_and_path_aware_related_content(): void
    {
        $source = $this->read('entity.php');
        self::assertStringContainsString("['dossier']", $source);
        self::assertStringContainsString("['knowledge']", $source);
        self::assertStringContainsString("['relation_sections']", $source);
        self::assertStringContainsString("['origin']", $source);
        self::assertStringContainsString('Liên quan trực tiếp', $source);
        self::assertStringContainsString('Mở rộng từ quan hệ nền', $source);
        self::assertStringContainsString('nhk_v3_public_dictionary_terms_for_text', $source);
    }

    public function test_brand_detail_prefers_dossier_structural_sections_and_uses_legacy_aggregation_only_as_fallback(): void
    {
        $source = $this->read('entity.php');

        self::assertStringNotContainsString('if ($type === \'brand\') foreach ([\'brands\',\'models\',\'variants\',\'movements\',\'music\',\'components\',\'classifications\',\'specimens\',\'products\'] as $group) unset($relationSections[$group]);', $source);
        self::assertStringContainsString('$dossier === null && $type === \'brand\' && is_array($entity[\'aggregation\'] ?? null)', $source);
    }

    public function test_entity_relation_sections_are_not_rendered_when_all_items_lack_public_urls(): void
    {
        $source = $this->read('entity.php');
        self::assertStringContainsString('$renderableItems', $source);
        self::assertStringContainsString('if ($renderableItems === []) continue;', $source);
    }

    public function test_article_detail_uses_post_dossier_canonical_media_gallery_and_path_aware_relations(): void
    {
        $source = $this->read('single.php');
        self::assertStringContainsString('nhk_v3_post_dossier', $source);
        self::assertStringContainsString('nhk_v3_article_media_gallery', $source);
        self::assertStringContainsString("['relation_sections']", $source);
        self::assertStringContainsString("['origin']", $source);
        self::assertStringContainsString('default-archive.svg', $source);
        self::assertStringContainsString('nhk_v3_public_dictionary_terms_for_text', $source);
    }

    public function test_media_archive_renders_images_without_requiring_a_fake_detail_url(): void
    {
        $source = $this->read('media.php');
        self::assertStringContainsString("['image_url']", $source);
        self::assertStringNotContainsString("if ($itemUrl === '') continue", $source);
    }

    public function test_video_archive_is_thumbnail_led(): void
    {
        $source = $this->read('video.php');
        self::assertStringContainsString('source_thumbnail_url', $source);
    }

    public function test_display_fallback_asset_exists_and_is_not_a_semantic_media_writer(): void
    {
        $fallback = $this->theme . '/assets/default-archive.svg';
        self::assertFileExists($fallback);
        self::assertStringNotContainsString('MediaUsage', (string) file_get_contents($fallback));
    }

    private function read(string $path): string
    {
        $file = $this->theme . '/' . $path;
        self::assertFileExists($file);
        return (string) file_get_contents($file);
    }
}
