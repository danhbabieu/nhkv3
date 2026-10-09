<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Demo\StagingReleasePolicy;
use PHPUnit\Framework\TestCase;

final class StagingReleasePolicyTest extends TestCase
{
    public function test_deployment_config_requires_exact_staging_https_identity(): void
    {
        self::assertSame(
            'STAGING_DEPLOYMENT_IDENTITY_REQUIRED',
            StagingReleasePolicy::validateDeploymentConfig(['environment_type' => 'staging']),
        );
        self::assertSame(
            'STAGING_DEPLOYMENT_IDENTITY_MISMATCH',
            StagingReleasePolicy::validateDeploymentConfig([
                'environment_type' => 'staging',
                'wp_home' => 'http://localhost:8080',
                'wp_siteurl' => 'http://localhost:8080',
            ]),
        );
        self::assertSame(
            'STAGING_DEPLOYMENT_IDENTITY_MISMATCH',
            StagingReleasePolicy::validateDeploymentConfig([
                'environment_type' => 'staging',
                'wp_home' => 'https://demo.1945.vn/path',
                'wp_siteurl' => 'https://demo.1945.vn',
            ]),
        );
        self::assertNull(StagingReleasePolicy::validateDeploymentConfig([
            'environment_type' => 'staging',
            'wp_home' => 'https://demo.1945.vn',
            'wp_siteurl' => 'https://demo.1945.vn',
        ]));
    }

    public function test_runtime_health_requires_database_and_both_https_wordpress_urls(): void
    {
        self::assertSame(
            'STAGING_RUNTIME_IDENTITY_MISMATCH',
            StagingReleasePolicy::validateRuntimeHealth([
                'environment' => 'staging',
                'database' => 'erourxcg_nhkv3',
                'site_url' => 'http://localhost:8080',
                'siteurl' => 'http://localhost:8080',
            ]),
        );
        self::assertNull(StagingReleasePolicy::validateRuntimeHealth([
            'environment' => 'staging',
            'database' => 'erourxcg_nhkv3',
            'site_url' => 'https://demo.1945.vn',
            'siteurl' => 'https://demo.1945.vn',
        ]));
    }

    public function test_migration_plan_skips_pre_migration_export_at_26_of_26(): void
    {
        self::assertSame(
            ['backup_required' => false, 'migration_required' => false],
            StagingReleasePolicy::migrationPlan(['migration_current' => 26, 'migration_target' => 26]),
        );
        self::assertSame(
            ['backup_required' => true, 'migration_required' => true],
            StagingReleasePolicy::migrationPlan(['migration_current' => 25, 'migration_target' => 26]),
        );
    }

    public function test_unsupported_migration_state_fails_closed(): void
    {
        $this->expectExceptionMessage('MIGRATION_STATE_UNSUPPORTED');

        StagingReleasePolicy::migrationPlan(['migration_current' => 24, 'migration_target' => 26]);
    }
}
