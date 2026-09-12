<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Entity\{EntityProfilePublicDossier, EntityProfileReadFoundation, EntityProfileRegistry, EntityProfileResolver};
use NHK\Core\Application\PublicIdentity\{RootPublicEntityRouteResolver, RootRouteCollisionPolicy};
use NHK\Core\Contracts\Entity\EntityDossierReader;
use NHK\Core\Contracts\PublicIdentity\{RootPublicIdentityReader, RootRouteOwnershipReader};
use NHK\Core\Domain\Authority\{AuthorityEntity, AuthorityState};
use NHK\Core\Infrastructure\PublicIdentity\WordPressRootRouteOwnershipReader;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class RootPublicEntityRouteResolverTest extends TestCase
{
    public function test_existing_brand_root_identity_resolves_to_brand_profile_without_slug_guessing(): void
    {
        $brand = $this->entity('brand', 'Odo', []);
        $identities = new FixtureRootIdentityReader([$this->identity($brand, 'odo')]);
        $resolver = $this->resolver([$brand], $identities, new FixtureRootRouteOwnershipReader());

        $result = $resolver->resolve('odo');

        self::assertSame('AVAILABLE', $result['status']);
        self::assertSame('/odo/', $result['path']);
        self::assertSame($brand->canonicalId, $result['entity']->canonicalId);
        self::assertSame('brand', $result['profile_resolution']['profile_key']);
        self::assertSame('/odo/', $result['dossier']['identity']['url']);
        self::assertSame('/odo/', $result['dossier']['seo_projection']['canonical']);
    }

    public function test_existing_canonical_clock_type_root_identity_resolves_from_entity_metadata(): void
    {
        $type = $this->entity('classification', 'Đồng hồ vai bò', ['family' => 'clock_type']);
        $resolver = $this->resolver([$type], new FixtureRootIdentityReader([$this->identity($type, 'vai-bo')]), new FixtureRootRouteOwnershipReader());

        $result = $resolver->resolve('vai-bo');

        self::assertSame('AVAILABLE', $result['status']);
        self::assertSame($type->canonicalId, $result['entity']->canonicalId);
        self::assertSame('clock_type', $result['profile_resolution']['profile_key']);
        self::assertSame('canonical', $result['profile_resolution']['family_state']);
        self::assertSame('clock_type', $result['dossier']['entity_profile']['key']);
    }

    public function test_legacy_family_is_read_compatibly_and_diagnostic_is_retained_without_mutation(): void
    {
        $type = $this->entity('classification', 'Đồng hồ chim cúc cu', ['family' => 'clock-type']);
        $before = $type->payload;
        $resolver = $this->resolver([$type], new FixtureRootIdentityReader([$this->identity($type, 'dong-ho-chim-cuc-cu')]), new FixtureRootRouteOwnershipReader());

        $result = $resolver->resolve('dong-ho-chim-cuc-cu');

        self::assertSame('AVAILABLE', $result['status']);
        self::assertSame('COMPATIBILITY_READ', $result['profile_resolution']['status']);
        self::assertSame('legacy-compatible', $result['profile_resolution']['family_state']);
        self::assertContains('DATA_COMPATIBILITY_GAP', $result['dossier']['diagnostics']);
        self::assertSame($before, $type->payload);
    }

    public function test_case_form_is_not_clock_type_even_when_name_and_slug_match(): void
    {
        $caseForm = $this->entity('classification', 'Đồng hồ vai bò', ['family' => 'case_form']);
        $resolver = $this->resolver([$caseForm], new FixtureRootIdentityReader([$this->identity($caseForm, 'vai-bo')]), new FixtureRootRouteOwnershipReader());

        $result = $resolver->resolve('vai-bo');

        self::assertSame('BLOCKED_PUBLIC_ELIGIBILITY', $result['status']);
        self::assertSame('PROFILE_UNRESOLVED', $result['reason']);
        self::assertSame('FAMILY_NOT_CLOCK_TYPE', $result['profile_resolution']['diagnostic']);
    }

    public function test_missing_public_identity_never_generates_a_url_from_name(): void
    {
        $type = $this->entity('classification', 'Đồng hồ công cộng', ['family' => 'clock_type']);
        $resolver = $this->resolver([$type], new FixtureRootIdentityReader(), new FixtureRootRouteOwnershipReader());

        $result = $resolver->resolve('dong-ho-cong-cong');

        self::assertSame('BLOCKED_PUBLIC_ELIGIBILITY', $result['status']);
        self::assertSame('MISSING_PUBLIC_IDENTITY', $result['reason']);
        self::assertNull($result['path']);
    }

    public function test_current_classification_identity_with_legacy_namespaced_path_is_not_reprojected_to_root(): void
    {
        $type = $this->entity('classification', 'Đồng hồ vai bò', ['family' => 'clock_type']);
        $identity = $this->identity($type, 'vai-bo');
        $identity['current_path'] = '/phan-loai/vai-bo/';
        $resolver = $this->resolver([$type], new FixtureRootIdentityReader([$identity]), new FixtureRootRouteOwnershipReader());

        $result = $resolver->resolve('vai-bo');

        self::assertSame('BLOCKED_PUBLIC_ELIGIBILITY', $result['status']);
        self::assertSame('PUBLIC_IDENTITY_NOT_ROOT_CANONICAL', $result['reason']);
        self::assertNull($result['path']);
    }

    public function test_brandless_clock_type_dossier_is_valid_with_empty_brand_relation(): void
    {
        $type = $this->entity('classification', 'Đồng hồ công cộng', ['family' => 'clock_type']);
        $resolver = $this->resolver([$type], new FixtureRootIdentityReader([$this->identity($type, 'dong-ho-cong-cong')]), new FixtureRootRouteOwnershipReader());

        $result = $resolver->resolve('dong-ho-cong-cong');

        self::assertSame('AVAILABLE', $result['status']);
        self::assertSame([], $result['dossier']['relation_sections']['brands']);
        self::assertSame('UNAVAILABLE_IMPLEMENTATION_GAP', $result['dossier']['public_dossier']['sections']['brands']['status']);
        self::assertStringNotContainsString('Unknown Brand', json_encode($result['dossier'], JSON_THROW_ON_ERROR));
    }

    public function test_global_root_collisions_fail_closed_for_entities_pages_reserved_routes_and_registered_routes(): void
    {
        $brand = $this->entity('brand', 'Odo', []);
        $cases = [
            'entity' => [['kind' => 'authority', 'identity_id' => 'other-identity']],
            'page' => [['kind' => 'wp_page', 'owner_id' => '42']],
            'registered' => [['kind' => 'registered_route', 'owner_id' => 'router']],
        ];
        foreach ($cases as $slug => $owners) {
            $reader = new FixtureRootRouteOwnershipReader(['/' . $slug . '/' => $owners]);
            $identity = $this->identity($brand, $slug);
            $result = $this->resolver([$brand], new FixtureRootIdentityReader([$identity]), $reader)->resolve($slug);
            self::assertSame('BLOCKED', $result['status'], $slug);
            self::assertSame('PUBLIC_SLUG_CONFLICT', $result['reason'], $slug);
            self::assertNull($result['path'], $slug);
        }

        $reserved = $this->resolver([$brand], new FixtureRootIdentityReader(), new FixtureRootRouteOwnershipReader(['/video/' => [['kind' => 'reserved_route']]]))->resolve('video');
        self::assertSame('BLOCKED', $reserved['status']);
        self::assertSame('PUBLIC_SLUG_CONFLICT', $reserved['reason']);
    }

    public function test_route_registry_gap_is_not_reported_as_an_empty_or_claimable_root(): void
    {
        $brand = $this->entity('brand', 'Odo', []);
        $resolver = $this->resolver([$brand], new FixtureRootIdentityReader([$this->identity($brand, 'odo')]), new UnavailableRootRouteOwnershipReader());

        $result = $resolver->resolve('odo');

        self::assertSame('UNAVAILABLE_IMPLEMENTATION_GAP', $result['status']);
        self::assertSame('ROOT_ROUTE_REGISTRY_UNAVAILABLE', $result['reason']);
        self::assertNull($result['path']);
    }

    public function test_root_foundation_does_not_intercept_video_detail_path(): void
    {
        $brand = $this->entity('brand', 'video', []);
        $resolver = $this->resolver([$brand], new FixtureRootIdentityReader([$this->identity($brand, 'video-video-a')]), new FixtureRootRouteOwnershipReader());

        $result = $resolver->resolve('video/video-a');

        self::assertSame('BLOCKED_PUBLIC_ELIGIBILITY', $result['status']);
        self::assertSame('PUBLIC_SLUG_INVALID', $result['reason']);
    }

    public function test_wordpress_route_owner_reuses_reserved_registry_and_fails_closed_without_global_registry(): void
    {
        $reader = new WordPressRootRouteOwnershipReader();

        foreach (['video', 'anh', 'loai-dong-ho'] as $slug) {
            $inspection = $reader->inspect('/' . $slug . '/');
            self::assertSame('AVAILABLE', $inspection['status'], $slug);
            self::assertSame('reserved_route', $inspection['owners'][0]['kind'], $slug);
        }

        self::assertSame('UNAVAILABLE', $reader->inspect('/odo/')['status']);
    }

    /** @param list<AuthorityEntity> $entities */
    private function resolver(array $entities, RootPublicIdentityReader $identities, RootRouteOwnershipReader $ownership): RootPublicEntityRouteResolver
    {
        $authority = new InMemoryAuthorityRepository();
        foreach ($entities as $entity) $authority->create($entity);
        $foundation = new EntityProfileReadFoundation(new EntityProfileRegistry(), new EntityProfileResolver(), new RootFixtureDossierReader());
        return new RootPublicEntityRouteResolver($identities, $authority, new EntityProfileResolver(), new EntityProfilePublicDossier($foundation), new RootRouteCollisionPolicy($ownership));
    }

    private function entity(string $type, string $name, array $payload): AuthorityEntity
    {
        return new AuthorityEntity(UuidCodec::newV7(), $type, 'nhk:' . $type . ':' . strtolower((string) random_int(1000, 9999)), $name, 1, $payload, AuthorityState::ACTIVE, 1);
    }

    /** @return array<string,mixed> */
    private function identity(AuthorityEntity $entity, string $slug): array
    {
        return ['identity_id' => UuidCodec::newV7(), 'owner_kind' => 'authority', 'owner_id' => $entity->canonicalId, 'route_type' => $entity->entityType, 'current_slug' => $slug, 'current_path' => '/' . $slug . '/'];
    }
}

