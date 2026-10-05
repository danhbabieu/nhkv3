<?php
declare(strict_types=1);
namespace NHK\Tests\Unit;
use NHK\Core\Application\Graph\SemanticRelationGovernanceAdapter;
use NHK\Core\Application\Dictionary\DictionaryLexicalRelationGovernanceAdapter;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class Phase2GovernanceAdapterTest extends TestCase
{
    public function test_graph_preview_is_deterministic_and_binds_registry_and_owner_revisions(): void
    {
        $adapter = new SemanticRelationGovernanceAdapter(
            registryVersion: '1.1.0',
            registryHash: 'registry-hash',
            endpointState: static fn (string $type, string $id): array => ['active' => true, 'revision' => 4],
            relationState: static fn (string $id): ?array => null,
        );
        $input = ['operation'=>'ADD','source'=>['type'=>'component','id'=>UuidCodec::newV7()],'target'=>['type'=>'music','id'=>UuidCodec::newV7()],'predicate'=>'associated_with','scope_code'=>'component_music_scope','provenance'=>'EXTERNAL_RESEARCH','evidence_refs'=>[['evidence_id'=>UuidCodec::newV7()]],'idempotency_key'=>'phase2-graph-add'];
        $one = $adapter->preview($input);
        $two = $adapter->preview($input);
        self::assertSame('READY', $one['status']);
        self::assertSame($one['plan_fingerprint'], $two['plan_fingerprint']);
        self::assertSame('1.1.0', $one['registry_version']);
        self::assertSame(4, $one['source_revision']);
    }

    public function test_graph_apply_requires_exact_approved_fingerprint_and_replans_on_revision_drift(): void
    {
        $source = UuidCodec::newV7(); $target = UuidCodec::newV7();
        $state = ['source_revision'=>4,'target_revision'=>5];
        $adapter = new SemanticRelationGovernanceAdapter('1.1.0','registry-hash',static function(string $type,string $id) use (&$state): array { return ['active'=>true,'revision'=>$id === $GLOBALS['phase2_source'] ? $state['source_revision'] : $state['target_revision']]; },static fn(string $id):?array=>null);
        $GLOBALS['phase2_source'] = $source;
        $input = ['operation'=>'ADD','source'=>['type'=>'component','id'=>$source],'target'=>['type'=>'music','id'=>$target],'predicate'=>'associated_with','scope_code'=>'component_music_scope','provenance'=>'EXTERNAL_RESEARCH','evidence_refs'=>[['evidence_id'=>UuidCodec::newV7()]],'idempotency_key'=>'phase2-graph-apply'];
        $plan = $adapter->preview($input);
        $changedPlan = $plan['plan'];
        $changedPlan['scope_code'] = 'changed-scope';
        self::assertSame('REPLAN_REQUIRED', $adapter->apply($changedPlan, $plan['plan_fingerprint'], 'phase2-graph-apply')['status']);
        self::assertSame('REPLAN_REQUIRED', $adapter->apply($plan['plan'], 'wrong-fingerprint', 'phase2-graph-apply')['status']);
        $state['source_revision'] = 6;
        $result = $adapter->apply($plan['plan'], $plan['plan_fingerprint'], 'phase2-graph-apply');
        self::assertSame('REPLAN_REQUIRED', $result['status']);
        self::assertContains('STALE_SOURCE_REVISION', $result['blockers']);
        unset($GLOBALS['phase2_source']);
    }

    public function test_graph_lifecycle_preview_reuses_existing_context_without_new_evidence(): void
    {
        $edgeUuid = UuidCodec::newV7();
        $context = new \NHK\Core\Domain\Graph\GraphRelationContext(UuidCodec::newV7(), $edgeUuid, 1, 1, 'dictionary', 'component', UuidCodec::newV7(), 'EXTERNAL_RESEARCH', [['evidence_id' => UuidCodec::newV7()]], str_repeat('a', 64), 'existing-context');
        $contexts = new class($context) {
            public function __construct(private object $context) {}
            public function findByEdgeUuid(string $uuid): ?object { return $uuid === $this->context->edgeUuid ? $this->context : null; }
        };
        $adapter = new SemanticRelationGovernanceAdapter('1.1.0', 'registry-hash', static fn (string $type, string $id): array => ['active' => true, 'revision' => 1], static fn (array $plan): array => [], null, $contexts);
        $preview = $adapter->preview(['operation' => 'RETIRE', 'source' => ['type' => 'component', 'id' => $context->scopeSubjectId], 'target' => ['type' => 'music', 'id' => UuidCodec::newV7()], 'predicate' => 'associated_with', 'scope_code' => 'dictionary', 'provenance' => '', 'evidence_refs' => [], 'edge_uuid' => $edgeUuid, 'expected_edge_revision' => 1, 'idempotency_key' => 'semantic-retire-existing']);

        self::assertSame('READY', $preview['status']);
        self::assertSame(1, $preview['plan']['expected_context_revision']);
    }

    public function test_lexical_adapter_rejects_entry_level_broader_and_keeps_governance_boundary(): void
    {
        $adapter = new DictionaryLexicalRelationGovernanceAdapter(
            repository: new class implements \NHK\Core\Contracts\Dictionary\DictionaryLexicalRelationRepository {
                public function create(\NHK\Core\Domain\Dictionary\DictionaryLexicalRelation $relation): \NHK\Core\Domain\Dictionary\DictionaryLexicalRelation { return $relation; }
                public function findByUuid(string $uuid): ?\NHK\Core\Domain\Dictionary\DictionaryLexicalRelation { return null; }
                public function findByIdempotencyKey(string $key): ?\NHK\Core\Domain\Dictionary\DictionaryLexicalRelation { return null; }
                public function update(\NHK\Core\Domain\Dictionary\DictionaryLexicalRelation $relation, int $expectedRevision): \NHK\Core\Domain\Dictionary\DictionaryLexicalRelation { return $relation; }
                public function retire(\NHK\Core\Domain\Dictionary\DictionaryLexicalRelation $relation, int $expectedRevision): \NHK\Core\Domain\Dictionary\DictionaryLexicalRelation { return $relation; }
                public function reactivate(\NHK\Core\Domain\Dictionary\DictionaryLexicalRelation $relation, int $expectedRevision): \NHK\Core\Domain\Dictionary\DictionaryLexicalRelation { return $relation; }
                public function listForEntry(string $entryUuid, int $afterId = 0, int $limit = 100, bool $includeRetired = false): array { return ['items'=>[],'next_cursor'=>null]; }
            },
            entryState: static fn (string $id): array => ['active'=>true,'revision'=>2],
            senseBelongs: static fn (string $entry,string $sense): bool => true,
        );
        $plan = $adapter->preview(['operation'=>'ADD','source_entry_uuid'=>UuidCodec::newV7(),'target_entry_uuid'=>UuidCodec::newV7(),'kind'=>'BROADER','idempotency_key'=>'phase2-lexical']);
        self::assertSame('BLOCKED', $plan['status']);
        self::assertContains('DICTIONARY_LEXICAL_RELATION_SENSE_REQUIRED', $plan['blockers']);
    }

    public function test_lexical_add_uses_repository_only_after_exact_plan_and_reads_back(): void
    {
        $repo = new class implements \NHK\Core\Contracts\Dictionary\DictionaryLexicalRelationRepository {
            public ?\NHK\Core\Domain\Dictionary\DictionaryLexicalRelation $saved = null;
            public function create(\NHK\Core\Domain\Dictionary\DictionaryLexicalRelation $relation): \NHK\Core\Domain\Dictionary\DictionaryLexicalRelation { return $this->saved = $relation; }
            public function findByUuid(string $uuid): ?\NHK\Core\Domain\Dictionary\DictionaryLexicalRelation { return $this->saved?->relationUuid === $uuid ? $this->saved : null; }
            public function findByIdempotencyKey(string $key): ?\NHK\Core\Domain\Dictionary\DictionaryLexicalRelation { return $this->saved?->idempotencyKey === $key ? $this->saved : null; }
            public function update(\NHK\Core\Domain\Dictionary\DictionaryLexicalRelation $relation, int $expectedRevision): \NHK\Core\Domain\Dictionary\DictionaryLexicalRelation { return $this->saved = $relation; }
            public function retire(\NHK\Core\Domain\Dictionary\DictionaryLexicalRelation $relation, int $expectedRevision): \NHK\Core\Domain\Dictionary\DictionaryLexicalRelation { return $this->saved = $relation->retired($expectedRevision); }
            public function reactivate(\NHK\Core\Domain\Dictionary\DictionaryLexicalRelation $relation, int $expectedRevision): \NHK\Core\Domain\Dictionary\DictionaryLexicalRelation { return $this->saved = $relation->reactivated($expectedRevision); }
            public function listForEntry(string $entryUuid, int $afterId = 0, int $limit = 100, bool $includeRetired = false): array { return ['items'=>[],'next_cursor'=>null]; }
        };
        $adapter = new DictionaryLexicalRelationGovernanceAdapter($repo, static fn(string $id): array => ['active'=>true,'revision'=>1], static fn(string $entry,string $sense):bool=>true);
        $plan = $adapter->preview(['operation'=>'ADD','source_entry_uuid'=>UuidCodec::newV7(),'target_entry_uuid'=>UuidCodec::newV7(),'kind'=>'RELATED','provenance'=>['source'=>'CURATOR'],'idempotency_key'=>'lexical-apply']);
        $result = $adapter->apply($plan['plan'], $plan['plan_fingerprint'], 'lexical-apply');
        self::assertSame('READ_BACK_VERIFIED', $result['status']);
        self::assertSame('RELATED', $result['relation']['relation_kind']);

        $changedPlan = $plan['plan'];
        $changedPlan['provenance'] = ['source' => 'changed'];
        self::assertSame('REPLAN_REQUIRED', $adapter->apply($changedPlan, $plan['plan_fingerprint'], 'lexical-apply')['status']);
        self::assertSame('REPLAN_REQUIRED', $adapter->apply($plan['plan'], 'wrong-fingerprint', 'lexical-apply')['status']);
    }

    public function test_lexical_relation_full_lifecycle_reads_canonical_revision_after_each_transition(): void
    {
        $repo = new class implements \NHK\Core\Contracts\Dictionary\DictionaryLexicalRelationRepository {
            public ?\NHK\Core\Domain\Dictionary\DictionaryLexicalRelation $saved = null;
            public function create(\NHK\Core\Domain\Dictionary\DictionaryLexicalRelation $relation): \NHK\Core\Domain\Dictionary\DictionaryLexicalRelation { return $this->saved = $relation; }
            public function findByUuid(string $uuid): ?\NHK\Core\Domain\Dictionary\DictionaryLexicalRelation { return $this->saved?->relationUuid === $uuid ? $this->saved : null; }
            public function findByIdempotencyKey(string $key): ?\NHK\Core\Domain\Dictionary\DictionaryLexicalRelation { return null; }
            public function update(\NHK\Core\Domain\Dictionary\DictionaryLexicalRelation $relation, int $expectedRevision): \NHK\Core\Domain\Dictionary\DictionaryLexicalRelation { return $this->saved = new \NHK\Core\Domain\Dictionary\DictionaryLexicalRelation($relation->relationUuid, $relation->sourceEntryUuid, $relation->sourceSenseUuid, $relation->targetEntryUuid, $relation->targetSenseUuid, $relation->kind, $relation->provenance, $relation->idempotencyKey, $relation->state, $expectedRevision + 1); }
            public function retire(\NHK\Core\Domain\Dictionary\DictionaryLexicalRelation $relation, int $expectedRevision): \NHK\Core\Domain\Dictionary\DictionaryLexicalRelation { return $this->saved = $relation->retired($expectedRevision); }
            public function reactivate(\NHK\Core\Domain\Dictionary\DictionaryLexicalRelation $relation, int $expectedRevision): \NHK\Core\Domain\Dictionary\DictionaryLexicalRelation { return $this->saved = $relation->reactivated($expectedRevision); }
            public function listForEntry(string $entryUuid, int $afterId = 0, int $limit = 100, bool $includeRetired = false): array { return ['items' => [], 'next_cursor' => null]; }
        };
        $source = UuidCodec::newV7(); $target = UuidCodec::newV7();
        $adapter = new DictionaryLexicalRelationGovernanceAdapter($repo, static fn (string $id): array => ['active' => true], static fn (string $entry, string $sense): bool => true);
        $base = ['source_entry_uuid' => $source, 'target_entry_uuid' => $target, 'kind' => 'RELATED'];
        $add = $adapter->preview($base + ['operation' => 'ADD', 'provenance' => ['source' => 'lifecycle'], 'idempotency_key' => 'lexical-lifecycle']);
        $added = $adapter->apply($add['plan'], $add['plan_fingerprint'], 'lexical-lifecycle');
        self::assertSame(1, $added['relation']['revision']);
        $relation = $added['relation'];
        self::assertSame('available', $adapter->read(['relation_uuid' => $relation['relation_uuid']])['status']);

        $replace = $adapter->preview($base + ['operation' => 'REPLACE', 'relation_uuid' => $relation['relation_uuid'], 'expected_revision' => 1, 'provenance' => ['source' => 'replaced'], 'idempotency_key' => 'lexical-replace']);
        $replaced = $adapter->apply($replace['plan'], $replace['plan_fingerprint'], 'lexical-replace');
        self::assertSame(2, $replaced['relation']['revision']);
        self::assertSame(['source' => 'replaced'], $adapter->read(['relation_uuid' => $relation['relation_uuid']])['relation']['provenance']);

        $retire = $adapter->preview($base + ['operation' => 'RETIRE', 'relation_uuid' => $relation['relation_uuid'], 'expected_revision' => 2, 'idempotency_key' => 'lexical-retire']);
        $retired = $adapter->apply($retire['plan'], $retire['plan_fingerprint'], 'lexical-retire');
        self::assertSame('RETIRED', $retired['relation']['state']);
        self::assertSame(3, $retired['relation']['revision']);
        self::assertSame('RETIRED', $adapter->read(['relation_uuid' => $relation['relation_uuid']])['relation']['state']);

        $reactivate = $adapter->preview($base + ['operation' => 'REACTIVATE', 'relation_uuid' => $relation['relation_uuid'], 'expected_revision' => 3, 'idempotency_key' => 'lexical-reactivate']);
        $active = $adapter->apply($reactivate['plan'], $reactivate['plan_fingerprint'], 'lexical-reactivate');
        self::assertSame('ACTIVE', $active['relation']['state']);
        self::assertSame(4, $active['relation']['revision']);
        self::assertSame('ACTIVE', $adapter->read(['relation_uuid' => $relation['relation_uuid']])['relation']['state']);
    }
}
