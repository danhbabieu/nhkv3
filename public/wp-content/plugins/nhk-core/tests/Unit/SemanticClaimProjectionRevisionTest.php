<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Projection\{ClaimProjectionService, ProjectionInvalidationService};
use NHK\Core\Contracts\Projection\ProjectionDependencyIndex;
use NHK\Core\Domain\Projection\{ProjectionRevision, ProjectionStatus};
use NHK\Core\Infrastructure\Projection\{InMemoryProjectionDependencyIndex, InMemoryProjectionRevisionStore};
use PHPUnit\Framework\TestCase;

final class SemanticClaimProjectionRevisionTest extends TestCase
{
    public function test_same_input_hash_is_idempotent_and_published_revision_is_not_replaced(): void
    {
        $store = new InMemoryProjectionRevisionStore();
        $input = ['node' => 'node-1', 'input_hash' => str_repeat('a', 64), 'claim_set_hash' => str_repeat('b', 64), 'graph_hash' => str_repeat('c', 64), 'payload' => ['seo' => ['canonical_url' => '/entity/node-1/', 'h1' => 'Node']]];
        $first = $store->saveCandidate(new ProjectionRevision($input['node'], 1, ProjectionStatus::CANDIDATE, $input['input_hash'], $input['claim_set_hash'], $input['graph_hash'], payload: $input['payload']));
        $second = $store->saveCandidate(new ProjectionRevision($input['node'], 1, ProjectionStatus::CANDIDATE, $input['input_hash'], $input['claim_set_hash'], $input['graph_hash'], payload: $input['payload']));
        self::assertSame($first->revision, $second->revision);
        $ready = $store->markReady($input['node'], $first->revision);
        $published = $store->publish($input['node'], $ready->revision);
        self::assertSame(ProjectionStatus::PUBLISHED, $published->status);
        self::assertSame($published->revision, $store->findPublished($input['node'])->revision);
    }

    public function test_invalidation_marks_only_dependent_sections_dirty(): void
    {
        $store = new InMemoryProjectionRevisionStore();
        $store->saveCandidate(new ProjectionRevision('node-1', 1, ProjectionStatus::CANDIDATE, str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64), payload: ['seo' => ['canonical_url' => '/x/', 'h1' => 'X']]));
        $index = new InMemoryProjectionDependencyIndex();
        $index->add(['kind' => 'claim', 'id' => 'claim-1', 'node_uuid' => 'node-1', 'section_key' => 'configuration']);
        $index->add(['kind' => 'claim', 'id' => 'claim-1', 'node_uuid' => 'node-2', 'section_key' => 'history']);
        $result = (new ProjectionInvalidationService($index, $store))->invalidateClaim('claim-1');
        self::assertCount(2, $result['impacted']);
        self::assertSame(['configuration'], $store->findCandidate('node-1')->dirtySections);
        self::assertNull($store->findCandidate('node-2'));
    }
}
