<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Migration\DictionaryLexicalRelationMigration025;
use NHK\Core\Infrastructure\Migration\GraphRelationContextMigration002;
use PHPUnit\Framework\TestCase;

final class Phase1MigrationContractTest extends TestCase
{
    public function test_phase_one_migrations_are_additive_and_versioned_after_existing_migrations(): void
    {
        self::assertSame(2, GraphRelationContextMigration002::VERSION);
        self::assertSame(25, DictionaryLexicalRelationMigration025::VERSION);
        self::assertGreaterThan(1, GraphRelationContextMigration002::VERSION);
        self::assertGreaterThan(24, DictionaryLexicalRelationMigration025::VERSION);
    }

    public function test_existing_migration_sources_remain_untouched_by_phase_one_wiring(): void
    {
        $graph = file_get_contents(__DIR__ . '/../../src/Infrastructure/Migration/GraphMigration001.php');
        $entrySense = file_get_contents(__DIR__ . '/../../src/Infrastructure/Migration/DictionaryEntrySenseMigration024.php');

        self::assertIsString($graph);
        self::assertIsString($entrySense);
        self::assertStringNotContainsString('GraphRelationContextMigration002', $graph);
        self::assertStringNotContainsString('DictionaryLexicalRelationMigration025', $entrySense);
    }
}
