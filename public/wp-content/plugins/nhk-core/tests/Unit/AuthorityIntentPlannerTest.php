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
        self::assertSame('clock_type', $plan['create_candidates'][0]['family']);
        self::assertSame('Đồng hồ công cộng', $plan['create_candidates'][0]['proposed_canonical_name']);
    }

    public function test_add_clock_type_uses_canonical_family_and_complete_review_candidate_shape(): void
    {
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository(), $this->types))->plan(
            ['text' => 'Thêm loại Đồng hồ công cộng.', 'authority_intent' => ['mode' => 'PLAN']],
            ['capture_id' => UuidCodec::newV7(), 'capture_revision' => 1],
        );

        self::assertCount(1, $plan['create_candidates']);
        $candidate = $plan['create_candidates'][0];
        self::assertSame('classification', $candidate['entity_type']);
        self::assertSame('clock_type', $candidate['family']);
        foreach (['name', 'aliases', 'description', 'proposed_stable_key', 'provenance', 'ambiguities', 'blockers'] as $field) self::assertArrayHasKey($field, $candidate);
        self::assertStringStartsWith('nhk:classification:clock-type.', $candidate['proposed_stable_key']);
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

    public function test_existing_classification_with_explicit_description_delta_produces_update_candidate(): void
    {
        $description = 'Đồng hồ công cộng phục vụ việc công bố thời gian trong không gian chung.';
        $entity = $this->entity('classification', 'nhk:classification:clock-type.public-clock', 'Đồng hồ công cộng', ['family' => 'clock_type', 'description' => 'Mô tả cũ.']);
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository([$entity]), $this->types))->plan(
            [
                'text' => 'Cập nhật mô tả cho Đồng hồ công cộng.',
                'authority_intent' => [
                    'mode' => 'PLAN',
                    'requests' => [[
                        'entity_type' => 'classification',
                        'name' => 'Đồng hồ công cộng',
                        'payload_delta' => ['description' => $description],
                    ]],
                ],
            ],
            ['capture_id' => UuidCodec::newV7(), 'capture_revision' => 4],
        );

        self::assertCount(1, $plan['update_candidates']);
        self::assertSame([], $plan['create_candidates']);
        self::assertSame([], $plan['reuse']);
        self::assertSame($entity->canonicalId, $plan['update_candidates'][0]['canonical_uuid']);
        self::assertSame($entity->revision, $plan['update_candidates'][0]['expected_revision']);
        self::assertSame(['description' => $description], $plan['update_candidates'][0]['payload_patch']);
        self::assertSame(['family' => 'clock_type', 'description' => $description], $plan['update_candidates'][0]['entity_payload']);
    }

    public function test_same_description_delta_reuses_existing_classification_without_update(): void
    {
        $description = 'Đồng hồ công cộng đã có mô tả.';
        $entity = $this->entity('classification', 'nhk:classification:clock-type.public-clock', 'Đồng hồ công cộng', ['family' => 'clock_type', 'description' => $description]);
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository([$entity]), $this->types))->plan(
            [
                'text' => 'Kiểm tra mô tả Đồng hồ công cộng.',
                'authority_intent' => ['mode' => 'PLAN', 'requests' => [[
                    'entity_type' => 'classification',
                    'name' => 'Đồng hồ công cộng',
                    'payload_delta' => ['description' => $description],
                ]]],
            ],
            ['capture_id' => UuidCodec::newV7(), 'capture_revision' => 1],
        );

        self::assertCount(1, $plan['reuse']);
        self::assertSame([], $plan['update_candidates']);
        self::assertSame([], $plan['create_candidates']);
    }

    public function test_existing_uuid_in_structured_intent_reuses_the_exact_authority_without_duplicate_creation(): void
    {
        $entity = $this->entity('classification', 'nhk:classification:clock-type.public-clock', 'Đồng hồ công cộng', ['family' => 'clock_type']);
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository([$entity]), $this->types))->plan([
            'text' => 'Đồng hồ công cộng — ' . $entity->canonicalId . '.',
            'authority_intent' => ['mode' => 'PLAN', 'requests' => [[
                'entity_type' => 'classification',
                'canonical_uuid' => $entity->canonicalId,
                'name' => 'Đồng hồ công cộng',
            ]]],
        ]);

        self::assertCount(1, $plan['reuse']);
        self::assertSame($entity->canonicalId, $plan['reuse'][0]['canonical_uuid']);
        self::assertSame([], $plan['create_candidates']);
        self::assertSame([], $plan['update_candidates']);
    }

    public function test_registry_unsupported_update_field_is_reviewed_without_mutating_plan(): void
    {
        $entity = $this->entity('classification', 'nhk:classification:clock-type.public-clock', 'Đồng hồ công cộng', ['family' => 'clock_type', 'description' => 'Mô tả.']);
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository([$entity]), $this->types))->plan(
            [
                'text' => 'Cập nhật Đồng hồ công cộng.',
                'authority_intent' => ['mode' => 'PLAN', 'requests' => [[
                    'entity_type' => 'classification',
                    'name' => 'Đồng hồ công cộng',
                    'payload_delta' => ['summary' => 'Không thuộc contract.'],
                ]]],
            ],
            ['capture_id' => UuidCodec::newV7(), 'capture_revision' => 1],
        );

        self::assertSame([], $plan['update_candidates']);
        self::assertSame([], $plan['create_candidates']);
        self::assertContains('UNSUPPORTED_AUTHORITY_FIELD', array_column($plan['blockers'], 'code'));
    }

    public function test_brand_country_delta_uses_the_same_registry_driven_update_path(): void
    {
        $entity = $this->entity('brand', 'nhk:brand:hermle', 'Hermle', ['description' => 'Mô tả.']);
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository([$entity]), $this->types))->plan(
            [
                'text' => 'Cập nhật quốc gia của Hermle.',
                'authority_intent' => ['mode' => 'PLAN', 'requests' => [[
                    'entity_type' => 'brand',
                    'name' => 'Hermle',
                    'payload_delta' => ['country' => 'Đức'],
                ]]],
            ],
            ['capture_id' => UuidCodec::newV7(), 'capture_revision' => 2],
        );

        self::assertCount(1, $plan['update_candidates']);
        self::assertSame(['description' => 'Mô tả.', 'country' => 'Đức'], $plan['update_candidates'][0]['entity_payload']);
        self::assertSame($entity->revision, $plan['update_candidates'][0]['expected_revision']);
    }

    public function test_replanning_same_update_input_is_fingerprint_stable_for_apply_approved_plan(): void
    {
        $entity = $this->entity('classification', 'nhk:classification:clock-type.public-clock', 'Đồng hồ công cộng', ['family' => 'clock_type', 'description' => 'Cũ.']);
        $input = [
            'text' => 'Cập nhật mô tả cho Đồng hồ công cộng.',
            'authority_intent' => ['mode' => 'APPLY_APPROVED_PLAN', 'requests' => [[
                'entity_type' => 'classification',
                'name' => 'Đồng hồ công cộng',
                'payload_delta' => ['description' => 'Mới.'],
            ]]],
        ];
        $context = ['capture_id' => UuidCodec::newV7(), 'capture_revision' => 7, 'contract' => ['documentation_version' => 'doc', 'manifest_hash' => 'hash']];
        $planner = new AuthorityIntentPlanner(new PlannerAuthorityRepository([$entity]), $this->types);

        $first = $planner->plan($input, $context);
        $replanned = $planner->plan($input, $context);

        self::assertSame($first['plan_fingerprint'], $replanned['plan_fingerprint']);
        self::assertSame($first['update_candidates'], $replanned['update_candidates']);
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

    public function test_four_hundred_day_group_without_canonical_id_stays_search_only(): void
    {
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository(), $this->types))->plan(
            [
                'text' => 'Tìm nhóm đồng hồ 400 ngày.',
                'authority_intent' => [
                    'mode' => 'PLAN',
                    'requests' => [[
                        'entity_type' => 'classification',
                        'name' => 'Đồng hồ 400 ngày',
                        'allow_create' => false,
                    ]],
                ],
            ],
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

    public function test_registered_authority_types_can_all_plan_through_one_generic_request_path(): void
    {
        foreach (['brand', 'model', 'variant', 'movement', 'music', 'component', 'specimen', 'product'] as $type) {
            $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository(), $this->types))->plan(
                ['text' => 'Tạo ' . $type . ' Build Fixture.', 'authority_intent' => ['mode' => 'PLAN']],
                ['capture_id' => UuidCodec::newV7(), 'capture_revision' => 1],
            );

            self::assertCount(1, $plan['create_candidates'], $type);
            self::assertSame($type, $plan['create_candidates'][0]['entity_type']);
        }
    }

    public function test_clock_type_fixture_uses_exact_canonical_family_for_plan_only(): void
    {
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository(), $this->types))->plan(
            ['text' => 'Tạo loại Đồng hồ công cộng.', 'authority_intent' => ['mode' => 'PLAN']],
            ['capture_id' => UuidCodec::newV7(), 'capture_revision' => 1],
        );

        self::assertSame('classification', $plan['create_candidates'][0]['entity_type']);
        self::assertSame('clock_type', $plan['create_candidates'][0]['family']);
        self::assertSame('nhk:classification:clock-type.dong-ho-cong-cong', $plan['create_candidates'][0]['proposed_stable_key']);
    }

    public function test_legacy_clock_type_family_is_a_typed_new_write_blocker(): void
    {
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository(), $this->types))->plan(
            ['text' => 'Tạo classification Legacy Clock family=clock-type.', 'authority_intent' => ['mode' => 'PLAN']],
            ['capture_id' => UuidCodec::newV7(), 'capture_revision' => 1],
        );

        self::assertSame([], $plan['create_candidates']);
        self::assertSame('LEGACY_CLOCK_TYPE_FAMILY_WRITE_REJECTED', $plan['blockers'][0]['code']);
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

    public function test_core_plan_exposes_authority_relation_and_article_sections(): void
    {
        $repository = new PlannerAuthorityRepository([
            $this->entity('classification', 'nhk:classification:clock-type.table-clock', 'Đồng hồ để bàn', ['family' => 'clock-type']),
            $this->entity('classification', 'nhk:classification:clock-type.mantel-clock', 'Mantel Clock', ['family' => 'clock-type']),
        ]);

        $plan = (new AuthorityIntentPlanner($repository, $this->types))->plan(
            ['text' => 'Tạo Mantel Clock nằm dưới Đồng hồ để bàn.', 'authority_intent' => ['mode' => 'PLAN']],
            ['capture_id' => UuidCodec::newV7(), 'capture_revision' => 1],
        );

        self::assertArrayHasKey('create_authorities', $plan);
        self::assertArrayHasKey('create_relations', $plan);
        self::assertArrayHasKey('article', $plan);
        self::assertCount(1, $plan['create_relations']);
        self::assertSame('subtype_of', $plan['create_relations'][0]['predicate']);
    }

    public function test_brand_plus_article_is_planned_as_mixed_without_polluting_brand_name(): void
    {
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository(), $this->types))->plan(
            ['text' => 'Tạo thương hiệu Hermle và một bài giới thiệu.', 'authority_intent' => ['mode' => 'PLAN']],
            ['capture_id' => UuidCodec::newV7(), 'capture_revision' => 1],
        );

        self::assertSame('Hermle', $plan['create_authorities'][0]['name']);
        self::assertTrue($plan['article']['requested']);
        self::assertSame('MIXED', $plan['article']['mode']);
    }

    public function test_non_article_plan_uses_null_article_section(): void
    {
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository(), $this->types))->plan(
            ['text' => 'Tạo thương hiệu Hermle.', 'authority_intent' => ['mode' => 'PLAN']],
            ['capture_id' => UuidCodec::newV7(), 'capture_revision' => 1],
        );

        self::assertNull($plan['article']);
    }

    public function test_new_hierarchy_endpoints_are_dependencies_of_one_relation_candidate(): void
    {
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository(), $this->types))->plan(
            ['text' => 'Tạo loại Mantel Clock nằm dưới Đồng hồ để bàn.', 'authority_intent' => ['mode' => 'PLAN']],
            ['capture_id' => UuidCodec::newV7(), 'capture_revision' => 1],
        );

        self::assertSame(['Mantel Clock', 'Đồng hồ để bàn'], array_column($plan['create_candidates'], 'proposed_canonical_name'));
        self::assertCount(1, $plan['relation_candidates']);
        self::assertSame('subtype_of', $plan['relation_candidates'][0]['predicate']);
        self::assertSame(
            array_column($plan['create_candidates'], 'candidate_id'),
            $plan['relation_candidates'][0]['dependencies'],
        );
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
