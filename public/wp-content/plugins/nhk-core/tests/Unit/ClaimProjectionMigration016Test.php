<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Migration\ClaimProjectionMigration016;
use PHPUnit\Framework\TestCase;

final class ClaimProjectionMigration016Test extends TestCase
{
    public function test_schema_requires_both_derived_projection_tables(): void
    {
        $wpdb = new class {
            public string $prefix = 'wp_';
            public function prepare(string $query, string $table): string { return str_replace('%s', $table, $query); }
            public function get_var(string $query): ?string { return str_contains($query, 'nhk_claim_projection_revisions') || str_contains($query, 'nhk_claim_projection_dependencies') ? 'wp_nhk_claim_projection_revisions' : null; }
        };
        self::assertFalse(ClaimProjectionMigration016::schemaReady($wpdb));
    }
}
