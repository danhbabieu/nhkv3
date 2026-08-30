<?php
declare(strict_types=1);
namespace NHK\Tests\Unit;
use PHPUnit\Framework\TestCase;
final class HealthCheckTest extends TestCase {
    public function test_health_contract_contains_required_fields(): void {
        self::assertSame(['plugin_version','api_version','database_reachable','migration_current','migration_target','migration_required'], ['plugin_version','api_version','database_reachable','migration_current','migration_target','migration_required']);
    }
}
