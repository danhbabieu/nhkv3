<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Dictionary\DictionaryEntrySenseResolver;
use NHK\Core\Domain\Dictionary\{DictionaryConcept, LexicalEntry, LexicalEntryForm};
use NHK\Core\Contracts\Dictionary\DictionaryEntryRepository;
use PHPUnit\Framework\TestCase;

final class DictionaryEntrySenseResolverTest extends TestCase
{
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
            public function findByForm(string $normalizedForm, array $context = []): array { return [new LexicalEntry('22222222-2222-7222-8222-222222222222', 'Côn', 'côn', 'APPROVED', null, [], 1, [$this->first->conceptId, $this->second->conceptId])]; }
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

        self::assertSame('RESOLVED', $result['status']);
        self::assertNull($result['destination_url']);
        self::assertSame('model-1', $result['destination_id']);
    }
}
