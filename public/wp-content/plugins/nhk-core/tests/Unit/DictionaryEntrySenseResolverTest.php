<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Dictionary\DictionaryEntrySenseResolver;
use NHK\Core\Domain\Dictionary\{DictionaryConcept, LexicalEntry, LexicalEntryForm};
use NHK\Core\Contracts\Dictionary\DictionaryEntryRepository;
use PHPUnit\Framework\TestCase;

final class DictionaryEntrySenseResolverTest extends TestCase
{
    public function test_approved_entry_with_persisted_public_slug_projects_entry_route(): void
    {
        $sense = new DictionaryConcept('sense-kinh-rao', 'Kính rào', 'Nghĩa', DictionaryConcept::APPROVED);
        $entry = new LexicalEntry('entry-kinh-rao', 'Kính rào', 'kính rào', DictionaryConcept::APPROVED, 'vi-VN', ['public_slug' => 'kinh-rao'], 1, [$sense->conceptId]);

        $result = $this->resolverFor($entry, [$sense])->resolve('Kính rào');

        self::assertSame('RESOLVED', $result['status']);
        self::assertSame('dictionary', $result['destination_type']);
        self::assertSame($entry->entryId, $result['destination_id']);
        self::assertSame('/tu-dien/kinh-rao/', $result['destination_url']);
    }

    public function test_exact_preferred_form_resolves_the_approved_entry(): void
    {
        $sense = new DictionaryConcept('sense-preferred', 'Kính kim cương', 'Nghĩa', DictionaryConcept::APPROVED);
        $entry = new LexicalEntry('entry-preferred', 'Kính kim cương', 'kính kim cương', DictionaryConcept::APPROVED, 'vi-VN', ['public_slug' => 'kinh-kim-cuong'], 1, [$sense->conceptId]);

        $result = $this->resolverFor($entry, [$sense], ['kính kim cương'])->resolve('Kính kim cương');

        self::assertSame('RESOLVED', $result['status']);
        self::assertSame('/tu-dien/kinh-kim-cuong/', $result['destination_url']);
    }

    public function test_alternate_form_resolves_the_same_entry_route(): void
    {
        $sense = new DictionaryConcept('sense-alternate', 'ÔĐô 30 kim cương', 'Nghĩa', DictionaryConcept::APPROVED);
        $entry = new LexicalEntry('entry-alternate', 'ÔĐô 30 kim cương', 'ôđô 30 kim cương', DictionaryConcept::APPROVED, 'vi-VN', ['public_slug' => 'odo-30-kim-cuong'], 1, [$sense->conceptId]);

        $result = $this->resolverFor($entry, [$sense], ['ôđô 30 kim cương', 'odo 30 kim cương'])->resolve('Odo 30 kim cương');

        self::assertSame('RESOLVED', $result['status']);
        self::assertSame($entry->entryId, $result['destination_id']);
        self::assertSame('/tu-dien/odo-30-kim-cuong/', $result['destination_url']);
    }

    public function test_delegated_sense_keeps_mapping_owner_precedence_and_current_owner_url(): void
    {
        $sense = new DictionaryConcept('sense-delegated', 'ÔĐô 36 kim cương', 'Nghĩa', DictionaryConcept::APPROVED, 'model', 'legacy-model', '/stale/');
        $entry = new LexicalEntry('entry-delegated', 'ÔĐô 36 kim cương', 'ôđô 36 kim cương', DictionaryConcept::APPROVED, 'vi-VN', ['public_slug' => 'odo-36-kim-cuong'], 1, [$sense->conceptId]);

        $result = $this->resolverFor(
            $entry,
            [$sense],
            ['ôđô 36 kim cương'],
            ['status' => 'AVAILABLE', 'type' => 'classification', 'id' => 'clock-type-36'],
            static fn (string $type, string $id, ?string $url): ?string => $type === 'classification' && $id === 'clock-type-36' ? '/phan-loai/odo-36-kim-cuong/' : null,
        )->resolve('ÔĐô 36 kim cương');

        self::assertSame('classification', $result['destination_type']);
        self::assertSame('clock-type-36', $result['destination_id']);
        self::assertSame('/phan-loai/odo-36-kim-cuong/', $result['destination_url']);
    }

    public function test_missing_public_slug_fails_closed_for_standalone_entry(): void
    {
        $sense = new DictionaryConcept('sense-no-slug', 'Không có slug', 'Nghĩa', DictionaryConcept::APPROVED);
        $entry = new LexicalEntry('entry-no-slug', 'Không có slug', 'không có slug', DictionaryConcept::APPROVED, 'vi-VN', [], 1, [$sense->conceptId]);

        $result = $this->resolverFor($entry, [$sense])->resolve('Không có slug');

        self::assertSame('UNKNOWN', $result['status']);
        self::assertNull($result['destination_url'] ?? null);
    }

