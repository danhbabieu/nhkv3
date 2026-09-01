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
}
