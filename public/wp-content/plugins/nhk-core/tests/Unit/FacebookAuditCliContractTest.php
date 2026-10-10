<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZipArchive;

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

    public function testFixtureCliProducesRequiredOverviewKeysAndValidatedWorkbook(): void
    {
        $root = dirname(__DIR__, 6);
        $output = tempnam(sys_get_temp_dir(), 'facebook-audit-e2e-') . '.xlsx';
        $checkpoint = tempnam(sys_get_temp_dir(), 'facebook-audit-e2e-') . '.json';
        $fixture = $root . '/public/wp-content/plugins/nhk-core/tests/Fixtures/facebook-audit-pilot.json';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/facebook-content-audit.php') . ' --mode=fixture --fixture=' . escapeshellarg($fixture) . ' --output=' . escapeshellarg($output) . ' --checkpoint=' . escapeshellarg($checkpoint) . ' 2>&1';
        exec($command, $lines, $status);

        self::assertSame(0, $status, implode("\n", $lines));
        $zip = new ZipArchive();
        self::assertSame(true, $zip->open($output));
        $overview = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        foreach (['PAGE_ID_VERIFIED', 'PAGE_ACCESS_STATUS', 'PAGE_POSTS_FOUND', 'GROUPS_DISCOVERED', 'GROUP_POSTS_VERIFIED', 'INACCESSIBLE_GROUPS', 'LOW_ENGAGEMENT_COUNT', 'TRADEMARK_REVIEW_COUNT', 'DELETE_CANDIDATES_COUNT', 'REPORT_LOCATION', 'BLOCKERS', 'NEXT_ACTION', 'SCOPE_LOCK_VERIFIED', 'READ_ONLY_VERIFIED', 'WORKBOOK_VALIDATED'] as $key) self::assertStringContainsString($key, $overview);
        $zip->close();
        @unlink($output);
        @unlink($checkpoint);
    }
}
