<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FrontendPresentationContractTest extends TestCase
{
    private string $theme;

    protected function setUp(): void
    {
        $this->theme = dirname(__DIR__, 2) . '/../../../themes/nhk-v3';
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

    public function test_entity_detail_renders_knowledge_gallery_and_direct_vs_derived_related_content(): void
    {
        $source = $this->read('entity.php');
        self::assertStringContainsString("['knowledge']", $source);
        self::assertStringContainsString("['gallery']", $source);
        self::assertStringContainsString('relationship_class', $source);
        self::assertStringContainsString('Liên quan trực tiếp', $source);
        self::assertStringContainsString('Mở rộng từ quan hệ nền', $source);
        self::assertStringContainsString('nhk_v3_public_dictionary_terms_for_text', $source);
    }

    public function test_article_detail_uses_canonical_media_gallery_and_separates_relation_depth(): void
    {
        $source = $this->read('single.php');
        self::assertStringContainsString('nhk_v3_article_media_gallery', $source);
        self::assertStringContainsString('default-archive.svg', $source);
        self::assertStringContainsString('relationship_class', $source);
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
