<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Migration\SpecimenProductRelationMigration026;
use PHPUnit\Framework\TestCase;

final class SpecimenProductRelationMigration026Test extends TestCase
{
    public function test_migration_is_forward_only_and_schema_readiness_requires_both_registry_rows(): void
    {
        self::assertSame(26, SpecimenProductRelationMigration026::VERSION);
        $database = new class {
            public string $prefix = 'wp_';
            public array $rows = [];
            public function prepare(string $query, string $predicate): array { return [$query, $predicate]; }
            public function get_var(array $prepared): string|int|null { return $this->rows[$prepared[1]] ?? null; }
        };

        self::assertFalse(SpecimenProductRelationMigration026::schemaReady($database));
        $database->rows['specimen_of'] = 1;
        self::assertFalse(SpecimenProductRelationMigration026::schemaReady($database));
        $database->rows['lists_specimen'] = 2;
        self::assertTrue(SpecimenProductRelationMigration026::schemaReady($database));
    }

    public function test_plugin_does_not_auto_wire_the_unexecuted_registry_migration(): void
    {
        $plugin = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Plugin.php');
        self::assertStringNotContainsString('SpecimenProductRelationMigration026::up()', $plugin);
    }
}
