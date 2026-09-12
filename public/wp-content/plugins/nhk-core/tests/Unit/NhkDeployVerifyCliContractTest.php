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

    public function test_dirty_checkout_fails_closed_before_composer_or_transfer(): void
    {
        $path = dirname(__DIR__, 6) . '/scripts/nhk-deploy-verify';
        $output = [];
        $status = 0;
        exec(escapeshellarg($path) . ' --target=demo.1945.vn --json 2>&1', $output, $status);

        self::assertNotSame(0, $status);
        self::assertStringContainsString('WORKTREE_NOT_CLEAN', implode("\n", $output));
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
}
