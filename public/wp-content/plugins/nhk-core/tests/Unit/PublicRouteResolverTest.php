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
}