    public function test_retired_entry_does_not_resolve_publicly(): void
    {
        $sense = new DictionaryConcept('sense-retired', 'Entry đã nghỉ', 'Nghĩa', DictionaryConcept::APPROVED);
        $entry = new LexicalEntry('entry-retired', 'Entry đã nghỉ', 'entry đã nghỉ', DictionaryConcept::RETIRED, 'vi-VN', ['public_slug' => 'entry-da-nghi'], 1, [$sense->conceptId]);

        $result = $this->resolverFor($entry, [$sense])->resolve('Entry đã nghỉ');

        self::assertSame('UNKNOWN', $result['status']);
        self::assertNull($result['destination_url'] ?? null);
    }

    public function test_400_ngay_delegated_resolution_remains_unchanged(): void
    {
        $sense = new DictionaryConcept('sense-400', '400 ngày', 'Loại đồng hồ.', DictionaryConcept::APPROVED, 'classification', 'clock-type-400', '/legacy/');
        $entry = new LexicalEntry('entry-400', '400 ngày', '400 ngày', DictionaryConcept::APPROVED, 'vi-VN', ['public_slug' => '400-ngay'], 1, [$sense->conceptId]);

        $result = $this->resolverFor($entry, [$sense], ['400 ngày'], null, static fn (string $type, string $id, ?string $url): ?string => $type === 'classification' && $id === 'clock-type-400' ? '/phan-loai/400-ngay/' : null)->resolve('400 ngày');

        self::assertSame('classification', $result['destination_type']);
        self::assertSame('clock-type-400', $result['destination_id']);
        self::assertSame('/phan-loai/400-ngay/', $result['destination_url']);
    }

    public function test_one_compatibility_entry_and_sense_resolves(): void
    {
        $concept = new DictionaryConcept('11111111-1111-7111-8111-111111111111', 'Vai bò', 'Dân gian', DictionaryConcept::APPROVED, 'model', 'model-1', '/dong-ho/model-1/');
        $repo = new class($concept) implements DictionaryEntryRepository {
            public function __construct(private DictionaryConcept $concept) {}
            public function findByForm(string $normalizedForm, array $context = []): array { return [new LexicalEntry('22222222-2222-7222-8222-222222222222', 'Vai bò', 'vai bò', 'APPROVED', 'vi-VN', [], 1, [$this->concept->conceptId])]; }
            public function findForConcept(string $conceptId): ?LexicalEntry { return null; }
            public function listSenses(LexicalEntry $entry, array $context = []): array { return [$this->concept]; }
            public function addForm(LexicalEntryForm $form): LexicalEntryForm { return $form; }
        };

        $result = (new DictionaryEntrySenseResolver($repo, static fn (string $type, string $id, ?string $url): ?string => $url))->resolve('vai bò');

        self::assertSame('RESOLVED', $result['status']);
        self::assertSame($concept->conceptId, $result['sense_id']);
        self::assertSame('/dong-ho/model-1/', $result['destination_url']);
    }

    public function test_multiple_senses_are_ambiguous_without_context(): void
    {
        $first = new DictionaryConcept('11111111-1111-7111-8111-111111111111', 'Côn', 'Meaning one', DictionaryConcept::APPROVED);
        $second = new DictionaryConcept('33333333-3333-7333-8333-333333333333', 'Côn', 'Meaning two', DictionaryConcept::APPROVED);
        $repo = new class($first, $second) implements DictionaryEntryRepository {
            public function __construct(private DictionaryConcept $first, private DictionaryConcept $second) {}
            public function findByForm(string $normalizedForm, array $context = []): array { return [new LexicalEntry('22222222-2222-7222-8222-222222222222', 'Côn', 'côn', 'APPROVED', null, ['public_slug' => 'con'], 1, [$this->first->conceptId, $this->second->conceptId])]; }
            public function findForConcept(string $conceptId): ?LexicalEntry { return null; }
            public function listSenses(LexicalEntry $entry, array $context = []): array { return [$this->first, $this->second]; }
            public function addForm(LexicalEntryForm $form): LexicalEntryForm { return $form; }
        };

        $result = (new DictionaryEntrySenseResolver($repo))->resolve('côn');

        self::assertSame('AMBIGUOUS', $result['status']);
        self::assertCount(2, $result['candidates']);
    }

