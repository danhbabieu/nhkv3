<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Migration\DictionaryMigration015;
use PHPUnit\Framework\TestCase;

final class DictionaryMigration015Test extends TestCase
{
    public function test_schema_is_not_ready_when_any_dictionary_table_is_missing(): void
    {
        $wpdb = new class {
            public string $prefix = 'wp_';
            public function prepare(string $query, string $table): string { return str_replace('%s', $table, $query); }
            public function get_var(string $query): ?string
            {
                foreach (['nhk_dictionary_concepts', 'nhk_dictionary_labels', 'nhk_dictionary_candidates'] as $suffix) {
                    if (str_contains($query, $suffix)) return 'wp_' . $suffix;
                }
                return null;
            }
        };

        self::assertFalse(DictionaryMigration015::schemaReady($wpdb));
    }

    public function test_schema_is_ready_only_when_all_dictionary_tables_exist(): void
    {
        $wpdb = new class {
            public string $prefix = 'wp_';
            public function prepare(string $query, string $table): string { return str_replace('%s', $table, $query); }
            public function get_var(string $query): ?string
            {
                foreach (['nhk_dictionary_concepts', 'nhk_dictionary_labels', 'nhk_dictionary_candidates', 'nhk_dictionary_mentions'] as $suffix) {
                    if (str_contains($query, $suffix)) return 'wp_' . $suffix;
                }
                return null;
            }
        };

        self::assertTrue(DictionaryMigration015::schemaReady($wpdb));
    }
}
