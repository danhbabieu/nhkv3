<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\{DictionaryHarvester, DictionaryLinkPlanner, DictionaryPlanningService, DictionaryResolver, DictionaryTermDetector};
use NHK\Core\Contracts\Dictionary\{DictionaryCandidateRepository, DictionaryMentionRepository};
use NHK\Core\Domain\Dictionary\{DictionaryCandidate, DictionaryMention};
use PHPUnit\Framework\TestCase;

final class DictionaryHarvesterTest extends TestCase
{
    public function test_harvest_preserves_source_kind_and_never_emits_semantic_write(): void
    {
        $candidates = new class implements DictionaryCandidateRepository {
            public int $writes = 0;
            public function upsertObservation(DictionaryCandidate $candidate): DictionaryCandidate { $this->writes++; return $candidate; }
            public function suppressed(string $normalizedTerm, string $contextHash): bool { return false; }
            public function listForReview(int $limit = 100): array { return []; }
            public function findById(string $candidateId): ?DictionaryCandidate { return null; }
            public function saveDecision(DictionaryCandidate $candidate, int $expectedRevision): DictionaryCandidate { return $candidate; }
        };
        $mentions = new class implements DictionaryMentionRepository {
            public array $items = [];
            public function upsert(DictionaryMention $mention): DictionaryMention { $this->items[] = $mention; return $mention; }
            public function listBySource(string $sourceKind, string $sourceId): array { return []; }
        };
        $resolver = new DictionaryResolver(static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): bool => false);
        $planning = new DictionaryPlanningService(new DictionaryTermDetector(), $resolver, $candidates, $mentions, new DictionaryLinkPlanner(), static fn (): string => '00000000-0000-7000-8000-000000000001');
        $result = (new DictionaryHarvester($planning))->harvest([['source_kind' => 'MEDIA', 'source_id' => 'asset-1', 'text' => 'Côn máng', 'context' => ['weak_sources' => ['filename']]]]);
        self::assertSame('MEDIA', $result['items'][0]['source_kind']);
        self::assertFalse($result['items'][0]['semantic_write']);
        self::assertSame(1, $candidates->writes);
        self::assertCount(1, $mentions->items);
    }

    public function test_dry_run_does_not_call_planning_persistence(): void
    {
        $planning = new class {
            public int $preview = 0;
            public int $plan = 0;
            public function preview(string $text, string $sourceKind, string $sourceId, array $context = [], array $hints = []): array { $this->preview++; return ['status' => 'AVAILABLE', 'resolved_terms' => [], 'candidate_terms' => [], 'ambiguous_terms' => []]; }
            public function plan(string $text, string $sourceKind, string $sourceId, array $context = [], array $hints = []): array { $this->plan++; return []; }
        };
        $result = (new DictionaryHarvester($planning))->harvest([['source_kind' => 'ARTICLE', 'source_id' => '1', 'text' => 'Thuật ngữ']], false);
        self::assertTrue($result['dry_run']);
        self::assertSame(1, $planning->preview);
        self::assertSame(0, $planning->plan);
    }
}
