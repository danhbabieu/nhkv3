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

    public function test_authorized_demo_staging_database_is_allowed(): void
    {
        self::assertTrue(MigrationDatabaseGuard::isUpAllowed('erourxcg_nhkv3', 'erourxcg_nhkv3', 'demo', 'staging'));
    }

    public function test_staging_without_authorization_is_denied(): void
    {
        self::assertFalse(MigrationDatabaseGuard::isUpAllowed('erourxcg_nhkv3', null, 'demo', 'staging'));
    }

    public function test_staging_with_wrong_database_is_denied(): void
    {
        self::assertFalse(MigrationDatabaseGuard::isUpAllowed('other_db', 'erourxcg_nhkv3', 'demo', 'staging'));
    }

    public function test_staging_requires_demo_runtime_configuration(): void
    {
        self::assertFalse(MigrationDatabaseGuard::isUpAllowed('erourxcg_nhkv3', 'erourxcg_nhkv3', null, 'staging'));
        self::assertFalse(MigrationDatabaseGuard::isUpAllowed('erourxcg_nhkv3', 'erourxcg_nhkv3', 'production', 'staging'));
    }

    public function test_production_is_denied_even_with_demo_authorization(): void
    {
        self::assertFalse(MigrationDatabaseGuard::isUpAllowed('erourxcg_nhkv3', 'erourxcg_nhkv3', 'demo', 'production'));
    }
}
