<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Shared\TestRuntimeIdentityPolicy;
use PHPUnit\Framework\TestCase;

final class TestRuntimeIdentityPolicyTest extends TestCase
{
    public function test_authorized_test_runtime_is_allowed(): void
    {
        self::assertTrue(TestRuntimeIdentityPolicy::evaluate($this->identity())['allowed']);
    }

    public function test_same_database_on_wrong_site_is_blocked(): void
    {
        self::assertFalse(TestRuntimeIdentityPolicy::evaluate($this->identity(site_url: 'https://other.example'))['allowed']);
    }

    public function test_same_site_on_wrong_database_is_blocked(): void
    {
        self::assertFalse(TestRuntimeIdentityPolicy::evaluate($this->identity(database: 'other_db'))['allowed']);
    }

    public function test_production_environment_is_blocked(): void
    {
        self::assertFalse(TestRuntimeIdentityPolicy::evaluate($this->identity(environment: 'production'))['allowed']);
    }

    public function test_unknown_staging_runtime_is_blocked(): void
    {
        self::assertFalse(TestRuntimeIdentityPolicy::evaluate($this->identity(database: 'other_db', site_url: 'https://other.example'))['allowed']);
    }

    public function test_missing_identity_fields_are_blocked(): void
    {
        self::assertFalse(TestRuntimeIdentityPolicy::evaluate([])['allowed']);
    }

    public function test_legacy_nhk_v3_test_is_not_authorized_by_default(): void
    {
        self::assertFalse(TestRuntimeIdentityPolicy::evaluate($this->identity(database: 'nhk_v3_test'))['allowed']);
    }

    private function identity(string $environment = 'staging', string $database = 'erourxcg_nhkv3', string $site_url = 'https://demo.1945.vn'): array
    {
        return ['environment' => $environment, 'database' => $database, 'site_url' => $site_url, 'project' => 'nhk-v3', 'runtime_identity' => 'nhk-v3'];
    }
}
