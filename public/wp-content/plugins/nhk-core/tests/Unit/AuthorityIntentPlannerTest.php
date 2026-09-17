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
        self::assertCount(1, $plan['reuse']);
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

    public function test_existing_brand_full_allowed_delta_is_one_exact_update_candidate(): void
    {
        $entity = $this->entity('brand', 'nhk:brand:hermle', 'Hermle', [
            'aliases' => ['Gebrüder Hermle'],
            'description' => 'Cũ.',
            'country' => 'Đức',
            'founded_year' => 1920,
        ]);
        $delta = [
            'aliases' => ['Gebrüder Hermle', 'Hermle Uhren'],
            'description' => 'Mô tả mới.',
            'country' => 'Đức',
            'founded_year' => 1922,
        ];

        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository([$entity]), $this->types))->plan([
            'text' => 'Cập nhật hồ sơ Hermle.',
            'authority_intent' => ['mode' => 'PLAN', 'requests' => [[
                'entity_type' => 'brand', 'name' => 'Hermle', 'payload_delta' => $delta,
            ]]],
        ]);

        self::assertCount(1, $plan['update_candidates']);
        self::assertSame([], $plan['create_candidates']);
        self::assertCount(1, $plan['reuse']);
        self::assertSame(['aliases' => ['Gebrüder Hermle', 'Hermle Uhren'], 'description' => 'Mô tả mới.', 'founded_year' => 1922], $plan['update_candidates'][0]['payload_patch']);
        self::assertSame($entity->canonicalId, $plan['update_candidates'][0]['canonical_uuid']);
        self::assertSame($entity->revision, $plan['update_candidates'][0]['expected_revision']);
        self::assertSame($entity->payload, $plan['update_candidates'][0]['before']);
        self::assertSame(array_replace($entity->payload, $delta), $plan['update_candidates'][0]['after']);
    }

    public function test_same_brand_allowed_delta_is_explicit_noop_reuse(): void
    {
        $entity = $this->entity('brand', 'nhk:brand:hermle', 'Hermle', [
            'aliases' => ['Hermle Uhren'], 'description' => 'Mô tả.', 'country' => 'Đức', 'founded_year' => 1922,
        ]);
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository([$entity]), $this->types))->plan([
            'authority_intent' => ['requests' => [[
                'entity_type' => 'brand', 'name' => 'Hermle', 'payload_delta' => $entity->payload,
            ]]],
        ]);

        self::assertCount(1, $plan['reuse']);
        self::assertSame('NOOP_VALUES_MATCH', $plan['reuse'][0]['reason']);
        self::assertSame([], $plan['update_candidates']);
    }

    public function test_structured_brand_update_is_reused_and_emits_one_exact_update_candidate(): void
    {
        $canonicalId = '01a090fd-9a71-7665-af5f-08f6e25b533e';
        $entity = $this->entity('brand', 'nhk:brand:hermle', 'Hermle', [], $canonicalId);
        $delta = [
            'country' => 'Germany',
            'founded_year' => 1922,
            'aliases' => ['Franz Hermle & Sohn'],
            'description' => 'German clock manufacturer founded in 1922.',
        ];

        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository([$entity]), $this->types))->plan([
            'text' => 'Bổ sung thông tin cho Hermle.',
            'subject_hints' => ['Hermle', $canonicalId],
            'authority_intent' => ['mode' => 'PLAN', 'requests' => [[
                'entity_type' => 'brand',
                'canonical_uuid' => $canonicalId,
                'name' => 'Hermle',
                'payload_delta' => $delta,
            ]]],
        ]);

        self::assertCount(1, $plan['reuse']);
        self::assertSame($canonicalId, $plan['reuse'][0]['canonical_uuid']);
        self::assertCount(1, $plan['update_candidates']);
        self::assertSame($canonicalId, $plan['update_candidates'][0]['canonical_uuid']);
        self::assertSame(1, $plan['update_candidates'][0]['expected_revision']);
        self::assertSame($delta, $plan['update_candidates'][0]['payload_patch']);
        self::assertSame([], $plan['create_candidates']);
        self::assertSame([], array_filter($plan['create_candidates'], static fn (array $candidate): bool => $candidate['entity_type'] === 'classification'));
    }

    public function test_uuid_only_structured_brand_update_hydrates_canonical_identity_before_create_validation(): void
    {
        $canonicalId = '01a090fd-9a71-7665-af5f-08f6e25b533e';
        $entity = $this->entity('brand', 'nhk:brand:hermle', 'Hermle', [], $canonicalId);
        $delta = ['country' => 'Germany', 'founded_year' => 1922];

        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository([$entity]), $this->types))->plan([
            'authority_intent' => ['mode' => 'PLAN', 'requests' => [[
                'entity_type' => 'brand',
                'canonical_uuid' => $canonicalId,
                'payload_delta' => $delta,
                'allow_create' => false,
            ]]],
        ], ['capture_id' => UuidCodec::newV7(), 'capture_revision' => 1]);

        self::assertSame([], $plan['blockers']);
        self::assertSame([], $plan['ambiguities']);
        self::assertCount(1, $plan['reuse']);
        self::assertCount(1, $plan['update_candidates']);
        self::assertSame([], $plan['create_candidates']);
        self::assertSame($canonicalId, $plan['reuse'][0]['canonical_uuid']);
        self::assertSame('Hermle', $plan['reuse'][0]['canonical_name']);
        self::assertSame('nhk:brand:hermle', $plan['reuse'][0]['stable_key']);
        self::assertSame($canonicalId, $plan['update_candidates'][0]['canonical_uuid']);
        self::assertSame('Hermle', $plan['update_candidates'][0]['canonical_name']);
        self::assertSame('nhk:brand:hermle', $plan['update_candidates'][0]['stable_key']);
        self::assertSame(1, $plan['update_candidates'][0]['expected_revision']);
        self::assertSame($delta, $plan['update_candidates'][0]['payload_patch']);
    }

    public function test_uuid_only_update_has_the_same_plan_as_redundant_matching_name(): void
    {
        $canonicalId = '01a090fd-9a71-7665-af5f-08f6e25b533e';
        $entity = $this->entity('brand', 'nhk:brand:hermle', 'Hermle', [], $canonicalId);
        $base = ['entity_type' => 'brand', 'canonical_uuid' => $canonicalId, 'payload_delta' => ['country' => 'Germany']];
        $planner = new AuthorityIntentPlanner(new PlannerAuthorityRepository([$entity]), $this->types);

        $withoutName = $planner->plan(['authority_intent' => ['mode' => 'PLAN', 'requests' => [$base]]]);
        $withName = $planner->plan(['authority_intent' => ['mode' => 'PLAN', 'requests' => [$base + ['name' => 'Hermle']]]]);

        self::assertSame($withoutName['plan_fingerprint'], $withName['plan_fingerprint']);
        self::assertSame($withoutName['reuse'], $withName['reuse']);
        self::assertSame($withoutName['update_candidates'], $withName['update_candidates']);
    }

    public function test_uuid_with_conflicting_name_fails_closed_without_overriding_uuid(): void
    {
        $canonicalId = '01a090fd-9a71-7665-af5f-08f6e25b533e';
        $entity = $this->entity('brand', 'nhk:brand:hermle', 'Hermle', [], $canonicalId);
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository([$entity]), $this->types))->plan([
            'authority_intent' => ['mode' => 'PLAN', 'requests' => [[
                'entity_type' => 'brand', 'canonical_uuid' => $canonicalId, 'name' => 'Some Other Brand', 'allow_create' => true,
            ]]],
        ]);

        self::assertSame([], $plan['reuse']);
        self::assertSame([], $plan['update_candidates']);
        self::assertSame([], $plan['create_candidates']);
        self::assertContains('IDENTITY_CONFLICT', array_column($plan['ambiguities'], 'code'));
    }

    public function test_uuid_not_found_does_not_fallback_to_create_even_when_creation_is_allowed(): void
    {
        $canonicalId = '01a090fd-9a71-7665-af5f-08f6e25b533e';
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository(), $this->types))->plan([
            'authority_intent' => ['mode' => 'PLAN', 'requests' => [[
                'entity_type' => 'brand', 'canonical_uuid' => $canonicalId, 'allow_create' => true,
            ]]],
        ]);

        self::assertSame([], $plan['reuse']);
        self::assertSame([], $plan['create_candidates']);
        self::assertContains('AUTHORITY_UUID_NOT_FOUND', array_column($plan['blockers'], 'code'));
    }

    public function test_uuid_only_matching_payload_is_a_noop_reuse(): void
    {
        $canonicalId = '01a090fd-9a71-7665-af5f-08f6e25b533e';
        $entity = $this->entity('brand', 'nhk:brand:hermle', 'Hermle', ['country' => 'Germany'], $canonicalId);
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository([$entity]), $this->types))->plan([
            'authority_intent' => ['mode' => 'PLAN', 'requests' => [[
                'entity_type' => 'brand', 'canonical_uuid' => $canonicalId, 'payload_delta' => ['country' => 'Germany'],
            ]]],
        ]);

        self::assertSame([], $plan['update_candidates']);
        self::assertSame('NOOP_VALUES_MATCH', $plan['reuse'][0]['reason']);
    }

    public function test_exact_brand_name_subject_hint_is_locator_only(): void
    {
        $entity = $this->entity('brand', 'nhk:brand:hermle', 'Hermle');
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository([$entity]), $this->types))->plan([
            'subject_hints' => ['Hermle'],
            'authority_intent' => ['mode' => 'PLAN'],
        ]);

        self::assertCount(1, $plan['reuse']);
        self::assertSame('brand', $plan['reuse'][0]['entity_type']);
        self::assertSame([], $plan['create_candidates']);
    }

    public function test_exact_brand_uuid_subject_hint_is_locator_only(): void
    {
        $canonicalId = '01a090fd-9a71-7665-af5f-08f6e25b533e';
        $entity = $this->entity('brand', 'nhk:brand:hermle', 'Hermle', [], $canonicalId);
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository([$entity]), $this->types))->plan([
            'subject_hints' => [$canonicalId],
            'authority_intent' => ['mode' => 'PLAN'],
        ]);

        self::assertCount(1, $plan['reuse']);
        self::assertSame($canonicalId, $plan['reuse'][0]['canonical_uuid']);
        self::assertSame([], $plan['create_candidates']);
    }

    public function test_name_and_uuid_subject_hints_resolve_to_one_canonical_subject(): void
    {
        $canonicalId = '01a090fd-9a71-7665-af5f-08f6e25b533e';
        $entity = $this->entity('brand', 'nhk:brand:hermle', 'Hermle', [], $canonicalId);
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository([$entity]), $this->types))->plan([
            'subject_hints' => ['Hermle', $canonicalId],
            'authority_intent' => ['mode' => 'PLAN'],
        ]);

        self::assertCount(1, $plan['reuse']);
        self::assertSame($canonicalId, $plan['reuse'][0]['canonical_uuid']);
        self::assertSame([], $plan['create_candidates']);
    }

    public function test_ambiguous_subject_hint_requires_review_without_creation(): void
    {
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository([
            $this->entity('brand', 'nhk:brand:hermle', 'Hermle'),
            $this->entity('model', 'nhk:model:hermle', 'Hermle'),
        ]), $this->types))->plan([
            'subject_hints' => ['Hermle'],
            'authority_intent' => ['mode' => 'PLAN'],
        ]);

        self::assertSame([], $plan['create_candidates']);
        self::assertContains('AMBIGUOUS_SUBJECT_HINT', array_column($plan['ambiguities'], 'code'));
    }

    public function test_existing_canonical_name_in_text_is_resolved_without_type_specific_lexicon(): void
    {
        $entity = $this->entity('brand', 'nhk:brand:junghans', 'Junghans');
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository([$entity]), $this->types))->plan([
            'text' => 'Ghi chú về Junghans.',
            'authority_intent' => ['mode' => 'PLAN'],
        ]);

        self::assertCount(1, $plan['reuse']);
        self::assertSame('brand', $plan['reuse'][0]['entity_type']);
        self::assertSame([], $plan['create_candidates']);
    }

    public function test_malformed_structured_delta_fails_closed(): void
    {
        $entity = $this->entity('brand', 'nhk:brand:hermle', 'Hermle');
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository([$entity]), $this->types))->plan([
            'authority_intent' => ['mode' => 'PLAN', 'requests' => [[
                'entity_type' => 'brand',
                'name' => 'Hermle',
                'payload_delta' => 'country=Germany',
            ]]],
        ]);

        self::assertSame([], $plan['create_candidates']);
        self::assertSame([], $plan['update_candidates']);
        self::assertContains('MALFORMED_AUTHORITY_PAYLOAD_DELTA', array_column($plan['blockers'], 'code'));
    }

    public function test_structured_uuid_type_mismatch_fails_closed_without_creating_other_type(): void
    {
        $canonicalId = '01a090fd-9a71-7665-af5f-08f6e25b533e';
        $entity = $this->entity('brand', 'nhk:brand:hermle', 'Hermle', [], $canonicalId);
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository([$entity]), $this->types))->plan([
            'authority_intent' => ['mode' => 'PLAN', 'requests' => [[
                'entity_type' => 'model',
                'canonical_uuid' => $canonicalId,
                'allow_create' => true,
            ]]],
        ]);

        self::assertSame([], $plan['create_candidates']);
        self::assertContains('AUTHORITY_UUID_TYPE_MISMATCH', array_column($plan['blockers'], 'code'));
    }

    public function test_retired_structured_target_requires_explicit_reactivation(): void
    {
        $canonicalId = '01a090fd-9a71-7665-af5f-08f6e25b533e';
        $retired = new AuthorityEntity($canonicalId, 'brand', 'nhk:brand:hermle', 'Hermle', 1, [], AuthorityState::RETIRED, 1);
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository([$retired]), $this->types))->plan([
            'authority_intent' => ['mode' => 'PLAN', 'requests' => [[
                'entity_type' => 'brand',
                'canonical_uuid' => $canonicalId,
                'allow_create' => true,
            ]]],
        ]);

        self::assertSame([], $plan['create_candidates']);
        self::assertContains('RETIRED_TARGET_REQUIRES_EXPLICIT_REACTIVATION', array_column($plan['blockers'], 'code'));
    }

    public function test_structured_authority_target_wins_over_conflicting_raw_text(): void
    {
        $entity = $this->entity('brand', 'nhk:brand:hermle', 'Hermle');
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository([$entity]), $this->types))->plan([
            'text' => 'Tạo loại Đồng hồ công cộng.',
            'authority_intent' => ['mode' => 'PLAN', 'requests' => [[
                'entity_type' => 'brand',
                'name' => 'Hermle',
            ]]],
        ]);

        self::assertCount(1, $plan['reuse']);
        self::assertSame('brand', $plan['reuse'][0]['entity_type']);
        self::assertSame([], $plan['create_candidates']);
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

    public function test_uuid_only_updates_use_one_generic_path_for_every_registered_authority_type(): void
    {
        $fields = [
            'brand' => ['country' => 'Germany'],
            'model' => ['description' => 'Updated model.'],
            'variant' => ['reference' => 'REF-2'],
            'movement' => ['caliber' => 'CAL-2'],
            'music' => ['artist' => 'Updated artist'],
            'component' => ['kind' => 'Updated component'],
            'classification' => ['description' => 'Updated classification.'],
            'specimen' => ['notes' => 'Updated notes.'],
            'product' => ['vendor' => 'Updated vendor'],
        ];

        foreach ($fields as $type => $delta) {
            $entity = $this->entity($type, 'nhk:' . $type . ':fixture', ucfirst($type) . ' Fixture');
            $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository([$entity]), $this->types))->plan([
                'authority_intent' => ['mode' => 'PLAN', 'requests' => [[
                    'entity_type' => $type,
                    'canonical_uuid' => $entity->canonicalId,
                    'payload_delta' => $delta,
                    'allow_create' => false,
                ]]],
            ]);

            self::assertSame([], $plan['blockers'], $type);
            self::assertSame([], $plan['ambiguities'], $type);
            self::assertCount(1, $plan['reuse'], $type);
            self::assertCount(1, $plan['update_candidates'], $type);
            self::assertSame([], $plan['create_candidates'], $type);
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

    public function test_authority_only_purpose_cannot_be_promoted_to_mixed_article_mode(): void
    {
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository(), $this->types))->plan(
            ['purpose' => 'AUTHORITY', 'text' => 'Tạo thương hiệu Hermle và một bài giới thiệu.', 'authority_intent' => ['mode' => 'PLAN']],
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

    public function test_structured_model_create_with_exact_brand_parent_plans_model_of_dependency(): void
    {
        $brandId = '01a090fd-9a71-7665-af5f-08f6e25b533e';
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository([
            $this->entity('brand', 'nhk:brand:hermle', 'Hermle', [], $brandId),
        ]), $this->types))->plan([
            'authority_intent' => ['mode' => 'PLAN', 'requests' => [[
                'entity_type' => 'model',
                'name' => 'Atherton',
                'payload_delta' => ['brand_uuid' => $brandId, 'description' => 'A model.'],
                'allow_create' => true,
            ]]],
        ]);

        self::assertCount(1, $plan['create_candidates']);
        self::assertCount(1, $plan['relation_candidates']);
        self::assertSame('model_of', $plan['relation_candidates'][0]['predicate']);
        self::assertSame($plan['create_candidates'][0]['candidate_id'], $plan['relation_candidates'][0]['source_candidate_id']);
        self::assertSame($brandId, $plan['relation_candidates'][0]['target_uuid']);
        self::assertSame([$plan['create_candidates'][0]['candidate_id']], $plan['relation_candidates'][0]['dependencies']);
    }

    public function test_structured_variant_update_with_exact_model_parent_plans_variant_of_relation(): void
    {
        $modelId = '01a090fd-9a71-7665-af5f-08f6e25b533e';
        $variantId = '01a090fd-9a71-7665-af5f-08f6e25b533f';
        $repository = new PlannerAuthorityRepository([
            $this->entity('model', 'nhk:model:hermle.atherton', 'Atherton', [], $modelId),
            $this->entity('variant', 'nhk:variant:hermle.atherton.black', 'Black', [], $variantId),
        ]);
        $plan = (new AuthorityIntentPlanner($repository, $this->types))->plan([
            'authority_intent' => ['mode' => 'PLAN', 'requests' => [[
                'entity_type' => 'variant',
                'canonical_uuid' => $variantId,
                'payload_delta' => ['model_uuid' => $modelId],
            ]]],
        ]);

        self::assertCount(1, $plan['relation_candidates']);
        self::assertSame('variant_of', $plan['relation_candidates'][0]['predicate']);
        self::assertSame($variantId, $plan['relation_candidates'][0]['source_uuid']);
        self::assertSame($modelId, $plan['relation_candidates'][0]['target_uuid']);
        self::assertSame([], $plan['blockers']);
    }

    public function test_structural_parent_binding_fails_closed_for_missing_or_wrong_type_parent(): void
    {
        $modelId = '01a090fd-9a71-7665-af5f-08f6e25b533e';
        $plan = (new AuthorityIntentPlanner(new PlannerAuthorityRepository([
            $this->entity('model', 'nhk:model:hermle.atherton', 'Atherton', [], $modelId),
        ]), $this->types))->plan([
            'authority_intent' => ['mode' => 'PLAN', 'requests' => [[
                'entity_type' => 'model',
                'name' => 'Missing Parent Model',
                'payload_delta' => ['brand_uuid' => $modelId],
                'allow_create' => true,
            ]]],
        ]);

        self::assertSame([], $plan['create_candidates']);
        self::assertSame([], $plan['relation_candidates']);
        self::assertContains('AUTHORITY_STRUCTURAL_PARENT_TYPE_MISMATCH', array_column($plan['blockers'], 'code'));
    }

    private function entity(string $type, string $key, string $name, array $payload = [], ?string $canonicalId = null): AuthorityEntity
    {
        return new AuthorityEntity($canonicalId ?? UuidCodec::newV7(), $type, $key, $name, 1, $payload, AuthorityState::ACTIVE, 1);
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
