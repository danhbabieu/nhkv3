<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Collector\{CollectorFacetMaintenanceExecutor, CollectorFacetMaintenanceService, CollectorProfileQuery};
use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Application\Governance\ControlledApplyOperationRegistry;
use NHK\Core\Application\Knowledge\KnowledgeService;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\Governance\ProposalRepository;
use NHK\Core\Domain\Governance\Proposal;
use NHK\Core\Domain\Knowledge\{CollectorFacetRegistry, Evidence, KnowledgeClaim, Source};
use NHK\Core\Governance\Exception\ProposalIdempotencyConflict;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class CollectorFacetRegistryTest extends TestCase
{
    public function test_registry_is_exact_and_projection_consumes_it(): void
    {
        self::assertCount(21, CollectorFacetRegistry::all());
        self::assertSame(['display_form', 'dimensions', 'dating', 'case_styles', 'motifs', 'materials', 'craft_modes', 'production_scale', 'movement_family', 'running_duration', 'drive_system', 'functions', 'sound', 'music', 'automata', 'night_shutoff', 'condition_guidance', 'originality_guidance', 'provenance', 'rarity', 'origin_certification'], CollectorFacetRegistry::all());
        self::assertTrue(CollectorFacetRegistry::isValidForScope('automata', 'model'));
        self::assertFalse(CollectorFacetRegistry::isValidForScope('automata', 'entity'));
    }

    public function test_unknown_and_unresolved_values_fail_closed(): void
    {
        self::assertSame('', CollectorFacetRegistry::resolve(['collector_facet' => 'invented']));
        self::assertSame('', CollectorFacetRegistry::resolve(['collector_facet' => 'automata', 'scope' => 'entity']));
        self::assertSame('', CollectorFacetRegistry::resolve([]));
        self::assertSame('dating', CollectorFacetRegistry::resolve(['facet' => 'chronology']));
    }

    public function test_governance_registry_accepts_only_the_narrow_operation_and_executor_rejects_extra_fields(): void
    {
        self::assertTrue((new ControlledApplyOperationRegistry())->supports('knowledge', CollectorFacetMaintenanceService::OPERATION));
        self::assertFalse((new ControlledApplyOperationRegistry())->supports('article', CollectorFacetMaintenanceService::OPERATION));
        $claim = $this->claim('guard', 'Guarded', ['scope' => 'entity']);
        $knowledge = new KnowledgeService(new CollectorFacetTestKnowledgeRepository([$claim]), $this->sources(), $this->evidence());
        $executor = new CollectorFacetMaintenanceExecutor($knowledge, static fn (string $id): array => ['status' => 'available', 'claims' => [$claim]]);
        $proposal = new Proposal(UuidCodec::newV7(), $claim->canonicalId, CollectorFacetMaintenanceService::OPERATION, ['field' => 'provenance.metadata.collector_facet', 'collector_facet' => 'dimensions', 'knowledge_uuid' => $claim->canonicalId, 'extra' => 'forbidden'], hash('sha256', 'x'), 1, hash('sha256', 'y'), targetUuid: $claim->canonicalId, entityType: 'knowledge');
        $this->expectExceptionMessage('COLLECTOR_FACET_PAYLOAD_FORBIDDEN_FIELD');
        $executor($proposal);
    }

    public function test_plan_is_branch_scoped_deduplicated_and_unresolved_is_noop(): void
    {
        $classification = UuidCodec::newV7();
        $valid = $this->claim('valid', 'Valid', ['collector_facet' => 'running_duration', 'scope' => 'entity']);
        $missing = $this->claim('missing', 'Missing', ['scope' => 'entity']);
        $other = $this->claim('other', 'Other', ['subject_id' => UuidCodec::newV7(), 'scope' => 'entity']);
        $repo = new CollectorFacetTestKnowledgeRepository([$valid, $missing, $other]);
        $service = new CollectorFacetMaintenanceService($repo, static fn (string $id): array => ['status' => 'available', 'classification_revision' => 3, 'claims' => [$valid, $missing, $missing, $other]]);
        $plan = $service->plan($classification);
        self::assertSame('available', $plan['status']);
        self::assertSame(2, $plan['total_branch_knowledge']);
        self::assertSame(1, $plan['already_valid']);
        self::assertSame([$missing->canonicalId], $plan['unresolved']);
        self::assertSame([], $plan['proposed_changes']);
    }

    public function test_governed_payload_binds_identity_revision_and_fingerprint(): void
    {
        $classification = UuidCodec::newV7();
        $claim = $this->claim('bind', 'Immutable claim', ['scope' => 'entity']);
        $repo = new CollectorFacetTestKnowledgeRepository([$claim]);
        $proposalRepo = new CollectorFacetTestProposalRepository();
        $service = new CollectorFacetMaintenanceService($repo, static fn (string $id): array => ['status' => 'available', 'classification_revision' => 4, 'claims' => [$claim]], new GovernanceService($proposalRepo));
        $proposal = $service->createProposal($classification, ['knowledge_uuid' => $claim->canonicalId, 'expected_revision' => 1, 'proposed_facet' => 'dimensions', 'confidence' => 'high', 'reason' => 'deterministic']);
        self::assertSame('knowledge', $proposal->entityType);
        self::assertSame(CollectorFacetMaintenanceService::OPERATION, $proposal->operation);
        self::assertSame($claim->canonicalId, $proposal->targetUuid);
        self::assertSame(1, $proposal->expectedRevision);
        self::assertSame('provenance.metadata.collector_facet', $proposal->payload['field']);
        self::assertSame(hash('sha256', 'Immutable claim'), $proposal->payload['claim_text_sha256']);
        self::assertSame(4, $proposal->payload['dependency_revisions'][$classification]);
        self::assertSame($proposal->id, $service->createProposal($classification, ['knowledge_uuid' => $claim->canonicalId, 'expected_revision' => 1, 'proposed_facet' => 'dimensions', 'confidence' => 'high', 'reason' => 'deterministic'])->id);
    }

    public function test_canonical_owner_changes_only_facet_and_rejects_stale_or_invalid(): void
    {
        $claim = $this->claim('apply', 'Text stays exactly', ['scope' => 'entity', 'other' => 'keep']);
        $repo = new CollectorFacetTestKnowledgeRepository([$claim]);
        $knowledge = new KnowledgeService($repo, $this->sources(), $this->evidence());
        $binding = [
            'knowledge_uuid' => $claim->canonicalId, 'stable_key' => $claim->stableKey,
            'claim_text_sha256' => hash('sha256', $claim->claimText), 'claim_type' => $claim->claimType,
            'scope' => 'entity', 'provenance_sha256' => CollectorFacetTestKnowledgeRepository::fingerprint($claim->provenance),
        ];
        $updated = $knowledge->updateCollectorFacet($claim->canonicalId, 'dimensions', 1, $binding);
        self::assertSame($claim->canonicalId, $updated->canonicalId);
        self::assertSame($claim->stableKey, $updated->stableKey);
        self::assertSame($claim->claimText, $updated->claimText);
        self::assertSame($claim->claimType, $updated->claimType);
        self::assertSame('keep', $updated->provenance['metadata']['other']);
        self::assertSame('dimensions', $updated->provenance['metadata']['collector_facet']);
        self::assertSame(2, $updated->revision);
        self::assertSame($updated, $knowledge->updateCollectorFacet($claim->canonicalId, 'dimensions', 2, [
            ...$binding, 'provenance_sha256' => CollectorFacetTestKnowledgeRepository::fingerprint($updated->provenance),
        ]));
        $this->expectExceptionMessage('COLLECTOR_FACET_STALE_REVISION');
        $knowledge->updateCollectorFacet($claim->canonicalId, 'music', 1, $binding);
    }

    private function claim(string $key, string $text, array $metadata): KnowledgeClaim
    {
        return new KnowledgeClaim(UuidCodec::newV7(), 'nhk:knowledge:' . $key, $text, 'fact', ['metadata' => $metadata]);
    }

    private function sources(): SourceRepository
    {
        return new class implements SourceRepository {
            public function findByCanonicalId(string $id): ?Source { return null; }
            public function findByStableKey(string $stableKey): ?Source { return null; }
            public function create(Source $source): Source { return $source; }
            public function update(Source $source, int $expectedRevision): Source { return $source; }
            public function list(bool $includeRetired = false): array { return []; }
        };
    }

    private function evidence(): EvidenceRepository
    {
        return new class implements EvidenceRepository {
            public function findByCanonicalId(string $id): ?Evidence { return null; }
            public function create(Evidence $evidence): Evidence { return $evidence; }
            public function update(Evidence $evidence, int $expectedRevision): Evidence { return $evidence; }
            public function listByClaim(string $claimId, bool $includeRetired = false): array { return []; }
            public function listBySource(string $sourceId, bool $includeRetired = false): array { return []; }
        };
    }
}

