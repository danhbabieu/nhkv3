<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
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
}
