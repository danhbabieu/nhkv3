<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Dictionary\DictionaryPreCreateResolver;
use NHK\Core\Contracts\Dictionary\DictionaryEntryRepository;
use NHK\Core\Domain\Dictionary\{DictionaryConcept, DictionaryPreCreateResolution, LexicalEntry, LexicalEntryForm};
use PHPUnit\Framework\TestCase;

final class DictionaryPreCreateResolverTest extends TestCase
{
    public function test_exact_existing_entry_and_sense_are_reused(): void
    {
        $sense = $this->sense('sense-1', 'Côn hoa thị', ['domain' => 'đồng hồ']);
        $entry = $this->entry('entry-1', 'Côn hoa thị', [$sense->conceptId], ['domain' => 'đồng hồ']);

        $result = (new DictionaryPreCreateResolver($this->repository([$entry], [$sense])))->resolveEntryCreate('Côn hoa thị', ['domain' => 'đồng hồ']);

        self::assertSame(DictionaryPreCreateResolution::REUSE_EXISTING, $result->action);
        self::assertSame('entry-1', $result->candidates[0]['entry_id']);
        self::assertSame('sense-1', $result->candidates[0]['sense_id']);
        self::assertFalse($result->canCreate());
    }

    public function test_unique_alternate_form_alignment_reuses_the_existing_entry(): void
    {
        $sense = $this->sense('sense-1', 'Kính kim cương');
        $entry = $this->entry('entry-1', 'Kính kim cương', [$sense->conceptId]);
        $repo = $this->repository([$entry], [$sense], ['kính kim cương' => [$entry], 'kính cương' => [$entry]]);

        $result = (new DictionaryPreCreateResolver($repo))->resolveEntryCreate('Kính cương');

        self::assertSame(DictionaryPreCreateResolution::REUSE_EXISTING, $result->action);
        self::assertSame('entry-1', $result->candidates[0]['entry_id']);
    }

    public function test_context_selects_one_sense_but_missing_context_is_review(): void
    {
        $machine = $this->sense('sense-machine', 'Côn', ['domain' => 'máy']);
        $pen = $this->sense('sense-pen', 'Côn', ['domain' => 'bút']);
        $entry = $this->entry('entry-1', 'Côn', [$machine->conceptId, $pen->conceptId]);
        $repo = $this->repository([$entry], [$machine, $pen]);
        $resolver = new DictionaryPreCreateResolver($repo);

        self::assertSame(DictionaryPreCreateResolution::REUSE_EXISTING, $resolver->resolveEntryCreate('Côn', ['domain' => 'máy'])->action);
        self::assertSame(DictionaryPreCreateResolution::REVIEW_REQUIRED, $resolver->resolveEntryCreate('Côn')->action);
    }

    public function test_retired_candidate_blocks_replacement_instead_of_creating_a_new_entry(): void
    {
        $sense = $this->sense('sense-retired', 'Côn hoa thị');
        $entry = $this->entry('entry-retired', 'Côn hoa thị', [$sense->conceptId], [], DictionaryConcept::RETIRED);

        $result = (new DictionaryPreCreateResolver($this->repository([$entry], [$sense])))->resolveEntryCreate('Côn hoa thị');

        self::assertSame(DictionaryPreCreateResolution::REVIEW_REQUIRED, $result->action);
        self::assertSame('RETIRED_CANDIDATE', $result->diagnostics['reason']);
        self::assertFalse($result->canCreate());
    }

    public function test_genuinely_new_form_is_create_eligible(): void
    {
        $result = (new DictionaryPreCreateResolver($this->repository()))->resolveEntryCreate('Kính rào');

        self::assertSame(DictionaryPreCreateResolution::CREATE_NEW, $result->action);
        self::assertTrue($result->canCreate());
    }

    public function test_form_addition_reuses_duplicate_or_adds_only_to_the_requested_entry(): void
    {
        $sense = $this->sense('sense-1', 'Côn');
        $entry = $this->entry('entry-1', 'Côn', [$sense->conceptId]);
        $repo = $this->repository([$entry], [$sense], ['côn' => [$entry], 'côn máng' => [$entry]]);
        $resolver = new DictionaryPreCreateResolver($repo);

        self::assertSame(DictionaryPreCreateResolution::REUSE_EXISTING, $resolver->resolveFormAddition('entry-1', 'Côn máng')->action);
        self::assertSame(DictionaryPreCreateResolution::ADD_FORM_TO_ENTRY, $resolver->resolveFormAddition('entry-1', 'Côn hoa thị')->action);
    }

