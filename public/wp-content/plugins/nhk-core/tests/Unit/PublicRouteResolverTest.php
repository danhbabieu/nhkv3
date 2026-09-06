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
    private function resolver(InMemoryAuthorityRepository &$repository, ?EntityTypeRegistry &$types = null): PublicRouteResolver
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $repository = new InMemoryAuthorityRepository();
        return new PublicRouteResolver($repository, $types);
    }

    public function test_brand_model_and_variant_use_public_slug_hierarchy(): void
    {
        $repository = new InMemoryAuthorityRepository(); $types = null;
        $resolver = $this->resolver($repository, $types);
        $authority = new AuthorityService($repository, $types);
        $brand = $authority->create('brand', 'nhk:brand:odo', 'Ô Đô');
        $model = $authority->create('model', 'nhk:model:odo-36', 'Ô Đô 36', ['brand_uuid' => $brand->canonicalId]);
        $variant = $authority->create('variant', 'nhk:variant:odo-36-8', 'Ô Đô 36 8', ['model_uuid' => $model->canonicalId]);

        self::assertSame('/odo/', $resolver->path($brand));
        self::assertSame('/odo/odo-36/', $resolver->path($model));
        self::assertSame('/odo/odo-36/odo-36-8/', $resolver->path($variant));
        self::assertSame('/bo-may/', $resolver->archivePath('movement'));
    }

    public function test_reserved_root_and_ambiguous_public_slug_fail_closed(): void
    {
        $repository = new InMemoryAuthorityRepository(); $types = null;
        $resolver = $this->resolver($repository, $types);
        $authority = new AuthorityService($repository, $types);
        $reserved = $authority->create('brand', 'nhk:brand:reserved', 'Video');
        $authority->create('brand', 'nhk:brand:first', 'Shared');
        $authority->create('brand', 'nhk:brand:second', 'Shared');

        self::assertNull($resolver->path($reserved));
        self::assertNull($resolver->resolve('brand', ['shared']));
    }

    public function test_slug_contract_is_shared_and_collision_safe_for_siblings(): void
    {
        $repository = new InMemoryAuthorityRepository(); $types = null;
        $resolver = $this->resolver($repository, $types);
        $authority = new AuthorityService($repository, $types);
        $brand = $authority->create('brand', 'nhk:brand:slug', 'Vê Đét');
        $first = $authority->create('model', 'nhk:model:first', 'Mẫu Chung', ['brand_uuid' => $brand->canonicalId]);
        $second = $authority->create('model', 'nhk:model:second', 'Mẫu Chung', ['brand_uuid' => $brand->canonicalId]);

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

    public function test_brand_and_model_archives_use_vietnamese_hubs_and_all_hubs_are_reserved(): void
    {
        $repository = new InMemoryAuthorityRepository(); $types = null;
        $resolver = $this->resolver($repository, $types);

        self::assertSame('/thuong-hieu/', $resolver->archivePath('brand'));
        self::assertSame('/mau/', $resolver->archivePath('model'));
        foreach (['thuong-hieu', 'mau', 'bo-may', 'ban-nhac', 'linh-kien', 'phan-loai', 'tri-thuc', 'hien-vat', 'san-pham', 'video', 'so-sanh', 'goc-chia-se', 'wp-admin', 'wp-json', 'feed', 'search', 'sitemap'] as $root) {
            self::assertContains($root, PublicRouteResolver::reservedRoots());
        }
    }

    public function test_brand_route_fails_closed_when_a_native_wordpress_root_exists(): void
    {
        $repository = new InMemoryAuthorityRepository(); $types = null;
        $this->resolver($repository, $types);
        $authority = new AuthorityService($repository, $types);
        $brand = $authority->create('brand', 'nhk:brand:foo', 'Foo');
        $resolver = new PublicRouteResolver($repository, $types, null, static fn (string $slug): bool => $slug === 'foo');

        self::assertNull($resolver->path($brand));
        self::assertNotNull($resolver->resolve('brand', ['foo']), 'Incoming collision resolution remains available to the HTTP boundary so it can emit IDENTITY_CONFLICT.');
    }
}
