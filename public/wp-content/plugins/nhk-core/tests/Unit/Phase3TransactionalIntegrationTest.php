<?php
declare(strict_types=1);

namespace NHK\Core\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryLexicalRelationGovernanceAdapter;
use NHK\Core\Application\Graph\SemanticRelationGovernanceAdapter;
use NHK\Core\Contracts\Dictionary\DictionaryLexicalRelationRepository;
use NHK\Core\Contracts\Shared\TransactionManager;
use NHK\Core\Domain\Dictionary\DictionaryLexicalRelation;
use NHK\Core\Domain\Graph\{EdgeState, GraphEdge, GraphNode, NodeReference};
use NHK\Core\Domain\Graph\GraphRelationContext;
use PHPUnit\Framework\TestCase;

final class Phase3TransactionalIntegrationTest extends TestCase
{
    public function testSemanticAddUsesOneTransactionAndRollsBackWhenContextFails(): void
    {
        $transactions = new Phase3TransactionSpy();
        $edgeUuid = '11111111-1111-7111-8111-111111111111';
        $graph = new class($edgeUuid) {
            public function __construct(private string $uuid) {}
            public function create(NodeReference $source, string $predicate, NodeReference $target): GraphEdge
            { return new GraphEdge($this->uuid, new GraphNode(1, $source), $predicate, new GraphNode(2, $target)); }
            public function findByUuid(string $uuid): ?GraphEdge { return $uuid === $this->uuid ? new GraphEdge($uuid, new GraphNode(1, new NodeReference('component', 'component-1')), 'associated_with', new GraphNode(2, new NodeReference('brand', 'brand-1'))) : null; }
        };
        $contexts = new class {
            public function findByIdempotencyKey(string $key): ?GraphRelationContext { return null; }
            public function create(GraphRelationContext $context): GraphRelationContext { throw new \RuntimeException('CONTEXT_INSERT_FAILED'); }
        };
        $adapter = new SemanticRelationGovernanceAdapter(
            '1.0.0',
            str_repeat('a', 64),
            static fn (string $type, string $id): array => ['exists' => true, 'active' => true, 'revision' => 1],
            static fn (array $plan): array => [],
            $graph,
            $contexts,
            $transactions,
        );
        $preview = $adapter->preview(['operation' => 'ADD', 'source' => ['type' => 'component', 'id' => 'component-1'], 'target' => ['type' => 'brand', 'id' => 'brand-1'], 'predicate' => 'associated_with', 'scope_code' => 'dictionary', 'provenance' => 'EXPLICIT_USER_KNOWLEDGE', 'evidence_refs' => [['evidence_id' => '22222222-2222-7222-8222-222222222222']], 'idempotency_key' => 'phase3-semantic-1']);
        $result = $adapter->apply($preview['plan'], $preview['plan_fingerprint'], 'phase3-semantic-1');
        self::assertSame('FAILED_FINAL', $result['status']);
        self::assertSame(1, $transactions->transactions);
        self::assertSame(1, $transactions->rollbacks);
        self::assertSame(0, $transactions->commits);
    }

    public function testLexicalAddUsesTransactionAndRetryReadsIdempotentRelation(): void
    {
        $transactions = new Phase3TransactionSpy();
        $repository = new class implements DictionaryLexicalRelationRepository {
            public ?DictionaryLexicalRelation $saved = null;
            public function create(DictionaryLexicalRelation $relation): DictionaryLexicalRelation { return $this->saved ??= $relation; }
            public function findByUuid(string $uuid): ?DictionaryLexicalRelation { return $this->saved?->relationUuid === $uuid ? $this->saved : null; }
            public function findByIdempotencyKey(string $key): ?DictionaryLexicalRelation { return $this->saved?->idempotencyKey === $key ? $this->saved : null; }
            public function update(DictionaryLexicalRelation $relation, int $expectedRevision): DictionaryLexicalRelation { return $relation; }
            public function retire(DictionaryLexicalRelation $relation, int $expectedRevision): DictionaryLexicalRelation { return $relation; }
            public function reactivate(DictionaryLexicalRelation $relation, int $expectedRevision): DictionaryLexicalRelation { return $relation; }
            public function listForEntry(string $entryUuid, int $afterId = 0, int $limit = 100, bool $includeRetired = false): array { return ['items' => [], 'next_cursor' => null]; }
        };
        $adapter = new DictionaryLexicalRelationGovernanceAdapter($repository, static fn (string $id): array => ['active' => true], static fn (string $entry, string $sense): bool => true, $transactions);
        $preview = $adapter->preview(['operation' => 'ADD', 'source_entry_uuid' => '33333333-3333-7333-8333-333333333333', 'target_entry_uuid' => '44444444-4444-7444-8444-444444444444', 'kind' => 'RELATED', 'provenance' => ['source' => 'phase3-test'], 'idempotency_key' => 'phase3-lexical-1']);
        $plan = $preview['plan'];
        $first = $adapter->apply($plan, $preview['plan_fingerprint'], 'phase3-lexical-1');
        $second = $adapter->apply($plan, $preview['plan_fingerprint'], 'phase3-lexical-1');
        self::assertSame('READ_BACK_VERIFIED', $first['status']);
        self::assertTrue($second['idempotent_replay']);
        self::assertSame(1, $transactions->transactions);
        self::assertSame(1, $transactions->commits);
    }

