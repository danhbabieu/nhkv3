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
        self::assertFalse(MigrationDatabaseGuard::isUpAllowed('nhk_v3_test', null, null, null));
    }

    public function test_authorized_demo_staging_database_is_allowed(): void
    {
        self::assertTrue(MigrationDatabaseGuard::isUpAllowed('erourxcg_nhkv3', 'erourxcg_nhkv3', 'staging', 'staging', 'https://demo.1945.vn', 'nhk-v3', 'nhk-v3'));
    }

    public function test_staging_without_authorization_is_denied(): void
    {
        self::assertFalse(MigrationDatabaseGuard::isUpAllowed('erourxcg_nhkv3', null, 'staging', 'staging', 'https://demo.1945.vn', 'nhk-v3', 'nhk-v3'));
    }

    public function test_staging_with_wrong_database_is_denied(): void
    {
        self::assertFalse(MigrationDatabaseGuard::isUpAllowed('other_db', 'erourxcg_nhkv3', 'staging', 'staging', 'https://demo.1945.vn', 'nhk-v3', 'nhk-v3'));
    }

    public function test_staging_requires_demo_runtime_configuration(): void
    {
        self::assertFalse(MigrationDatabaseGuard::isUpAllowed('erourxcg_nhkv3', 'erourxcg_nhkv3', 'staging', 'staging', null, 'nhk-v3', 'nhk-v3'));
        self::assertFalse(MigrationDatabaseGuard::isUpAllowed('erourxcg_nhkv3', 'erourxcg_nhkv3', 'staging', 'staging', 'https://demo.1945.vn', 'other-project', 'other-project'));
    }

    public function test_production_is_denied_even_with_demo_authorization(): void
    {
        self::assertFalse(MigrationDatabaseGuard::isUpAllowed('erourxcg_nhkv3', 'erourxcg_nhkv3', 'staging', 'production', 'https://demo.1945.vn', 'nhk-v3', 'nhk-v3'));
    }

    public function test_explicit_recovery_database_is_allowed_only_in_non_staging_runtime(): void
    {
        self::assertTrue(MigrationDatabaseGuard::isUpAllowed(
            'nhk_v3_video_recovery',
            'nhk_v3_video_recovery',
            'recovery',
            'development'
        ));
        self::assertFalse(MigrationDatabaseGuard::isUpAllowed(
            'nhk_v3_video_recovery',
            'nhk_v3_video_recovery',
            'recovery',
            'staging'
        ));
        self::assertFalse(MigrationDatabaseGuard::isUpAllowed(
            'other_db',
            'nhk_v3_video_recovery',
            'recovery',
            'development'
        ));
        self::assertFalse(MigrationDatabaseGuard::isUpAllowed(
            'nhk_v3',
            'nhk_v3_video_recovery',
            'recovery',
            'development'
        ));
    }
}
