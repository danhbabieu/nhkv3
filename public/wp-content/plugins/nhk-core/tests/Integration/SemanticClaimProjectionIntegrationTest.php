<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Domain\Projection\{ProjectionRevision, ProjectionStatus};
use NHK\Core\Infrastructure\Migration\ClaimProjectionMigration016;
use NHK\Core\Infrastructure\Projection\{WpdbProjectionDependencyIndex, WpdbProjectionRevisionStore};
use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

final class SemanticClaimProjectionIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('NHK_WP_TEST_PATH') === false) self::markTestSkipped('Set NHK_WP_TEST_PATH=public for WordPress integration tests.');
        require_once rtrim((string) getenv('NHK_WP_TEST_PATH'), '/') . '/wp-load.php';
        TestDatabaseGuard::selectTestDatabase();
        TestDatabaseGuard::requireTestDatabase();
        global $wpdb;
        (new ClaimProjectionMigration016())->up();
        self::assertTrue(ClaimProjectionMigration016::schemaReady($wpdb));
    }

    public function test_wpdb_store_roundtrips_idempotency_publication_and_dependencies(): void
    {
        global $wpdb;
        $node = '22222222-2222-4222-8222-222222222222';
        $store = new WpdbProjectionRevisionStore($wpdb);
        $dependencies = new WpdbProjectionDependencyIndex($wpdb);
        $this->cleanup($node);

        try {
            $hash = str_repeat('a', 64);
            $first = $store->saveCandidate($this->revision($node, $hash, '/projection-runtime/'));
            $same = $store->saveCandidate($this->revision($node, $hash, '/projection-runtime/'));
            $ready = $store->markReady($node, $first->revision);
            $published = $store->publish($node, $ready->revision);
            $replay = $store->saveCandidate($this->revision($node, $hash, '/projection-runtime/'));

            $candidate = $store->saveCandidate($this->revision($node, str_repeat('d', 64), '/projection-runtime/'));
            $dependencies->add(['kind' => 'claim', 'id' => 'projection-runtime-claim', 'node_uuid' => $node, 'node_type' => 'variant', 'section_key' => 'sound', 'scope' => 'direct', 'graph_distance' => 0, 'dependency_revision' => 1]);

            self::assertSame(1, $first->revision);
            self::assertSame($first->revision, $same->revision);
            self::assertSame(ProjectionStatus::PUBLISHED, $published->status);
            self::assertSame($published->revision, $replay->revision);
            self::assertSame(2, $candidate->revision);
            self::assertSame($published->revision, $store->findPublished($node)?->revision);
            self::assertCount(1, $wpdb->get_results($wpdb->prepare("SELECT id FROM {$wpdb->prefix}nhk_claim_projection_revisions WHERE node_uuid=%s AND status='published'", $node)));
            self::assertSame('projection-runtime-claim', $dependencies->findByDependency('claim', 'projection-runtime-claim')[0]['id']);
        } finally {
            $dependencies->removeForNode($node);
            $this->cleanup($node);
        }
    }

    private function revision(string $node, string $hash, string $url): ProjectionRevision
    {
        return new ProjectionRevision($node, 1, ProjectionStatus::CANDIDATE, $hash, str_repeat('b', 64), str_repeat('c', 64), payload: ['ledger' => ['claim_count' => 1], 'seo' => ['canonical_url' => $url, 'h1' => 'Projection runtime']]);
    }

    private function cleanup(string $node): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_claim_projection_dependencies WHERE node_uuid=%s", $node));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_claim_projection_revisions WHERE node_uuid=%s", $node));
    }
}
