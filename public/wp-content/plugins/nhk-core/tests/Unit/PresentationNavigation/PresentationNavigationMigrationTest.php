<?php
declare(strict_types=1);

namespace NHK\Tests\Unit\PresentationNavigation;

use NHK\Core\Infrastructure\Migration\PresentationNavigationMigration023;
use PHPUnit\Framework\TestCase;

final class PresentationNavigationMigrationTest extends TestCase
{
    public function test_schema_probe_is_fail_closed_when_table_is_missing(): void
    {
        $wpdb = new class {
            public string $prefix = 'wp_';
            public function prepare(string $query, string $table): string { return str_replace('%s', $table, $query); }
            public function get_var(string $query): ?string { return null; }
        };
        self::assertFalse(PresentationNavigationMigration023::schemaReady($wpdb));
    }

    public function test_migration_source_contains_only_presentation_columns_and_version_23(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Infrastructure/Migration/PresentationNavigationMigration023.php');
        self::assertSame(23, PresentationNavigationMigration023::VERSION);
        foreach (['navigation_key', 'canonical_uuid', 'parent_id', 'sort_order', 'show_in_type_index', 'show_in_header_menu', 'show_in_mobile_menu', 'show_in_sidebar', 'featured', 'revision'] as $column) self::assertStringContainsString($column, $source);
        self::assertStringNotContainsString('nhk_classification', $source);
    }
}
