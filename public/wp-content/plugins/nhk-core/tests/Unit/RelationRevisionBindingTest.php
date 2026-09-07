<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Graph\RelationRevisionBinder;
use NHK\Core\Contracts\Graph\EndpointRevisionReader;
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, NodeReference};
use PHPUnit\Framework\TestCase;

final class RelationRevisionBindingTest extends TestCase
{
    /** @dataProvider authorityTypes */
    public function test_binds_current_source_and_target_revisions_for_about_relation(string $targetType): void
    {
        $sourceId = '018f7c48-6d87-7a1d-8c9e-3b8c4c8d1f22';
        $targetId = '018f7c48-6d87-7a1d-8c9e-3b8c4c8d1f23';
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('knowledge', new RevisionedResolver('knowledge', [$sourceId => 1]));
        $endpoints->register($targetType, new RevisionedResolver($targetType, [$targetId => 3]));

        $bound = (new RelationRevisionBinder($endpoints))->bind([
            'source_type' => 'knowledge', 'source_uuid' => $sourceId,
            'target_type' => $targetType, 'target_uuid' => $targetId,
            'predicate' => 'about',
        ]);

        self::assertSame(1, $bound['source_revision']);
        self::assertSame(3, $bound['target_revision']);
    }

    public static function authorityTypes(): iterable
    {
        yield 'brand' => ['brand'];
        yield 'model' => ['model'];
        yield 'variant' => ['variant'];
    }

}

final class RevisionedResolver implements EndpointRevisionReader
{
    public function __construct(private string $type, private array $revisions) {}
    public function supports(string $endpoint_type): bool { return $endpoint_type === $this->type; }
    public function normalize(NodeReference $reference): NodeReference { return $reference; }
    public function exists(NodeReference $reference): bool { return isset($this->revisions[$reference->endpoint_key]); }
    public function revision(NodeReference $reference): ?int { return $this->revisions[$reference->endpoint_key] ?? null; }
}
