<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Graph\ExplicitRelationIntentPlanner;
use NHK\Core\Application\Authority\AuthorityIntentPlanner;
use NHK\Core\Application\Authority\AuthorityPlanFingerprint;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Graph\EndpointRevisionReader;
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, NodeReference, PredicateRegistry};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class ExplicitRelationIntentPlannerTest extends TestCase
{
    private const SOURCE = '01a07cbc-3595-7e63-8c1b-5b308c644125';
    private const TARGET = '01a08156-c400-7739-a40f-61185cd62fcd';

    public function test_missing_existing_classification_about_knowledge_edge_produces_typed_candidate(): void
    {
        $planner = $this->planner([
            'classification' => [self::SOURCE => ['active' => true, 'revision' => 1]],
            'knowledge' => [self::TARGET => ['active' => true, 'revision' => 1]],
        ]);

        $result = $planner->plan([$this->intent()]);

        self::assertCount(1, $result['relation_candidates']);
        self::assertSame([], $result['relation_reuse']);
        self::assertSame('classification', $result['relation_candidates'][0]['source_type']);
        self::assertSame(self::SOURCE, $result['relation_candidates'][0]['source_uuid']);
        self::assertSame(1, $result['relation_candidates'][0]['source_revision']);
        self::assertSame('about', $result['relation_candidates'][0]['predicate']);
        self::assertSame('knowledge', $result['relation_candidates'][0]['target_type']);
        self::assertSame(self::TARGET, $result['relation_candidates'][0]['target_uuid']);
        self::assertSame(1, $result['relation_candidates'][0]['target_revision']);
        self::assertSame('EXPLICIT_USER_RELATION', $result['relation_candidates'][0]['provenance']);
    }

    public function test_exact_active_edge_is_reused_without_candidate_or_duplicate(): void
    {
        $edgeId = UuidCodec::newV7();
        $planner = $this->planner(
            [
                'classification' => [self::SOURCE => ['active' => true, 'revision' => 1]],
                'knowledge' => [self::TARGET => ['active' => true, 'revision' => 1]],
            ],
            [$this->key() => ['status' => 'ACTIVE', 'canonical_id' => $edgeId, 'revision' => 1]],
        );

        $result = $planner->plan([$this->intent()]);

        self::assertSame([], $result['relation_candidates']);
        self::assertCount(1, $result['relation_reuse']);
        self::assertSame('EXISTING', $result['relation_reuse'][0]['status']);
        self::assertSame($edgeId, $result['relation_reuse'][0]['canonical_id']);
        self::assertTrue($result['relation_reuse'][0]['idempotent']);
    }

    public function test_unsupported_predicate_is_a_typed_blocker(): void
    {
        $planner = $this->planner([
            'classification' => [self::SOURCE => ['active' => true, 'revision' => 1]],
            'knowledge' => [self::TARGET => ['active' => true, 'revision' => 1]],
        ]);

        $result = $planner->plan([$this->intent(['predicate' => 'invented_predicate'])]);

        self::assertContains('RELATION_PREDICATE_UNSUPPORTED', array_column($result['blockers'], 'code'));
        self::assertSame([], $result['relation_candidates']);
    }

    public function test_invalid_source_uuid_fails_closed(): void
    {
        $planner = $this->planner(['knowledge' => [self::TARGET => ['active' => true, 'revision' => 1]]]);

        $result = $planner->plan([$this->intent(['source_uuid' => 'not-a-uuid'])]);

        self::assertContains('RELATION_SOURCE_UUID_INVALID', array_column($result['blockers'], 'code'));
    }

    public function test_invalid_target_uuid_fails_closed(): void
    {
        $planner = $this->planner(['classification' => [self::SOURCE => ['active' => true, 'revision' => 1]]]);

        $result = $planner->plan([$this->intent(['target_uuid' => 'not-a-uuid'])]);

        self::assertContains('RELATION_TARGET_UUID_INVALID', array_column($result['blockers'], 'code'));
    }

    public function test_wrong_endpoint_type_fails_closed(): void
    {
        $planner = $this->planner([
            'knowledge' => [self::SOURCE => ['active' => true, 'revision' => 1], self::TARGET => ['active' => true, 'revision' => 1]],
        ]);

        $result = $planner->plan([$this->intent(['source_type' => 'knowledge', 'target_type' => 'classification', 'predicate' => 'subtype_of'])]);

        self::assertContains('RELATION_ENDPOINT_TYPES_UNSUPPORTED', array_column($result['blockers'], 'code'));
    }

    public function test_retired_endpoint_fails_closed(): void
    {
        $planner = $this->planner([
            'classification' => [self::SOURCE => ['active' => false, 'revision' => 2]],
            'knowledge' => [self::TARGET => ['active' => true, 'revision' => 1]],
        ]);

        $result = $planner->plan([$this->intent()]);

        self::assertContains('RELATION_SOURCE_INACTIVE', array_column($result['blockers'], 'code'));
    }

    public function test_multiple_intents_have_bounded_deterministic_order(): void
    {
        $second = '01a08156-c400-7739-a40f-61185cd62fce';
        $planner = $this->planner([
            'classification' => [self::SOURCE => ['active' => true, 'revision' => 1]],
            'knowledge' => [
                self::TARGET => ['active' => true, 'revision' => 1],
                $second => ['active' => true, 'revision' => 1],
            ],
        ]);

        $result = $planner->plan([
            $this->intent(['target_uuid' => $second]),
            $this->intent(['target_uuid' => self::TARGET]),
        ]);

        self::assertSame([self::TARGET, $second], array_column($result['relation_candidates'], 'target_uuid'));
    }

    public function test_prose_without_typed_relation_intent_does_not_create_a_relation(): void
    {
        self::assertSame([], (new \NHK\Core\Application\Authority\AuthorityIntentPlanner($this->authorityRepository(), $this->types()))->plan([
            'text' => 'Bahnhäusle nói về một tri thức hiện có.',
            'authority_intent' => ['mode' => 'PLAN'],
        ])['relation_candidates']);
    }

    public function test_authority_capture_plan_consumes_typed_relation_intents_without_parsing_prose(): void
    {
        $relationPlanner = $this->planner([
            'classification' => [self::SOURCE => ['active' => true, 'revision' => 1]],
            'knowledge' => [self::TARGET => ['active' => true, 'revision' => 1]],
        ]);
        $planner = new AuthorityIntentPlanner($this->authorityRepository(), $this->types(), relationIntents: $relationPlanner);

        $plan = $planner->plan([
            'text' => 'Bahnhäusle nói về một tri thức hiện có.',
            'authority_intent' => ['mode' => 'PLAN', 'relation_intents' => [$this->intent()]],
        ]);

        self::assertCount(1, $plan['relation_candidates']);
        self::assertSame(self::SOURCE, $plan['relation_candidates'][0]['source_uuid']);
        self::assertSame(self::TARGET, $plan['relation_candidates'][0]['target_uuid']);
    }

    public function test_structured_relation_resolves_model_and_brand_types_from_canonical_endpoints(): void
    {
        $planner = $this->planner([
            'model' => [self::SOURCE => ['active' => true, 'revision' => 3]],
            'brand' => [self::TARGET => ['active' => true, 'revision' => 2]],
        ]);

        $result = $planner->plan([['source_uuid' => self::SOURCE, 'predicate' => 'model_of', 'target_uuid' => self::TARGET]]);
        self::assertCount(1, $result['relation_candidates']);
        self::assertSame('model', $result['relation_candidates'][0]['source_type']);
        self::assertSame('brand', $result['relation_candidates'][0]['target_type']);
        self::assertSame(3, $result['relation_candidates'][0]['source_revision']);
        self::assertSame(2, $result['relation_candidates'][0]['target_revision']);
    }

    public function test_structured_relation_resolves_variant_and_model_types_from_canonical_endpoints(): void
    {
        $planner = $this->planner([
            'variant' => [self::SOURCE => ['active' => true, 'revision' => 4]],
            'model' => [self::TARGET => ['active' => true, 'revision' => 5]],
        ]);

        $result = $planner->plan([['source_uuid' => self::SOURCE, 'predicate' => 'variant_of', 'target_uuid' => self::TARGET]]);
        self::assertCount(1, $result['relation_candidates']);
        self::assertSame('variant', $result['relation_candidates'][0]['source_type']);
        self::assertSame('model', $result['relation_candidates'][0]['target_type']);
    }

    public function test_authority_capture_plan_consumes_relations_without_client_endpoint_types(): void
    {
        $relationPlanner = $this->planner([
            'model' => [self::SOURCE => ['active' => true, 'revision' => 1]],
            'brand' => [self::TARGET => ['active' => true, 'revision' => 1]],
        ]);
        $planner = new AuthorityIntentPlanner($this->authorityRepository(), $this->types(), relationIntents: $relationPlanner);

        $plan = $planner->plan([
            'text' => 'Do not infer this relation from prose.',
            'authority_intent' => ['mode' => 'PLAN', 'relations' => [[
                'source_uuid' => self::SOURCE,
                'predicate' => 'model_of',
                'target_uuid' => self::TARGET,
            ]]],
        ]);

        self::assertCount(1, $plan['relation_candidates']);
        self::assertSame('model', $plan['relation_candidates'][0]['source_type']);
        self::assertSame('brand', $plan['relation_candidates'][0]['target_type']);
    }

    public function test_cardinality_conflict_fails_closed_before_relation_candidate_creation(): void
    {
        $endpoints = new \NHK\Core\Domain\Graph\EndpointTypeRegistry();
        $endpoints->register('model', new PlannerEndpointResolver('model', [self::SOURCE => ['active' => true, 'revision' => 1]]));
        $endpoints->register('brand', new PlannerEndpointResolver('brand', [self::TARGET => ['active' => true, 'revision' => 1]]));
        $planner = new ExplicitRelationIntentPlanner(
            $endpoints,
            new PredicateRegistry(),
            static fn (NodeReference $reference): array => ['active' => true, 'revision' => 1],
            static fn (array $packet): array => ['status' => 'CARDINALITY_CONFLICT', 'reason' => 'INBOUND_CARDINALITY_ONE'],
        );

        $result = $planner->plan([['source_uuid' => self::SOURCE, 'predicate' => 'model_of', 'target_uuid' => self::TARGET]]);
        self::assertContains('RELATION_CARDINALITY_CONFLICT', array_column($result['blockers'], 'code'));
        self::assertSame([], $result['relation_candidates']);
    }

    public function test_self_relation_fails_closed_for_registered_predicate(): void
    {
        $planner = $this->planner([
            'classification' => [self::SOURCE => ['active' => true, 'revision' => 1]],
        ]);
        $result = $planner->plan([[
            'source_type' => 'classification',
            'source_uuid' => self::SOURCE,
            'predicate' => 'subtype_of',
            'target_type' => 'classification',
            'target_uuid' => self::SOURCE,
        ]]);

        self::assertContains('RELATION_SELF_FORBIDDEN', array_column($result['blockers'], 'code'));
    }

    public function test_relation_candidate_identity_changes_when_endpoint_revision_changes(): void
    {
        $first = $this->planner([
            'classification' => [self::SOURCE => ['active' => true, 'revision' => 1]],
            'knowledge' => [self::TARGET => ['active' => true, 'revision' => 1]],
        ])->plan([$this->intent()]);
        $second = $this->planner([
            'classification' => [self::SOURCE => ['active' => true, 'revision' => 2]],
            'knowledge' => [self::TARGET => ['active' => true, 'revision' => 1]],
        ])->plan([$this->intent()]);
        $third = $this->planner([
            'classification' => [self::SOURCE => ['active' => true, 'revision' => 1]],
            'knowledge' => [self::TARGET => ['active' => true, 'revision' => 2]],
        ])->plan([$this->intent()]);

        self::assertNotSame($first['relation_candidates'][0]['candidate_id'], $second['relation_candidates'][0]['candidate_id']);
        self::assertNotSame($first['relation_candidates'][0]['candidate_id'], $third['relation_candidates'][0]['candidate_id']);
    }

    public function test_relation_candidate_fingerprint_changes_when_predicate_or_endpoint_identity_changes(): void
    {
        $otherClassification = UuidCodec::newV7();
        $states = [
            'classification' => [self::SOURCE => ['active' => true, 'revision' => 1]],
            'knowledge' => [self::TARGET => ['active' => true, 'revision' => 1]],
        ];
        $base = $this->planner($states)->plan([$this->intent()]);
        $predicate = $this->planner(['classification' => $states['classification'] + [$otherClassification => ['active' => true, 'revision' => 1]]])->plan([
            $this->intent(['predicate' => 'subtype_of', 'target_type' => 'classification', 'target_uuid' => $otherClassification]),
        ]);
        $otherTarget = UuidCodec::newV7();
        $identity = $this->planner($states + ['knowledge' => $states['knowledge'] + [$otherTarget => ['active' => true, 'revision' => 1]]])->plan([$this->intent(['target_uuid' => $otherTarget])]);

        $baseId = $base['relation_candidates'][0]['candidate_id'];
        self::assertNotSame($baseId, $predicate['relation_candidates'][0]['candidate_id']);
        self::assertNotSame($baseId, $identity['relation_candidates'][0]['candidate_id']);
        self::assertNotSame(
            AuthorityPlanFingerprint::compute('capture', 1, $base),
            AuthorityPlanFingerprint::compute('capture', 1, $identity),
        );
    }

    private function types(): \NHK\Core\Domain\Authority\EntityTypeRegistry
    {
        $types = new \NHK\Core\Domain\Authority\EntityTypeRegistry();
        \NHK\Core\Domain\Authority\CanonicalEntityTypeCatalog::registerInto($types);
        return $types;
    }

    private function authorityRepository(): AuthorityRepository
    {
        return new class implements AuthorityRepository {
            public function findByCanonicalId(string $id): ?AuthorityEntity { return null; }
            public function findByStableKey(string $type, string $key): ?AuthorityEntity { return null; }
            public function create(AuthorityEntity $entity): AuthorityEntity { return $entity; }
            public function update(AuthorityEntity $entity, int $expectedRevision): AuthorityEntity { return $entity; }
            public function rekey(AuthorityEntity $entity, string $oldStableKey, string $newStableKey, int $expectedRevision): AuthorityEntity { return $entity; }
            public function listByType(string $type, bool $includeRetired = false): array { return []; }
        };
    }

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    private function intent(array $changes = []): array
    {
        return array_replace([
            'source_type' => 'classification',
            'source_uuid' => self::SOURCE,
            'predicate' => 'about',
            'target_type' => 'knowledge',
            'target_uuid' => self::TARGET,
            'provenance' => 'EXPLICIT_USER_RELATION',
            'reason' => 'Explicit canonical relation request.',
        ], $changes);
    }

    /** @param array<string,array<string,array{active:bool,revision:int}>> $states @param array<string,array<string,mixed>> $edges */
    private function planner(array $states, array $edges = []): ExplicitRelationIntentPlanner
    {
        $endpoints = new \NHK\Core\Domain\Graph\EndpointTypeRegistry();
        foreach ($states as $type => $records) $endpoints->register($type, new PlannerEndpointResolver($type, $records));
        return new ExplicitRelationIntentPlanner(
            $endpoints,
            new PredicateRegistry(),
            static function (NodeReference $reference) use ($states): ?array {
                return $states[$reference->endpoint_type][$reference->endpoint_key] ?? null;
            },
            static function (array $packet) use ($edges): ?array {
                $key = strtolower((string) ($packet['source_type'] ?? '')) . ':' . strtolower((string) ($packet['source_uuid'] ?? '')) . '|' . strtolower((string) ($packet['predicate'] ?? '')) . '|' . strtolower((string) ($packet['target_type'] ?? '')) . ':' . strtolower((string) ($packet['target_uuid'] ?? ''));
                return $edges[$key] ?? null;
            },
        );
    }

    private function key(): string
    {
        return 'classification:' . strtolower(self::SOURCE) . '|about|knowledge:' . strtolower(self::TARGET);
    }
}

final class PlannerEndpointResolver implements EndpointRevisionReader
{
    /** @param array<string,array{active:bool,revision:int}> $records */
    public function __construct(private string $type, private array $records) {}
    public function supports(string $endpoint_type): bool { return $endpoint_type === $this->type; }
    public function exists(NodeReference $reference): bool { return isset($this->records[$reference->endpoint_key]); }
    public function normalize(NodeReference $reference): NodeReference { return new NodeReference($this->type, strtolower(trim($reference->endpoint_key))); }
    public function revision(NodeReference $reference): ?int { return $this->records[$reference->endpoint_key]['revision'] ?? null; }
}
