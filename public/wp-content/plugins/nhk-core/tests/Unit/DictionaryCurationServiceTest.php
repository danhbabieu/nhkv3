<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryCurationService;
use NHK\Core\Application\Dictionary\DictionaryPreCreateResolver;
use NHK\Core\Contracts\Dictionary\{DictionaryCandidateRepository, DictionaryConceptRepository, DictionaryEntryRepository};
use NHK\Core\Domain\Dictionary\{DictionaryCandidate, DictionaryCandidateState, DictionaryConcept, DictionaryLabel, LexicalEntry, LexicalEntryForm};
use PHPUnit\Framework\TestCase;

final class DictionaryCurationServiceTest extends TestCase
{
    public function test_new_candidate_becomes_draft_not_public_concept(): void
    {
        $hash = hash('sha256', '{}');
        $candidate = new DictionaryCandidate('candidate-1', 'vai bò', $hash, ['Vai bò'], DictionaryCandidateState::NEEDS_REVIEW, ['usage_scope' => ['Vietnam']], [], 3, 'a', 'b', 1);
        [$candidateRepo, $conceptRepo] = $this->repositories($candidate);
        $service = new DictionaryCurationService($candidateRepo, $conceptRepo, static fn (): string => 'concept-1', null, null, new DictionaryPreCreateResolver($this->entryRepository([], [])));

        $result = $service->createDraftFromCandidate('candidate-1', 1, 'Vai bò', 'Tên gọi dân gian tại Việt Nam.', ['public_slug' => 'vai-bo', 'term_type' => 'COLLOQUIAL']);

        self::assertSame(DictionaryConcept::DRAFT, $result['concept']->status);
        self::assertSame(DictionaryCandidateState::PROPOSED_NEW, $result['candidate']->state);
    }

    public function test_attach_existing_adds_alias_and_resolves_candidate(): void
    {
        $hash = hash('sha256', '{}');
        $candidate = new DictionaryCandidate('candidate-1', 'côn máng', $hash, ['Côn máng'], DictionaryCandidateState::NEEDS_REVIEW, [], [], 2, 'a', 'b', 1);
        [$candidateRepo, $conceptRepo] = $this->repositories($candidate, new DictionaryConcept('concept-1', 'Côn lòng máng', 'Khái niệm đã duyệt.', DictionaryConcept::APPROVED));
        $service = new DictionaryCurationService($candidateRepo, $conceptRepo);

        $result = $service->attachToExisting('candidate-1', 1, 'concept-1', DictionaryLabel::COLLOQUIAL, 'vi-VN');

        self::assertSame(DictionaryCandidateState::RESOLVED_EXISTING, $result['candidate']->state);
        self::assertSame('Côn máng', $result['label']->label);
        self::assertSame(DictionaryLabel::COLLOQUIAL, $result['label']->kind);
    }

    public function test_candidate_matching_existing_entry_does_not_create_a_second_concept(): void
    {
        $hash = hash('sha256', '{}');
        $candidate = new DictionaryCandidate('candidate-existing', 'côn hoa thị', hash('sha256', '{"domain":"clock"}'), ['Côn hoa thị'], DictionaryCandidateState::NEEDS_REVIEW, ['domain' => 'clock'], [], 1, 'a', 'b', 1);
        [$candidateRepo, $conceptRepo] = $this->repositories($candidate);
        $sense = new DictionaryConcept('sense-existing', 'Côn hoa thị', 'Nghĩa', DictionaryConcept::APPROVED, null, null, null, ['domain' => 'clock'], 2);
        $entry = new LexicalEntry('entry-existing', 'Côn hoa thị', 'côn hoa thị', DictionaryConcept::APPROVED, 'vi-VN', [], 3, [$sense->conceptId]);
        $entries = $this->entryRepository([$entry], [$sense]);
        $service = new DictionaryCurationService($candidateRepo, $conceptRepo, null, null, null, new DictionaryPreCreateResolver($entries));

        $result = $service->createDraftFromCandidate('candidate-existing', 1, 'Côn hoa thị', 'Không tạo lại');

        self::assertSame('REUSE_EXISTING', $result['resolution']['action']);
        self::assertSame(0, $conceptRepo->creates);
    }

    public function test_curated_preferred_label_reuses_existing_entry_when_raw_candidate_is_a_typo(): void
    {
        $candidate = new DictionaryCandidate('candidate-typo', 'côn hoa thj', hash('sha256', '{"domain":"clock"}'), ['Côn hoa thj'], DictionaryCandidateState::NEEDS_REVIEW, ['domain' => 'clock'], [], 1, 'a', 'b', 1);
        [$candidateRepo, $conceptRepo] = $this->repositories($candidate);
        $sense = new DictionaryConcept('sense-existing', 'Côn hoa thị', 'Nghĩa', DictionaryConcept::APPROVED, null, null, null, ['domain' => 'clock'], 2);
        $entry = new LexicalEntry('entry-existing', 'Côn hoa thị', 'côn hoa thị', DictionaryConcept::APPROVED, 'vi-VN', [], 3, [$sense->conceptId]);
        $service = new DictionaryCurationService($candidateRepo, $conceptRepo, null, null, null, new DictionaryPreCreateResolver($this->entryRepository([$entry], [$sense])));

        $result = $service->createDraftFromCandidate('candidate-typo', 1, 'Côn hoa thị', 'Không tạo lại');

        self::assertSame('REUSE_EXISTING', $result['resolution']['action']);
        self::assertSame(0, $conceptRepo->creates);
    }

