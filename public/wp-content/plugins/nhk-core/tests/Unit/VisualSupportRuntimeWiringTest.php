<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class VisualSupportRuntimeWiringTest extends TestCase
{
    public function test_canonical_media_readback_is_the_only_runtime_handoff_to_reverse_reconciliation(): void
    {
        $mediaService = (string) file_get_contents(__DIR__ . '/../../src/Application/Media/MediaService.php');
        $plugin = (string) file_get_contents(__DIR__ . '/../../src/Plugin.php');
        self::assertStringContainsString("do_action('nhk_v3_media_canonical_readback'", $mediaService);
        self::assertStringContainsString("add_action('nhk_v3_media_canonical_readback'", $plugin);
        self::assertStringContainsString('VisualSupportReverseReconciliationService', $plugin);
        self::assertStringContainsString('WpdbVisualSupportRequirementRepository', $plugin);
        self::assertStringContainsString("invalidate('visual_support_requirement'", $plugin);
        self::assertStringNotContainsString('visual-support-requirement', strtolower((string) file_get_contents(__DIR__ . '/../../src/Application/Mcp/McpToolCatalog.php')));
    }
}
