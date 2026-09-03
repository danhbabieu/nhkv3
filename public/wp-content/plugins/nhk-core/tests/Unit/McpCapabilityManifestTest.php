<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\McpCapabilityManifest;
use PHPUnit\Framework\TestCase;

final class McpCapabilityManifestTest extends TestCase
{
    public function test_manifest_is_derived_from_registered_catalog_and_does_not_advertise_unknown_operations(): void
    {
        $manifest = McpCapabilityManifest::all();

        self::assertArrayHasKey('article', $manifest);
        self::assertContains('nhk.article.preflight', $manifest['article']['reads']);
        self::assertContains('nhk.article.ingest', $manifest['article']['writes']);
        self::assertTrue($manifest['article']['seo_preflight']);
        self::assertSame([], $manifest['article']['unsupported']);
        self::assertNotContains('nhk.article.create', array_merge($manifest['article']['reads'], $manifest['article']['writes']));
    }

    public function test_unknown_content_kind_fails_closed(): void
    {
        self::assertNull(McpCapabilityManifest::forContentKind('album'));
    }
}
