<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DictionaryBackfillAdminBoundaryTest extends TestCase
{
    public function test_backfill_admin_is_read_only_and_uses_dry_run_runtime(): void
    {
        $path = dirname(__DIR__, 2) . '/src/Infrastructure/Admin/DictionaryBackfillAdminPage.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('backfillDryRun(', $source);
        self::assertStringContainsString("'no_write'", $source);
        self::assertStringNotContainsString('admin_post_', $source);
        self::assertStringNotContainsString('->plan(', $source);
        self::assertStringNotContainsString('->curation(', $source);
    }
}