    public function testLexicalApplyFailsWhenFreshCanonicalReadbackCannotFindCreatedRelation(): void
    {
        $transactions = new Phase3TransactionSpy();
        $repository = new class implements DictionaryLexicalRelationRepository {
            public function create(DictionaryLexicalRelation $relation): DictionaryLexicalRelation { return $relation; }
            public function findByUuid(string $uuid): ?DictionaryLexicalRelation { return null; }
            public function findByIdempotencyKey(string $key): ?DictionaryLexicalRelation { return null; }
            public function update(DictionaryLexicalRelation $relation, int $expectedRevision): DictionaryLexicalRelation { return $relation; }
            public function retire(DictionaryLexicalRelation $relation, int $expectedRevision): DictionaryLexicalRelation { return $relation; }
            public function reactivate(DictionaryLexicalRelation $relation, int $expectedRevision): DictionaryLexicalRelation { return $relation; }
            public function listForEntry(string $entryUuid, int $afterId = 0, int $limit = 100, bool $includeRetired = false): array { return ['items' => [], 'next_cursor' => null]; }
        };
        $adapter = new DictionaryLexicalRelationGovernanceAdapter($repository, static fn (string $id): array => ['active' => true], static fn (string $entry, string $sense): bool => true, $transactions);
        $preview = $adapter->preview(['operation' => 'ADD', 'source_entry_uuid' => '33333333-3333-7333-8333-333333333333', 'target_entry_uuid' => '44444444-4444-7444-8444-444444444444', 'kind' => 'RELATED', 'provenance' => ['source' => 'phase3-readback'], 'idempotency_key' => 'phase3-readback-failure']);

        $result = $adapter->apply($preview['plan'], $preview['plan_fingerprint'], 'phase3-readback-failure');

        self::assertSame('FAILED_FINAL', $result['status']);
        self::assertContains('DICTIONARY_LEXICAL_RELATION_READ_BACK_FAILED', $result['blockers']);
        self::assertSame(1, $transactions->rollbacks);
        self::assertSame(0, $transactions->commits);
    }

    public function testSemanticApplyFailsWhenFreshCanonicalReadbackCannotFindContext(): void
    {
        $transactions = new Phase3TransactionSpy();
        $edgeUuid = '11111111-1111-7111-8111-111111111111';
        $graph = new class($edgeUuid) {
            public function __construct(private string $uuid) {}
            public function create(NodeReference $source, string $predicate, NodeReference $target): GraphEdge { return new GraphEdge($this->uuid, new GraphNode(1, $source), $predicate, new GraphNode(2, $target)); }
            public function findByUuid(string $uuid): ?GraphEdge { return $uuid === $this->uuid ? new GraphEdge($uuid, new GraphNode(1, new NodeReference('component', 'component-1')), 'associated_with', new GraphNode(2, new NodeReference('brand', 'brand-1'))) : null; }
        };
        $contexts = new class {
            public function findByIdempotencyKey(string $key): ?GraphRelationContext { return null; }
            public function create(GraphRelationContext $context): GraphRelationContext { return $context; }
            public function findByEdgeUuid(string $uuid): ?GraphRelationContext { return null; }
        };
        $adapter = new SemanticRelationGovernanceAdapter('1.0.0', str_repeat('a', 64), static fn (string $type, string $id): array => ['exists' => true, 'active' => true, 'revision' => 1], static fn (array $plan): array => [], $graph, $contexts, $transactions);
        $preview = $adapter->preview(['operation' => 'ADD', 'source' => ['type' => 'component', 'id' => 'component-1'], 'target' => ['type' => 'brand', 'id' => 'brand-1'], 'predicate' => 'associated_with', 'scope_code' => 'dictionary', 'provenance' => 'EXPLICIT_USER_KNOWLEDGE', 'evidence_refs' => [['evidence_id' => '22222222-2222-7222-8222-222222222222']], 'idempotency_key' => 'phase3-semantic-readback-failure']);

        $result = $adapter->apply($preview['plan'], $preview['plan_fingerprint'], 'phase3-semantic-readback-failure');

        self::assertSame('FAILED_FINAL', $result['status']);
        self::assertContains('GRAPH_RELATION_READ_BACK_FAILED', $result['blockers']);
        self::assertSame(1, $transactions->rollbacks);
        self::assertSame(0, $transactions->commits);
    }
}

final class Phase3TransactionSpy implements TransactionManager
{
    public int $transactions = 0;
    public int $commits = 0;
    public int $rollbacks = 0;
    public function begin(): void {}
    public function commit(): void {}
    public function rollback(): void {}
    public function transactional(callable $callback): mixed
    {
        $this->transactions++;
        try { $result = $callback(); $this->commits++; return $result; }
        catch (\Throwable $error) { $this->rollbacks++; throw $error; }
    }
    public function run(callable $callback): mixed { return $this->transactional($callback); }
}
