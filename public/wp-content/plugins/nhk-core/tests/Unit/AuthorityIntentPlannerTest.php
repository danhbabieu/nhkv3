<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityIntentPlanner;
use NHK\Core\Domain\Authority\{AuthorityEntity, AuthorityState, CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class AuthorityIntentPlannerTest extends TestCase
{
    private EntityTypeRegistry $types;

    protected function setUp(): void
    {
        $this->types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($this->types);
    }

    public function test_absent_brand_produces_one_server_owned_create_candidate(): void
    {
        $planner = new AuthorityIntentPlanner(new PlannerAuthorityRepository(), $this->types);

        $plan = $planner->plan(
            ['text' => 'Tạo thương hiệu Hermle.', 'authority_intent' => ['mode' => 'PLAN']],
            ['capture_id' => UuidCodec::newV7(), 'capture_revision' => 1],
        );

        self::assertCount(1, $plan['create_candidates']);
        self::assertSame('brand', $plan['create_candidates'][0]['entity_type']);
        self::assertSame('Hermle', $plan['create_candidates'][0]['proposed_canonical_name']);
        self::assertSame('nhk:brand:hermle', $plan['create_candidates'][0]['stable_key_preview']);
        self::assertSame([], $plan['reuse']);
    }

    public function test_existing_clock_types_and_france_are_reused_without_combined_identity(): void
    {
        $repository = new PlannerAuthorityRepository([
            $this->entity('classification', 'nhk:classification:clock-type.table-clock', 'Đồng hồ để bàn', ['family' => 'clock-type']),
            $this->entity('classification', 'nhk:classification:clock-type.cuckoo-clock', 'Đồng hồ cúc cu', ['family' => 'clock-type']),
            $this->entity('classification', 'nhk:classification:origin.france', 'Pháp', ['family' => 'origin']),
        ]);
        $planner = new AuthorityIntentPlanner($repository, $this->types);

        $plan = $planner->plan(
            ['text' => 'Đồng hồ để bàn Pháp và Đồng hồ cúc cu đã có chưa?', 'authority_intent' => ['mode' => 'PLAN']],
            ['capture_id' => UuidCodec::newV7(), 'capture_revision' => 1],
        );

        self::assertCount(3, $plan['reuse']);
        self::assertSame([], $plan['create_candidates']);
        self::assertNotContains('French Table Clock', array_column($plan['create_candidates'], 'proposed_canonical_name'));
        self::assertSame('COMPOSED_FACETS_NOT_NEW_IDENTITY', $plan['rejected_or_composed_facets'][0]['reason']);
    }

    public function test_missing_public_clock_is_planned_as_one_clock_type_create(): void
    {
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository(), $this->types))->plan(
            ['text' => 'Tạo loại Đồng hồ công cộng.', 'authority_intent' => ['mode' => 'PLAN']],
            ['capture_id' => UuidCodec::newV7(), 'capture_revision' => 1],
        );

        self::assertCount(1, $plan['create_candidates']);
        self::assertSame('classification', $plan['create_candidates'][0]['entity_type']);
        self::assertSame('clock-type', $plan['create_candidates'][0]['family']);
        self::assertSame('Đồng hồ công cộng', $plan['create_candidates'][0]['proposed_canonical_name']);
    }

    public function test_existing_public_clock_is_reused_without_create(): void
    {
        $repository = new PlannerAuthorityRepository([
            $this->entity('classification', 'nhk:classification:clock-type.public-clock', 'Đồng hồ công cộng', ['family' => 'clock-type']),
        ]);
        $plan = (new AuthorityIntentPlanner($repository, $this->types))->plan(
            ['text' => 'Tạo loại Đồng hồ công cộng.', 'authority_intent' => ['mode' => 'PLAN']],
            ['capture_id' => UuidCodec::newV7(), 'capture_revision' => 1],
        );

        self::assertCount(1, $plan['reuse']);
        self::assertSame('nhk:classification:clock-type.public-clock', $plan['reuse'][0]['stable_key']);
        self::assertSame([], $plan['create_candidates']);
    }

    public function test_glass_dome_is_reviewed_without_model_type_or_subtype_creation(): void
    {
        $planner = new AuthorityIntentPlanner(new PlannerAuthorityRepository(), $this->types);

        $plan = $planner->plan(
            ['text' => 'Hermle để bàn có ly úp.', 'authority_intent' => ['mode' => 'PLAN']],
            ['capture_id' => UuidCodec::newV7(), 'capture_revision' => 1],
        );

        self::assertSame([], array_filter($plan['create_candidates'], static fn (array $candidate): bool => in_array($candidate['entity_type'], ['model', 'classification'], true)));
        self::assertSame([], $plan['relation_candidates']);
        self::assertContains('GLASS_DOME_SEMANTIC_REVIEW_REQUIRED', array_column($plan['ambiguities'], 'code'));
    }

    public function test_ambiguous_brand_is_not_fuzzy_created(): void
    {
        $repository = new PlannerAuthorityRepository([
            $this->entity('brand', 'nhk:brand:hermle-a', 'Hermle A'),
            $this->entity('brand', 'nhk:brand:hermle-b', 'Hermle B'),
        ]);
        $planner = new AuthorityIntentPlanner($repository, $this->types);

        $plan = $planner->plan(
            ['text' => 'Tạo thương hiệu Hermle.', 'authority_intent' => ['mode' => 'PLAN']],
            ['capture_id' => UuidCodec::newV7(), 'capture_revision' => 1],
        );

        self::assertSame('IDENTITY_CONFLICT', $plan['ambiguities'][0]['code']);
        self::assertSame([], $plan['create_candidates']);
    }

    public function test_existence_question_never_turns_a_missing_classification_into_a_create_candidate(): void
    {
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository(), $this->types))->plan(
            ['text' => 'Đồng hồ cúc cu đã có chưa?', 'authority_intent' => ['mode' => 'PLAN']],
            ['capture_id' => UuidCodec::newV7(), 'capture_revision' => 1],
        );

        self::assertSame([], $plan['create_candidates']);
        self::assertSame('CANONICAL_NOT_FOUND', $plan['ambiguities'][0]['code']);
    }

    public function test_client_cannot_override_server_owned_create_stable_key(): void
    {
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository(), $this->types))->plan(
            ['text' => 'Tạo thương hiệu Hermle.', 'stable_key' => 'client:forged', 'authority_intent' => ['mode' => 'PLAN']],
            ['capture_id' => UuidCodec::newV7(), 'capture_revision' => 1],
        );

        self::assertSame('nhk:brand:hermle', $plan['create_candidates'][0]['stable_key_preview']);
    }

    public function test_true_mantel_clock_hierarchy_is_planned_as_subtype_relation(): void
    {
        $repository = new PlannerAuthorityRepository([
            $this->entity('classification', 'nhk:classification:clock-type.table-clock', 'Đồng hồ để bàn', ['family' => 'clock-type']),
            $this->entity('classification', 'nhk:classification:clock-type.mantel-clock', 'Mantel Clock', ['family' => 'clock-type']),
        ]);
        $plan = (new AuthorityIntentPlanner($repository, $this->types))->plan(
            ['text' => 'Mantel Clock thuộc Đồng hồ để bàn.', 'authority_intent' => ['mode' => 'PLAN']],
            ['capture_id' => UuidCodec::newV7(), 'capture_revision' => 1],
        );

        self::assertCount(1, $plan['relation_candidates']);
        self::assertSame('subtype_of', $plan['relation_candidates'][0]['predicate']);
        self::assertSame('classification', $plan['relation_candidates'][0]['source_type']);
        self::assertSame('classification', $plan['relation_candidates'][0]['target_type']);
        self::assertSame([], $plan['create_candidates']);
    }

    private function entity(string $type, string $key, string $name, array $payload = []): AuthorityEntity
    {
        return new AuthorityEntity(UuidCodec::newV7(), $type, $key, $name, 1, $payload, AuthorityState::ACTIVE, 1);
    }
}

final class PlannerAuthorityRepository implements AuthorityRepository
{
    /** @param list<AuthorityEntity> $items */
    public function __construct(private array $items = []) {}
    public function findByCanonicalId(string $id): ?AuthorityEntity { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
    public function findByStableKey(string $type, string $key): ?AuthorityEntity { foreach ($this->items as $item) if ($item->entityType === $type && $item->stableKey === $key) return $item; return null; }
    public function create(AuthorityEntity $entity): AuthorityEntity { return $entity; }
    public function update(AuthorityEntity $entity, int $expectedRevision): AuthorityEntity { return $entity; }
    public function rekey(AuthorityEntity $entity, string $oldStableKey, string $newStableKey, int $expectedRevision): AuthorityEntity { return $entity; }
    public function listByType(string $type, bool $includeRetired = false): array { return array_values(array_filter($this->items, static fn (AuthorityEntity $item): bool => $item->entityType === $type && ($includeRetired || $item->active()))); }
}
