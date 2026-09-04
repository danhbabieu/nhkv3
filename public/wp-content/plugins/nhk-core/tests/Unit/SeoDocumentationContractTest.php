<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SeoDocumentationContractTest extends TestCase
{
    public function test_governed_seo_contract_set_is_present_and_projection_only(): void
    {
        $root = dirname(__DIR__, 6);
        $files = [
            'docs/seo/NHK_V3_SEO_CORE_CONTRACT.md',
            'docs/seo/ENTITY_SEO_PROJECTION_CONTRACT.md',
            'docs/seo/MEDIA_IMAGE_SEO_PROJECTION_CONTRACT.md',
            'docs/seo/SITEMAP_INDEXABILITY_CONTRACT.md',
        ];

        foreach ($files as $file) {
            self::assertFileExists($root . '/' . $file);
            $contents = (string) file_get_contents($root . '/' . $file);
            self::assertStringContainsString('projection-only', strtolower($contents));
        }
        $core = (string) file_get_contents($root . '/docs/seo/NHK_V3_SEO_CORE_CONTRACT.md');
        foreach (['READY', 'INCOMPLETE', 'BLOCKED', 'UNAVAILABLE', 'NOT_APPLICABLE'] as $status) {
            self::assertStringContainsString($status, $core);
        }
    }

    public function test_router_and_index_route_seo_through_shared_contracts(): void
    {
        $root = dirname(__DIR__, 6);
        foreach (['docs/constitution/READ_FIRST.md', 'docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md'] as $file) {
            $contents = (string) file_get_contents($root . '/' . $file);
            self::assertStringContainsString('NHK_V3_SEO_CORE_CONTRACT.md', $contents);
            self::assertStringContainsString('ENTITY_SEO_PROJECTION_CONTRACT.md', $contents);
            self::assertStringContainsString('MEDIA_IMAGE_SEO_PROJECTION_CONTRACT.md', $contents);
            self::assertStringContainsString('SITEMAP_INDEXABILITY_CONTRACT.md', $contents);
        }
    }

    public function test_contract_set_does_not_create_an_seo_semantic_shortcut(): void
    {
        $root = dirname(__DIR__, 6);
        $contents = '';
        foreach (glob($root . '/docs/seo/*SEO*CONTRACT.md') ?: [] as $file) {
            $contents .= (string) file_get_contents($file);
        }
        self::assertStringNotContainsString('SEO_CONSTITUTION', $contents);
        self::assertStringNotContainsString('Product–Specimen relation is created by SEO', $contents);
        self::assertStringContainsString('FAQ', $contents);
    }
}
