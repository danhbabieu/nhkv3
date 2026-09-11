<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PluginBootWiringTest extends TestCase
{
    public function test_public_entity_projection_dependencies_are_created_before_projection(): void
    {
        $plugin = (string) file_get_contents(__DIR__ . '/../../src/Plugin.php');

        $projection = strpos($plugin, 'new EntityMediaProjection($publicMedia, $publicAssets, $publicUsages)');
        $assets = strpos($plugin, '$publicAssets = new WpdbMediaAssetRepository($wpdb);');
        $usages = strpos($plugin, '$publicUsages = new WpdbMediaUsageRepository($wpdb);');

        self::assertNotFalse($projection);
        self::assertNotFalse($assets);
        self::assertNotFalse($usages);
        self::assertLessThan($projection, $assets);
        self::assertLessThan($projection, $usages);
    }

    public function test_plugin_entrypoint_boots_dedicated_entity_dossier_projection(): void
    {
        $entrypoint = (string) file_get_contents(__DIR__ . '/../../nhk-core.php');

        self::assertStringContainsString('use NHK\\Core\\Infrastructure\\Frontend\\EntityDossierBootstrap;', $entrypoint);
        self::assertStringContainsString('EntityDossierBootstrap::boot();', $entrypoint);
    }

    public function test_boot_does_not_run_migrations_without_explicit_runtime_gate(): void
    {
        $plugin = (string) file_get_contents(__DIR__ . '/../../src/Plugin.php');

        self::assertStringContainsString(
            "if (self::runtimeMigrationsEnabled()) self::runPendingMigrations();",
            $plugin
        );
        self::assertStringContainsString(
            "defined('NHK_RUN_MIGRATIONS') && NHK_RUN_MIGRATIONS === true",
            $plugin
        );
        self::assertStringContainsString(
            'self::runPendingMigrations();',
            substr($plugin, strpos($plugin, 'public static function activate(): void'))
        );
    }

    public function test_ability_registry_bootstrap_runs_before_easy_mcp_rest_registration(): void
    {
        $plugin = (string) file_get_contents(__DIR__ . '/../../src/Plugin.php');

        $bootstrap = "add_action('rest_api_init', [McpAbilityRegistration::class, 'bootstrapRegistry'], 0);";

        self::assertStringContainsString($bootstrap, $plugin);
        self::assertLessThan(
            strpos($plugin, "add_action('rest_api_init', static function ()"),
            strpos($plugin, $bootstrap)
        );
    }

    public function test_easy_mcp_native_file_adapter_is_registered_without_changing_the_nhk_entrypoint(): void
    {
        $plugin = (string) file_get_contents(__DIR__ . '/../../src/Plugin.php');

        self::assertStringContainsString('EasyMcpNativeFileCompatibilityAdapter::register();', $plugin);
        self::assertStringContainsString("add_filter('rest_request_before_callbacks'", (string) file_get_contents(__DIR__ . '/../../src/Infrastructure/Mcp/EasyMcpNativeFileCompatibilityAdapter.php'));
        self::assertStringContainsString("add_filter('wp_ability_normalize_input'", (string) file_get_contents(__DIR__ . '/../../src/Infrastructure/Mcp/EasyMcpNativeFileCompatibilityAdapter.php'));
        self::assertStringContainsString("add_filter('rest_post_dispatch'", (string) file_get_contents(__DIR__ . '/../../src/Infrastructure/Mcp/EasyMcpNativeFileCompatibilityAdapter.php'));
        self::assertStringContainsString("add_filter('rest_pre_echo_response'", (string) file_get_contents(__DIR__ . '/../../src/Infrastructure/Mcp/EasyMcpNativeFileCompatibilityAdapter.php'));
        self::assertStringContainsString("'/easy-mcp-ai/v1/mcp'", (string) file_get_contents(__DIR__ . '/../../src/Infrastructure/Mcp/EasyMcpNativeFileCompatibilityAdapter.php'));
        self::assertStringContainsString('rest_get_server()->dispatch($proxy)', (string) file_get_contents(__DIR__ . '/../../src/Infrastructure/Mcp/EasyMcpNativeFileCompatibilityAdapter.php'));
        self::assertStringNotContainsString('wp_set_current_user', (string) file_get_contents(__DIR__ . '/../../src/Infrastructure/Mcp/EasyMcpNativeFileCompatibilityAdapter.php'));
        self::assertStringNotContainsString('wp_upload_media', (string) file_get_contents(__DIR__ . '/../../src/Infrastructure/Mcp/EasyMcpNativeFileCompatibilityAdapter.php'));
    }

    public function test_new_capture_semantic_write_back_uses_capture_governance_policy_boundary(): void
    {
        $plugin = (string) file_get_contents(__DIR__ . '/../../src/Plugin.php');
        self::assertStringContainsString('$captureGovernance->execute(', $plugin);
        self::assertStringContainsString("capture_id'] ?? ''", $plugin);
        self::assertStringNotContainsString("'blockers' => ['SEMANTIC_WRITE_BACK_REQUIRES_GOVERNANCE']", $plugin);
    }

    public function test_capture_owned_draft_write_suppresses_generic_post_media_hook_until_capture_reconciliation(): void
    {
        $plugin = (string) file_get_contents(__DIR__ . '/../../src/Plugin.php');
        self::assertStringContainsString('CaptureEditorialWriteGuard::active()', $plugin);
        self::assertLessThan(
            strpos($plugin, 'if ($attachmentBridge->isHandlingWrite()) return;'),
            strpos($plugin, 'CaptureEditorialWriteGuard::active()'),
        );
    }

    public function test_capture_publication_gate_consumes_locked_subject_resolution_state(): void
    {
        $plugin = (string) file_get_contents(__DIR__ . '/../../src/Plugin.php');
        self::assertStringContainsString("'subject_resolution' => \$context['subject_resolution'] ?? []", $plugin);
        self::assertStringContainsString("'subject_resolved' =>", $plugin);
    }
}
