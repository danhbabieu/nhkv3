<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Knowledge\{KnowledgeEnrichmentPlanner, KnowledgeEnrichmentProposalFactory};
use NHK\Core\Application\Semantic\{ClaimRetrievalEngine, EditorialClaimRetrievalService, EditorialKnowledgeSelector, UniversalEnrichmentCore, UniversalInputEnvelope};
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Knowledge\{KnowledgeClaim, Source};
use PHPUnit\Framework\TestCase;

final class UniversalKnowledgeBranchTest extends TestCase
{
    private const SUBJECT = '4cbe5aa1-4222-46bd-a140-6ab66d2da199';

    public function test_source_observation_is_planned_from_the_envelope_and_generated_prose_is_rejected(): void
    {
        $core = $this->core();
        $input = UniversalInputEnvelope::fromArray([
            'owner_or_source_type' => 'external_url',
            'subject_resolution' => ['primary' => ['id' => self::SUBJECT, 'type' => 'variant']],
            'observations' => [['value' => 'A source observation.', 'origin' => 'SOURCE_EXPLICIT']],
        ]);

        $planned = $core->enrich($input, ['profile' => 'knowledge_delta', 'facet' => 'recognition', 'scope' => 'variant', 'knowledge' => true, 'subject_id' => self::SUBJECT]);
        $rejected = $core->enrich($input, ['profile' => 'knowledge_delta', 'facet' => 'recognition', 'scope' => 'variant', 'knowledge' => true, 'subject_id' => self::SUBJECT, 'observation' => 'Generated.', 'origin' => 'GENERATED_ARTICLE_PROSE']);

        self::assertSame(['new_claim'], $planned->toArray()['knowledge']['classifications']);
        self::assertTrue($planned->toArray()['knowledge']['proposal_ready']);
        self::assertContains('GENERATED_PROSE_NOT_KNOWLEDGE', $rejected->toArray()['knowledge']['diagnostics']);
        self::assertFalse($rejected->toArray()['knowledge']['proposal_ready']);
    }

    private function core(): UniversalEnrichmentCore
    {
        $claims = new class implements KnowledgeRepository {
            public function findByCanonicalId(string $id): ?KnowledgeClaim { return null; }
            public function findByStableKey(string $key): ?KnowledgeClaim { return null; }
            public function create(KnowledgeClaim $claim): KnowledgeClaim { throw new \LogicException('read-only'); }
            public function update(KnowledgeClaim $claim, int $revision): KnowledgeClaim { throw new \LogicException('read-only'); }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $sources = new class implements SourceRepository {
            public function findByCanonicalId(string $id): ?Source { return null; }
            public function findByStableKey(string $key): ?Source { return null; }
            public function create(Source $item): Source { throw new \LogicException('read-only'); }
            public function update(Source $item, int $revision): Source { throw new \LogicException('read-only'); }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $evidence = new class implements EvidenceRepository {
            public function findByCanonicalId(string $id): ?\NHK\Core\Domain\Knowledge\Evidence { return null; }
            public function create(\NHK\Core\Domain\Knowledge\Evidence $item): \NHK\Core\Domain\Knowledge\Evidence { throw new \LogicException('read-only'); }
            public function update(\NHK\Core\Domain\Knowledge\Evidence $item, int $revision): \NHK\Core\Domain\Knowledge\Evidence { throw new \LogicException('read-only'); }
            public function listByClaim(string $claimId, bool $includeRetired = false): array { return []; }
            public function listBySource(string $sourceId, bool $includeRetired = false): array { return []; }
        };
        $engine = new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []);
        return new UniversalEnrichmentCore(new EditorialClaimRetrievalService($engine), new EditorialKnowledgeSelector(), new KnowledgeEnrichmentPlanner($claims, $evidence, $sources), new KnowledgeEnrichmentProposalFactory());
    }
}