final class CollectorFacetTestKnowledgeRepository implements KnowledgeRepository
{
    /** @param list<KnowledgeClaim> $claims */
    public function __construct(private array $claims) {}
    public function findByCanonicalId(string $id): ?KnowledgeClaim { foreach ($this->claims as $claim) if ($claim->canonicalId === $id) return $claim; return null; }
    public function findByStableKey(string $stableKey): ?KnowledgeClaim { foreach ($this->claims as $claim) if ($claim->stableKey === $stableKey) return $claim; return null; }
    public function create(KnowledgeClaim $claim): KnowledgeClaim { $this->claims[] = $claim; return $claim; }
    public function update(KnowledgeClaim $claim, int $expectedRevision): KnowledgeClaim { $current = $this->findByCanonicalId($claim->canonicalId); if (!$current || $current->revision !== $expectedRevision) throw new \RuntimeException('STALE'); $saved = new KnowledgeClaim($claim->canonicalId, $claim->stableKey, $claim->claimText, $claim->claimType, $claim->provenance, $claim->active, $expectedRevision + 1); foreach ($this->claims as $i => $item) if ($item->canonicalId === $claim->canonicalId) $this->claims[$i] = $saved; return $saved; }
    public function list(bool $includeRetired = false): array { return $this->claims; }
    public static function fingerprint(array $value): string { $sort = static function (mixed $item) use (&$sort): mixed { if (!is_array($item)) return $item; foreach ($item as $key => $child) $item[$key] = $sort($child); if (array_keys($item) !== range(0, count($item) - 1)) ksort($item); return $item; }; return hash('sha256', json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); }
}

final class CollectorFacetTestProposalRepository implements ProposalRepository
{
    /** @var array<string,Proposal> */
    private array $items = [];
    public function create(Proposal $proposal): Proposal { $this->items[$proposal->id] = $proposal; return $proposal; }
    public function find(string $id): ?Proposal { return $this->items[$id] ?? null; }
    public function findByIdempotencyKey(string $key): ?Proposal { foreach ($this->items as $item) if ($item->idempotencyKey === $key) return $item; return null; }
    public function save(Proposal $proposal): Proposal { $this->items[$proposal->id] = $proposal; return $proposal; }
    public function findForUpdate(string $id): ?Proposal { return $this->find($id); }
    public function recordApproval(Proposal $proposal, string $actor): void {}
    public function latestApproval(string $proposalId): ?array { return null; }
    public function findLatestVideoIngest(string $videoId): ?Proposal { return null; }
}
