<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\McpSemanticContextResolver;
use NHK\Core\Domain\Authority\{AuthorityEntity, CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class McpSemanticContextResolverTest extends TestCase
{
    public function test_resolves_exact_uuid_before_name_and_reports_ambiguous_name_candidates(): void
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $repository = new InMemoryAuthorityRepository();
        $one = new AuthorityEntity('11111111-1111-4111-8111-111111111111', 'brand', 'brand:odo', 'Odo', 1, ['aliases' => ['Ô Đô']]);
        $two = new AuthorityEntity('22222222-2222-4222-8222-222222222222', 'brand', 'brand:odo-alt', 'Odo', 1, []);
        $repository->create($one);
        $repository->create($two);

        $report = (new McpSemanticContextResolver($repository, $types))->resolve([
            'brand' => ['name' => 'Odo', 'id' => $one->canonicalId],
        ]);

        self::assertSame('uuid_exact', $report['resolved']['brand']['match']);
        self::assertSame($one->canonicalId, $report['resolved']['brand']['id']);
        self::assertSame([], $report['ambiguities']);
        self::assertSame([], $report['missing']);

        $ambiguous = (new McpSemanticContextResolver($repository, $types))->resolve(['brand' => ['name' => 'Odo']]);
        self::assertArrayHasKey('brand', $ambiguous['ambiguities']);
        self::assertCount(2, $ambiguous['candidates']['brand']);
        self::assertArrayNotHasKey('brand', $ambiguous['resolved']);
    }

    public function test_resolves_explicit_canonical_uuid_before_stable_key_and_name(): void
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $repository = new InMemoryAuthorityRepository();
        $variant = new AuthorityEntity('95873bfe-d978-4eda-a5a2-ce9ba79625df', 'variant', 'variant:36-10', '36/10', 1, []);
        $repository->create($variant);

        $report = (new McpSemanticContextResolver($repository, $types))->resolve([
            'variant' => ['canonical_uuid' => $variant->canonicalId, 'stable_key' => $variant->stableKey, 'name' => 'not the canonical name'],
        ]);

        self::assertSame($variant->canonicalId, $report['resolved']['variant']['id']);
        self::assertSame('uuid_exact', $report['resolved']['variant']['match']);
        self::assertSame([], $report['missing']);
        self::assertSame([], $report['conflicts']);
    }

    public function test_stable_key_fallback_and_ambiguous_alias_remain_fail_closed(): void
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $repository = new InMemoryAuthorityRepository();
        $one = new AuthorityEntity('33333333-3333-4333-8333-333333333333', 'variant', 'variant:36-10', '36/10', 1, ['aliases' => ['Thirty Six Ten']]);
        $two = new AuthorityEntity('44444444-4444-4444-8444-444444444444', 'variant', 'variant:36-10-alt', '36/10 Alt', 1, ['aliases' => ['Thirty Six Ten']]);
        $repository->create($one);
        $repository->create($two);

        $stable = (new McpSemanticContextResolver($repository, $types))->resolve(['variant' => ['stable_key' => $one->stableKey]]);
        self::assertSame($one->canonicalId, $stable['resolved']['variant']['id']);
        self::assertSame('stable_key_exact', $stable['resolved']['variant']['match']);

        $ambiguous = (new McpSemanticContextResolver($repository, $types))->resolve(['variant' => ['name' => 'Thirty Six Ten']]);
        self::assertArrayHasKey('variant', $ambiguous['ambiguities']);
        self::assertArrayNotHasKey('variant', $ambiguous['resolved']);
    }

    public function test_capture_and_semantic_resolution_share_the_same_typed_variant_packet(): void
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $repository = new InMemoryAuthorityRepository();
        $variant = new AuthorityEntity(
            '95873bfe-d978-4eda-a5a2-ce9ba79625df',
            'variant',
            'nhk:variant:odo.36.10',
            'Đồng hồ Odo 36/10',
            1,
            ['aliases' => ['Odo 36/10']],
            revision: 2,
        );
        $repository->create($variant);

        $report = (new McpSemanticContextResolver($repository, $types))->resolve(['variant' => ['name' => 'Odo 36/10']]);

        self::assertSame($variant->canonicalId, $report['resolved']['variant']['id']);
        self::assertSame($variant->stableKey, $report['resolved']['variant']['stable_key']);
        self::assertSame($variant->canonicalName, $report['resolved']['variant']['name']);
        self::assertSame(2, $report['resolved']['variant']['revision']);
        self::assertSame('exact_name_or_alias', $report['resolved']['variant']['match']);
    }
}
