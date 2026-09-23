<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\{CanonicalAuthoritySubjectResolver, SubjectResolutionService, SubjectStructuralContextReader};
use NHK\Core\Domain\Authority\{AuthorityEntity, CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class HierarchicalSubjectResolutionTest extends TestCase
{
    public function test_composite_lookup_reuses_one_existing_variant(): void
    {
        $repo = new InMemoryAuthorityRepository(); $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $brand = '99999999-9999-4999-8999-999999999999'; $variant = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $repo->create(new AuthorityEntity($brand, 'brand', 'nhk:brand:alpha', 'Brand Alpha', 1, ['aliases' => []]));
        $repo->create(new AuthorityEntity($variant, 'variant', 'nhk:variant:alpha.x.y', 'Brand Alpha X/Y', 1, ['reference' => 'X/Y', 'aliases' => []]));
        $matches = (new CanonicalAuthoritySubjectResolver($repo, $types))->resolveComposite(['Brand Alpha', 'X/Y']);
        self::assertCount(1, $matches); self::assertSame($variant, $matches[0]['id']); self::assertSame('composite_explicit_hint', $matches[0]['match']);
    }

    public function test_compatible_parent_does_not_compete_with_narrowest_variant(): void
    {
        $brand = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'; $variant = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $reader = new class($brand, $variant) implements SubjectStructuralContextReader {
            public function __construct(private string $brand, private string $variant) {}
            public function contextFor(array $candidate): array { return (string) ($candidate['id'] ?? '') === $this->variant ? ['status' => 'available', 'candidate' => $candidate, 'ancestors' => [['id' => $this->brand, 'type' => 'brand']], 'relation_path' => ['variant_of', 'model_of'], 'reasons' => [], 'warnings' => []] : ['status' => 'available', 'candidate' => $candidate, 'ancestors' => [], 'relation_path' => [], 'reasons' => [], 'warnings' => []]; }
        };
        $service = new SubjectResolutionService(static fn (string $hint): array => match ($hint) {
            'Brand Alpha' => [['id' => $brand, 'type' => 'brand', 'name' => 'Brand Alpha', 'revision' => 2]],
            'Brand Alpha X/Y', 'X/Y' => [['id' => $variant, 'type' => 'variant', 'name' => 'Brand Alpha X/Y', 'revision' => 5]],
            default => [],
        }, $reader);
        $result = $service->resolveSources(['subject_hints' => ['Brand Alpha', 'Brand Alpha X/Y', 'X/Y']]);
        self::assertSame('resolved', $result['status']); self::assertSame($variant, $result['primary']['id']); self::assertSame([$brand], array_column($result['compatible_context'], 'id')); self::assertNotContains('PRIMARY_SUBJECT_AMBIGUOUS', $result['diagnostics']);
    }
}
