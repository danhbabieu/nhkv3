<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\{CanonicalAuthoritySubjectResolver, SubjectResolutionService};
use NHK\Core\Domain\Authority\{AuthorityEntity, CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class HierarchicalSubjectResolutionVerificationTest extends TestCase
{
    public function test_brand_only_is_resolved_as_the_existing_brand(): void
    {
        $brand = '11111111-1111-4111-8111-111111111111';
        [$resolver] = $this->resolver([new AuthorityEntity($brand, 'brand', 'nhk:brand:acme', 'Acme', 1, [])]);
        $result = (new SubjectResolutionService($resolver))->resolve(['Acme']);
        self::assertSame('resolved', $result['status']);
        self::assertSame($brand, $result['primary']['id']);
        self::assertSame('brand', $result['primary']['type']);
    }

    public function test_brand_and_designation_composite_resolves_existing_variant_only(): void
    {
        $brand = '22222222-2222-4222-8222-222222222222';
        $variant = '33333333-3333-4333-8333-333333333333';
        [$resolver] = $this->resolver([
            new AuthorityEntity($brand, 'brand', 'nhk:brand:acme', 'Acme', 1, []),
            new AuthorityEntity($variant, 'variant', 'nhk:variant:acme-42', 'Acme 42', 1, ['designation' => '42', 'brand_uuid' => $brand]),
        ]);
        $service = new SubjectResolutionService($resolver, null, [$resolver, 'resolveComposite']);
        $result = $service->resolve(['Acme', '42']);
        self::assertSame('resolved', $result['status']);
        self::assertSame($variant, $result['primary']['id']);
    }

    public function test_shared_designation_without_brand_context_is_ambiguous(): void
    {
        $one = '44444444-4444-4444-8444-444444444444';
        $two = '55555555-5555-4555-8555-555555555555';
        [$resolver] = $this->resolver([
            new AuthorityEntity($one, 'variant', 'nhk:variant:one-42', 'One 42', 1, ['designation' => '42']),
            new AuthorityEntity($two, 'variant', 'nhk:variant:two-42', 'Two 42', 1, ['designation' => '42']),
        ]);
        $result = (new SubjectResolutionService($resolver, null, [$resolver, 'resolveComposite']))->resolve(['42']);
        self::assertSame('ambiguous', $result['status']);
        self::assertCount(2, $result['subjects']);
    }

    /** @param list<AuthorityEntity> $entities @return array{0:CanonicalAuthoritySubjectResolver} */
    private function resolver(array $entities): array
    {
        $repository = new InMemoryAuthorityRepository();
        foreach ($entities as $entity) $repository->create($entity);
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        return [new CanonicalAuthoritySubjectResolver($repository, $types)];
    }
}
