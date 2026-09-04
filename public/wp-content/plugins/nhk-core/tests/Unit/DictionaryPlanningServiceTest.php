<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\{DictionaryLinkPlanner, DictionaryPlanningService, DictionaryResolver, DictionaryTermDetector};
use NHK\Core\Contracts\Dictionary\{DictionaryCandidateRepository, DictionaryMentionRepository};
use NHK\Core\Domain\Dictionary\{DictionaryCandidate, DictionaryMention};
use PHPUnit\Framework\TestCase;

final class DictionaryPlanningServiceTest extends TestCase
{
    public function test_unknown_hint_creates_one_private_candidate_and_mention_without_blocking(): void
    {
        $candidateRepo = new class implements DictionaryCandidateRepository {
            public array $items = [];
            public function upsertObservation(DictionaryCandidate $candidate): DictionaryCandidate { $this->items[] = $candidate; return $candidate; }
            public function suppressed(string $normalizedTerm, string $contextHash): bool { return false; }
            public function listForReview(int $limit = 100): array { return $this->items; }
            public function findById(string $candidateId): ?DictionaryCandidate { return null; }
            public function saveDecision(DictionaryCandidate $candidate, int $expectedRevision): DictionaryCandidate { return $candidate; }
        };
        $mentionRepo = new class implements DictionaryMentionRepository {
            public array $items = [];
            public function upsert(DictionaryMention $mention): DictionaryMention { $this->items[] = $mention; return $mention; }
            public function listBySource(string $sourceKind, string $sourceId): array { return $this->items; }
        };
        $resolver = new DictionaryResolver(static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): bool => false);
        $service = new DictionaryPlanningService(new DictionaryTermDetector(), $resolver, $candidateRepo, $mentionRepo, new DictionaryLinkPlanner());

        $plan = $service->plan(
            'Chiếc máy sử dụng côn lòng máng trắng cho bộ chuông.',
            'ARTICLE',
            '55',
            ['domain' => 'clock'],
            ['côn lòng máng trắng'],
        );

        self::assertCount(1, $plan['candidate_terms']);
        self::assertSame('côn lòng máng trắng', $plan['candidate_terms'][0]['normalized_term']);
        self::assertCount(1, $candidateRepo->items);
        self::assertCount(1, $mentionRepo->items);
        self::assertFalse($plan['blocking']);
        self::assertSame([], $plan['internal_link_candidates']);
    }

    public function test_suppressed_unknown_term_does_not_recreate_candidate(): void
    {
        $candidateRepo = new class implements DictionaryCandidateRepository {
            public int $writes = 0;
            public function upsertObservation(DictionaryCandidate $candidate): DictionaryCandidate { $this->writes++; return $candidate; }
            public function suppressed(string $normalizedTerm, string $contextHash): bool { return $normalizedTerm === 'máy đẹp'; }
            public function listForReview(int $limit = 100): array { return []; }
            public function findById(string $candidateId): ?DictionaryCandidate { return null; }
            public function saveDecision(DictionaryCandidate $candidate, int $expectedRevision): DictionaryCandidate { return $candidate; }
        };
        $mentionRepo = new class implements DictionaryMentionRepository {
            public function upsert(DictionaryMention $mention): DictionaryMention { return $mention; }
            public function listBySource(string $sourceKind, string $sourceId): array { return []; }
        };
        $resolver = new DictionaryResolver(static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): bool => true);
        $service = new DictionaryPlanningService(new DictionaryTermDetector(), $resolver, $candidateRepo, $mentionRepo, new DictionaryLinkPlanner());

        $plan = $service->plan('Máy đẹp và sạch.', 'ARTICLE', '55', [], ['Máy đẹp']);

        self::assertSame([], $plan['candidate_terms']);
        self::assertSame(0, $candidateRepo->writes);
        self::assertContains('DICTIONARY_TERM_SUPPRESSED', $plan['warnings']);
    }

    public function test_resolved_approved_term_produces_canonical_link_candidate(): void
    {
        $candidateRepo = new class implements DictionaryCandidateRepository {
            public function upsertObservation(DictionaryCandidate $candidate): DictionaryCandidate { throw new \RuntimeException('candidate write not expected'); }
            public function suppressed(string $normalizedTerm, string $contextHash): bool { return false; }
            public function listForReview(int $limit = 100): array { return []; }
            public function findById(string $candidateId): ?DictionaryCandidate { return null; }
            public function saveDecision(DictionaryCandidate $candidate, int $expectedRevision): DictionaryCandidate { return $candidate; }
        };
        $mentionRepo = new class implements DictionaryMentionRepository {
            public array $items = [];
            public function upsert(DictionaryMention $mention): DictionaryMention { $this->items[] = $mention; return $mention; }
            public function listBySource(string $sourceKind, string $sourceId): array { return $this->items; }
        };
        $resolver = new DictionaryResolver(
            static fn (string $term): array => $term === 'westminster' ? [['concept_id' => 'concept-w', 'preferred_label' => 'Westminster', 'destination_type' => 'music', 'destination_id' => 'music-w', 'destination_url' => '/ban-nhac/westminster/']] : [],
            static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): bool => false,
        );
        $service = new DictionaryPlanningService(new DictionaryTermDetector(), $resolver, $candidateRepo, $mentionRepo, new DictionaryLinkPlanner());

        $plan = $service->plan('Bản Westminster được nhắc trong bài.', 'ARTICLE', '55', [], ['Westminster']);

        self::assertCount(1, $plan['resolved_terms']);
        self::assertCount(1, $plan['internal_link_candidates']);
        self::assertSame('/ban-nhac/westminster/', $plan['internal_link_candidates'][0]['url']);
        self::assertCount(1, $mentionRepo->items);
    }

    public function test_ambiguous_term_is_reported_and_never_linked(): void
    {
        $candidateRepo = new class implements DictionaryCandidateRepository {
            public function upsertObservation(DictionaryCandidate $candidate): DictionaryCandidate { return $candidate; }
            public function suppressed(string $normalizedTerm, string $contextHash): bool { return false; }
            public function listForReview(int $limit = 100): array { return []; }
            public function findById(string $candidateId): ?DictionaryCandidate { return null; }
            public function saveDecision(DictionaryCandidate $candidate, int $expectedRevision): DictionaryCandidate { return $candidate; }
        };
        $mentionRepo = new class implements DictionaryMentionRepository {
            public function upsert(DictionaryMention $mention): DictionaryMention { return $mention; }
            public function listBySource(string $sourceKind, string $sourceId): array { return []; }
        };
        $resolver = new DictionaryResolver(
            static fn (): array => [['concept_id' => 'a', 'destination_url' => '/a/'], ['concept_id' => 'b', 'destination_url' => '/b/']],
            static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): bool => false,
        );
        $service = new DictionaryPlanningService(new DictionaryTermDetector(), $resolver, $candidateRepo, $mentionRepo, new DictionaryLinkPlanner());

        $plan = $service->plan('Côn là cách gọi đang cần ngữ cảnh.', 'ARTICLE', '55', [], ['Côn']);

        self::assertCount(1, $plan['ambiguous_terms']);
        self::assertSame([], $plan['internal_link_candidates']);
        self::assertFalse($plan['blocking']);
    }
}
