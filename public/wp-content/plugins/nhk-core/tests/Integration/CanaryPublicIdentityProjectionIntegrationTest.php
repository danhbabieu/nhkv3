<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Application\Migration\CanaryPublicIdentityProjection;
use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

final class CanaryPublicIdentityProjectionIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('NHK_WP_TEST_PATH') === false || trim((string) getenv('NHK_WP_TEST_PATH')) === '') {
            self::markTestSkipped('ENVIRONMENT_BLOCKED: set NHK_WP_TEST_PATH=public for guarded read-only integration verification.');
        }

        require_once rtrim((string) getenv('NHK_WP_TEST_PATH'), '/') . '/wp-load.php';
        TestDatabaseGuard::selectTestDatabase();
        TestDatabaseGuard::requireTestDatabase();
    }

    public function test_guarded_canary_evidence_does_not_project_or_mutate(): void
    {
        $result = (new CanaryPublicIdentityProjection())->inspect();

        self::assertSame(0, $result['mutation_count']);
        self::assertFalse($result['live_projection_performed']);
        self::assertSame(CanaryPublicIdentityProjection::CANONICAL_PATH, $result['expected']['canonical_path']);
    }
}