final class FixtureRootIdentityReader implements RootPublicIdentityReader
{
    /** @param list<array<string,mixed>> $records */
    public function __construct(private array $records = []) {}
    public function findCurrentByRootSlug(string $slug): array { return array_values(array_filter($this->records, static fn (array $record): bool => (string) ($record['current_slug'] ?? '') === $slug)); }
}

final class FixtureRootRouteOwnershipReader implements RootRouteOwnershipReader
{
    /** @param array<string,list<array<string,mixed>>> $owners */
    public function __construct(private array $owners = []) {}
    public function inspect(string $path): array { return ['status' => 'AVAILABLE', 'owners' => $this->owners[$path] ?? [], 'source' => 'fixture']; }
}

final class UnavailableRootRouteOwnershipReader implements RootRouteOwnershipReader
{
    public function inspect(string $path): array { return ['status' => 'UNAVAILABLE', 'owners' => [], 'source' => 'fixture-gap']; }
}

final class RootFixtureDossierReader implements EntityDossierReader
{
    public function forEntity(AuthorityEntity $entity): array
    {
        return [
            'status' => 'AVAILABLE',
            'identity' => ['type' => $entity->entityType, 'name' => $entity->canonicalName, 'url' => '/phan-loai/legacy/'],
            'seo_projection' => ['canonical' => '/phan-loai/legacy/', 'sitemap' => '/phan-loai/legacy/', 'breadcrumb' => '/phan-loai/legacy/', 'card' => '/phan-loai/legacy/', 'search' => '/phan-loai/legacy/', 'internal_link' => '/phan-loai/legacy/', 'open_graph' => ['url' => '/phan-loai/legacy/'], 'json_ld' => ['url' => '/phan-loai/legacy/', '@id' => '/phan-loai/legacy/', 'mainEntityOfPage' => '/phan-loai/legacy/']],
            'knowledge' => ['status' => 'AVAILABLE', 'facets' => [], 'claim_count' => 0, 'evidence_count' => 0],
            'relation_sections' => ['brands' => [], 'classifications' => [], 'models' => [], 'variants' => [], 'specimens' => [], 'products' => [], 'videos' => [], 'articles' => []],
            'media_gallery' => [],
            'diagnostics' => [],
            'warnings' => [],
            'availability' => ['graph' => 'AVAILABLE', 'knowledge' => 'AVAILABLE'],
        ];
    }
}
