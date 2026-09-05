<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Entity\{PublicIdentityContract, PublicRouteResolver};
use NHK\Core\Contracts\PublicIdentity\PublicIdentityRepository;
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class PersistedPublicIdentityRouteTest extends TestCase
{
    public function test_persisted_slug_wins_over_changed_display_name(): void
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $authorityRepo = new InMemoryAuthorityRepository();
        $authority = new AuthorityService($authorityRepo, $types);
        $brand = $authority->create('brand', 'nhk:brand:freeze', 'Tên cũ');
        $identities = new FrozenIdentityRepository([
            'authority|' . $brand->canonicalId . '|brand' => ['current_slug' => 'ten-cu'],
        ]);
        $resolver = new PublicRouteResolver($authorityRepo, $types, null, null, $identities);
        $identity = new PublicIdentityContract($types, $identities);

        $renamed = $authority->rename($brand->canonicalId, 'Tên mới hoàn toàn', 1);

        self::assertSame('/ten-cu/', $resolver->path($renamed));
        self::assertSame('ten-cu', $identity->resolve($renamed)['slug']);
        self::assertSame($renamed->canonicalId, $resolver->resolve('brand', ['ten-cu'])?->canonicalId);
        self::assertNull($resolver->resolve('brand', ['ten-moi-hoan-toan']));
    }

    public function test_nested_model_and_variant_paths_use_each_persisted_slug_segment(): void
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $authorityRepo = new InMemoryAuthorityRepository();
        $authority = new AuthorityService($authorityRepo, $types);
        $brand = $authority->create('brand', 'nhk:brand:freeze-nested', 'Brand mới');
        $model = $authority->create('model', 'nhk:model:freeze-nested', 'Model mới', ['brand_uuid' => $brand->canonicalId]);
        $variant = $authority->create('variant', 'nhk:variant:freeze-nested', 'Variant mới', ['model_uuid' => $model->canonicalId]);
        $identities = new FrozenIdentityRepository([
            'authority|' . $brand->canonicalId . '|brand' => ['current_slug' => 'brand-cu'],
            'authority|' . $model->canonicalId . '|model' => ['current_slug' => 'model-cu'],
            'authority|' . $variant->canonicalId . '|variant' => ['current_slug' => 'variant-cu'],
        ]);
        $resolver = new PublicRouteResolver($authorityRepo, $types, null, null, $identities);

        self::assertSame('/brand-cu/model-cu/', $resolver->path($model));
        self::assertSame('/brand-cu/model-cu/variant-cu/', $resolver->path($variant));
        self::assertSame($variant->canonicalId, $resolver->resolve('variant', ['brand-cu', 'model-cu', 'variant-cu'])?->canonicalId);
    }
}

final class FrozenIdentityRepository implements PublicIdentityRepository
{
    public function __construct(private array $owners) {}
    public function allocate(array $record, string $idempotencyKey): array { return $record; }
    public function change(array $record, string $oldPath, int $expectedRevision, string $idempotencyKey): array { return $record; }
    public function findCurrentById(string $identityId): ?array { return null; }
    public function findCurrentByOwner(string $ownerKind, string $ownerId, string $routeType): ?array { return $this->owners[$ownerKind . '|' . $ownerId . '|' . $routeType] ?? null; }
    public function slugExists(string $routeType, string $scope, string $slug, ?string $excludeIdentityId = null): bool { return false; }
    public function resolveHistoric(string $path): array { return ['status' => 'NOT_FOUND']; }
}
