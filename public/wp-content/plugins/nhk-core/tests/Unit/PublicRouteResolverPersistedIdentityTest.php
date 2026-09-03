<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Entity\PublicRouteResolver;
use NHK\Core\Application\Entity\{EntityPageQuery, PublicEntityEligibilityPolicy};
use NHK\Core\Contracts\PublicIdentity\PublicIdentityRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\PublicIdentity\{HistoricPublicRoute, PublicIdentity, PublicIdentityMutationResult};
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class PublicRouteResolverPersistedIdentityTest extends TestCase
{
    public function test_all_nine_authority_types_project_from_persisted_slugs(): void
    {
        [$resolver, $authority, $identity, $authorityRepository] = $this->fixture();
        $brand = $authority->create('brand', 'brand-odo', 'Ô Đô');
        $model = $authority->create('model', 'model-36', 'Ô Đô 36', ['brand_uuid' => $brand->canonicalId]);
        $variant = $authority->create('variant', 'variant-8', 'Ô Đô 36 8', ['model_uuid' => $model->canonicalId]);
        $this->persist($identity, $brand, 'odo-persisted', 'root');
        $this->persist($identity, $model, 'model-renamed', 'brand:' . $brand->canonicalId);
        $this->persist($identity, $variant, 'variant-renamed', 'model:' . $model->canonicalId);

        $expected = [
            'brand' => '/odo-persisted/',
            'model' => '/odo-persisted/model-renamed/',
            'variant' => '/odo-persisted/model-renamed/variant-renamed/',
        ];
        self::assertSame($expected['brand'], $resolver->path($brand));
        self::assertSame($expected['model'], $resolver->path($model));
        self::assertSame($expected['variant'], $resolver->path($variant));

        foreach (['movement', 'music', 'component', 'classification', 'specimen', 'product'] as $type) {
            $entity = $authority->create($type, $type . '-1', ucfirst($type));
            $this->persist($identity, $entity, $type . '-persisted', 'namespace:' . $type);
            self::assertSame('/' . PublicRouteResolver::namespaceFor($type) . '/' . $type . '-persisted/', $resolver->path($entity));
        }
    }

    public function test_canonical_name_rename_does_not_change_persisted_route(): void
    {
        [$resolver, $authority, $identity, $authorityRepository] = $this->fixture();
        $entity = $authority->create('brand', 'brand-odo', 'Ô Đô');
        $this->persist($identity, $entity, 'stable-public-slug', 'root');
        $renamed = $authority->rename($entity->canonicalId, 'Tên hoàn toàn khác', 1);

        self::assertSame('/stable-public-slug/', $resolver->path($renamed));
    }

    public function test_missing_inactive_or_ambiguous_hierarchy_fails_closed(): void
    {
        [$resolver, $authority, $identity] = $this->fixture();
        $brand = $authority->create('brand', 'brand-1', 'Brand');
        $missing = $authority->create('model', 'model-missing', 'Model Missing', ['brand_uuid' => '550e8400-e29b-41d4-a716-446655440000']);
        $this->persist($identity, $brand, 'brand', 'root');
        $this->persist($identity, $missing, 'model-missing', 'brand:550e8400-e29b-41d4-a716-446655440000');
        self::assertNull($resolver->path($missing));

        $inactive = $authority->create('brand', 'brand-inactive', 'Inactive');
        $authority->retire($inactive->canonicalId, 1);
        $child = $authority->create('model', 'model-inactive', 'Model Inactive', ['brand_uuid' => $inactive->canonicalId]);
        $this->persist($identity, $inactive, 'inactive', 'root');
        $this->persist($identity, $child, 'child', 'brand:' . $inactive->canonicalId);
        self::assertNull($resolver->path($child));
    }

    public function test_same_child_slug_is_allowed_in_separate_parent_scopes(): void
    {
        [$resolver, $authority, $identity] = $this->fixture();
        $firstBrand = $authority->create('brand', 'brand-a', 'Brand A');
        $secondBrand = $authority->create('brand', 'brand-b', 'Brand B');
        $first = $authority->create('model', 'model-a', 'Same Name', ['brand_uuid' => $firstBrand->canonicalId]);
        $second = $authority->create('model', 'model-b', 'Same Name', ['brand_uuid' => $secondBrand->canonicalId]);
        foreach ([[$firstBrand, 'a'], [$secondBrand, 'b']] as [$brand, $slug]) $this->persist($identity, $brand, $slug, 'root');
        $this->persist($identity, $first, 'same', 'brand:' . $firstBrand->canonicalId);
        $this->persist($identity, $second, 'same', 'brand:' . $secondBrand->canonicalId);

        self::assertSame('/a/same/', $resolver->path($first));
        self::assertSame('/b/same/', $resolver->path($second));
    }

    public function test_native_wordpress_root_collision_blocks_brand_route(): void
    {
        [$resolver, $authority, $identity] = $this->fixture(static fn (string $slug): bool => $slug === 'owned');
        $brand = $authority->create('brand', 'brand-owned', 'Owned');
        $this->persist($identity, $brand, 'owned', 'root');

        self::assertNull($resolver->path($brand));
    }

    public function test_inbound_resolution_rejects_a_native_root_collision(): void
    {
        [$resolver, $authority, $identity] = $this->fixture(static fn (string $slug): bool => $slug === 'owned');
        $brand = $authority->create('brand', 'brand-owned', 'Owned');
        $this->persist($identity, $brand, 'owned', 'root');

        self::assertNull($resolver->resolve('brand', ['owned']));
    }

    public function test_entity_detail_and_eligibility_use_persisted_identity_not_display_name(): void
    {
        [$resolver, $authority, $identity, $authorityRepository] = $this->fixture();
        $brand = $authority->create('brand', 'brand-symbol', '!!!');
        $this->persist($identity, $brand, 'persisted-brand', 'root');

        self::assertTrue((new PublicEntityEligibilityPolicy($authorityRepository, $resolver->types(), $resolver))->evaluate($brand)->eligible);
        self::assertSame('/persisted-brand/', (new EntityPageQuery($authorityRepository, $resolver->types(), null, null, $resolver))->detail('brand', 'persisted-brand')['url']);
    }

    /** @return array{PublicRouteResolver,AuthorityService,TestPublicIdentityRepository,InMemoryAuthorityRepository} */
    private function fixture(?\Closure $nativeRootExists = null): array
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $authorityRepository = new InMemoryAuthorityRepository();
        $identity = new TestPublicIdentityRepository();
        return [new PublicRouteResolver($authorityRepository, $types, null, $nativeRootExists, $identity), new AuthorityService($authorityRepository, $types), $identity, $authorityRepository];
    }

    private function persist(TestPublicIdentityRepository $repository, AuthorityEntity $entity, string $slug, string $scope): void
    {
        $repository->identities[$entity->canonicalId] = new PublicIdentity('identity-' . $entity->canonicalId, 'authority', $entity->canonicalId, $entity->entityType, $slug, $scope, 'public-route-v1', 1);
    }
}

final class TestPublicIdentityRepository implements PublicIdentityRepository
{
    /** @var array<string,PublicIdentity> */
    public array $identities = [];

    public function findByOwner(string $ownerKind, string $ownerId): ?PublicIdentity
    {
        $identity = $this->identities[$ownerId] ?? null;
        return $identity && $identity->ownerKind === $ownerKind ? $identity : null;
    }
    public function findByRoute(string $routeType, string $collisionScope, string $slug): ?PublicIdentity
    {
        foreach ($this->identities as $identity) if ($identity->routeType === $routeType && $identity->collisionScope === $collisionScope && $identity->currentSlug === $slug) return $identity;
        return null;
    }
    public function create(PublicIdentity $identity): PublicIdentityMutationResult { return PublicIdentityMutationResult::accepted($identity); }
    public function update(PublicIdentity $identity, int $expectedRevision): PublicIdentityMutationResult { return PublicIdentityMutationResult::accepted($identity); }
    public function appendHistoricRoute(HistoricPublicRoute $historicRoute): PublicIdentityMutationResult { return PublicIdentityMutationResult::acceptedHistoricRoute($historicRoute); }
}
