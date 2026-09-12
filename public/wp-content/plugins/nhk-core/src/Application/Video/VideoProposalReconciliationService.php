<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Application\Capture\CaptureVideoProvenancePlanner;
use NHK\Core\Application\Governance\{GovernanceAutomationPolicyResolver, GovernanceService, ProposalEligibilityService};
use NHK\Core\Application\Semantic\SubjectResolutionService;
use NHK\Core\Contracts\Governance\{GovernedLifecycle, ProposalRepository, VideoProposalReconciliationPort};
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\PublicIdentity\{PublicIdentityRepository};
use NHK\Core\Application\PublicIdentity\PublicIdentityService;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Governance\{CommandCanonicalizer, Proposal, ProposalState, ProposalSubjectBindingValidator};
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Repairs an existing Video proposal through fresh governed dependencies.
 * Approved proposal payloads are never edited; a replacement is the only
 * mutable command produced by this service.
 */
final class VideoProposalReconciliationService implements VideoProposalReconciliationPort
{
    /** @param callable(string):bool $can @param callable():string $actor @param callable(string):array<string,mixed>|null $publicIdentityReadback */
    public function __construct(
        private ProposalRepository $proposals,
        private GovernedLifecycle $lifecycle,
        private GovernanceService $governance,
        private ProposalEligibilityService $eligibility,
        private $apply,
        private VideoRepository $videos,
        private SourceRepository $sources,
        private KnowledgeRepository $claims,
        private EvidenceRepository $evidence,
        private CaptureVideoProvenancePlanner $planner,
        private ?SubjectResolutionService $subjects = null,
        private ?GovernanceAutomationPolicyResolver $policies = null,
        private $can = null,
        private $actor = null,
        private $publicIdentityReadback = null,
        private ?PublicIdentityService $publicIdentities = null,
        private ?PublicIdentityRepository $identityRepository = null,
        /** @var callable(string):bool|null */
        private $successfulApplyExists = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function reconcile(string $proposalId): array
    {
        $original = $this->proposals->find($proposalId);
        if ($original === null) return $this->blocked($proposalId, 'PROPOSAL_NOT_FOUND');
        if ($original->entityType !== 'video' || $original->operation !== 'ingest') return $this->blocked($proposalId, 'VIDEO_RECONCILIATION_UNSUPPORTED');
        if ($original->state === ProposalState::APPLIED || $original->appliedAt !== null || $this->hasSuccessfulApply($proposalId)) return $this->blocked($proposalId, 'REPAIR_APPLIED_PROPOSAL_FORBIDDEN');
        if ($original->state === ProposalState::SUPERSEDED) {
            $replacementId = trim((string) ($original->supersededByProposalId ?? ''));
            if (!UuidCodec::isValid($replacementId)) return $this->blocked($proposalId, 'REPAIR_SUPERSEDED_REPLACEMENT_MISSING');
            $replayed = $this->run(['proposal_id' => $replacementId]);
            if (($replayed['status'] ?? '') !== 'APPLIED') return $this->withReplacement($proposalId, $replayed);
            $replayed['status'] = 'REUSED_CANONICAL';
            $replayed['replaced_proposal_id'] = $replacementId;
            $replayed['canonical_id'] = $this->canonicalId($replayed);
            $replayed['public_identity_readback'] = $this->publicIdentityReadback($replayed['canonical_id']);
            return $replayed;
        }
        if ($original->state !== ProposalState::APPROVED) return $this->blocked($proposalId, 'NOT_APPROVED');

        $payload = $original->payload;
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $source = is_array($metadata['source'] ?? null) ? $metadata['source'] : [];
        $platform = strtolower(trim((string) ($source['platform'] ?? 'youtube')));
        $externalId = trim((string) ($source['external_video_id'] ?? ''));
        if ($externalId === '') return $this->blocked($proposalId, 'SOURCE_UNAVAILABLE');

        $existing = $this->videos->findByExternalReference($platform, $externalId);
        $subject = $this->resolveSubject($metadata, $source);
        if ($subject === null) return $this->blocked($proposalId, 'SUBJECT_UNRESOLVED');

        $planned = $this->planner->plan($proposalId, ['payload' => $payload], $source, $subject, ['user_hint' => $this->userHint($metadata)]);
        if (($planned['status'] ?? '') !== 'READY') return $this->blocked($proposalId, $this->firstReason((array) ($planned['blockers'] ?? []), 'SUBJECT_UNRESOLVED'));

        $sourceId = $this->resolveOrApplySource($planned, $original);
        if (!UuidCodec::isValid($sourceId)) return $this->blocked($proposalId, 'SOURCE_UNAVAILABLE');
        $claimId = $this->resolveOrApplyClaim($planned, $original, $subject);
        if (!UuidCodec::isValid($claimId)) return $this->blocked($proposalId, 'EVIDENCE_REQUIRED');
        $evidenceId = $this->resolveEvidence($claimId, $sourceId);
        if ($evidenceId === null) {
            $withPlaceholder = $this->planner->attachEvidence($planned, $sourceId, $claimId, UuidCodec::newV7());
            $evidenceArguments = (array) (($withPlaceholder['dependencies'] ?? [])[2] ?? []);
            $evidenceWrite = $this->run($evidenceArguments);
            if (($evidenceWrite['status'] ?? '') !== 'APPLIED') return $this->blocked($proposalId, $this->firstReason((array) ($evidenceWrite['blockers'] ?? []), 'EVIDENCE_REQUIRED'));
            $evidenceId = $this->canonicalId($evidenceWrite);
        }
        if (!UuidCodec::isValid($evidenceId)) return $this->blocked($proposalId, 'CANONICAL_EVIDENCE_REQUIRED');

        $complete = $this->planner->attachEvidence($planned, $sourceId, $claimId, $evidenceId);
        $video = is_array($complete['video_proposal'] ?? null) ? $complete['video_proposal'] : [];
        $videoPayload = is_array($video['payload'] ?? null) ? $video['payload'] : [];
        $canonicalId = $existing?->canonicalId ?: trim((string) ($payload['canonical_id'] ?? ''));
        if (!UuidCodec::isValid($canonicalId)) $canonicalId = UuidCodec::newV7();
        $videoPayload['canonical_id'] = $canonicalId;
        $videoPayload['metadata']['subject_resolution_packet'] = $subject;
        $video['payload'] = $videoPayload;
        $replacement = $this->newVideoProposal($original, $video, $canonicalId, [$sourceId, $claimId, $evidenceId]);
        $replacement = $this->lifecycle->createFromArguments($replacement);
        $result = $this->run(['proposal_id' => $replacement->id]);
        if (($result['status'] ?? '') !== 'APPLIED') return $this->withReplacement($proposalId, $result);

        $this->governance->supersede($proposalId, $replacement->id, $this->actor());
        $result['status'] = $existing === null ? 'REBUILT_AND_APPLIED' : 'REUSED_CANONICAL';
        $result['replaced_proposal_id'] = $replacement->id;
        $result['canonical_id'] = $this->canonicalId($result) ?: $canonicalId;
        $result['public_identity_readback'] = $this->publicIdentityReadback($result['canonical_id']);
        return $result;
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private function run(array $plan): array
    {
        try {
            $proposal = isset($plan['proposal_id']) ? $this->proposals->find((string) $plan['proposal_id']) : $this->lifecycle->createFromArguments($plan);
            if (!$proposal instanceof Proposal) return ['status' => 'FAILED', 'blockers' => ['PROPOSAL_NOT_FOUND']];
            $review = $this->lifecycle->review($proposal->id);
            $state = (string) ($review['state'] ?? $proposal->state->value);
            if ($state === ProposalState::APPLIED->value || $proposal->state === ProposalState::APPLIED) {
                $applied = ($this->apply)($proposal->id);
                if (!is_array($applied['canonical_readback'] ?? null)) return ['status' => 'FAILED_RETRYABLE', 'blockers' => ['CANONICAL_READBACK_VERIFICATION_FAILED']];
                return ['status' => 'APPLIED', 'proposal_id' => $proposal->id, 'canonical_id' => $applied['canonical_id'] ?? $applied['result_entity_uuid'] ?? null, 'canonical_readback' => $applied['canonical_readback'], 'idempotent' => (bool) ($applied['idempotent'] ?? true)];
            }
            if ($state === ProposalState::DRAFT->value) {
                $proposal = $this->lifecycle->submit($proposal->id);
                $review = $this->lifecycle->review($proposal->id);
                $state = (string) ($review['state'] ?? $proposal->state->value);
            }
            if ($state === ProposalState::SUBMITTED->value) {
                if ($this->can !== null && !(($this->can)('nhk_approve_proposals'))) return ['status' => 'BLOCKED', 'blockers' => ['GOVERNANCE_APPROVAL_REQUIRED']];
                if ($this->policies !== null && $this->policies->resolve('video')->value === 'REVIEW_REQUIRED') return ['status' => 'BLOCKED', 'blockers' => ['GOVERNANCE_APPROVAL_REQUIRED']];
                $proposal = $this->lifecycle->approve($proposal->id, (string) ($review['content_fingerprint'] ?? $proposal->contentFingerprint), (string) ($review['dependency_fingerprint'] ?? $proposal->dependencyFingerprint), $this->actor());
            }
            $eligible = $this->lifecycle->eligibility($proposal->id);
            if (($eligible['ready'] ?? false) !== true) return ['status' => 'BLOCKED', 'blockers' => array_values(array_map('strval', (array) ($eligible['reasons'] ?? ['PROPOSAL_NOT_ELIGIBLE'])))];
            $applied = ($this->apply)($proposal->id);
            if (!is_array($applied['canonical_readback'] ?? null)) return ['status' => 'FAILED', 'blockers' => ['CANONICAL_READBACK_VERIFICATION_FAILED']];
            return ['status' => 'APPLIED', 'proposal_id' => $proposal->id, 'canonical_id' => $applied['canonical_id'] ?? $applied['result_entity_uuid'] ?? null, 'canonical_readback' => $applied['canonical_readback'], 'idempotent' => (bool) ($applied['idempotent'] ?? false)];
        } catch (\Throwable $error) {
            return ['status' => 'FAILED', 'blockers' => [$this->errorCode($error)], 'message' => substr(preg_replace('/\s+/', ' ', trim($error->getMessage())) ?: 'Video reconciliation failed.', 0, 160)];
        }
    }

    /** @return array<string,mixed> */
    private function newVideoProposal(Proposal $original, array $video, string $canonicalId, array $dependencyIds): array
    {
        $payload = is_array($video['payload'] ?? null) ? $video['payload'] : [];
        return [
            'operation' => 'ingest', 'entity_type' => 'video', 'subject_id' => $canonicalId, 'target_uuid' => $this->videos->findByCanonicalId($canonicalId) ? $canonicalId : null,
            'expected_revision' => $this->videos->findByCanonicalId($canonicalId)?->revision, 'dependency_ids' => $dependencyIds,
            'payload' => $payload, 'idempotency_key' => 'video-reconcile:' . $original->id . ':' . hash('sha256', CommandCanonicalizer::canonicalize([$canonicalId, $dependencyIds, $payload])),
        ];
    }

    private function resolveOrApplySource(array $plan, Proposal $original): string
    {
        $stableKey = trim((string) ($plan['source_stable_key'] ?? ''));
        $existing = $stableKey !== '' ? $this->sources->findByStableKey($stableKey) : null;
        if ($existing !== null && $existing->active) return $existing->canonicalId;
        $write = $this->run((array) (($plan['dependencies'] ?? [])[0] ?? []));
        return ($write['status'] ?? '') === 'APPLIED' ? $this->canonicalId($write) : '';
    }

    private function resolveOrApplyClaim(array $plan, Proposal $original, array $subject): string
    {
        $stableKey = trim((string) ($plan['claim_stable_key'] ?? ''));
        $existing = $stableKey !== '' ? $this->claims->findByStableKey($stableKey) : null;
        if ($existing !== null && $existing->active) return $existing->canonicalId;
        $arguments = (array) (($plan['dependencies'] ?? [])[1] ?? []);
        $write = $this->run($arguments);
        return ($write['status'] ?? '') === 'APPLIED' ? $this->canonicalId($write) : '';
    }

    private function resolveEvidence(string $claimId, string $sourceId): ?string
    {
        foreach ($this->evidence->listByClaim($claimId) as $item) if ($item->active && $item->sourceId === $sourceId) return $item->canonicalId;
        return null;
    }

    private function resolveSubject(array $metadata, array $source): ?array
    {
        $packet = is_array($metadata['subject_resolution_packet'] ?? null) ? $metadata['subject_resolution_packet'] : [];
        $attachmentTarget = null;
        foreach ((array) ($metadata['semantic_attachments'] ?? []) as $attachment) {
            if (!is_array($attachment)) continue;
            $id = trim((string) ($attachment['target_uuid'] ?? ''));
            $type = trim((string) ($attachment['target_type'] ?? ''));
            if (UuidCodec::isValid($id) && $type !== '') {
                $attachmentTarget = ['id' => $id, 'type' => $type, 'name' => ''];
                break;
            }
        }
        $packetIsVariant = strtolower(trim((string) ($packet['type'] ?? ''))) === 'variant';
        if ($packetIsVariant && UuidCodec::isValid((string) ($packet['id'] ?? ''))) return $packet;
        if ($this->subjects !== null) {
            $title = (string) ($source['source_title'] ?? '');
            $hints = [$title];
            if (preg_match('/\b\d+\s*\/\s*\d+\b/u', $title, $match) === 1) $hints[] = $match[0];
            $resolved = $this->subjects->resolve($hints);
            $primary = is_array($resolved['primary'] ?? null) ? $resolved['primary'] : null;
            return $primary !== null && (string) ($primary['type'] ?? '') === 'variant' ? $primary : null;
        }
        if ($attachmentTarget !== null) return $attachmentTarget;
        return UuidCodec::isValid((string) ($packet['id'] ?? '')) && trim((string) ($packet['type'] ?? '')) !== '' ? $packet : null;
    }

    private function userHint(array $metadata): string { return is_array($metadata['provenance']['user_hint'] ?? null) ? (string) ($metadata['provenance']['user_hint']['value'] ?? '') : ''; }
    private function canonicalId(array $result): string { return trim((string) ($result['canonical_id'] ?? $result['result_entity_uuid'] ?? ($result['canonical_readback']['canonical_id'] ?? ''))); }
    private function firstReason(array $reasons, string $fallback): string { return trim((string) ($reasons[0] ?? $fallback)) ?: $fallback; }
    private function actor(): string { return $this->actor !== null ? (string) (($this->actor)()) : 'video-reconciler'; }
    private function blocked(string $id, string $reason): array { return ['ok' => false, 'outcome' => 'blocked', 'status' => 'BLOCKED', 'proposal_id' => $id, 'reason' => $reason, 'blockers' => [$reason]]; }
    private function withReplacement(string $id, array $result): array { return ['ok' => false, 'outcome' => 'failed', 'status' => ($result['status'] ?? '') === 'BLOCKED' ? 'BLOCKED' : 'FAILED', 'proposal_id' => $id, 'reason' => $this->firstReason((array) ($result['blockers'] ?? []), 'VIDEO_RECONCILIATION_FAILED')] + $result; }
    private function errorCode(\Throwable $error): string
    {
        $code = trim((string) $error->getCode());
        if (preg_match('/^[A-Z][A-Z0-9_]{2,63}$/', $code) === 1) return $code;
        $message = trim($error->getMessage());
        return preg_match('/(?:^|:)([A-Z][A-Z0-9_]{2,63})$/', $message, $match) === 1 ? $match[1] : 'VIDEO_RECONCILIATION_FAILED';
    }
    private function publicIdentityReadback(string $id): mixed
    {
        if (!UuidCodec::isValid($id)) return null;
        if ($this->publicIdentityReadback !== null) return ($this->publicIdentityReadback)($id);
        if ($this->publicIdentities === null || $this->identityRepository === null) return null;
        $identity = $this->identityRepository->findCurrentByOwner('video', $id, 'video');
        if ($identity === null) {
            $video = $this->videos->findByCanonicalId($id);
            if ($video === null || trim($video->title) === '') throw new \RuntimeException('PUBLIC_IDENTITY_INPUT_INVALID');
            $this->publicIdentities->allocateCanonical('video', $id, 'video', 'root', $video->title, [], 'video-reconcile:' . $id . ':public-identity');
            $identity = $this->identityRepository->findCurrentByOwner('video', $id, 'video');
        }
        if (!is_array($identity) || (string) ($identity['current_path'] ?? '') !== '/video/' . (string) ($identity['current_slug'] ?? '') . '/') throw new \RuntimeException('PUBLIC_IDENTITY_NOT_PERSISTED');
        return $identity;
    }

    private function hasSuccessfulApply(string $proposalId): bool
    {
        if ($this->successfulApplyExists === null) return false;
        try {
            return (bool) ($this->successfulApplyExists)($proposalId);
        } catch (\Throwable) {
            throw new \RuntimeException('REPAIR_APPLY_HISTORY_UNAVAILABLE');
        }
    }
}
