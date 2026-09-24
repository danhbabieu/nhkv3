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

    public function test_article_research_inventory_captures_every_required_media_dependency_and_uses_input_resolution(): void
    {
        $plugin = (string) file_get_contents(__DIR__ . '/../../src/Plugin.php');
        $inventoryStart = strpos($plugin, 'static function (array $input) use ($authority, $types, $claims, $sources, $evidence, $media, $assets, $usages, $videos, $graphService, $predicates)');
        self::assertNotFalse($inventoryStart, 'Article inventory composition must capture its required MediaAssetRepository.');

        $inventory = substr($plugin, $inventoryStart, strpos($plugin, "                [\$articlePublicEligibility, 'evaluate']", $inventoryStart) - $inventoryStart);
        self::assertStringContainsString('$assets->listByMediaId', $inventory);
        self::assertStringContainsString('$input[\'subject_resolution\'][\'primary\'][\'id\']', $inventory);
        self::assertStringNotContainsString('$resolution[\'primary\'][\'id\']', $inventory);
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

    public function test_capture_repository_has_one_declaration_before_every_rest_api_use(): void
    {
        $plugin = (string) file_get_contents(__DIR__ . '/../../src/Plugin.php');
        $declaration = 'new WpdbCaptureRepository($wpdb)';
        $firstUse = 'McpAbilityRegistration::registerReadAbilities';

        self::assertSame(1, substr_count($plugin, $declaration));
        self::assertLessThan(strpos($plugin, $firstUse), strpos($plugin, $declaration));
    }

    public function test_media_binding_read_ability_has_registered_operation_repository_and_dispatch_case(): void
    {
        $plugin = (string) file_get_contents(__DIR__ . '/../../src/Plugin.php');
        $registration = (string) file_get_contents(__DIR__ . '/../../src/Application/Mcp/McpAbilityRegistration.php');
        self::assertStringContainsString('new WpdbMediaBindingOperationRepository($wpdb)', $plugin);
        self::assertStringContainsString("'nhk.media.binding.get' => \$read->mediaBindingGet", $registration);
    }

    public function test_knowledge_writer_preview_service_is_injected_into_production_mcp_transport(): void
    {
        $plugin = (string) file_get_contents(__DIR__ . '/../../src/Plugin.php');
        $service = strpos($plugin, '$knowledgeWriterPreview = new KnowledgeWriterPreviewService(');
        $transport = strpos($plugin, 'new McpTransport(');
        $injection = strpos($plugin, 'knowledgeWriterPreview: $knowledgeWriterPreview');

        self::assertNotFalse($service, 'The MCP composition must construct the preview service.');
        self::assertNotFalse($transport, 'The MCP composition must construct its transport.');
        self::assertNotFalse($injection, 'The production transport must receive the preview service.');
        self::assertLessThan($transport, $service, 'The service must exist before the transport is composed.');
        self::assertGreaterThan($transport, $injection, 'The named service dependency must belong to the transport construction.');
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

    public function test_public_capture_ability_composition_passes_one_internal_dependency_validator_through_transport_and_coordinator(): void
    {
        $plugin = (string) file_get_contents(__DIR__ . '/../../src/Plugin.php');
        self::assertStringContainsString('$canonicalDependencies = new CanonicalDependencyValidator($claims, $sources, $evidence);', $plugin);
        self::assertStringContainsString('new McpTransport(', $plugin);
        self::assertStringContainsString('new CanonicalDependencyValidator($claims, $sources, $evidence)', $plugin);
        self::assertStringContainsString('new EditorialCaptureCoordinator(', $plugin);
        self::assertStringContainsString('$canonicalDependencies,', $plugin);
        self::assertStringContainsString('McpAbilityRegistration::registerGovernedAbilities();', $plugin);
        self::assertStringContainsString("'nhk.capture.ingest' => 'nhk-v3/capture-ingest'", (string) file_get_contents(__DIR__ . '/../../src/Application/Mcp/McpAbilityRegistration.php'));
        self::assertStringContainsString('rest_do_request($request)', (string) file_get_contents(__DIR__ . '/../../src/Application/Mcp/McpAbilityRegistration.php'));
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

    public function test_article_trash_hook_cannot_enter_generic_media_reconciliation(): void
    {
        $plugin = (string) file_get_contents(__DIR__ . '/../../src/Plugin.php');
        $hook = strpos($plugin, '$reconcilePostMedia = static function');
        $reconcile = strpos($plugin, '$articleMedia->ensureForPost', $hook);

        self::assertNotFalse($hook);
        self::assertNotFalse($reconcile);
        self::assertStringContainsString("if (\$post->post_status === 'trash') return;", substr($plugin, $hook, $reconcile - $hook));
    }

    public function test_wordpress_article_lifecycle_uses_native_trash_boundaries(): void
    {
        $store = (string) file_get_contents(__DIR__ . '/../../src/Infrastructure/WordPress/WpEditorialPostStore.php');

        self::assertStringContainsString('wp_trash_post($postId)', $store);
        self::assertStringContainsString('wp_untrash_post($postId)', $store);
        self::assertStringNotContainsString("return \$this->transition(\$postId, 'trash'", $store);
        self::assertStringNotContainsString("return \$this->transition(\$postId, 'draft'", $store);
    }

    public function test_capture_publication_gate_consumes_locked_subject_resolution_state(): void
    {
        $plugin = (string) file_get_contents(__DIR__ . '/../../src/Plugin.php');
        self::assertStringContainsString("'subject_resolution' => \$context['subject_resolution'] ?? []", $plugin);
        self::assertStringContainsString("'subject_resolved' =>", $plugin);
    }

    public function test_capture_composition_injects_content_preparation_before_normal_article_flow(): void
    {
        $plugin = (string) file_get_contents(__DIR__ . '/../../src/Plugin.php');

        self::assertStringContainsString('new ContentPreparationOrchestrator(', $plugin);
        self::assertStringContainsString('$captureSubjectResolver,', $plugin);
        self::assertStringContainsString('$captureCanonicalInventory = self::canonicalInventory($types, $authority, $media, $videos, $claims, $sources, $evidence);', $plugin);
        self::assertStringContainsString('$contentPreparation,', $plugin);
    }

    public function test_capture_media_adoption_updates_the_canonical_media_object(): void
    {
        $plugin = (string) file_get_contents(__DIR__ . '/../../src/Plugin.php');

        self::assertStringContainsString(
            '$media->update(new \\NHK\\Core\\Domain\\Media\\Media($current->canonicalId, $current->stableKey',
            $plugin
        );
        self::assertStringNotContainsString(
            '$media->update($current->canonicalId, $current->canonicalName',
            $plugin
        );
    }

    public function test_governance_runtime_composes_production_admission_behind_the_shared_apply_guard(): void
    {
        $factory = (string) file_get_contents(__DIR__ . '/../../src/Infrastructure/Governance/GovernanceRuntimeFactory.php');
        $guard = (string) file_get_contents(__DIR__ . '/../../src/Application/Governance/OperationScopedStagingGuard.php');

        self::assertStringContainsString('new OperationScopedStagingGuard(', $factory);
        self::assertStringContainsString('$stagingGuard,', $factory);
        self::assertStringContainsString('new ProductionGovernanceAdmission(', $guard);
        self::assertStringContainsString("in_array(\$environment, ['production', 'prod'], true)", $guard);
    }
}
