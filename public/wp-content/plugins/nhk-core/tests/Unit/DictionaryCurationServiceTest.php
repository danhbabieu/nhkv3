<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryCurationService;
use NHK\Core\Contracts\Dictionary\{DictionaryCandidateRepository, DictionaryConceptRepository};
use NHK\Core\Domain\Dictionary\{DictionaryCandidate, DictionaryCandidateState, DictionaryConcept, DictionaryLabel};
use PHPUnit\Framework\TestCase;

final class DictionaryCurationServiceTest extends TestCase
{
    public function test_new_candidate_becomes_draft_not_public_concept(): void
    {
        $hash = hash('sha256', '{}');
        $candidate = new DictionaryCandidate('candidate-1', 'vai bò', $hash, ['Vai bò'], DictionaryCandidateState::NEEDS_REVIEW, ['usage_scope' => ['Vietnam']], [], 3, 'a', 'b', 1);
        [$candidateRepo, $conceptRepo] = $this->repositories($candidate);
        $service = new DictionaryCurationService($candidateRepo, $conceptRepo, static fn (): string => 'concept-1');

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
            public function createConcept(DictionaryConcept $concept): DictionaryConcept { return $this->concept = $concept; }
            public function updateConcept(DictionaryConcept $concept, int $expectedRevision): DictionaryConcept { return $this->concept = $concept; }
            public function addLabel(DictionaryLabel $label): DictionaryLabel { $this->labels[] = $label; return $label; }
        };
        return [$candidateRepo, $conceptRepo];
    }
}
