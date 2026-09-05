<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Contracts\Dictionary\{DictionaryCandidateRepository, DictionaryConceptRepository, DictionaryMentionRepository};
use NHK\Core\Domain\Dictionary\{DictionaryCandidate, DictionaryCandidateState, DictionaryConcept, DictionaryLabel, DictionaryMention};
use PHPUnit\Framework\TestCase;

final class DictionaryRepositoryContractTest extends TestCase
{
    public function test_candidate_upsert_accumulates_occurrences_without_reopening_suppression(): void
    {
        $repo = new class implements DictionaryCandidateRepository {
            private array $items = [];
            public function upsertObservation(DictionaryCandidate $candidate): DictionaryCandidate {
                $key = $candidate->normalizedTerm . ':' . $candidate->contextHash;
                $existing = $this->items[$key] ?? null;
                if ($existing instanceof DictionaryCandidate) {
                    if ($existing->suppressed()) return $existing;
                    return $this->items[$key] = new DictionaryCandidate($existing->candidateId, $existing->normalizedTerm, $existing->contextHash, array_values(array_unique([...$existing->rawForms, ...$candidate->rawForms])), $existing->state, $existing->context, $existing->suggestions, $existing->occurrences + 1, $existing->firstSeenAt, $candidate->lastSeenAt, $existing->revision + 1);
                }
                return $this->items[$key] = $candidate;
            }
            public function suppressed(string $normalizedTerm, string $contextHash): bool { $item = $this->items[$normalizedTerm . ':' . $contextHash] ?? null; return $item instanceof DictionaryCandidate && $item->suppressed(); }
            public function listForReview(int $limit = 100): array { return array_slice(array_values($this->items), 0, $limit); }
            public function findById(string $candidateId): ?DictionaryCandidate { foreach ($this->items as $item) if ($item->candidateId === $candidateId) return $item; return null; }
            public function saveDecision(DictionaryCandidate $candidate, int $expectedRevision): DictionaryCandidate { foreach ($this->items as $key => $item) if ($item->candidateId === $candidate->candidateId && $item->revision === $expectedRevision) return $this->items[$key] = $candidate; throw new \RuntimeException('revision conflict'); }
        };
        $hash = hash('sha256', '{}');
        $first = new DictionaryCandidate('c1', 'côn lòng máng', $hash, ['Côn lòng máng'], DictionaryCandidateState::NEEDS_REVIEW, [], [], 1, '2026-09-05 01:00:00', '2026-09-05 01:00:00');
        $second = new DictionaryCandidate('c2', 'côn lòng máng', $hash, ['côn lòng máng'], DictionaryCandidateState::NEEDS_REVIEW, [], [], 1, '2026-09-05 02:00:00', '2026-09-05 02:00:00');
        $repo->upsertObservation($first);
        self::assertSame(2, $repo->upsertObservation($second)->occurrences);

        $current = $repo->listForReview()[0];
        $suppressed = new DictionaryCandidate($current->candidateId, $current->normalizedTerm, $current->contextHash, $current->rawForms, DictionaryCandidateState::DO_NOT_SUGGEST, $current->context, $current->suggestions, $current->occurrences, $current->firstSeenAt, $current->lastSeenAt, $current->revision + 1);
        $repo->saveDecision($suppressed, $current->revision);
        self::assertTrue($repo->suppressed('côn lòng máng', $hash));
        self::assertSame($suppressed->occurrences, $repo->upsertObservation($second)->occurrences);
    }

    public function test_dictionary_repository_types_keep_lexical_storage_separate_from_semantic_stores(): void
    {
        self::assertTrue(interface_exists(DictionaryConceptRepository::class));
        self::assertTrue(interface_exists(DictionaryCandidateRepository::class));
        self::assertTrue(interface_exists(DictionaryMentionRepository::class));
        self::assertTrue(class_exists(DictionaryConcept::class));
        self::assertTrue(class_exists(DictionaryLabel::class));
        self::assertTrue(class_exists(DictionaryMention::class));
    }
}
