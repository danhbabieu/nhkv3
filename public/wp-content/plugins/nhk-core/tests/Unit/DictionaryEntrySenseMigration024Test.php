<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Infrastructure\Migration\DictionaryEntrySenseMigration024;
use PHPUnit\Framework\TestCase;

final class DictionaryEntrySenseMigration024Test extends TestCase
{
    public function test_schema_ready_requires_all_additive_tables(): void
    {
        $wpdb = new class {
            public string $prefix = 'wp_';
            public function prepare(string $query, string $table): string { return str_replace('%s', $table, $query); }
            public function get_var(string $query): ?string
            {
                foreach (['nhk_dictionary_entries', 'nhk_dictionary_forms', 'nhk_dictionary_entry_senses'] as $suffix) {
                    if (str_contains($query, $suffix)) return str_contains($query, 'entry_senses') ? 'wp_nhk_dictionary_entry_senses' : null;
                }
                return null;
            }
        };

        self::assertFalse(DictionaryEntrySenseMigration024::schemaReady($wpdb));
    }

    public function test_schema_ready_is_true_when_all_additive_tables_exist(): void
    {
        $wpdb = new class {
            public string $prefix = 'wp_';
            public function prepare(string $query, string $table): string { return str_replace('%s', $table, $query); }
            public function get_var(string $query): ?string
            {
                foreach (['nhk_dictionary_entries', 'nhk_dictionary_forms', 'nhk_dictionary_entry_senses'] as $suffix) {
                    if (str_contains($query, $suffix)) return 'wp_' . $suffix;
                }
                return null;
            }
        };

        self::assertTrue(DictionaryEntrySenseMigration024::schemaReady($wpdb));
    }
}
