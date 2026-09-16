<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\McpToolCatalog;
use PHPUnit\Framework\TestCase;

final class CaptureMediaIdsContractTest extends TestCase
{
    public function test_capture_declares_ordered_optional_media_ids_uuid_array(): void
    {
        $tool = null;
        foreach (McpToolCatalog::tools() as $definition) if ($definition['name'] === 'nhk.capture.ingest') $tool = $definition;
        self::assertIsArray($tool);
        self::assertArrayHasKey('media_ids', $tool['inputSchema']['properties']);
        self::assertSame('array', $tool['inputSchema']['properties']['media_ids']['type']);
        self::assertSame('uuid', $tool['inputSchema']['properties']['media_ids']['items']['format']);
    }

    public function test_capture_declares_existing_first_party_media_url_input_without_replacing_file_uploads(): void
    {
        $tool = null;
        foreach (McpToolCatalog::tools() as $definition) if ($definition['name'] === 'nhk.capture.ingest') $tool = $definition;
        self::assertIsArray($tool);
        $urls = $tool['inputSchema']['properties']['existing_media_urls'];
        self::assertSame('array', $urls['type']);
        self::assertSame(20, $urls['maxItems']);
        self::assertSame('uri', $urls['items']['format']);
        self::assertArrayHasKey('files', $tool['inputSchema']['properties']);
        self::assertArrayHasKey('media_ids', $tool['inputSchema']['properties']);
        self::assertArrayHasKey('media', $tool['inputSchema']['properties']);
    }
}
