<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

final class MediaUsageWordPressProjectionIntegrationTest extends TestCase
{
    public function test_guarded_runtime_is_explicitly_required_for_article_469_projection(): void
    {
        $path = trim((string) getenv('NHK_WP_TEST_PATH'));
        if ($path === '') self::markTestSkipped('NHK_WP_TEST_PATH is required; no integration result claimed.');
        require_once rtrim($path, '/') . '/wp-load.php';
        TestDatabaseGuard::selectTestDatabase();
        TestDatabaseGuard::requireTestDatabase();
        self::assertFileExists($path);
    }
}