    public function test_form_collision_with_another_entry_requires_review(): void
    {
        $firstSense = $this->sense('sense-1', 'Côn');
        $secondSense = $this->sense('sense-2', 'Côn hoa thị');
        $first = $this->entry('entry-1', 'Côn', [$firstSense->conceptId]);
        $second = $this->entry('entry-2', 'Côn hoa thị', [$secondSense->conceptId]);
        $repo = $this->repository([$first, $second], [$firstSense, $secondSense], ['côn hoa thị' => [$second]]);

        $result = (new DictionaryPreCreateResolver($repo))->resolveFormAddition('entry-1', 'Côn hoa thị');

        self::assertSame(DictionaryPreCreateResolution::REVIEW_REQUIRED, $result->action);
    }

    public function test_conflicting_sense_mapping_requires_review_and_same_mapping_reuses(): void
    {
        $sense = $this->sense('sense-1', 'Côn');
        $first = $this->entry('entry-1', 'Côn', [$sense->conceptId]);
        $second = $this->entry('entry-2', 'Côn khác', []);
        $repo = $this->repository([$first, $second], [$sense]);
        $resolver = new DictionaryPreCreateResolver($repo);

        self::assertSame(DictionaryPreCreateResolution::REUSE_EXISTING, $resolver->resolveSenseAddition('entry-1', 'sense-1')->action);
        self::assertSame(DictionaryPreCreateResolution::REVIEW_REQUIRED, $resolver->resolveSenseAddition('entry-2', 'sense-1')->action);
    }

    public function test_shared_owner_is_evidence_and_does_not_override_divergent_context(): void
    {
        $sense = $this->sense('sense-1', 'Côn', ['domain' => 'máy'], 'classification', 'owner-1');
        $entry = $this->entry('entry-1', 'Côn', [$sense->conceptId]);
        $result = (new DictionaryPreCreateResolver($this->repository([$entry], [$sense])))->resolveEntryCreate('Côn', ['domain' => 'bút'], 'classification', 'owner-1');

        self::assertSame(DictionaryPreCreateResolution::REVIEW_REQUIRED, $result->action);
    }

    public function test_mapping_owner_is_authoritative_over_legacy_concept_destination(): void
    {
        $sense = $this->sense('sense-1', 'Côn', ['domain' => 'clock'], 'classification', 'legacy-owner');
        $entry = $this->entry('entry-1', 'Côn', [$sense->conceptId]);
        $repo = new class($entry, $sense) implements DictionaryEntryRepository {
            public function __construct(private LexicalEntry $entry, private DictionaryConcept $sense) {}
            public function findByForm(string $form, array $context = []): array { return [$this->entry]; }
            public function findForConcept(string $id): ?LexicalEntry { return $this->entry; }
            public function listSenses(LexicalEntry $entry, array $context = []): array { return [$this->sense]; }
            public function addForm(LexicalEntryForm $form): LexicalEntryForm { return $form; }
            public function semanticReference(string $entryId, string $senseId): array { return ['status' => 'AVAILABLE', 'source' => 'MAPPING', 'type' => 'classification', 'id' => 'mapped-owner', 'revision' => 4]; }
        };

        $result = (new DictionaryPreCreateResolver($repo))->resolveEntryCreate('Côn', ['domain' => 'clock'], 'classification', 'mapped-owner');

        self::assertSame(DictionaryPreCreateResolution::REUSE_EXISTING, $result->action);
        self::assertSame('MAPPING', $result->candidates[0]['semantic_reference']['source']);
    }

    public function test_owner_mismatch_requires_review_even_without_context(): void
    {
        $sense = $this->sense('sense-1', 'Côn', [], 'classification', 'other-owner');
        $entry = $this->entry('entry-1', 'Côn', [$sense->conceptId]);

        $result = (new DictionaryPreCreateResolver($this->repository([$entry], [$sense])))->resolveEntryCreate('Côn', [], 'classification', 'requested-owner');

        self::assertSame(DictionaryPreCreateResolution::REVIEW_REQUIRED, $result->action);
        self::assertSame('SEMANTIC_OWNER_CONFLICT', $result->diagnostics['reason']);
    }

