<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Entity\PublicRouteResolver;
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class PublicRouteResolverTest extends TestCase
{
    private function resolver(InMemoryAuthorityRepository &$repository, ?EntityTypeRegistry &$types = null, ?TestPublicIdentityRepository &$identity = null): PublicRouteResolver
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $repository = new InMemoryAuthorityRepository();
        $identity = new TestPublicIdentityRepository();
        return new PublicRouteResolver($repository, $types, null, null, $identity);
    }

    public function test_brand_model_and_variant_use_public_slug_hierarchy(): void
    {
        $repository = new InMemoryAuthorityRepository(); $types = null; $identity = null;
        $resolver = $this->resolver($repository, $types, $identity);
        $authority = new AuthorityService($repository, $types);
        $brand = $authority->create('brand', 'nhk:brand:odo', 'Ô Đô');
        $model = $authority->create('model', 'nhk:model:odo-36', 'Ô Đô 36', ['brand_uuid' => $brand->canonicalId]);
        $variant = $authority->create('variant', 'nhk:variant:odo-36-8', 'Ô Đô 36 8', ['model_uuid' => $model->canonicalId]);
        $this->persist($identity, $brand, 'odo', 'root'); $this->persist($identity, $model, 'odo-36', 'brand:' . $brand->canonicalId); $this->persist($identity, $variant, 'odo-36-8', 'model:' . $model->canonicalId);

        self::assertSame('/odo/', $resolver->path($brand));
        self::assertSame('/odo/odo-36/', $resolver->path($model));
        self::assertSame('/odo/odo-36/odo-36-8/', $resolver->path($variant));
        self::assertSame('/bo-may/', $resolver->archivePath('movement'));
    }

    public function test_reserved_root_and_ambiguous_public_slug_fail_closed(): void
    {
        $repository = new InMemoryAuthorityRepository(); $types = null; $identity = null;
        $resolver = $this->resolver($repository, $types, $identity);
        $authority = new AuthorityService($repository, $types);
        $reserved = $authority->create('brand', 'nhk:brand:reserved', 'Video');
        $authority->create('brand', 'nhk:brand:first', 'Shared');
        $authority->create('brand', 'nhk:brand:second', 'Shared');

        self::assertNull($resolver->path($reserved));
        self::assertNull($resolver->resolve('brand', ['shared']));
    }

    public function test_slug_contract_is_shared_and_collision_safe_for_siblings(): void
    {
        $repository = new InMemoryAuthorityRepository(); $types = null; $identity = null;
        $resolver = $this->resolver($repository, $types, $identity);
        $authority = new AuthorityService($repository, $types);
        $brand = $authority->create('brand', 'nhk:brand:slug', 'Vê Đét');
        $first = $authority->create('model', 'nhk:model:first', 'Mẫu Chung', ['brand_uuid' => $brand->canonicalId]);
        $second = $authority->create('model', 'nhk:model:second', 'Mẫu Chung', ['brand_uuid' => $brand->canonicalId]);
        $this->persist($identity, $brand, 've-det', 'root');

        self::assertSame('ve-det', PublicRouteResolver::slug(' Vê Đét '));
        self::assertNull($resolver->path($first));
        self::assertNull($resolver->path($second));
        self::assertSame('/ve-det/', $resolver->path($brand));
    }

    public function test_historical_vietnamese_o_do_slug_is_canonicalized_to_odo(): void
    {
        self::assertSame('odo', PublicRouteResolver::slug('Ô Đô'));
        self::assertSame('odo-36', PublicRouteResolver::slug('Ô Đô 36'));
        self::assertSame('kim-odo-54', PublicRouteResolver::slug('Kim Odo 54'));
        self::assertSame('odometer', PublicRouteResolver::slug('Odometer'));
    }

    public function test_canonical_slug_normalizes_full_vietnamese_unicode_before_ascii_cleanup(): void
    {
        foreach (['à','á','ả','ã','ạ','ă','ằ','ắ','ẳ','ẵ','ặ','â','ầ','ấ','ẩ','ẫ','ậ'] as $value) self::assertSame('a', PublicRouteResolver::slug($value), $value);
        foreach (['è','é','ẻ','ẽ','ẹ','ê','ề','ế','ể','ễ','ệ'] as $value) self::assertSame('e', PublicRouteResolver::slug($value), $value);
        foreach (['ì','í','ỉ','ĩ','ị'] as $value) self::assertSame('i', PublicRouteResolver::slug($value), $value);
        foreach (['ò','ó','ỏ','õ','ọ','ô','ồ','ố','ổ','ỗ','ộ','ơ','ờ','ớ','ở','ỡ','ợ'] as $value) self::assertSame('o', PublicRouteResolver::slug($value), $value);
        foreach (['ù','ú','ủ','ũ','ụ','ư','ừ','ứ','ử','ữ','ự'] as $value) self::assertSame('u', PublicRouteResolver::slug($value), $value);
        foreach (['ỳ','ý','ỷ','ỹ','ỵ'] as $value) self::assertSame('y', PublicRouteResolver::slug($value), $value);
        self::assertSame('d', PublicRouteResolver::slug('đ'));
        self::assertSame('tuoi-tho-o-xuong', PublicRouteResolver::slug('Tuổi thọ ở xưởng'));
        self::assertSame('u-o-tuoi', PublicRouteResolver::slug("u\u{031B} o\u{031B} tuo\u{0302}\u{0309}i"));
    }

    public function test_canonical_slug_normalizes_separators_and_public_nhk_token_only(): void
    {
        self::assertSame('a-b-c-d-e', PublicRouteResolver::slug('  A / B – C — D _ E  '));
        self::assertSame('a-b', PublicRouteResolver::slug('---A///___B---'));
        self::assertSame('nha-kho-tri-thuc', PublicRouteResolver::slug('NHK tri thức'));
        self::assertSame('tri-thuc-nha-kho', PublicRouteResolver::slug('tri thức nhk'));
        self::assertSame('nhkv3', PublicRouteResolver::slug('nhkv3'));
        self::assertSame('abcnhkxyz', PublicRouteResolver::slug('abcnhkxyz'));
        self::assertSame('ascii-slug-123', PublicRouteResolver::slug('ASCII Slug 123'));
    }

    public function test_collision_uses_meaningful_suffix_and_remains_deterministic(): void
    {
        $repository = new InMemoryAuthorityRepository(); $types = null;
        $resolver = $this->resolver($repository, $types);
        $authority = new AuthorityService($repository, $types);
        $brand = $authority->create('brand', 'nhk:brand:collision', 'Acme');
        $first = $authority->create('model', 'nhk:model:collision-a', 'Series', ['brand_uuid' => $brand->canonicalId, 'launch_year' => 1970]);
        $second = $authority->create('model', 'nhk:model:collision-b', 'Series', ['brand_uuid' => $brand->canonicalId, 'launch_year' => 1980]);
        $unique = $authority->create('model', 'nhk:model:unique', 'Unique', ['brand_uuid' => $brand->canonicalId, 'launch_year' => 1990]);

        self::assertSame('/acme/series-1970/', $resolver->path($first));
        self::assertSame('/acme/series-1980/', $resolver->path($second));
        self::assertSame('/acme/unique/', $resolver->path($unique));
        self::assertSame($first->canonicalId, $resolver->resolve('model', ['acme', 'series-1970'])?->canonicalId);
        self::assertSame('/acme/series-1970/', $resolver->path($first));
    }

    public function test_collision_without_meaningful_discriminator_is_reconciliation_problem(): void
    {
        $repository = new InMemoryAuthorityRepository(); $types = null;
        $resolver = $this->resolver($repository, $types);
        $authority = new AuthorityService($repository, $types);
        $brand = $authority->create('brand', 'nhk:brand:duplicate', 'Acme Duplicate');
        $first = $authority->create('model', 'nhk:model:duplicate-a', 'Same', ['brand_uuid' => $brand->canonicalId]);
        $second = $authority->create('model', 'nhk:model:duplicate-b', 'Same', ['brand_uuid' => $brand->canonicalId]);

        self::assertNull($resolver->path($first));
        self::assertNull($resolver->path($second));
        self::assertNull($resolver->resolve('model', ['acme-duplicate', 'same']));
    }

    public function test_every_registered_cross_brand_type_has_a_vietnamese_archive(): void
    {
        $repository = new InMemoryAuthorityRepository(); $types = null;
        $resolver = $this->resolver($repository, $types);
        foreach (['movement' => '/bo-may/', 'music' => '/ban-nhac/', 'component' => '/linh-kien/', 'classification' => '/phan-loai/', 'specimen' => '/hien-vat/', 'product' => '/san-pham/'] as $type => $path) {
            self::assertSame($path, $resolver->archivePath($type));
        }
    }

    public function test_clock_type_profile_has_registered_archive_intent_without_changing_generic_classification_archive(): void
    {
        $repository = new InMemoryAuthorityRepository(); $types = null;
        $resolver = $this->resolver($repository, $types);

        self::assertSame('/phan-loai/', $resolver->archivePath('classification'));
        self::assertSame('/loai-dong-ho/', $resolver->archivePathForProfile('clock_type'));
    }

    public function test_clock_type_profile_uses_prefixed_detail_routes_without_changing_semantic_identity(): void
    {
        $repository = new InMemoryAuthorityRepository(); $types = null;
        $resolver = $this->resolver($repository, $types);
        $authority = new AuthorityService($repository, $types);
        $brand = $authority->create('brand', 'nhk:brand:odo-route', 'Odo');
        $vaiBo = $authority->create('classification', 'nhk:classification:clock-type.vai-bo', 'Vai bò', ['family' => 'clock_type']);
        $congCong = $authority->create('classification', 'nhk:classification:clock-type.cong-cong', 'Đồng hồ công cộng', ['family' => 'clock_type']);
        $chimCucCu = $authority->create('classification', 'nhk:classification:clock-type.chim-cuc-cu', 'Chim cúc cu', ['family' => 'clock_type']);

        self::assertSame('/odo/', $resolver->path($brand));
        self::assertSame('/dong-ho-vai-bo/', $resolver->path($vaiBo));
        self::assertSame('/dong-ho-cong-cong/', $resolver->path($congCong));
        self::assertSame('/dong-ho-chim-cuc-cu/', $resolver->path($chimCucCu));
        self::assertStringNotContainsString('/dong-ho-dong-ho-cong-cong/', (string) $resolver->path($congCong));
        self::assertSame($congCong->canonicalId, $resolver->resolve('classification', ['dong-ho-cong-cong'])?->canonicalId);
        self::assertSame('classification', $congCong->entityType);
        self::assertSame('clock_type', $congCong->payload['family']);
        self::assertSame('nhk:classification:clock-type.cong-cong', $congCong->stableKey);
    }

    public function test_clock_type_slug_collisions_fail_closed_and_legacy_family_keeps_generic_route(): void
    {
        $repository = new InMemoryAuthorityRepository(); $types = null;
        $resolver = $this->resolver($repository, $types);
        $authority = new AuthorityService($repository, $types);
        $first = $authority->create('classification', 'nhk:classification:clock-type.vai-bo', 'Vai bò', ['family' => 'clock_type']);
        $second = $authority->create('classification', 'nhk:classification:clock-type.vai-bo-prefixed', 'Đồng hồ Vai bò', ['family' => 'clock_type']);
        $legacy = $authority->create('classification', 'nhk:classification:legacy-vai-bo', 'Đồng hồ Vai bò', ['family' => 'clock-type']);

        self::assertNull($resolver->path($first));
        self::assertNull($resolver->path($second));
        self::assertSame('/phan-loai/dong-ho-vai-bo/', $resolver->path($legacy));
        self::assertNull($resolver->resolve('classification', ['dong-ho-vai-bo']));

        $crossRepository = new InMemoryAuthorityRepository();
        $crossTypes = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($crossTypes);
        $crossAuthority = new AuthorityService($crossRepository, $crossTypes);
        $crossClock = $crossAuthority->create('classification', 'nhk:classification:clock-type.cross', 'Vai bò', ['family' => 'clock_type']);
        $crossBrand = $crossAuthority->create('brand', 'nhk:brand:clock-type-cross', 'Đồng hồ Vai bò');
        $crossResolver = new PublicRouteResolver($crossRepository, $crossTypes);

        self::assertNull($crossResolver->path($crossClock));
        self::assertNull($crossResolver->path($crossBrand));
    }

    public function test_persisted_clock_type_identity_remains_authoritative_without_auto_rewrite(): void
    {
        $repository = new InMemoryAuthorityRepository(); $types = null;
        $this->resolver($repository, $types);
        $authority = new AuthorityService($repository, $types);
        $entity = $authority->create('classification', 'nhk:classification:clock-type.persisted', 'Vai bò', ['family' => 'clock_type']);
        $identity = new class($entity->canonicalId) implements \NHK\Core\Contracts\PublicIdentity\PublicIdentityRepository {
            public function __construct(private string $ownerId) {}
            public function allocate(array $record, string $idempotencyKey): array { return []; }
            public function change(array $record, string $oldPath, int $expectedRevision, string $idempotencyKey): array { return []; }
            public function findCurrentById(string $identityId): ?array { return null; }
            public function findCurrentByOwner(string $ownerKind, string $ownerId, string $routeType): ?array
            { return $ownerId === $this->ownerId ? ['current_slug' => 'vai-bo', 'current_path' => '/phan-loai/vai-bo/'] : null; }
            public function slugExists(string $routeType, string $scope, string $slug, ?string $excludeIdentityId = null): bool { return false; }
            public function resolveHistoric(string $path): array { return []; }
        };

        $resolver = new PublicRouteResolver($repository, $types, null, null, $identity);

        self::assertSame('/phan-loai/vai-bo/', $resolver->path($entity));
        self::assertSame($entity->canonicalId, $resolver->resolve('classification', ['vai-bo'])?->canonicalId);
    }

    public function test_brand_and_model_archives_use_vietnamese_hubs_and_all_hubs_are_reserved(): void
    {
        $repository = new InMemoryAuthorityRepository(); $types = null;
        $resolver = $this->resolver($repository, $types);

        self::assertSame('/thuong-hieu/', $resolver->archivePath('brand'));
        self::assertSame('/mau/', $resolver->archivePath('model'));
        foreach (['thuong-hieu', 'mau', 'bo-may', 'ban-nhac', 'linh-kien', 'phan-loai', 'tri-thuc', 'hien-vat', 'san-pham', 'video', 'anh', 'loai-dong-ho', 'so-sanh', 'goc-chia-se', 'wp-admin', 'wp-json', 'feed', 'search', 'sitemap'] as $root) {
            self::assertContains($root, PublicRouteResolver::reservedRoots());
        }
    }

    public function test_brand_route_fails_closed_when_a_native_wordpress_root_exists(): void
    {
        $repository = new InMemoryAuthorityRepository(); $types = null;
        $this->resolver($repository, $types);
        $authority = new AuthorityService($repository, $types);
        $brand = $authority->create('brand', 'nhk:brand:foo', 'Foo');
        $identity = new TestPublicIdentityRepository();
        $this->persist($identity, $brand, 'foo', 'root');
        $resolver = new PublicRouteResolver($repository, $types, null, static fn (string $slug): bool => $slug === 'foo', $identity);

        self::assertNull($resolver->path($brand));
        self::assertNull($resolver->resolve('brand', ['foo']));
    }

    private function persist(TestPublicIdentityRepository $repository, \NHK\Core\Domain\Authority\AuthorityEntity $entity, string $slug, string $scope): void
    {
        $repository->identities[$entity->canonicalId] = new \NHK\Core\Domain\PublicIdentity\PublicIdentity('identity-' . $entity->canonicalId, 'authority', $entity->canonicalId, $entity->entityType, $slug, $scope, 'public-route-v1', 1);
    }
}
