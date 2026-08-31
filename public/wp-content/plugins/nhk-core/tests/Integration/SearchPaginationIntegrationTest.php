<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

final class SearchPaginationIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('NHK_WP_TEST_PATH') === false) self::markTestSkipped('Set NHK_WP_TEST_PATH=public for WordPress integration tests.');
        require_once rtrim((string) getenv('NHK_WP_TEST_PATH'), '/') . '/wp-load.php';
        TestDatabaseGuard::selectTestDatabase();
        TestDatabaseGuard::requireTestDatabase();
        require_once dirname(__DIR__, 2) . '/nhk-core.php';
        do_action('rest_api_init');
    }

    public function test_semantic_search_page_is_bounded_when_native_posts_are_exhausted(): void
    {
        $request = new \WP_REST_Request('GET', '/nhk/v1/search');
        $request->set_param('q', 'odo');
        $request->set_param('page', 2);
        $request->set_param('per_page', 5);
        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status());
        $data = $response->get_data();
        self::assertSame(2, $data['page']);
        self::assertSame(5, $data['per_page']);
        foreach (['entities', 'media', 'videos', 'knowledge'] as $group) {
            self::assertLessThanOrEqual(5, count($data['groups'][$group]));
            self::assertGreaterThanOrEqual(
                count($data['groups'][$group]),
                $data['semantic_totals'][$group]
            );
        }
    }
}