    public function test_same_mapping_owner_reuses_existing_entry(): void
    {
        $sense = $this->sense('sense-1', 'Côn', [], 'classification', 'legacy-owner');
        $entry = $this->entry('entry-1', 'Côn', [$sense->conceptId]);
        $repo = new class($entry, $sense) implements DictionaryEntryRepository {
            public function __construct(private LexicalEntry $entry, private DictionaryConcept $sense) {}
            public function findByForm(string $form, array $context = []): array { return [$this->entry]; }
            public function findForConcept(string $id): ?LexicalEntry { return $this->entry; }
            public function listSenses(LexicalEntry $entry, array $context = []): array { return [$this->sense]; }
            public function addForm(LexicalEntryForm $form): LexicalEntryForm { return $form; }
            public function semanticReference(string $entryId, string $senseId): array { return ['status' => 'AVAILABLE', 'source' => 'MAPPING', 'type' => 'classification', 'id' => 'mapped-owner', 'revision' => 4]; }
        };

        self::assertSame(DictionaryPreCreateResolution::REUSE_EXISTING, (new DictionaryPreCreateResolver($repo))->resolveEntryCreate('Côn', [], 'classification', 'mapped-owner')->action);
    }

    public function test_unavailable_dependency_returns_review_instead_of_create(): void
    {
        $repo = $this->repository([], [], [], true);

        $result = (new DictionaryPreCreateResolver($repo))->resolveEntryCreate('Kính rào');

        self::assertSame(DictionaryPreCreateResolution::REVIEW_REQUIRED, $result->action);
        self::assertSame('DICTIONARY_PRE_CREATE_DEPENDENCY_UNAVAILABLE', $result->diagnostics['reason']);
    }

    private function sense(string $id, string $label, array $context = [], ?string $destinationType = null, ?string $destinationId = null): DictionaryConcept
    {
        return new DictionaryConcept($id, $label, 'Nghĩa', DictionaryConcept::APPROVED, $destinationType, $destinationId, null, $context, 2);
    }

    private function entry(string $id, string $preferred, array $senseIds, array $context = [], string $status = DictionaryConcept::APPROVED): LexicalEntry
    {
        return new LexicalEntry($id, $preferred, (new \NHK\Core\Application\Dictionary\DictionaryTermNormalizer())->normalize($preferred), $status, 'vi-VN', $context, 3, $senseIds);
    }

    private function repository(array $entries = [], array $senses = [], array $forms = [], bool $unavailable = false): DictionaryEntryRepository
    {
        return new class($entries, $senses, $forms, $unavailable) implements DictionaryEntryRepository {
            public function __construct(private array $entries, private array $senses, private array $forms, private bool $unavailable) {}
            public function findByForm(string $normalizedForm, array $context = []): array
            {
                if ($this->unavailable) throw new \RuntimeException('dependency unavailable');
                if (array_key_exists($normalizedForm, $this->forms)) return $this->forms[$normalizedForm];
                return array_values(array_filter($this->entries, static fn (LexicalEntry $entry): bool => $entry->normalizedPreferredForm === $normalizedForm));
            }
            public function findForConcept(string $conceptId): ?LexicalEntry
            {
                foreach ($this->entries as $entry) if (in_array($conceptId, $entry->senseIds, true)) return $entry;
                return null;
            }
            public function listSenses(LexicalEntry $entry, array $context = []): array
            {
                if ($this->unavailable) throw new \RuntimeException('dependency unavailable');
                return array_values(array_filter($this->senses, static function (DictionaryConcept $sense) use ($entry, $context): bool {
                    if (!in_array($sense->conceptId, $entry->senseIds, true)) return false;
                    foreach ($context as $key => $value) if ($value !== null && $value !== '' && ($sense->context[$key] ?? null) !== $value) return false;
                    return true;
                }));
            }
            public function addForm(LexicalEntryForm $form): LexicalEntryForm { return $form; }
            public function findById(string $entryId): ?LexicalEntry
            {
                foreach ($this->entries as $entry) if ($entry->entryId === $entryId) return $entry;
                return null;
            }
        };
    }
}
