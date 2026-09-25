<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class MediaUsageWordPressProjectionIntegrationTest extends TestCase
{
    public function test_guarded_runtime_is_explicitly_required_for_article_469_projection(): void
    {
        $path = trim((string) getenv('NHK_WP_TEST_PATH'));
        $database = trim((string) getenv('NHK_WP_TEST_DB'));
        if ($path === '' || $database === '') {
            self::markTestSkipped('NHK_WP_TEST_PATH and NHK_WP_TEST_DB are required; no integration result claimed.');
        }
        self::assertFileExists($path);
        self::assertSame('nhk_v3_test', $database, 'Integration mutations are permitted only on exact nhk_v3_test.');
    }
}
