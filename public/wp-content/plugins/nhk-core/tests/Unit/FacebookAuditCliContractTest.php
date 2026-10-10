<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FacebookAuditCliContractTest extends TestCase
{
    public function testMetaModeWithoutCredentialFailsClosedWithoutCreatingReport(): void
    {
        $output = tempnam(sys_get_temp_dir(), 'facebook-audit-cli-') . '.xlsx';
        $command = 'META_ACCESS_TOKEN= ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 6) . '/tools/facebook-content-audit.php') . ' --mode=meta --output=' . escapeshellarg($output) . ' 2>&1';
        exec($command, $lines, $status);

        self::assertSame(2, $status);
        self::assertStringContainsString('META_CREDENTIALS_MISSING', implode("\n", $lines));
        self::assertFileDoesNotExist($output);
        @unlink($output);
    }

    public function testCliSourceContainsNoWriteActionNames(): void
    {
        $path = dirname(__DIR__, 6) . '/tools/facebook-content-audit.php';
        $source = (string) file_get_contents($path);
        self::assertStringNotContainsString('wp_delete_post', $source);
        self::assertStringNotContainsString('DELETE FROM', strtoupper($source));
        self::assertStringNotContainsString('wp_remote_post', $source);
    }
}
