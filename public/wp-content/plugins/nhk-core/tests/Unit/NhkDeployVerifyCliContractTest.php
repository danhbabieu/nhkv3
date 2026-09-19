<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Demo\PluginHeaderVersionReader;
use PHPUnit\Framework\TestCase;

final class NhkDeployVerifyCliContractTest extends TestCase
{
    public function test_shell_is_thin_and_delegates_to_the_php_runner(): void
    {
        $path = dirname(__DIR__, 6) . '/scripts/nhk-deploy-verify';
        self::assertFileExists($path);
        self::assertTrue(is_executable($path));
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        self::assertStringContainsString('tools/nhk-deploy-verify.php', $contents);
        self::assertStringContainsString('exec php', $contents);
    }

    public function test_non_allowlisted_target_fails_before_any_deployment(): void
    {
        $path = dirname(__DIR__, 6) . '/scripts/nhk-deploy-verify';
        $output = [];
        $status = 0;
        exec(escapeshellarg($path) . ' --target=production.example --json 2>&1', $output, $status);

        self::assertNotSame(0, $status);
        self::assertStringContainsString('DEPLOYMENT_TARGET_NOT_ALLOWLISTED', implode("\n", $output));
    }

    public function test_checkout_or_missing_config_fails_closed_before_composer_or_transfer(): void
    {
        $path = dirname(__DIR__, 6) . '/scripts/nhk-deploy-verify';
        $output = [];
        $status = 0;
        exec('env -u NHK_DEMO_DEPLOY_CONFIG ' . escapeshellarg($path) . ' --target=demo.1945.vn --json 2>&1', $output, $status);

        self::assertNotSame(0, $status);
        $receipt = implode("\n", $output);
        self::assertTrue(
            str_contains($receipt, 'WORKTREE_NOT_CLEAN') || str_contains($receipt, 'REMOTE_DEPLOYMENT_CONFIG_REQUIRED'),
            $receipt,
        );
    }

    public function test_plugin_header_reader_accepts_wordpress_comment_format(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'nhk-plugin-header-');
        self::assertIsString($path);
        file_put_contents($path, "/**\n * Plugin Name: NHK Core\n * Version: 0.1.0\n */\n");

        try {
            self::assertSame('0.1.0', PluginHeaderVersionReader::read($path));
        } finally {
            unlink($path);
        }
    }

    public function test_deploy_receipt_projects_the_full_release_tuple(): void
    {
        $runner = (string) file_get_contents(dirname(__DIR__, 6) . '/tools/nhk-deploy-verify.php');
        foreach (['source_revision', 'runtime_version', 'documentation_version', 'manifest_hash', 'build_identity', 'catalog_version', 'resource_version', 'release_identity'] as $field) {
            self::assertStringContainsString("'{$field}'", $runner, $field);
        }
    }

    public function test_deploy_verifier_passes_the_complete_bootstrap_packet(): void
    {
        $runner = (string) file_get_contents(dirname(__DIR__, 6) . '/tools/nhk-deploy-verify.php');
        self::assertStringContainsString('$verifier->verify($baseUrl, $localBootstrap, $expectedBuildIdentity)', $runner);
        self::assertStringNotContainsString('$verifier->verify($baseUrl, $expectedManifest, $expectedBuildIdentity)', $runner);
        self::assertStringContainsString("throw new RuntimeException('DOC_BOOTSTRAP_INVALID')", $runner);
    }
}
