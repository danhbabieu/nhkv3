<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Application\Capture\ClockTypeMembershipCandidate;
use NHK\Core\Application\Entity\{EntityProfileResolver, EntityProfileResolution};
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Governance\{GovernanceActionPort, GovernedLifecycle};
use NHK\Core\Domain\Governance\Proposal;
use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Domain\Graph\NodeReference;

/**
 * Clock-Type relation lifecycle adapter. Creation is proposal-only; the
 * existing ControlledApplyService remains the sole Graph writer.
 */
final class ClockTypeMembershipGovernanceService
{
    public function __construct(
        private GovernedLifecycle $lifecycle,
        private GovernanceActionPort $actions,
        private GraphService $graph,
        private AuthorityRepository $authority,
        private EntityProfileResolver $profiles = new EntityProfileResolver(),
    ) {}

    public function createAndSubmit(ClockTypeMembershipCandidate $candidate): Proposal
    {
        if ($candidate->candidateStatus !== 'QUALIFIED') throw new \InvalidArgumentException('CLOCK_TYPE_CANDIDATE_NOT_QUALIFIED');
        if ($candidate->targetFamily !== 'clock_type') throw new \InvalidArgumentException('TARGET_FAMILY_NOT_CLOCK_TYPE');
        $payload = [
            'source_type' => $candidate->sourceType,
            'source_uuid' => $candidate->sourceUuid,
            'source_key' => $candidate->sourceUuid,
            'source_revision' => $candidate->sourceRevision,
            'predicate' => ClockTypeMembershipCandidate::PREDICATE,
            'target_type' => 'classification',
            'target_uuid' => $candidate->targetClassificationUuid,
            'target_key' => $candidate->targetClassificationUuid,
            'target_revision' => $candidate->targetRevision,
            'scope' => $candidate->sourceType,
            'provenance' => $candidate->provenanceClass,
            'support_status' => $candidate->supportStatus,
            'resolution_basis' => $candidate->resolutionBasis,
            'candidate_id' => $candidate->candidateId,
            'capture_id' => $candidate->captureId,
            'capture_revision' => $candidate->captureRevision,
            'evidence_refs' => [],
            'dependency_revisions' => [$candidate->sourceUuid => $candidate->sourceRevision, $candidate->targetClassificationUuid => $candidate->targetRevision],
        ];
        $proposal = $this->lifecycle->createFromArguments([
            'operation' => 'relation_create',
            'entity_type' => 'relation',
            'subject_id' => $candidate->sourceUuid,
            'target_uuid' => $candidate->targetClassificationUuid,
            'expected_revision' => $candidate->sourceRevision,
            'payload' => $payload,
            'idempotency_key' => $candidate->candidateId,
        ]);
        return $proposal->state->value === 'draft' ? $this->lifecycle->submit($proposal->id) : $proposal;
    }

    /** @return array<string,mixed> */
    public function approveAndApply(string $proposalId, string $contentFingerprint, string $dependencyFingerprint, string $actor): array
    {
        $approved = $this->actions->approve($proposalId, $contentFingerprint, $dependencyFingerprint, $actor);
        $eligibility = $this->actions->eligibility($approved->id);
        if (!$eligibility->ready) throw new \RuntimeException('CLOCK_TYPE_MEMBERSHIP_NOT_ELIGIBLE: ' . implode(',', $eligibility->reasons));
        $this->revalidateImmediatelyBeforeApply($approved);
        $result = $this->actions->apply($approved->id);
        $proposal = $this->actions->find($approved->id) ?? throw new \RuntimeException('PROPOSAL_NOT_FOUND_AFTER_APPLY');
        $readBack = $this->exactReadBack($proposal);
        return [...$result, 'canonical_relation_readback' => $readBack];
    }

    private function revalidateImmediatelyBeforeApply(Proposal $proposal): void
    {
        $payload = $proposal->payload;
        $sourceType = trim((string) ($payload['source_type'] ?? ''));
        $sourceUuid = trim((string) ($payload['source_uuid'] ?? ''));
        $targetUuid = trim((string) ($payload['target_uuid'] ?? ''));
        if (!in_array($sourceType, ['model', 'variant', 'specimen', 'product'], true) || (string) ($payload['predicate'] ?? '') !== ClockTypeMembershipCandidate::PREDICATE || (string) ($payload['scope'] ?? '') !== $sourceType) throw new \RuntimeException('CLOCK_TYPE_MEMBERSHIP_ELIGIBILITY_FAILED');
        $source = $this->authority->findByCanonicalId($sourceUuid);
        $target = $this->authority->findByCanonicalId($targetUuid);
        $profile = $target instanceof \NHK\Core\Domain\Authority\AuthorityEntity ? $this->profiles->resolveProfile($target) : null;
        if (!$source instanceof \NHK\Core\Domain\Authority\AuthorityEntity || !$source->active() || $source->entityType !== $sourceType || $source->revision !== (int) ($payload['source_revision'] ?? 0)) throw new \RuntimeException('CLOCK_TYPE_MEMBERSHIP_SOURCE_REVISION_OR_STATE_CHANGED');
        if (!$target instanceof \NHK\Core\Domain\Authority\AuthorityEntity || !$target->active() || $target->entityType !== 'classification' || $target->revision !== (int) ($payload['target_revision'] ?? 0) || ($target->payload['family'] ?? null) !== 'clock_type' || $profile?->status !== EntityProfileResolution::RESOLVED) throw new \RuntimeException('CLOCK_TYPE_MEMBERSHIP_TARGET_REVISION_OR_FAMILY_CHANGED');
        $existing = $this->graph->findEdge(new NodeReference($sourceType, $sourceUuid), ClockTypeMembershipCandidate::PREDICATE, new NodeReference('classification', $targetUuid));
        if ($existing?->isActive()) throw new \RuntimeException('CLOCK_TYPE_MEMBERSHIP_ALREADY_CANONICAL');
        if ($existing !== null) throw new \RuntimeException('CLOCK_TYPE_MEMBERSHIP_RETIRED_RELATION_REQUIRES_REVIEW');
    }

    /** @return array<string,mixed> */
    private function exactReadBack(Proposal $proposal): array
    {
        $payload = $proposal->payload;
        $source = new NodeReference((string) ($payload['source_type'] ?? ''), (string) ($payload['source_uuid'] ?? ''));
        $target = new NodeReference((string) ($payload['target_type'] ?? ''), (string) ($payload['target_uuid'] ?? ''));
        $edge = $this->graph->findEdge($source, ClockTypeMembershipCandidate::PREDICATE, $target);
        if ($edge === null || !$edge->isActive()) throw new \RuntimeException('CANONICAL_RELATION_READBACK_VERIFICATION_FAILED');
        if ($edge->source->reference->key() !== $source->key() || $edge->target->reference->key() !== $target->key() || $edge->predicate !== ClockTypeMembershipCandidate::PREDICATE) throw new \RuntimeException('CANONICAL_RELATION_READBACK_VERIFICATION_FAILED');
        return ['entity_type' => 'relation', 'canonical_id' => $edge->edge_uuid, 'active' => true, 'revision' => $edge->revision, 'predicate' => $edge->predicate, 'source' => $edge->source->reference->key(), 'target' => $edge->target->reference->key()];
    }
}
