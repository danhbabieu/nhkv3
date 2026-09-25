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

    public function test_exact_base_variant_beats_specialized_descendant_for_generic_composite_hints(): void
    {
        $brand = '66666666-6666-4666-8666-666666666666';
        $base = '77777777-7777-4777-8777-777777777777';
        $specialized = '88888888-8888-4888-8888-888888888888';
        [$resolver] = $this->resolver([
            new AuthorityEntity($brand, 'brand', 'nhk:brand:acme', 'Acme', 1, []),
            new AuthorityEntity($base, 'variant', 'nhk:variant:acme-42', 'Acme 42', 1, [
                'designation' => '42',
                'brand_uuid' => $brand,
            ]),
            new AuthorityEntity($specialized, 'variant', 'nhk:variant:acme-42-special', 'Acme 42 Special', 1, [
                'designation' => '42 Special',
                'brand_uuid' => $brand,
            ]),
        ]);

        $result = (new SubjectResolutionService($resolver, null, [$resolver, 'resolveComposite']))->resolve(['Acme', '42']);

        self::assertSame('resolved', $result['status']);
        self::assertSame($base, $result['primary']['id']);
        self::assertSame('variant', $result['primary']['type']);
    }

    public function test_explicit_specialized_designation_beats_base_variant(): void
    {
        $brand = '99999999-9999-4999-8999-999999999999';
        $base = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $specialized = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        [$resolver] = $this->resolver([
            new AuthorityEntity($brand, 'brand', 'nhk:brand:acme-special', 'Acme Special', 1, []),
            new AuthorityEntity($base, 'variant', 'nhk:variant:acme-special-42', 'Acme Special 42', 1, [
                'designation' => '42',
                'brand_uuid' => $brand,
            ]),
            new AuthorityEntity($specialized, 'variant', 'nhk:variant:acme-special-42-edition-b', 'Acme Special 42 Edition B', 1, [
                'designation' => '42 Edition B',
                'brand_uuid' => $brand,
            ]),
        ]);

        $result = (new SubjectResolutionService($resolver, null, [$resolver, 'resolveComposite']))->resolve(['Acme Special', '42 Edition B']);

        self::assertSame('resolved', $result['status']);
        self::assertSame($specialized, $result['primary']['id']);
    }

    public function test_exact_base_variant_name_wins_over_expanded_variants(): void
    {
        $base = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $specialized = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
        [$resolver] = $this->resolver([
            new AuthorityEntity($base, 'variant', 'nhk:variant:base-name', 'Base Name', 1, []),
            new AuthorityEntity($specialized, 'variant', 'nhk:variant:base-name-special', 'Base Name Special', 1, []),
        ]);

        $result = (new SubjectResolutionService($resolver, null, [$resolver, 'resolveComposite']))->resolve(['Base Name']);

        self::assertSame('resolved', $result['status']);
        self::assertSame($base, $result['primary']['id']);
    }

    public function test_canonical_name_beats_specialized_aliases_without_suppressing_equal_canonical_ambiguity(): void
    {
        $base = '16161616-1616-4616-8616-161616161616';
        $one = '17171717-1717-4717-8717-171717171717';
        $two = '18181818-1818-4818-8818-181818181818';
        [$resolver] = $this->resolver([
            new AuthorityEntity($base, 'variant', 'nhk:variant:base', 'Base Identity', 1, []),
            new AuthorityEntity($one, 'variant', 'nhk:variant:special-one', 'Special One', 1, ['aliases' => ['Base Identity']]),
            new AuthorityEntity($two, 'variant', 'nhk:variant:special-two', 'Special Two', 1, ['aliases' => ['Base Identity']]),
        ]);

        $result = (new SubjectResolutionService($resolver, null, [$resolver, 'resolveComposite']))->resolve(['Base Identity']);

        self::assertSame('resolved', $result['status']);
        self::assertSame($base, $result['primary']['id']);
        self::assertSame('composite_exact_identity', $result['primary']['match']);
    }

    public function test_prefix_of_multiple_specialized_variants_without_exact_base_is_ambiguous(): void
    {
        $one = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
        $two = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
        [$resolver] = $this->resolver([
            new AuthorityEntity($one, 'variant', 'nhk:variant:prefix-one', 'Base Name Special', 1, []),
            new AuthorityEntity($two, 'variant', 'nhk:variant:prefix-two', 'Base Name Edition B', 1, []),
        ]);

        $result = (new SubjectResolutionService($resolver, null, [$resolver, 'resolveComposite']))->resolve(['Base', 'Name']);

        self::assertSame('ambiguous', $result['status']);
        self::assertCount(2, $result['subjects']);
    }

    public function test_exact_specialized_uuid_remains_primary_with_compatible_base_hint(): void
    {
        $base = '12121212-1212-4212-8212-121212121212';
        $specialized = '13131313-1313-4313-8313-131313131313';
        [$resolver] = $this->resolver([
            new AuthorityEntity($base, 'variant', 'nhk:variant:uuid-base', 'Acme 42', 1, []),
            new AuthorityEntity($specialized, 'variant', 'nhk:variant:uuid-special', 'Acme 42 Special', 1, ['parent_uuid' => $base]),
        ]);

        $result = (new SubjectResolutionService($resolver, null, [$resolver, 'resolveComposite']))->resolveSources([
            'canonical_uuid' => [$specialized],
            'subject_hints' => ['Acme 42'],
        ]);

        self::assertSame('resolved', $result['status']);
        self::assertSame($specialized, $result['primary']['id']);
    }

    public function test_exact_base_uuid_conflicts_with_explicit_specialized_identity(): void
    {
        $base = '14141414-1414-4414-8414-141414141414';
        $specialized = '15151515-1515-4515-8515-151515151515';
        [$resolver] = $this->resolver([
            new AuthorityEntity($base, 'variant', 'nhk:variant:uuid-base-conflict', 'Acme 42', 1, []),
            new AuthorityEntity($specialized, 'variant', 'nhk:variant:uuid-special-conflict', 'Acme 42 Special', 1, ['parent_uuid' => $base]),
        ]);

        $result = (new SubjectResolutionService($resolver, null, [$resolver, 'resolveComposite']))->resolveSources([
            'canonical_uuid' => [$base],
            'subject_hints' => ['Acme 42 Special'],
        ]);

        self::assertSame('conflict', $result['status']);
        self::assertContains('SUBJECT_CONFLICT_REVIEW_REQUIRED', $result['diagnostics']);
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
