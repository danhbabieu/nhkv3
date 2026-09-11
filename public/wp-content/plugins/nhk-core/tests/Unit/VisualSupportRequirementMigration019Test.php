<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Migration\VisualSupportRequirementMigration019;
use PHPUnit\Framework\TestCase;

final class VisualSupportRequirementMigration019Test extends TestCase
{
    public function test_schema_ready_checks_the_additive_requirement_table(): void
    {
        $wpdb = new class {
            public string $prefix = 'wp_';
            public function prepare(string $query, string $table): string { return str_replace('%s', $table, $query); }
            public function get_var(string $query): ?string { return str_contains($query, 'nhk_visual_support_requirements') ? 'wp_nhk_visual_support_requirements' : null; }
        };

        self::assertSame(19, VisualSupportRequirementMigration019::VERSION);
        self::assertTrue(VisualSupportRequirementMigration019::schemaReady($wpdb));
        $source = (string) file_get_contents(__DIR__ . '/../../src/Infrastructure/Migration/VisualSupportRequirementMigration019.php');
        self::assertStringContainsString('nhk_visual_support_requirements', $source);
        self::assertStringContainsString('visual_requirement_semantic', $source);
        self::assertStringContainsString('visual_requirement_reverse', $source);
        self::assertStringContainsString('MigrationDatabaseGuard::assertUpAllowed', $source);
    }
}
