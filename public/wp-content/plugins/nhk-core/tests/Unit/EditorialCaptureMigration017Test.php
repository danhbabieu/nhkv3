<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Migration\EditorialCaptureMigration017;
use PHPUnit\Framework\TestCase;

final class EditorialCaptureMigration017Test extends TestCase
{
    public function test_schema_ready_checks_the_capture_table(): void
    {
        $wpdb = new class {
            public string $prefix = 'wp_';
            public function prepare(string $query, string $table): string { return str_replace('%s', $table, $query); }
            public function get_var(string $query): ?string { return str_contains($query, 'nhk_editorial_captures') ? 'wp_nhk_editorial_captures' : null; }
        };
        self::assertTrue(EditorialCaptureMigration017::schemaReady($wpdb));
    }
}
