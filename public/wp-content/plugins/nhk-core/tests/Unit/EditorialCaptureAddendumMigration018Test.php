<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Migration\EditorialCaptureAddendumMigration018;
use PHPUnit\Framework\TestCase;

final class EditorialCaptureAddendumMigration018Test extends TestCase
{
    public function test_schema_ready_checks_the_addendum_ledger_table(): void
    {
        $wpdb = new class {
            public string $prefix = 'wp_';
            public function prepare(string $query, string $table): string { return str_replace('%s', $table, $query); }
            public function get_var(string $query): ?string { return str_contains($query, 'nhk_editorial_capture_addenda') ? 'wp_nhk_editorial_capture_addenda' : null; }
        };

        self::assertTrue(EditorialCaptureAddendumMigration018::schemaReady($wpdb));
    }
}
