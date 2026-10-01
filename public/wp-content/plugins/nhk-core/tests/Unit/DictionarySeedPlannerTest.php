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
            ]],
        ]);

        self::assertSame(0, $lookups);
        self::assertSame([], $result['items']);
        self::assertSame(0, $result['aggregate']['total']);
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
