<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\{DictionaryLinkPlanner, DictionaryPlanningService, DictionaryResolver, DictionaryTermDetector};
use NHK\Core\Contracts\Dictionary\{DictionaryCandidateRepository, DictionaryMentionRepository};
use NHK\Core\Domain\Dictionary\{DictionaryCandidate, DictionaryCandidateState, DictionaryMention};
use PHPUnit\Framework\TestCase;

final class DictionaryReplayIdempotencyTest extends TestCase
{
    public function test_first_replay_distinct_source_and_changed_observation_have_deterministic_counts(): void
    {
        [$candidates, $mentions, $service] = $this->service();

        $service->plan('Có côn lòng máng trắng.', 'ARTICLE', '1', [], ['côn lòng máng trắng']);
        self::assertSame(1, $candidates->items[0]->occurrences);
        self::assertSame(1, $candidates->items[0]->revision);

        $replay = $service->plan('Có côn lòng máng trắng.', 'ARTICLE', '1', [], ['côn lòng máng trắng']);
        self::assertTrue($replay['candidate_terms'][0]['replayed']);
        self::assertSame(1, $candidates->items[0]->occurrences);
        self::assertSame(1, $candidates->items[0]->revision);

        $service->plan('Có côn lòng máng trắng.', 'ARTICLE', '2', [], ['côn lòng máng trắng']);
        self::assertSame(2, $candidates->items[0]->occurrences);
        self::assertSame(2, $candidates->items[0]->revision);

        $service->plan('Có côn lòng máng đen.', 'ARTICLE', '1', [], ['côn lòng máng đen']);
        self::assertCount(2, $candidates->items);
        self::assertSame(1, $candidates->items[1]->occurrences);
        self::assertCount(3, $mentions->items);
    }

    public function test_suppressed_and_ambiguous_replays_do_not_increment_candidates(): void
    {
        [$candidates, , $service] = $this->service();
        $service->plan('Côn lòng máng.', 'ARTICLE', '1', [], ['côn lòng máng']);
        $candidates->markSuppressed('côn lòng máng');
        $service->plan('Côn lòng máng.', 'ARTICLE', '1', [], ['côn lòng máng']);
        self::assertSame(1, $candidates->items[0]->occurrences);
        self::assertSame(DictionaryCandidateState::DO_NOT_SUGGEST, $candidates->items[0]->state);

        [$ambiguous, , $ambiguousService] = $this->service(true);
        $ambiguousService->plan('Côn.', 'ARTICLE', '1', [], ['côn']);
        $ambiguousService->plan('Côn.', 'ARTICLE', '1', [], ['côn']);
        self::assertCount(1, $ambiguous->items);
        self::assertSame(1, $ambiguous->items[0]->occurrences);
        self::assertSame(1, $ambiguous->items[0]->revision);
    }

    private function service(bool $ambiguous = false): array
    {
        $candidates = new class implements DictionaryCandidateRepository {
            public array $items = [];
            public function upsertObservation(DictionaryCandidate $candidate): DictionaryCandidate {
                foreach ($this->items as $index => $existing) {
                    if ($existing->normalizedTerm !== $candidate->normalizedTerm || $existing->contextHash !== $candidate->contextHash) continue;
                    return $this->items[$index] = new DictionaryCandidate($existing->candidateId, $existing->normalizedTerm, $existing->contextHash, array_values(array_unique([...$existing->rawForms, ...$candidate->rawForms])), $existing->state, $existing->context, $existing->suggestions, $existing->occurrences + 1, $existing->firstSeenAt, $candidate->lastSeenAt, $existing->revision + 1);
                }
                return $this->items[] = $candidate;
            }
            public function suppressed(string $normalizedTerm, string $contextHash): bool { foreach ($this->items as $item) if ($item->normalizedTerm === $normalizedTerm && $item->contextHash === $contextHash) return $item->suppressed(); return false; }
            public function listForReview(int $limit = 100): array { return $this->items; }
            public function findById(string $candidateId): ?DictionaryCandidate { foreach ($this->items as $item) if ($item->candidateId === $candidateId) return $item; return null; }
            public function saveDecision(DictionaryCandidate $candidate, int $expectedRevision): DictionaryCandidate { return $candidate; }
            public function markSuppressed(string $term): void { foreach ($this->items as $index => $item) if ($item->normalizedTerm === $term) $this->items[$index] = new DictionaryCandidate($item->candidateId, $item->normalizedTerm, $item->contextHash, $item->rawForms, DictionaryCandidateState::DO_NOT_SUGGEST, $item->context, $item->suggestions, $item->occurrences, $item->firstSeenAt, $item->lastSeenAt, $item->revision); }
        };
        $mentions = new class implements DictionaryMentionRepository {
            public array $items = [];
            public function upsert(DictionaryMention $mention): DictionaryMention { foreach ($this->items as $item) if ($item->fingerprint === $mention->fingerprint) return $item; return $this->items[] = $mention; }
            public function listBySource(string $sourceKind, string $sourceId): array { return []; }
        };
        $resolver = new DictionaryResolver(
            $ambiguous ? static fn (): array => [['concept_id' => 'a'], ['concept_id' => 'b']] : static fn (): array => [],
            static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): bool => false,
        );
        $counter = 0;
        return [$candidates, $mentions, new DictionaryPlanningService(new DictionaryTermDetector(), $resolver, $candidates, $mentions, new DictionaryLinkPlanner(), static function () use (&$counter): string { return '00000000-0000-7000-8000-' . str_pad((string) ++$counter, 12, '0', STR_PAD_LEFT); })];
    }
}