    public function test_ambiguous_candidate_fails_closed_before_concept_creation(): void
    {
        $candidate = new DictionaryCandidate('candidate-ambiguous', 'côn', hash('sha256', '{}'), ['Côn'], DictionaryCandidateState::NEEDS_REVIEW, [], [], 1, 'a', 'b', 1);
        [$candidateRepo, $conceptRepo] = $this->repositories($candidate);
        $first = new DictionaryConcept('sense-one', 'Côn', 'Máy', DictionaryConcept::APPROVED, null, null, null, ['domain' => 'machine'], 1);
        $second = new DictionaryConcept('sense-two', 'Côn', 'Bút', DictionaryConcept::APPROVED, null, null, null, ['domain' => 'pen'], 1);
        $entry = new LexicalEntry('entry-ambiguous', 'Côn', 'côn', DictionaryConcept::APPROVED, 'vi-VN', [], 2, [$first->conceptId, $second->conceptId]);
        $entries = $this->entryRepository([$entry], [$first, $second]);
        $service = new DictionaryCurationService($candidateRepo, $conceptRepo, null, null, null, new DictionaryPreCreateResolver($entries));

        $this->expectExceptionMessage('DICTIONARY_PRE_CREATE_REVIEW_REQUIRED');
        $service->createDraftFromCandidate('candidate-ambiguous', 1, 'Côn', 'Không rõ nghĩa');
    }

    public function test_retired_candidate_fails_closed_instead_of_replacing_the_retired_entry(): void
    {
        $candidate = new DictionaryCandidate('candidate-retired', 'côn hoa thị', hash('sha256', '{}'), ['Côn hoa thị'], DictionaryCandidateState::NEEDS_REVIEW, [], [], 1, 'a', 'b', 1);
        [$candidateRepo, $conceptRepo] = $this->repositories($candidate);
        $sense = new DictionaryConcept('sense-retired', 'Côn hoa thị', 'Nghĩa', DictionaryConcept::APPROVED);
        $entry = new LexicalEntry('entry-retired', 'Côn hoa thị', 'côn hoa thị', DictionaryConcept::RETIRED, 'vi-VN', [], 2, [$sense->conceptId]);
        $entries = $this->entryRepository([$entry], [$sense]);
        $service = new DictionaryCurationService($candidateRepo, $conceptRepo, null, null, null, new DictionaryPreCreateResolver($entries));

        $this->expectExceptionMessage('DICTIONARY_PRE_CREATE_REVIEW_REQUIRED');
        $service->createDraftFromCandidate('candidate-retired', 1, 'Côn hoa thị', 'Không thay thế');
    }

    public function test_genuinely_new_candidate_remains_a_draft_with_resolution_evidence(): void
    {
        $candidate = new DictionaryCandidate('candidate-new-resolved', 'kính rào', hash('sha256', '{}'), ['Kính rào'], DictionaryCandidateState::NEEDS_REVIEW, [], [], 1, 'a', 'b', 1);
        [$candidateRepo, $conceptRepo] = $this->repositories($candidate);
        $entries = $this->entryRepository([], []);
        $service = new DictionaryCurationService($candidateRepo, $conceptRepo, static fn (): string => 'concept-new-resolved', null, null, new DictionaryPreCreateResolver($entries));

        $result = $service->createDraftFromCandidate('candidate-new-resolved', 1, 'Kính rào', 'Nghĩa mới');

        self::assertSame(DictionaryConcept::DRAFT, $result['concept']->status);
        self::assertSame('CREATE_NEW', $result['resolution']['action']);
    }

