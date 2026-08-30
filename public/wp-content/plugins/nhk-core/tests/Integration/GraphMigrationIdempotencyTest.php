<?php
declare(strict_types=1);
namespace NHK\Tests\Integration;
use NHK\Core\Domain\Graph\PredicateRegistry;
use NHK\Core\Infrastructure\Migration\GraphMigration001;
use PHPUnit\Framework\TestCase;
final class GraphMigrationIdempotencyTest extends TestCase {
    protected function setUp(): void { if (!getenv('NHK_WP_TEST_PATH')) self::markTestSkipped('Set NHK_WP_TEST_PATH=public for WordPress integration tests.'); require_once rtrim((string)getenv('NHK_WP_TEST_PATH'),'/').'/wp-load.php'; (new GraphMigration001())->up(); }
    public function test_up_is_idempotent_and_down_isolated(): void { global $wpdb; $table=$wpdb->prefix.'nhk_graph_predicates';$before=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");(new GraphMigration001())->up();self::assertSame($before,(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}"));self::assertSame(2,$before);(new GraphMigration001())->down();self::assertSame(0,(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=%s',$wpdb->prefix.'nhk_graph_edges')));(new GraphMigration001())->up();self::assertSame(1,(int)get_option('nhk_core_migration_current')); }
}
