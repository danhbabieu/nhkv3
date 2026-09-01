<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Authority\{AuthorityEntity, AuthorityState};
use NHK\Core\Domain\Graph\NodeReference;
use NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository;
use NHK\Core\Infrastructure\Graph\AuthorityEndpointResolver;
use NHK\Core\Infrastructure\Migration\AuthorityMigration002;
use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

final class P5CanonicalDomainIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('NHK_WP_TEST_PATH') === false) self::markTestSkipped('Set NHK_WP_TEST_PATH=public for WordPress integration tests.');
        require_once rtrim((string) getenv('NHK_WP_TEST_PATH'), '/') . '/wp-load.php';
        TestDatabaseGuard::selectTestDatabase();
        TestDatabaseGuard::requireTestDatabase();
        require_once dirname(__DIR__, 2) . '/nhk-core.php';
        do_action('rest_api_init');
        (new AuthorityMigration002())->up();
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'nhk_entities WHERE stable_key LIKE %s', 'p5-integration-%'));
    }

    public function test_all_canonical_types_persist_through_one_authority_contract_and_resolve_in_graph(): void
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $repository = new WpdbAuthorityRepository();
        $service = new AuthorityService($repository, $types);
        $resolver = new AuthorityEndpointResolver($types, $repository);
        $ids = [];

        foreach ($types->all() as $definition) {
            $entity = $service->create($definition->type, 'p5-integration-' . $definition->type, ucfirst($definition->type));
            $ids[] = $entity->canonicalId;
            $reference = $resolver->normalize(new NodeReference($definition->type, $entity->canonicalId));
            self::assertTrue($resolver->supports($definition->type));
            self::assertTrue($resolver->exists($reference));
            $retired = $service->retire($entity->canonicalId, 1);
            $updated = $retired;
            $reactivationRevision = 2;
            if ($definition->type === 'movement') {
                $updated = $service->update($retired->canonicalId, ['frequency_hz' => 28800.0], 2);
                self::assertSame($entity->canonicalId, $updated->canonicalId);
                $reactivationRevision = 3;
            }
            $reactivated = $service->reactivate($updated->canonicalId, $reactivationRevision);
            self::assertSame($entity->canonicalId, $reactivated->canonicalId);
            self::assertSame($definition->type === 'movement' ? 4 : 3, $reactivated->revision);
        }

        self::assertCount(9, array_unique($ids));
        foreach ($types->all() as $definition) self::assertNotNull($repository->findByStableKey($definition->type, 'p5-integration-' . $definition->type));
    }

    public function test_public_entity_api_filters_unregistered_payload_fields(): void
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $repository = new WpdbAuthorityRepository();
        $entity = new \NHK\Core\Domain\Authority\AuthorityEntity(
            \NHK\Core\Shared\Uuid\UuidCodec::newV7(),
            'brand',
            'p5-integration-public-payload',
            'Public payload',
            1,
            ['country' => 'Switzerland', 'private_note' => 'internal']
        );
        $repository->create($entity);
        $response = rest_do_request(new \WP_REST_Request('GET', '/nhk/v1/entity/brand/' . $entity->canonicalId));

        self::assertSame(200, $response->get_status());
        self::assertSame(['country' => 'Switzerland'], $response->get_data()['payload']);
        self::assertArrayNotHasKey('active', $response->get_data());
        self::assertArrayNotHasKey('revision', $response->get_data());
    }

    public function test_public_entity_api_list_excludes_retired_entities_before_pagination(): void
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $repository = new WpdbAuthorityRepository();
        $service = new AuthorityService($repository, $types);
        $baseline = count($repository->listByType('brand'));
        $active = $service->create('brand', 'p5-integration-active-list', 'Active list item');
        $retired = $service->create('brand', 'p5-integration-retired-list', 'Retired list item');
        $service->retire($retired->canonicalId, 1);

        $request = new \WP_REST_Request('GET', '/nhk/v1/entity/brand');
        $request->set_param('page', 1);
        $request->set_param('per_page', 100);
        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status());
        $data = $response->get_data();
        self::assertSame($baseline + 1, $data['total']);
        self::assertContains($active->canonicalId, array_column($data['items'], 'id'));
        self::assertNotContains($retired->canonicalId, array_column($data['items'], 'id'));
    }

    public function test_authority_repository_rejects_duplicate_identity_with_changed_state(): void
    {
        $repository = new WpdbAuthorityRepository();
        $entity = new AuthorityEntity(
            \NHK\Core\Shared\Uuid\UuidCodec::newV7(),
            'brand',
            'p5-integration-repository-conflict-' . bin2hex(random_bytes(4)),
            'Repository conflict',
            1,
            ['country' => 'Switzerland'],
        );
        $repository->create($entity);

        try {
            $repository->create(new AuthorityEntity(
                $entity->canonicalId,
                $entity->entityType,
                $entity->stableKey,
                $entity->canonicalName,
                $entity->schemaVersion,
                $entity->payload,
                AuthorityState::RETIRED,
                $entity->revision,
            ));
            self::fail('Expected a same-identity Authority entity with changed state to be rejected.');
        } catch (\NHK\Core\Authority\Exception\StableKeyCollision $exception) {
            self::assertSame('Stable key already exists.', $exception->getMessage());
        } finally {
            global $wpdb;
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'nhk_entities WHERE canonical_uuid=%s', \NHK\Core\Shared\Uuid\UuidCodec::toBinary($entity->canonicalId)));
        }
    }
}