    public function test_approved_concept_can_bind_labels_to_an_active_canonical_owner_without_creating_a_public_dictionary_url(): void
    {
        $concept = new DictionaryConcept('concept-owner', 'Mặt bát giác nằm', 'Clock type', DictionaryConcept::DRAFT, null, null, null, [], 1);
        $concepts = new class($concept) implements DictionaryConceptRepository {
            public function __construct(private DictionaryConcept $concept) {}
            public function findById(string $id): ?DictionaryConcept { return $id === $this->concept->conceptId ? $this->concept : null; }
            public function findApprovedByNormalizedLabel(string $normalizedLabel, array $context = []): array { return []; }
            public function listApproved(int $limit = 500): array { return []; }
            public function listLabels(string $conceptId, bool $includeInactive = false): array { return []; }
            public function createConcept(DictionaryConcept $concept): DictionaryConcept { return $concept; }
            public function updateConcept(DictionaryConcept $concept, int $expectedRevision): DictionaryConcept { $this->concept = new DictionaryConcept($concept->conceptId, $concept->preferredLabel, $concept->definition, $concept->status, $concept->destinationType, $concept->destinationId, $concept->destinationUrl, $concept->context, $concept->revision + 1); return $this->concept; }
            public function addLabel(DictionaryLabel $label): DictionaryLabel { return $label; }
            public function saveLabel(DictionaryLabel $label, string $previousNormalizedLabel, int $expectedConceptRevision): DictionaryLabel { return $label; }
        };
        $ownerCandidate = new DictionaryCandidate('owner-candidate', 'mặt nằm', hash('sha256', '{}'), ['Mặt nằm'], DictionaryCandidateState::NEEDS_REVIEW, [], [], 1, 'a', 'b', 1);
        [$candidateRepo] = $this->repositories($ownerCandidate);
        $service = new DictionaryCurationService(
            $candidateRepo,
            $concepts,
            null,
            null,
            static fn (string $type, string $id): bool => $type === 'classification' && $id === 'authority-1',
        );
        $approved = $service->approveConcept('concept-owner', 1, 'classification', 'authority-1');
        self::assertSame(DictionaryConcept::APPROVED, $approved->status);
        self::assertSame('classification', $approved->destinationType);
        self::assertSame('authority-1', $approved->destinationId);
        self::assertNull($approved->destinationUrl);
    }

    private function repositories(DictionaryCandidate $candidate, ?DictionaryConcept $existingConcept = null): array
    {
        $candidateRepo = new class($candidate) implements DictionaryCandidateRepository {
            public function __construct(private DictionaryCandidate $candidate) {}
            public function upsertObservation(DictionaryCandidate $candidate): DictionaryCandidate { return $this->candidate; }
            public function suppressed(string $normalizedTerm, string $contextHash): bool { return $this->candidate->suppressed(); }
            public function listForReview(int $limit = 100): array { return [$this->candidate]; }
            public function findById(string $candidateId): ?DictionaryCandidate { return $candidateId === $this->candidate->candidateId ? $this->candidate : null; }
            public function saveDecision(DictionaryCandidate $candidate, int $expectedRevision): DictionaryCandidate { if ($this->candidate->revision !== $expectedRevision) throw new \RuntimeException('conflict'); return $this->candidate = $candidate; }
        };
        $conceptRepo = new class($existingConcept) implements DictionaryConceptRepository {
            public array $labels = [];
            public function __construct(private ?DictionaryConcept $concept) {}
            public function findById(string $conceptId): ?DictionaryConcept { return $this->concept?->conceptId === $conceptId ? $this->concept : null; }
            public function findApprovedByNormalizedLabel(string $normalizedLabel, array $context = []): array { return []; }
            public function listApproved(int $limit = 500): array { return $this->concept?->approved() ? [$this->concept] : []; }
            public function listLabels(string $conceptId, bool $includeInactive = false): array { return $this->labels; }
            public int $creates = 0;
            public function createConcept(DictionaryConcept $concept): DictionaryConcept { $this->creates++; return $this->concept = $concept; }
            public function updateConcept(DictionaryConcept $concept, int $expectedRevision): DictionaryConcept { return $this->concept = $concept; }
            public function addLabel(DictionaryLabel $label): DictionaryLabel { $this->labels[] = $label; return $label; }
            public function saveLabel(DictionaryLabel $label, string $previousNormalizedLabel, int $expectedConceptRevision): DictionaryLabel { $this->labels[] = $label; return $label; }
        };
        return [$candidateRepo, $conceptRepo];
    }

    private function entryRepository(array $entries, array $senses): DictionaryEntryRepository
    {
        return new class($entries, $senses) implements DictionaryEntryRepository {
            public function __construct(private array $entries, private array $senses) {}
            public function findByForm(string $normalizedForm, array $context = []): array { return array_values(array_filter($this->entries, static fn (LexicalEntry $entry): bool => $entry->normalizedPreferredForm === $normalizedForm)); }
            public function findForConcept(string $conceptId): ?LexicalEntry { foreach ($this->entries as $entry) if (in_array($conceptId, $entry->senseIds, true)) return $entry; return null; }
            public function listSenses(LexicalEntry $entry, array $context = []): array { return array_values(array_filter($this->senses, static fn (DictionaryConcept $sense): bool => in_array($sense->conceptId, $entry->senseIds, true) && self::matches($sense, $context))); }
            public function addForm(LexicalEntryForm $form): LexicalEntryForm { return $form; }
            private static function matches(DictionaryConcept $sense, array $context): bool { foreach ($context as $key => $value) if ($value !== null && $value !== '' && ($sense->context[$key] ?? null) !== $value) return false; return true; }
        };
    }
}
