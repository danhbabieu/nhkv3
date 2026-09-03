<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DemoCutoverCliContractTest extends TestCase
{
    public function test_shell_is_thin_and_delegates_to_php_runner(): void
    {
        $path = dirname(__DIR__, 6) . '/scripts/nhk-demo-cutover';
        self::assertFileExists($path);
        self::assertTrue(is_executable($path));
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        self::assertStringContainsString('tools/nhk-demo-cutover.php', $contents);
        self::assertStringNotContainsString('odo', strtolower($contents));
    }

    public function test_requested_command_resolves_adapter_and_fails_closed_without_external_config(): void
    {
        $path = dirname(__DIR__, 6) . '/scripts/nhk-demo-cutover';
        $output = [];
        $status = 0;
        exec(escapeshellarg($path) . ' --target=demo.1945.vn --pack=odo --json 2>&1', $output, $status);

        self::assertNotSame(0, $status);
        self::assertStringContainsString('REMOTE_DEPLOYMENT_CONFIG_REQUIRED', implode("\n", $output));
    }

    public function test_unknown_pack_fails_closed_before_deployment(): void
    {
        $path = dirname(__DIR__, 6) . '/scripts/nhk-demo-cutover';
        $output = [];
        $status = 0;
        exec(escapeshellarg($path) . ' --target=demo.1945.vn --pack=missing-pack --json 2>&1', $output, $status);

        self::assertNotSame(0, $status);
        self::assertStringContainsString('PACK_MANIFEST_UNAVAILABLE', implode("\n", $output));
    }

    public function test_remote_entrypoint_has_fixed_bootstrap_and_operation_allowlist(): void
    {
        $path = dirname(__DIR__, 2) . '/bin/nhk-core-maintenance.php';
        self::assertFileExists($path);
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        self::assertStringContainsString("\$wordpressRoot . '/wp-load.php'", $contents);
        foreach (['health', 'inventory', 'dry-run', 'backup/snapshot', 'governance-plan', 'controlled-apply', 'read-back'] as $operation) {
            self::assertStringContainsString("'{$operation}'", $contents);
        }
        self::assertStringNotContainsString('eval(', $contents);
        self::assertStringNotContainsString('UPDATE ', $contents);
        self::assertStringNotContainsString('DELETE ', $contents);
    }
}
