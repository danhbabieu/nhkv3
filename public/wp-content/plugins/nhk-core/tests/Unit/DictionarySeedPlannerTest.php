<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Dictionary\{DictionaryResolver, DictionarySeedPlanner};
use NHK\Core\Application\Semantic\CanonicalAuthoritySubjectResolver;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, AuthorityState, EntityTypeRegistry, CanonicalEntityTypeCatalog};
use NHK\Core\Application\Semantic\StructuredSemanticInterpreter;
use PHPUnit\Framework\TestCase;

final class DictionarySeedPlannerTest extends TestCase
{
    public function test_seed_plan_resolves_reuses_deduplicates_and_preserves_lineage_without_writes(): void
    {
        $resolver = new DictionaryResolver(
            static fn (string $term): array => $term === 'existing label' ? [['concept_id' => 'concept-1', 'preferred_label' => 'Existing Label', 'destination_type' => 'model', 'destination_id' => 'model-1']] : [],
            static fn (string $term): array => $term === 'alias' ? [['concept_id' => 'concept-1', 'preferred_label' => 'Existing Label', 'destination_type' => 'model', 'destination_id' => 'model-1']] : [],
            static fn (): array => [],
            static fn (): array => [],
            static fn (): bool => false,
        );
        $packet = (new StructuredSemanticInterpreter())->interpret([
            'text' => 'Existing Label và alias.',
            'source_kind' => 'article',
            'source_identifier' => 'article:1',
            'metadata' => ['lexical_hints' => ['Existing Label', 'existing label', 'alias']],
        ]);

        $result = (new DictionarySeedPlanner($resolver))->plan($packet, ['source_family' => 'article:1']);

        self::assertTrue($result['read_only']);
        self::assertFalse($result['mutated']);
        self::assertSame('READ_ONLY_PLAN', $result['status']);
        self::assertCount(2, $result['items']);
        self::assertSame('RESOLVED_EXISTING', $result['items'][0]['classification']);
        self::assertSame(['Existing Label'], $result['items'][0]['raw_forms']);
        self::assertSame(['article:1'], $result['items'][0]['source_families']);
        self::assertSame('ALIAS_TO_EXISTING', $result['items'][1]['classification']);
        self::assertSame('concept-1', $result['items'][1]['resolution']['concept_id']);
    }

    public function test_seed_plan_fails_closed_for_ambiguity_and_keeps_unknown_valid_terms(): void
    {
        $resolver = new DictionaryResolver(
            static fn (string $term): array => $term === 'ambiguous' ? [['concept_id' => 'a'], ['concept_id' => 'b']] : [],
            static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): bool => false,
        );
        $packet = [
            'locale' => 'vi-VN',
            'source_context' => ['source_kind' => 'human_chat'],
            'semantic_query_seeds' => [
                ['raw_span' => 'Ambiguous', 'normalized_form' => 'ambiguous', 'category' => 'LEXICAL_TERM', 'locale' => 'vi-VN'],
                ['raw_span' => 'Valid Unknown', 'normalized_form' => 'valid unknown', 'category' => 'LEXICAL_TERM', 'locale' => 'vi-VN'],
            ],
        ];

        $result = (new DictionarySeedPlanner($resolver))->plan($packet, ['source_family' => 'chat:7']);

