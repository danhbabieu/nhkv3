<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Migration\PublicIdentityMigration014;
use NHK\Core\Infrastructure\PublicIdentity\WpdbPublicIdentityRepository;
use PHPUnit\Framework\TestCase;

final class PublicIdentityMigration014Test extends TestCase
{
    public function test_schema_is_not_ready_when_history_table_is_missing_even_at_current_version(): void
    {
        $wpdb = new class {
            public string $prefix = 'wp_';

            public function prepare(string $query, string $table): string
            {
                return str_replace('%s', $table, $query);
            }

            public function get_var(string $query): ?string
            {
                return str_contains($query, 'nhk_public_identities') ? 'wp_nhk_public_identities' : null;
            }
        };

        self::assertFalse(PublicIdentityMigration014::schemaReady($wpdb));
    }

    public function test_schema_is_ready_only_when_both_identity_tables_exist(): void
    {
        $wpdb = new class {
            public string $prefix = 'wp_';

            public function prepare(string $query, string $table): string
            {
                return str_replace('%s', $table, $query);
            }

            public function get_var(string $query): ?string
            {
                return str_contains($query, 'nhk_public_identities') || str_contains($query, 'nhk_public_identity_history')
                    ? (str_contains($query, 'history') ? 'wp_nhk_public_identity_history' : 'wp_nhk_public_identities')
                    : null;
            }
        };

        self::assertTrue(PublicIdentityMigration014::schemaReady($wpdb));
    }

    public function test_historic_resolution_fails_closed_when_schema_is_missing(): void
    {
        $wpdb = new class {
            public string $prefix = 'wp_';

            public function prepare(string $query, string $table): string
            {
                return str_replace('%s', $table, $query);
            }

            public function get_var(string $query): ?string
            {
                return null;
            }
        };

        self::assertSame(['status' => 'UNAVAILABLE'], (new WpdbPublicIdentityRepository($wpdb))->resolveHistoric('/tri-thuc/'));
    }
}