    public function test_destination_is_revalidated_and_url_alone_is_not_identity(): void
    {
        $concept = new DictionaryConcept('11111111-1111-7111-8111-111111111111', 'X', 'Meaning', DictionaryConcept::APPROVED, 'model', 'model-1', '/stale/');
        $repo = new class($concept) implements DictionaryEntryRepository {
            public function __construct(private DictionaryConcept $concept) {}
            public function findByForm(string $normalizedForm, array $context = []): array { return [new LexicalEntry('22222222-2222-7222-8222-222222222222', 'X', 'x', 'APPROVED', null, [], 1, [$this->concept->conceptId])]; }
            public function findForConcept(string $conceptId): ?LexicalEntry { return null; }
            public function listSenses(LexicalEntry $entry, array $context = []): array { return [$this->concept]; }
            public function addForm(LexicalEntryForm $form): LexicalEntryForm { return $form; }
        };

        $result = (new DictionaryEntrySenseResolver($repo, static fn (): ?string => null))->resolve('x');

        self::assertSame('UNKNOWN', $result['status']);
        self::assertNull($result['destination_url'] ?? null);
        self::assertSame('DICTIONARY_SEMANTIC_DESTINATION_UNAVAILABLE', $result['reason']);
    }

    public function test_context_selects_one_sense_and_missing_context_is_ambiguous(): void
    {
        $first = new DictionaryConcept('11111111-1111-7111-8111-111111111111', 'Côn', 'Máy', DictionaryConcept::APPROVED, null, null, null, ['domain' => 'máy']);
        $second = new DictionaryConcept('33333333-3333-7333-8333-333333333333', 'Côn', 'Bút', DictionaryConcept::APPROVED, null, null, null, ['domain' => 'bút']);
        $repo = new class($first, $second) implements DictionaryEntryRepository {
            public function __construct(private DictionaryConcept $first, private DictionaryConcept $second) {}
            public function findByForm(string $normalizedForm, array $context = []): array { return [new LexicalEntry('22222222-2222-7222-8222-222222222222', 'Côn', 'côn', 'APPROVED', null, ['public_slug' => 'con'], 1, [$this->first->conceptId, $this->second->conceptId])]; }
            public function findForConcept(string $conceptId): ?LexicalEntry { return null; }
            public function listSenses(LexicalEntry $entry, array $context = []): array { return $context === [] ? [$this->first, $this->second] : array_values(array_filter([$this->first, $this->second], static fn (DictionaryConcept $sense): bool => ($sense->context['domain'] ?? null) === ($context['domain'] ?? null))); }
            public function addForm(LexicalEntryForm $form): LexicalEntryForm { return $form; }
        };
        $resolver = new DictionaryEntrySenseResolver($repo);
        self::assertSame('RESOLVED', $resolver->resolve('côn', ['domain' => 'máy'])['status']);
        self::assertSame('AMBIGUOUS', $resolver->resolve('côn')['status']);
    }

    public function test_invalid_semantic_reference_fails_closed(): void
    {
        $concept = new DictionaryConcept('11111111-1111-7111-8111-111111111111', 'X', 'Meaning', DictionaryConcept::APPROVED, 'knowledge', '11111111-1111-7111-8111-111111111111', null);
        $repo = new class($concept) implements DictionaryEntryRepository {
            public function __construct(private DictionaryConcept $concept) {}
            public function findByForm(string $normalizedForm, array $context = []): array { return [new LexicalEntry('22222222-2222-7222-8222-222222222222', 'X', 'x', 'APPROVED', null, [], 1, [$this->concept->conceptId])]; }
            public function findForConcept(string $conceptId): ?LexicalEntry { return null; }
            public function listSenses(LexicalEntry $entry, array $context = []): array { return [$this->concept]; }
            public function addForm(LexicalEntryForm $form): LexicalEntryForm { return $form; }
        };
        self::assertSame('UNKNOWN', (new DictionaryEntrySenseResolver($repo, static fn (): bool => false))->resolve('x')['status']);
    }

    private function resolverFor(LexicalEntry $entry, array $senses, array $forms = [], ?array $semanticReference = null, ?callable $destinationValidator = null): DictionaryEntrySenseResolver
    {
        $forms = $forms !== [] ? $forms : [$entry->normalizedPreferredForm];
        $repo = new class($entry, $senses, $forms, $semanticReference) implements DictionaryEntryRepository {
            public function __construct(private LexicalEntry $entry, private array $senses, private array $forms, private ?array $semanticReference) {}
            public function findByForm(string $normalizedForm, array $context = []): array { return in_array($normalizedForm, $this->forms, true) ? [$this->entry] : []; }
            public function findForConcept(string $conceptId): ?LexicalEntry { return null; }
            public function listSenses(LexicalEntry $entry, array $context = []): array { return $this->senses; }
            public function semanticReference(string $entryId, string $senseId): array { return $this->semanticReference ?? ['status' => 'ABSENT', 'type' => null, 'id' => null]; }
            public function addForm(LexicalEntryForm $form): LexicalEntryForm { return $form; }
        };

        return new DictionaryEntrySenseResolver($repo, $destinationValidator);
    }
}