        self::assertSame(['AMBIGUOUS', 'NEW_LEXICAL_CANDIDATE'], array_column($result['items'], 'classification'));
        self::assertSame([], $result['items'][0]['resolution']['destination_ids']);
        self::assertSame('valid unknown', $result['items'][1]['normalized_form']);
        self::assertContains('AMBIGUOUS_CANONICAL_OWNER', $result['items'][0]['diagnostics']);
    }

    public function test_seed_plan_classifies_suppressed_editorial_and_noise_without_persisting(): void
    {
        $resolver = new DictionaryResolver(
            static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): array => [],
            static fn (string $term): bool => $term === 'suppressed term',
        );
        $packet = [
            'semantic_query_seeds' => [
                ['raw_span' => 'Suppressed Term', 'normalized_form' => 'suppressed term', 'category' => 'LEXICAL_TERM', 'locale' => 'vi-VN'],
                ['raw_span' => 'Editorial tail', 'normalized_form' => 'editorial tail', 'category' => 'EDITORIAL_SIGNAL', 'locale' => 'vi-VN'],
                ['raw_span' => 'noise fragment', 'normalized_form' => 'noise fragment', 'category' => 'NOISE', 'locale' => 'vi-VN'],
            ],
        ];

        $result = (new DictionarySeedPlanner($resolver))->plan($packet);

        self::assertSame(['SUPPRESSED', 'EDITORIAL_ONLY', 'NOISE'], array_column($result['items'], 'classification'));
        self::assertFalse($result['mutated']);
        self::assertSame(3, $result['aggregate']['total']);
    }

    public function test_seed_plan_does_not_resolve_observation_only_seed(): void
    {
        $lookups = 0;
        $resolver = new DictionaryResolver(
            static function () use (&$lookups): array {
                $lookups++;
                return [['concept_id' => 'should-not-resolve']];
            },
            static fn (): array => [],
            static fn (): array => [],
            static fn (): array => [],
            static fn (): bool => false,
        );

        $result = (new DictionarySeedPlanner($resolver))->plan([
            'semantic_query_seeds' => [[
                'raw_span' => 'carillon',
                'normalized_form' => 'carillon',
                'category' => 'LEXICAL_OBSERVATION',
                'locale' => 'vi-VN',
                'evidence_status' => 'OBSERVATION_ONLY',
                'resolver_eligible' => false,
                'lookup_variants' => ['should-not-resolve'],
            ]],
        ]);

        self::assertSame(0, $lookups);
        self::assertSame([], $result['items']);
        self::assertSame(0, $result['aggregate']['total']);
    }

    public function test_unknown_primary_reuses_exact_unique_structural_variant_without_changing_observation_identity(): void
    {
        $lookups = [];
        $resolver = new DictionaryResolver(
            static function (string $term) use (&$lookups): array {
                $lookups[] = $term;
                return $term === '400' ? [['preferred_label' => '400-Day Clock', 'destination_type' => 'classification', 'destination_id' => 'sense-400']] : [];
            },
            static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): bool => false,
        );

        $result = (new DictionarySeedPlanner($resolver))->plan(['semantic_query_seeds' => [[
            'raw_span' => '400 ngày', 'normalized_form' => '400 ngày', 'lookup_variants' => ['400'], 'category' => 'CONFIGURATION', 'locale' => 'vi-VN', 'resolver_eligible' => true,
        ]]]);

        self::assertSame(['400 ngày', '400'], $lookups);
        self::assertSame('400 ngày', $result['items'][0]['normalized_form']);
        self::assertSame(['400 ngày'], $result['items'][0]['raw_forms']);
        self::assertSame('ALIAS_TO_EXISTING', $result['items'][0]['classification']);
        self::assertSame('sense-400', $result['items'][0]['resolved_destination_id']);
        self::assertContains('STRUCTURAL_VARIANT_REUSED', $result['items'][0]['diagnostics']);
    }

    public function test_primary_resolution_does_not_consume_structural_fallback(): void
    {
        $lookups = [];
        $resolver = new DictionaryResolver(
            static function (string $term) use (&$lookups): array { $lookups[] = $term; return [['preferred_label' => '400 ngày', 'destination_type' => 'sense', 'destination_id' => 'sense-1']]; },
            static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): bool => false,
        );

        (new DictionarySeedPlanner($resolver))->plan(['semantic_query_seeds' => [[
            'raw_span' => '400 ngày', 'normalized_form' => '400 ngày', 'lookup_variants' => ['400'], 'category' => 'CONFIGURATION', 'resolver_eligible' => true,
        ]]]);

        self::assertSame(['400 ngày'], $lookups);
    }

    public function test_ambiguous_structural_fallback_never_uses_first_candidate(): void
    {
        $resolver = new DictionaryResolver(
            static fn (string $term): array => $term === '400' ? [['id' => 'a'], ['id' => 'b']] : [],
            static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): bool => false,
        );

        $result = (new DictionarySeedPlanner($resolver))->plan(['semantic_query_seeds' => [[
            'raw_span' => '400 ngày', 'normalized_form' => '400 ngày', 'lookup_variants' => ['400'], 'category' => 'CONFIGURATION', 'resolver_eligible' => true,
        ]]]);

        self::assertSame('AMBIGUOUS', $result['items'][0]['classification']);
        self::assertSame([], $result['items'][0]['resolution']['destination_ids']);
        self::assertSame('REVIEW_AMBIGUITY', $result['items'][0]['suggested_action']);
    }

    public function test_lookup_budget_counts_primary_and_fallback_calls(): void
    {
        $calls = 0;
        $resolver = new DictionaryResolver(
            static function () use (&$calls): array { $calls++; return []; },
            static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): bool => false,
        );

        (new DictionarySeedPlanner($resolver))->plan(['semantic_query_seeds' => [[
            'raw_span' => '400 ngày', 'normalized_form' => '400 ngày', 'lookup_variants' => ['400'], 'category' => 'CONFIGURATION', 'resolver_eligible' => true,
        ]]], ['max_lookup_cost' => 1]);

        self::assertSame(1, $calls);
    }

    public function test_budget_exhaustion_does_not_classify_partially_evaluated_seed_as_new(): void
    {
        $calls = [];
        $resolver = new DictionaryResolver(
            static function (string $term) use (&$calls): array { $calls[] = $term; return []; },
            static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): bool => false,
        );

        $result = (new DictionarySeedPlanner($resolver))->plan(['semantic_query_seeds' => [[
            'raw_span' => '8 alpha 8 beta', 'normalized_form' => '8 alpha 8 beta', 'lookup_variants' => ['alpha 8 beta'], 'category' => 'CONFIGURATION', 'resolver_eligible' => true,
        ]]], ['max_lookup_cost' => 1]);

        self::assertSame(['8 alpha 8 beta'], $calls);
        self::assertSame([], $result['items']);
        self::assertTrue($result['diagnostics']['budget_exhausted']);
        self::assertTrue($result['diagnostics']['has_more_seeds']);
        self::assertSame(0, $result['diagnostics']['next_seed_offset']);
    }

    public function test_planner_uses_only_the_first_packet_lookup_variant(): void
    {
        $calls = [];
        $resolver = new DictionaryResolver(
            static function (string $term) use (&$calls): array { $calls[] = $term; return []; },
            static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): bool => false,
        );

        (new DictionarySeedPlanner($resolver))->plan(['semantic_query_seeds' => [[
            'raw_span' => '8 alpha 8 beta', 'normalized_form' => '8 alpha 8 beta', 'lookup_variants' => ['alpha 8 beta', 'beta 8 alpha'], 'category' => 'CONFIGURATION', 'resolver_eligible' => true,
        ]]]);

        self::assertSame(['8 alpha 8 beta', 'alpha 8 beta'], $calls);
    }

    public function test_lookup_budget_replays_after_fallback_at_the_next_unresolved_seed(): void
    {
        $lookups = [];
        $resolver = new DictionaryResolver(
            static function (string $term) use (&$lookups): array {
                $lookups[] = $term;
                return $term === 'compact' ? [['preferred_label' => 'Compact', 'destination_type' => 'classification', 'destination_id' => 'compact-1']] : [];
            },
            static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): bool => false,
        );

        $result = (new DictionarySeedPlanner($resolver))->plan(['semantic_query_seeds' => [
            ['raw_span' => 'structural', 'normalized_form' => 'structural', 'category' => 'CONFIGURATION', 'lookup_variants' => ['compact'], 'resolver_eligible' => true],
            ['raw_span' => 'next', 'normalized_form' => 'next', 'category' => 'LEXICAL_TERM', 'resolver_eligible' => true],
        ]], ['max_lookup_cost' => 2]);

        self::assertSame(['structural', 'compact'], $lookups);
        self::assertCount(1, $result['items']);
        self::assertTrue($result['diagnostics']['budget_exhausted']);
        self::assertTrue($result['diagnostics']['has_more_seeds']);
        self::assertSame(1, $result['diagnostics']['next_seed_offset']);
    }

    public function test_natural_language_known_spans_converge_without_new_duplicate_candidate(): void
    {
        $resolver = new DictionaryResolver(
            static fn (string $term): array => in_array($term, ['400 ngày', 'anniversary clock'], true) ? [[
                'concept_id' => '01a0ff0c-6687-798d-8f44-6761d242815a',
                'preferred_label' => '400 ngày', 'destination_type' => 'dictionary', 'destination_id' => '01a0ff0c-6687-798d-8f44-6761d242815a', 'locale' => 'en',
            ]] : [],
            static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): bool => false,
        );

        $packet = (new StructuredSemanticInterpreter())->interpret([
            'text' => 'Đồng hồ 400 ngày, còn gọi là Anniversary clock.',
            'source_kind' => 'article',
            'metadata' => ['approved_labels' => ['400 ngày', 'Anniversary clock']],
        ]);
        $result = (new DictionarySeedPlanner($resolver))->plan($packet);

        $known = array_values(array_filter($result['items'], static fn (array $item): bool => in_array($item['normalized_form'], ['400 ngày', 'anniversary clock'], true)));
        self::assertCount(2, $known);
        self::assertSame(['01a0ff0c-6687-798d-8f44-6761d242815a', '01a0ff0c-6687-798d-8f44-6761d242815a'], array_column($known, 'resolved_dictionary_concept_id'));
        self::assertNotContains('NEW_CONCEPT_CANDIDATE', array_column($known, 'suggested_action'));
    }

    public function test_suppressed_structural_fallback_does_not_suppress_original_observation(): void
    {
        $resolver = new DictionaryResolver(
            static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): array => [],
            static fn (string $term): bool => $term === 'alpha 8 beta',
        );
        $result = (new DictionarySeedPlanner($resolver))->plan(['semantic_query_seeds' => [[
            'raw_span' => '8 alpha 8 beta', 'normalized_form' => '8 alpha 8 beta', 'lookup_variants' => ['alpha 8 beta'], 'category' => 'CONFIGURATION', 'resolver_eligible' => true,
        ]]]);

        self::assertSame('NEW_LEXICAL_CANDIDATE', $result['items'][0]['classification']);
        self::assertContains('DICTIONARY_STRUCTURAL_VARIANT_SUPPRESSED', $result['items'][0]['diagnostics']);
    }

    public function test_seed_plan_exposes_canonical_reuse_and_action_without_creating_a_concept(): void
    {
        $resolver = new DictionaryResolver(
            static fn (): array => [],
            static fn (): array => [[
                'preferred_label' => 'ÔĐô 36/10',
                'destination_type' => 'variant',
                'destination_id' => 'variant-1',
            ]],
            static fn (): array => [],
            static fn (): array => [],
            static fn (): bool => false,
        );

        $result = (new DictionarySeedPlanner($resolver))->plan([
            'semantic_query_seeds' => [[
                'raw_span' => 'ÔĐô 36/10',
                'normalized_form' => 'ôđô 36/10',
                'category' => 'IDENTIFIER',
                'locale' => 'vi-VN',
            ]],
        ]);

        $item = $result['items'][0];
        self::assertSame('RESOLVED_EXISTING', $item['classification']);
        self::assertSame('RESOLVED', $item['resolution_status']);
        self::assertSame('variant', $item['resolved_destination_type']);
        self::assertSame('variant-1', $item['resolved_destination_id']);
        self::assertSame('REUSE_EXISTING', $item['suggested_action']);
        self::assertNull($item['resolved_dictionary_concept_id']);
        self::assertFalse($result['mutated']);
    }

    public function test_canonical_authority_short_form_is_confirmed_only_when_one_identifier_suffix_owner_exists(): void
    {
        $resolver = new CanonicalAuthoritySubjectResolver($this->authorityRepository([
            new AuthorityEntity('11111111-1111-4111-8111-111111111111', 'model', 'nhk:model:acme-x200', 'Acme Model X200', 1, []),
        ]), $this->types());
        $dictionary = new DictionaryResolver(static fn (): array => [], $resolver, static fn (): array => [], static fn (): array => [], static fn (): bool => false);

        $result = (new DictionarySeedPlanner($dictionary))->plan([
            'semantic_query_seeds' => [['raw_span' => 'Acme X200', 'normalized_form' => 'acme x200', 'category' => 'PROPER_NAME']],
        ]);

        self::assertSame('ALIAS_TO_EXISTING', $result['items'][0]['classification']);
        self::assertSame('11111111-1111-4111-8111-111111111111', $result['items'][0]['resolved_destination_id']);
        self::assertSame('ADD_ALIAS_CANDIDATE', $result['items'][0]['suggested_action']);
    }

    public function test_exact_canonical_display_name_resolves_existing_authority_owner(): void
    {
        $authorityResolver = new CanonicalAuthoritySubjectResolver($this->authorityRepository([
            new AuthorityEntity('33333333-3333-4333-8333-333333333333', 'model', 'nhk:model:acme-x200', 'Acme Model X200', 1, []),
        ]), $this->types());
        $result = (new DictionarySeedPlanner(new DictionaryResolver(static fn (): array => [], $authorityResolver, static fn (): array => [], static fn (): array => [], static fn (): bool => false)))->plan([
            'semantic_query_seeds' => [['raw_span' => 'Acme Model X200', 'normalized_form' => 'acme model x200', 'category' => 'PROPER_NAME']],
        ]);

        self::assertSame('RESOLVED_EXISTING', $result['items'][0]['classification']);
        self::assertSame('REUSE_EXISTING', $result['items'][0]['suggested_action']);
        self::assertSame('33333333-3333-4333-8333-333333333333', $result['items'][0]['resolved_destination_id']);
    }

    public function test_generic_phonetic_form_plans_alias_against_existing_owner_without_creating_concept(): void
    {
        $authorityResolver = new CanonicalAuthoritySubjectResolver($this->authorityRepository([
            new AuthorityEntity('44444444-4444-4444-8444-444444444444', 'variant', 'nhk:variant:odo.36.10', 'Đồng hồ Odo 36/10', 1, []),
        ]), $this->types());
        $result = (new DictionarySeedPlanner(new DictionaryResolver(static fn (): array => [], $authorityResolver, static fn (): array => [], static fn (): array => [], static fn (): bool => false)))->plan([
            'semantic_query_seeds' => [['raw_span' => 'ÔĐô 36/10', 'normalized_form' => 'ôđô 36/10', 'category' => 'IDENTIFIER']],
        ]);

        self::assertSame('ALIAS_TO_EXISTING', $result['items'][0]['classification']);
        self::assertSame('ADD_ALIAS_CANDIDATE', $result['items'][0]['suggested_action']);
        self::assertSame('44444444-4444-4444-8444-444444444444', $result['items'][0]['resolved_destination_id']);
        self::assertFalse($result['mutated']);
    }

    public function test_exact_canonical_sentence_seeds_do_not_create_a_new_concept_for_the_canonical_owner(): void
    {
        $authorityResolver = new CanonicalAuthoritySubjectResolver($this->authorityRepository([
            new AuthorityEntity('55555555-5555-4555-8555-555555555555', 'variant', 'nhk:variant:odo.36.10', 'Đồng hồ Odo 36/10', 1, []),
        ]), $this->types());
        $packet = (new StructuredSemanticInterpreter())->interpret(['text' => 'Đồng hồ Odo 36/10', 'source_kind' => 'human_chat'])->toArray();
        $result = (new DictionarySeedPlanner(new DictionaryResolver(static fn (): array => [], $authorityResolver, static fn (): array => [], static fn (): array => [], static fn (): bool => false)))->plan($packet);

        self::assertNotContains('NEW_CONCEPT_CANDIDATE', array_column($result['items'], 'suggested_action'));
        self::assertContains('55555555-5555-4555-8555-555555555555', array_column($result['items'], 'resolved_destination_id'));
    }

    public function test_canonical_authority_short_form_with_multiple_suffix_owners_is_ambiguous(): void
    {
        $resolver = new CanonicalAuthoritySubjectResolver($this->authorityRepository([
            new AuthorityEntity('11111111-1111-4111-8111-111111111111', 'model', 'nhk:model:acme-x200', 'Acme Model X200', 1, []),
            new AuthorityEntity('22222222-2222-4222-8222-222222222222', 'model', 'nhk:model:other-x200', 'Other Model X200', 1, []),
        ]), $this->types());
        $dictionary = new DictionaryResolver(static fn (): array => [], $resolver, static fn (): array => [], static fn (): array => [], static fn (): bool => false);

        $result = (new DictionarySeedPlanner($dictionary))->plan([
            'semantic_query_seeds' => [['raw_span' => 'X200', 'normalized_form' => 'x200', 'category' => 'IDENTIFIER']],
        ]);

        self::assertSame('AMBIGUOUS', $result['items'][0]['classification']);
        self::assertSame('REVIEW_AMBIGUITY', $result['items'][0]['suggested_action']);
        self::assertSame(2, $result['items'][0]['ambiguity_count']);
    }

    public function test_search_discovery_without_canonical_confirmation_never_proves_identity(): void
    {
        $authority = $this->authorityRepository([
            new AuthorityEntity('11111111-1111-4111-8111-111111111111', 'model', 'nhk:model:acme-x200', 'Acme Model X200', 1, []),
        ]);
        $canonicalResolver = new CanonicalAuthoritySubjectResolver($authority, $this->types());
        $dictionary = new DictionaryResolver(
            static fn (): array => [],
            $canonicalResolver,
            static fn (): array => [],
            static fn (): array => [],
            static fn (): bool => false,
        );

        $result = (new DictionarySeedPlanner($dictionary))->plan([
            'semantic_query_seeds' => [['raw_span' => 'Model X2', 'normalized_form' => 'model x2', 'category' => 'IDENTIFIER']],
        ]);

        self::assertSame('NEW_LEXICAL_CANDIDATE', $result['items'][0]['classification']);
        self::assertSame('NEW_CONCEPT_CANDIDATE', $result['items'][0]['suggested_action']);
    }

    public function test_configuration_seed_reuses_existing_sense_through_bounded_structural_variant(): void
    {
        $resolver = new DictionaryResolver(
            static fn (string $term): array => $term === 'côn 8 búa' ? [[
                'concept_id' => '11111111-1111-7111-8111-111111111111',
                'preferred_label' => 'Côn 8 búa',
                'destination_type' => 'dictionary',
                'destination_id' => '11111111-1111-7111-8111-111111111111',
            ]] : [],
            static fn (): array => [],
            static fn (): array => [],
            static fn (): array => [],
            static fn (): bool => false,
        );

        $result = (new DictionarySeedPlanner($resolver))->plan([
            'semantic_query_seeds' => [[
                'raw_span' => '8 côn 8 búa',
                'normalized_form' => '8 côn 8 búa',
                'category' => 'CONFIGURATION',
                'locale' => 'vi-VN',
                'lookup_variants' => ['côn 8 búa'],
            ]],
        ], ['source_family' => 'chat:configuration']);

        $item = $result['items'][0];
        self::assertSame('ALIAS_TO_EXISTING', $item['classification']);
        self::assertSame('RESOLVED', $item['resolution_status']);
        self::assertSame('11111111-1111-7111-8111-111111111111', $item['resolved_dictionary_concept_id']);
        self::assertSame('ADD_ALIAS_CANDIDATE', $item['suggested_action']);
        self::assertContains('STRUCTURAL_VARIANT_REUSED', $item['diagnostics']);
        self::assertNotSame('NEW_CONCEPT_CANDIDATE', $item['suggested_action']);
    }

    public function test_configuration_seed_does_not_collapse_unequal_cardinalities(): void
    {
        $resolver = new DictionaryResolver(
            static fn (string $term): array => $term === 'alpha 10 beta' ? [[
                'concept_id' => '22222222-2222-7222-8222-222222222222',
                'preferred_label' => 'Alpha 10 beta',
            ]] : [],
            static fn (): array => [],
            static fn (): array => [],
            static fn (): array => [],
            static fn (): bool => false,
        );

        $result = (new DictionarySeedPlanner($resolver))->plan([
            'semantic_query_seeds' => [[
                'raw_span' => '8 alpha 10 beta',
                'normalized_form' => '8 alpha 10 beta',
                'category' => 'CONFIGURATION',
                'locale' => 'vi-VN',
            ]],
        ]);

        self::assertSame('NEW_LEXICAL_CANDIDATE', $result['items'][0]['classification']);
        self::assertSame('NEW_CONCEPT_CANDIDATE', $result['items'][0]['suggested_action']);
        self::assertNotContains('STRUCTURAL_CONFIGURATION_REUSE', $result['items'][0]['diagnostics']);
    }

    private function types(): EntityTypeRegistry
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        return $types;
    }

    /** @param list<AuthorityEntity> $entities */
    private function authorityRepository(array $entities): AuthorityRepository
    {
        return new class($entities) implements AuthorityRepository {
            public function __construct(private array $entities) {}
            public function findByCanonicalId(string $id): ?AuthorityEntity { foreach ($this->entities as $entity) if ($entity->canonicalId === $id) return $entity; return null; }
            public function findByStableKey(string $type, string $key): ?AuthorityEntity { foreach ($this->entities as $entity) if ($entity->entityType === $type && $entity->stableKey === $key) return $entity; return null; }
            public function create(AuthorityEntity $entity): AuthorityEntity { throw new \LogicException('not used'); }
            public function update(AuthorityEntity $entity, int $expectedRevision): AuthorityEntity { throw new \LogicException('not used'); }
            public function rekey(AuthorityEntity $entity, string $oldStableKey, string $newStableKey, int $expectedRevision): AuthorityEntity { throw new \LogicException('not used'); }
            public function listByType(string $type, bool $includeRetired = false): array { return array_values(array_filter($this->entities, static fn (AuthorityEntity $entity): bool => $entity->entityType === $type && ($includeRetired || $entity->state === AuthorityState::ACTIVE))); }
        };
    }
}
