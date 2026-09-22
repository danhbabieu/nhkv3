<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\{McpAbilityRegistration, SingleEntryPointPolicy};
use PHPUnit\Framework\TestCase;

final class McpWidgetAbilityExposureTest extends TestCase
{
    public function test_widget_tools_have_ability_bridges_and_are_not_explicitly_excluded(): void
    {
        self::assertSame('nhk-v3/media-widget-upload', McpAbilityRegistration::abilityNameForTool('nhk.media.widget-upload'));
        self::assertSame('nhk-v3/media-upload-widget-open', McpAbilityRegistration::abilityNameForTool('nhk.media.upload-widget.open'));
        self::assertContains('nhk-v3/media-widget-upload', McpAbilityRegistration::abilityNames());
        self::assertContains('nhk-v3/media-upload-widget-open', McpAbilityRegistration::abilityNames());
        self::assertArrayNotHasKey('nhk.media.widget-upload', McpAbilityRegistration::explicitExclusionReasons());
        self::assertArrayNotHasKey('nhk.media.upload-widget.open', McpAbilityRegistration::explicitExclusionReasons());
    }

    public function test_widget_upload_is_internal_but_open_tool_and_capture_are_enableable(): void
    {
        self::assertTrue(SingleEntryPointPolicy::isInternalOnly('nhk.media.widget-upload'));
        self::assertContains('nhk-v3/media-widget-upload', McpAbilityRegistration::explicitInternalAdminAbilityAllowlist());
        self::assertContains('nhk-v3/media-upload-widget-open', McpAbilityRegistration::operatorEnabledAbilityAllowlist());
        self::assertNotContains('nhk-v3/media-widget-upload', McpAbilityRegistration::operatorEnabledAbilityAllowlist());
        self::assertContains('nhk-v3/media-widget-upload', McpAbilityRegistration::ensureEasyMcpEnabledAbilities(['nhk-v3/media-widget-upload']));
        self::assertContains('nhk-v3/capture-ingest', McpAbilityRegistration::operatorEnabledAbilityAllowlist());
    }

    public function test_existing_media_writers_remain_internal_and_not_operator_enabled(): void
    {
        foreach (['nhk.media.ingest', 'nhk.media.upload-batch'] as $tool) {
            self::assertTrue(SingleEntryPointPolicy::isInternalOnly($tool));
            self::assertNotContains(McpAbilityRegistration::abilityNameForTool($tool), McpAbilityRegistration::operatorEnabledAbilityAllowlist());
        }
    }

    public function test_mcp_app_diagnostics_is_a_read_only_admin_easy_mcp_ability(): void
    {
        self::assertContains('nhk-v3/mcp-app-diagnostics', McpAbilityRegistration::abilityNames());
        self::assertContains('nhk-v3/mcp-app-diagnostics', McpAbilityRegistration::explicitInternalAdminAbilityAllowlist());
        self::assertContains('nhk-v3/mcp-app-diagnostics', McpAbilityRegistration::ensureEasyMcpEnabledAbilities([]));
    }
}
