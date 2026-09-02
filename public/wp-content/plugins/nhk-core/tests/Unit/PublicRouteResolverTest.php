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

        self::assertSame('/o-do/', $resolver->path($brand));
        self::assertSame('/o-do/o-do-36/', $resolver->path($model));
        self::assertSame('/o-do/o-do-36/o-do-36-8/', $resolver->path($variant));
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
