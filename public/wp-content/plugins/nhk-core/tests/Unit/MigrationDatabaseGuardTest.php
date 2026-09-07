<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Migration\MigrationDatabaseGuard;
use PHPUnit\Framework\TestCase;

final class MigrationDatabaseGuardTest extends TestCase
{
    public function test_canonical_development_database_is_allowed_without_external_authorization(): void
    {
        self::assertTrue(MigrationDatabaseGuard::isUpAllowed('nhk_v3', null, null, null));
        self::assertTrue(MigrationDatabaseGuard::isUpAllowed('nhk_v3_test', null, null, null));
    }

    public function test_explicit_demo_database_authorization_allows_noncanonical_database(): void
    {
        self::assertTrue(MigrationDatabaseGuard::isUpAllowed('demo_actual', 'demo_actual', 'demo', 'development'));
    }

    public function test_noncanonical_database_without_matching_authorization_is_denied(): void
    {
        self::assertFalse(MigrationDatabaseGuard::isUpAllowed('demo_actual', null, 'demo', 'development'));
        self::assertFalse(MigrationDatabaseGuard::isUpAllowed('demo_actual', 'other_database', 'demo', 'development'));
        self::assertFalse(MigrationDatabaseGuard::isUpAllowed('demo_actual', 'demo_actual', null, 'development'));
    }

    public function test_explicit_authorization_cannot_open_production_runtime(): void
    {
        self::assertFalse(MigrationDatabaseGuard::isUpAllowed('production_actual', 'production_actual', 'demo', 'production'));
        self::assertFalse(MigrationDatabaseGuard::isUpAllowed('production_actual', 'production_actual', 'production', 'production'));
    }
}
