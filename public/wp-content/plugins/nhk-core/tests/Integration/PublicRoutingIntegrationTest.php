<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

final class PublicRoutingIntegrationTest extends TestCase
{
    /** @var array<string,string> */
    private array $server = [];

    protected function setUp(): void
    {
        if (getenv('NHK_WP_TEST_PATH') === false) self::markTestSkipped('Set NHK_WP_TEST_PATH=public for WordPress integration tests.');
        require_once rtrim((string) getenv('NHK_WP_TEST_PATH'), '/') . '/wp-load.php';
        TestDatabaseGuard::selectTestDatabase();
        TestDatabaseGuard::requireTestDatabase();
        foreach (['REQUEST_URI', 'PHP_SELF', 'SCRIPT_NAME'] as $key) if (isset($_SERVER[$key])) $this->server[$key] = (string) $_SERVER[$key];
    }

    protected function tearDown(): void
    {
        foreach (['REQUEST_URI', 'PHP_SELF', 'SCRIPT_NAME'] as $key) {
            if (isset($this->server[$key])) $_SERVER[$key] = $this->server[$key];
            else unset($_SERVER[$key]);
        }
    }

    public function test_published_post_55_keeps_native_root_permalink_resolution(): void
    {
        $post = get_post(55);
        if (!$post instanceof \WP_Post || $post->post_status !== 'publish' || $post->post_type !== 'post' || $post->post_name !== 'dong-ho-24-may-tron-ten-goi-54-thi-truong-viet-nam') {
            self::markTestSkipped('nhk_v3_test does not contain the confirmed published Post 55 fixture.');
        }

        $wp = $this->runRequest('/dong-ho-24-may-tron-ten-goi-54-thi-truong-viet-nam/');

        self::assertStringContainsString('name=', $wp->matched_query, 'The root bridge must retain a native WP slug query.');
        self::assertSame('dong-ho-24-may-tron-ten-goi-54-thi-truong-viet-nam', $wp->query_vars['name'] ?? null);
        self::assertSame(55, get_queried_object_id());
        self::assertTrue(is_singular('post'));
        self::assertFalse(is_404());
        self::assertArrayNotHasKey('nhk_core_entity_context', $GLOBALS);
    }

    private function runRequest(string $path): \WP
    {
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['PHP_SELF'] = '/index.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $wp = new \WP();
        $wp->main();
        return $wp;
    }
}
