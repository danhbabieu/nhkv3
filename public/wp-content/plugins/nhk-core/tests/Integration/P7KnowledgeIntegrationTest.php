<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Application\Knowledge\KnowledgeService;
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Core\Infrastructure\Knowledge\{WpdbEvidenceRepository, WpdbKnowledgeRepository, WpdbSourceRepository};
use NHK\Core\Infrastructure\Migration\{KnowledgeEvidenceMetadataMigration007, KnowledgeMigration005};
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

final class P7KnowledgeIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('NHK_WP_TEST_PATH') === false) self::markTestSkipped('Set NHK_WP_TEST_PATH=public for WordPress integration tests.');
        require_once rtrim((string) getenv('NHK_WP_TEST_PATH'), '/') . '/wp-load.php';
        TestDatabaseGuard::selectTestDatabase();
        TestDatabaseGuard::requireTestDatabase();
        (new KnowledgeMigration005())->down();
        (new KnowledgeMigration005())->up();
        (new KnowledgeEvidenceMetadataMigration007())->up();
    }

    protected function tearDown(): void
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) return;
        $wpdb->query("DELETE FROM {$wpdb->prefix}nhk_evidence WHERE claim_uuid IN (SELECT canonical_uuid FROM {$wpdb->prefix}nhk_knowledge_claims WHERE stable_key LIKE 'p7-integration-%')");
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_knowledge_claims WHERE stable_key LIKE %s", 'p7-integration-%'));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_sources WHERE stable_key LIKE %s", 'p7-integration-%'));
    }

    public function test_migration_is_idempotent_and_service_persists_claim_source_evidence(): void
    {
        global $wpdb;
        $migration = new KnowledgeMigration005();
        $migration->up();
        foreach (['nhk_knowledge_claims', 'nhk_sources', 'nhk_evidence'] as $table) self::assertSame($wpdb->prefix . $table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . $table)));
        $service = new KnowledgeService(new WpdbKnowledgeRepository($wpdb), new WpdbSourceRepository($wpdb), new WpdbEvidenceRepository($wpdb));
        $claim = $service->createClaim('p7-integration-claim', 'The clock uses a spring-driven movement.', 'technical', ['source' => 'test']);
        $source = $service->createSource('p7-integration-source', 'Integration catalogue', 'catalog', 'catalog:page-1');
        $evidence = $service->cite($claim->canonicalId, $source->canonicalId, 'Spring-driven movement', 'supports');
        self::assertSame($claim->canonicalId, $evidence->claimId);
        self::assertCount(1, $service->evidenceForClaim($claim->canonicalId));
        self::assertSame($claim->canonicalId, $service->createClaim('p7-integration-claim', 'The clock uses a spring-driven movement.', 'technical', ['source' => 'test'])->canonicalId);
    }

    public function test_private_source_is_not_exposed_by_public_rest_read(): void
    {
        global $wpdb;
        $source = (new WpdbSourceRepository($wpdb))->create(new Source(UuidCodec::newV7(), 'p7-integration-private', 'Private source', 'archive', 'https://example.test/private', ['visibility' => 'PRIVATE']));
        $response = rest_do_request(new \WP_REST_Request('GET', '/nhk/v1/knowledge/source/' . $source->canonicalId));
        self::assertSame(404, $response->get_status());
    }

    public function test_private_evidence_is_not_exposed_by_public_rest_read(): void
    {
        global $wpdb;
        $service = new KnowledgeService(new WpdbKnowledgeRepository($wpdb), new WpdbSourceRepository($wpdb), new WpdbEvidenceRepository($wpdb));
        $claim = $service->createClaim('p7-integration-private-claim', 'Private claim for REST boundary.', 'fact', ['metadata' => ['verification_status' => 'UNVERIFIED']]);
        $source = $service->createSource('p7-integration-private-evidence-source', 'Private evidence source', 'archive', 'https://example.test/private-evidence');
        $evidence = $service->cite($claim->canonicalId, $source->canonicalId, 'Private excerpt');
        $response = rest_do_request(new \WP_REST_Request('GET', '/nhk/v1/knowledge/evidence/' . $evidence->canonicalId));
        self::assertSame(404, $response->get_status());
    }

    public function test_source_and_evidence_repositories_ignore_corrupt_metadata_rows(): void
    {
        global $wpdb;
        $sourceRepository = new WpdbSourceRepository($wpdb);
        $source = $sourceRepository->create(new Source(UuidCodec::newV7(), 'p7-integration-corrupt-source', 'Corrupt source', 'archive'));
        $service = new KnowledgeService(new WpdbKnowledgeRepository($wpdb), $sourceRepository, new WpdbEvidenceRepository($wpdb));
        $claim = $service->createClaim('p7-integration-corrupt-evidence-claim', 'Corrupt evidence claim.', 'fact');
        $evidence = $service->cite($claim->canonicalId, $source->canonicalId, 'Corrupt evidence excerpt');
        $evidenceRepository = new WpdbEvidenceRepository($wpdb);

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}nhk_sources SET metadata_json=%s WHERE canonical_uuid=%s",
            '"not-an-object"',
            UuidCodec::toBinary($source->canonicalId)
        ));
        self::assertNull($sourceRepository->findByCanonicalId($source->canonicalId));
        self::assertSame([], $sourceRepository->list());

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}nhk_evidence SET metadata_json=%s WHERE evidence_uuid=%s",
            '"not-an-object"',
            UuidCodec::toBinary($evidence->canonicalId)
        ));

        self::assertNull($evidenceRepository->findByCanonicalId($evidence->canonicalId));
        self::assertSame([], $evidenceRepository->listByClaim($claim->canonicalId));
    }

    public function test_knowledge_repository_ignores_corrupt_provenance_rows(): void
    {
        global $wpdb;
        $repository = new WpdbKnowledgeRepository($wpdb);
        $claim = $repository->create(new KnowledgeClaim(UuidCodec::newV7(), 'p7-integration-corrupt-provenance', 'Corrupt provenance claim.', 'fact', ['source' => 'test']));

        try {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}nhk_knowledge_claims SET provenance_json=%s WHERE canonical_uuid=%s",
                '"not-an-object"',
                UuidCodec::toBinary($claim->canonicalId)
            ));

            self::assertNull($repository->findByCanonicalId($claim->canonicalId));
            self::assertNotContains($claim->canonicalId, array_column(array_map(static fn (KnowledgeClaim $item): array => ['id' => $item->canonicalId], $repository->list()), 'id'));
        } finally {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_knowledge_claims WHERE canonical_uuid=%s", UuidCodec::toBinary($claim->canonicalId)));
        }
    }

    public function test_source_repository_rejects_same_identity_with_changed_metadata(): void
    {
        global $wpdb;
        $repository = new WpdbSourceRepository($wpdb);
        $source = new Source(UuidCodec::newV7(), 'p7-integration-source-conflict', 'Conflict source', 'catalog', 'https://example.test/catalog', ['visibility' => 'PRIVATE']);
        $repository->create($source);
        $raced = $repository->create(new Source(UuidCodec::newV7(), $source->stableKey, $source->title, $source->sourceType, $source->locator, $source->metadata));
        self::assertSame($source->canonicalId, $raced->canonicalId);

        try {
            $repository->create(new Source($source->canonicalId, $source->stableKey, $source->title, $source->sourceType, $source->locator, ['visibility' => 'PUBLIC']));
            self::fail('Expected a same-identity Source with changed metadata to be rejected.');
        } catch (\NHK\Core\Domain\Knowledge\KnowledgeException $exception) {
            self::assertSame('Source identity already exists.', $exception->getMessage());
        } finally {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_sources WHERE canonical_uuid=%s", UuidCodec::toBinary($source->canonicalId)));
        }
    }

    public function test_knowledge_repository_rejects_same_identity_with_changed_provenance(): void
    {
        global $wpdb;
        $repository = new WpdbKnowledgeRepository($wpdb);
        $claim = new KnowledgeClaim(UuidCodec::newV7(), 'p7-integration-claim-conflict', 'Conflict claim', 'fact', ['source' => 'test']);
        $repository->create($claim);

        try {
            $repository->create(new KnowledgeClaim($claim->canonicalId, $claim->stableKey, $claim->claimText, $claim->claimType, ['source' => 'changed']));
            self::fail('Expected a same-identity Knowledge claim with changed provenance to be rejected.');
        } catch (\NHK\Core\Domain\Knowledge\KnowledgeException $exception) {
            self::assertSame('Knowledge claim identity already exists.', $exception->getMessage());
        } finally {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_knowledge_claims WHERE canonical_uuid=%s", UuidCodec::toBinary($claim->canonicalId)));
        }
    }

    public function test_evidence_repository_rejects_same_identity_with_changed_metadata(): void
    {
        global $wpdb;
        $service = new KnowledgeService(new WpdbKnowledgeRepository($wpdb), new WpdbSourceRepository($wpdb), new WpdbEvidenceRepository($wpdb));
        $claim = $service->createClaim('p7-integration-evidence-claim', 'Evidence identity claim.', 'fact');
        $source = $service->createSource('p7-integration-evidence-source', 'Evidence source', 'catalog', 'catalog:evidence');
        $repository = new WpdbEvidenceRepository($wpdb);
        $evidence = new Evidence(UuidCodec::newV7(), $claim->canonicalId, $source->canonicalId, 'supports', 'Original excerpt', null, true, 1, ['visibility' => 'PRIVATE']);
        $repository->create($evidence);
        $raced = $repository->create(new Evidence($evidence->canonicalId, $evidence->claimId, $evidence->sourceId, $evidence->relation, $evidence->excerpt, $evidence->locator, $evidence->active, $evidence->revision, $evidence->metadata));
        self::assertSame($evidence->canonicalId, $raced->canonicalId);

        try {
            $repository->create(new Evidence($evidence->canonicalId, $evidence->claimId, $evidence->sourceId, $evidence->relation, $evidence->excerpt, $evidence->locator, $evidence->active, $evidence->revision, ['visibility' => 'PUBLIC']));
            self::fail('Expected a same-identity Evidence with changed metadata to be rejected.');
        } catch (\NHK\Core\Domain\Knowledge\KnowledgeException $exception) {
            self::assertSame('Evidence identity already exists.', $exception->getMessage());
        }
    }
}
