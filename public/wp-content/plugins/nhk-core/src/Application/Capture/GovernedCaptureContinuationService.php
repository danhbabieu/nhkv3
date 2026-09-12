<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Application\Governance\GovernanceAutomationPolicyResolver;
use NHK\Core\Application\Semantic\ClaimReusePolicy;
use NHK\Core\Contracts\Governance\GovernedLifecycle;
use NHK\Core\Contracts\Governance\VideoProposalReconciliationPort;
use NHK\Core\Domain\Governance\{CommandCanonicalizer, Proposal, ProposalState};
use NHK\Core\Domain\Governance\ProposalSubjectBindingValidator;
use NHK\Core\Domain\Knowledge\KnowledgeFacetProfile;
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Runs the bounded semantic Governance lifecycle for an existing Capture.
 * Proposal abilities remain internal; the canonical Capture boundary owns
 * this continuation and only applies after its explicit gates pass.
 */
final class GovernedCaptureContinuationService
{
    /** @param callable(string):array<string,mixed> $apply @param callable(string):bool $can */
    public function __construct(
        private GovernedLifecycle $governance,
        private $apply,
        private GovernanceAutomationPolicyResolver $policies,
        private $can,
        private ?ClaimReusePolicy $claimReuse = null,
        private ?CaptureVideoProvenancePlanner $videoProvenance = null,
        private ?VideoProposalReconciliationPort $videoReconciliation = null,
        /** @var callable(array<string,mixed>):array<string,mixed>|string|null */
        private $videoDependencyState = null,
    ) {}

    /** @return array<string,mixed> */
    public function execute(string $captureId, string $continuationKey, array $context, array $control = []): array
    {
        $proposalIds = array_values(array_filter(array_map('strval', (array) ($control['proposal_ids'] ?? [])), static fn (string $id): bool => UuidCodec::isValid($id)));
        $reusedClaims = $this->reusedClaims($context);
        $plans = $proposalIds !== [] ? array_map(static fn (string $id): array => ['proposal_id' => $id], $proposalIds) : $this->plans($captureId, $continuationKey, $context);
        $skippedVideoChildren = [];
        $videoChildren = [];
        if ($proposalIds === [] && ($context['existing_capture_continuation'] ?? false) === true && ($context['resume_video'] ?? false) !== true) {
            $previousChildren = $this->previousVideoChildren($context);
            $plans = array_values(array_filter($plans, function (array $plan) use (&$skippedVideoChildren, &$videoChildren, $previousChildren, $context): bool {
                $isVideo = isset($plan['capture_video_provenance']) || (($plan['entity_type'] ?? '') === 'video');
                if (!$isVideo) return true;
                $fingerprint = $this->videoPlanFingerprint($plan, $context);
                $previous = $previousChildren[$fingerprint] ?? null;
                // Old Captures may predate the fingerprint receipt. They are
                // safe to skip on text-only continuation, but an explicit
                // resume_video control is required to retry that child.
                $unchanged = $previousChildren === [] || ($previous !== null && ($previous['fingerprint'] ?? '') === $fingerprint);
                $previousStatus = (string) ($previous['status'] ?? 'FAILED_RETRYABLE');
                if (!$unchanged || !in_array($previousStatus, ['FAILED_RETRYABLE', 'SYSTEM_BLOCKED', 'APPLIED', 'REUSED_VERIFIED', 'SKIPPED_UNCHANGED'], true)) return true;
                $reason = in_array($previousStatus, ['APPLIED', 'REUSED_VERIFIED'], true) ? 'VIDEO_CHILD_ALREADY_VERIFIED' : 'VIDEO_CHILD_UNCHANGED_ON_TEXT_ADDENDUM';
                $skippedVideoChildren[] = ['status' => 'SKIPPED_UNCHANGED', 'reason' => $reason, 'fingerprint' => $fingerprint];
                $videoChildren[] = ['fingerprint' => $fingerprint, 'status' => $previousStatus === 'APPLIED' ? 'REUSED_VERIFIED' : 'SKIPPED_UNCHANGED', 'reason' => $reason];
                return false;
            }));
        }
        if ($plans === []) return ['status' => 'REVIEW_REQUIRED', 'writes' => $skippedVideoChildren, 'reused_claims' => $reusedClaims, 'video_children' => $videoChildren, 'blockers' => $skippedVideoChildren !== [] ? ['VIDEO_CHILD_UNCHANGED_ON_TEXT_ADDENDUM'] : ($reusedClaims === [] ? ['SEMANTIC_SUBJECT_OR_DELTA_REQUIRED'] : []), 'governance' => ['lifecycle' => [], 'status' => 'REVIEW_REQUIRED', 'skipped_video_children' => count($skippedVideoChildren)]];

        $writes = [];
        $lifecycle = [];
        foreach ($plans as $plan) {
            if (isset($plan['capture_video_provenance']) && is_array($plan['capture_video_provenance'])) {
                $before = count($writes);
                $this->executeVideoProvenancePlan($plan['capture_video_provenance'], $control, $writes, $lifecycle);
                $childWrites = array_slice($writes, $before);
                $last = $childWrites === [] ? ['status' => 'FAILED_RETRYABLE', 'blockers' => ['VIDEO_CHILD_NO_RESULT']] : $childWrites[array_key_last($childWrites)];
                $videoChildren[] = ['fingerprint' => $this->videoPlanFingerprint($plan, $context), 'status' => (string) ($last['status'] ?? 'FAILED_RETRYABLE'), 'blockers' => (array) ($last['blockers'] ?? [])];
                continue;
            }
            try {
                $write = $this->runGovernedPlan($plan, $control, $lifecycle);
            } catch (\Throwable $error) {
                $write = $this->classifiedFailure($plan, $error);
            }
            $writes[] = $write;
            if (($plan['entity_type'] ?? '') === 'video') $videoChildren[] = ['fingerprint' => $this->videoPlanFingerprint($plan, $context), 'status' => (string) ($write['status'] ?? 'FAILED_RETRYABLE'), 'blockers' => (array) ($write['blockers'] ?? [])];
        }

        $pending = array_values(array_filter($writes, static fn (array $write): bool => ($write['status'] ?? '') === 'REVIEW_REQUIRED'));
        $blocked = array_values(array_filter($writes, static fn (array $write): bool => ($write['status'] ?? '') === 'SYSTEM_BLOCKED'));
        $retryable = array_values(array_filter($writes, static fn (array $write): bool => ($write['status'] ?? '') === 'FAILED_RETRYABLE'));
        $applied = array_values(array_filter($writes, static fn (array $write): bool => ($write['status'] ?? '') === 'APPLIED'));
        $status = $blocked !== [] ? 'SYSTEM_BLOCKED' : ($retryable !== [] ? 'FAILED_RETRYABLE' : ($pending !== [] ? 'REVIEW_REQUIRED' : 'APPLIED'));
        $failureWrites = $blocked !== [] ? $blocked : $retryable;
        $failureBlockers = $failureWrites !== [] ? array_values(array_unique(array_merge(...array_map(static fn (array $write): array => (array) ($write['blockers'] ?? []), $failureWrites)))) : ($pending !== [] ? ['GOVERNANCE_APPROVAL_REQUIRED'] : ($skippedVideoChildren !== [] ? ['VIDEO_CHILD_UNCHANGED_ON_TEXT_ADDENDUM'] : []));
        return ['status' => $status, 'writes' => array_merge($skippedVideoChildren, $writes), 'reused_claims' => $reusedClaims, 'video_children' => $videoChildren, 'blockers' => $failureBlockers, 'governance' => ['lifecycle' => array_values(array_unique($lifecycle)), 'status' => $status, 'applied_count' => count($applied), 'pending_count' => count($pending), 'retryable_count' => count($retryable), 'skipped_video_children' => count($skippedVideoChildren)]];
    }

    /** @return list<array<string,mixed>> */
    private function plans(string $captureId, string $continuationKey, array $context): array
    {
        $resolved = is_array($context['subject_resolution']['resolved'] ?? null) ? $context['subject_resolution']['resolved'] : [];
        $variants = array_values(array_filter($resolved, static fn (mixed $item): bool => is_array($item) && ($item['type'] ?? '') === 'variant' && UuidCodec::isValid((string) ($item['id'] ?? ''))));
        $plans = [];
        if (count($variants) === 1) {
            $variant = $variants[0];
            $deltaText = trim((string) ($context['continuation_delta_text'] ?? ''));
            $candidates = $deltaText !== ''
                ? [['text' => $deltaText, 'provenance' => 'EXPLICIT_USER_KNOWLEDGE']]
                : (array) ($context['interpretation']['user_claim_candidates'] ?? []);
            foreach ($candidates as $candidate) {
                if (!is_array($candidate) || trim((string) ($candidate['text'] ?? '')) === '') continue;
                $scope = trim((string) ($candidate['scope'] ?? 'variant')) ?: 'variant';
                $facet = trim((string) ($candidate['facet'] ?? 'identity')) ?: 'identity';
                try {
                    new KnowledgeFacetProfile($facet, $scope);
                } catch (\Throwable) {
                    // Invalid interpreter output is a bounded review gap. Do
                    // not silently coerce it into another semantic facet.
                    continue;
                }
                if ($this->claimReuse?->find(['text' => (string) $candidate['text'], 'subject_id' => (string) $variant['id'], 'scope' => $scope], $this->retrievedClaims($context)) !== null) continue;
                $payload = [
                    'stable_key' => 'nhk:knowledge:capture.' . hash('sha256', CommandCanonicalizer::canonicalize([$captureId, $variant['id'], trim((string) $candidate['text'])])),
                    'text' => trim((string) $candidate['text']), 'claim_type' => 'fact',
                    'provenance' => ['metadata' => ['facet' => $facet, 'scope' => $scope, 'version' => 1, 'subject_id' => $variant['id'], 'subject_type' => 'variant'], 'origin' => (string) ($candidate['provenance'] ?? 'EXPLICIT_USER_KNOWLEDGE')],
                ];
                $plans[] = $this->arguments('knowledge', 'ingest', (string) $variant['id'], $payload, 'capture:' . $captureId . ':knowledge:' . hash('sha256', (string) $payload['stable_key']));
            }
            foreach ((array) ($context['observations'] ?? []) as $observation) {
                if (!is_array($observation)) continue;
                $componentId = trim((string) ($observation['component_id'] ?? ''));
                if (!UuidCodec::isValid($componentId)) continue;
                $payload = ['source_type' => 'variant', 'source_uuid' => (string) $variant['id'], 'target_type' => 'component', 'target_uuid' => $componentId, 'predicate' => 'about', 'origin' => 'EXPLICIT_USER_RELATION'];
                $plans[] = $this->arguments('relation', 'relation_create', 'relation', $payload, 'capture:' . $captureId . ':component:' . $componentId);
            }
        }
        foreach ((array) ($context['assets'] ?? []) as $index => $asset) {
            if (!is_array($asset) || ($asset['kind'] ?? '') !== 'video' || !is_array($asset['video_proposal'] ?? null)) continue;
            $video = $asset['video_proposal'];
            $entityType = trim((string) ($video['entity_type'] ?? 'video')) ?: 'video';
            $operation = trim((string) ($video['operation'] ?? 'ingest')) ?: 'ingest';
            $subjectId = trim((string) ($video['subject_id'] ?? ''));
            $payload = is_array($video['payload'] ?? null) ? $video['payload'] : $video;
            if ($this->videoProvenance !== null && $entityType === 'video' && $operation === 'ingest') {
                $source = is_array($payload['metadata']['source'] ?? null) ? $payload['metadata']['source'] : (is_array($payload['metadata']['source_snapshot'] ?? null) ? $payload['metadata']['source_snapshot'] : []);
                $primary = is_array($context['subject_resolution']['primary'] ?? null) ? $context['subject_resolution']['primary'] : [];
                if ($primary === []) {
                    $resolvedVariants = array_values(array_filter($resolved, static fn (mixed $item): bool => is_array($item) && ($item['type'] ?? '') === 'variant'));
                    $primary = $resolvedVariants[0] ?? [];
                }
                $hint = is_array($payload['metadata']['provenance']['user_hint'] ?? null) ? (string) ($payload['metadata']['provenance']['user_hint']['value'] ?? '') : '';
                $plans[] = ['capture_video_provenance' => $this->videoProvenance->plan($captureId, $video, $source, $primary, ['user_hint' => $hint])];
                continue;
            }
            $plans[] = $this->arguments($entityType, $operation, $subjectId, $payload, 'capture:' . $captureId . ':video:' . hash('sha256', CommandCanonicalizer::canonicalize($payload)));
        }
        return $plans;
    }

    /** @param array<string,mixed> $provenancePlan @param list<array<string,mixed>> $writes @param list<string> $lifecycle */
    private function executeVideoProvenancePlan(array $provenancePlan, array $control, array &$writes, array &$lifecycle): void
    {
        if (($provenancePlan['status'] ?? '') !== 'READY') {
            $writes[] = ['status' => 'REVIEW_REQUIRED', 'blockers' => array_values(array_map('strval', (array) ($provenancePlan['blockers'] ?? ['SOURCE_SUBJECT_IDENTITY_UNCONFIRMED']))), 'diagnostics' => $provenancePlan['diagnostics'] ?? []];
            return;
        }
        $dependencyWrites = [];
        $canonicalIds = [];
        foreach (array_slice((array) ($provenancePlan['dependencies'] ?? []), 0, 2) as $dependency) {
            try {
                $write = $this->runGovernedPlan($dependency, $control, $lifecycle);
            } catch (\Throwable $error) {
                $write = $this->classifiedFailure($dependency, $error);
            }
            $dependencyWrites[] = $write;
            if (($write['status'] ?? '') !== 'APPLIED') {
                array_push($writes, ...$dependencyWrites);
                return;
            }
            $canonicalId = trim((string) ($write['canonical_id'] ?? ''));
            if (!$this->hasCanonicalReadback($write, $canonicalId)) {
                array_push($writes, ...array_merge($dependencyWrites, [['status' => 'SYSTEM_BLOCKED', 'blockers' => ['CANONICAL_READBACK_VERIFICATION_FAILED']]]));
                return;
            }
            $canonicalIds[] = $canonicalId;
        }
        if (count($canonicalIds) !== 2) {
            array_push($writes, ...array_merge($dependencyWrites, [['status' => 'SYSTEM_BLOCKED', 'blockers' => ['VIDEO_PROVENANCE_DEPENDENCY_READBACK_INCOMPLETE']]]));
            return;
        }
        $withEvidence = $this->videoProvenance?->attachEvidence($provenancePlan, $canonicalIds[0], $canonicalIds[1], 'pending-evidence');
        if (!is_array($withEvidence)) {
            array_push($writes, ...array_merge($dependencyWrites, [['status' => 'SYSTEM_BLOCKED', 'blockers' => ['VIDEO_PROVENANCE_PLANNER_UNAVAILABLE']]]));
            return;
        }
        $evidenceArguments = (array) ($withEvidence['dependencies'][2] ?? []);
        try {
            $evidenceWrite = $this->runGovernedPlan($evidenceArguments, $control, $lifecycle);
        } catch (\Throwable $error) {
            $evidenceWrite = $this->classifiedFailure($evidenceArguments, $error);
        }
        $allWrites = array_merge($dependencyWrites, [$evidenceWrite]);
        if (($evidenceWrite['status'] ?? '') !== 'APPLIED') {
            array_push($writes, ...$allWrites);
            return;
        }
        $evidenceId = trim((string) ($evidenceWrite['canonical_id'] ?? ''));
        if (!$this->hasCanonicalReadback($evidenceWrite, $evidenceId)) {
            array_push($writes, ...array_merge($allWrites, [['status' => 'SYSTEM_BLOCKED', 'blockers' => ['CANONICAL_READBACK_VERIFICATION_FAILED']]]));
            return;
        }
        $complete = $this->videoProvenance->attachEvidence($provenancePlan, $canonicalIds[0], $canonicalIds[1], $evidenceId);
        try {
            $videoWrite = $this->runGovernedPlan((array) ($complete['video_proposal'] ?? []), $control, $lifecycle);
        } catch (\Throwable $error) {
            $videoWrite = $this->classifiedFailure((array) ($complete['video_proposal'] ?? []), $error);
        }
        array_push($writes, ...array_merge($allWrites, [$videoWrite]));
    }

    /** @param array<string,mixed> $write */
    private function hasCanonicalReadback(array $write, string $canonicalId): bool
    {
        if (!UuidCodec::isValid($canonicalId)) return false;
        $readback = is_array($write['canonical_readback'] ?? null) ? $write['canonical_readback'] : [];
        return (string) ($readback['canonical_id'] ?? '') === $canonicalId && ($readback['active'] ?? false) === true;
    }

    /** @param array<string,mixed> $plan @param list<string> $lifecycle @return array<string,mixed> */
    private function runGovernedPlan(array $plan, array $control, array &$lifecycle): array
    {
        if (isset($plan['proposal_id'])) {
            $review = $this->governance->review((string) $plan['proposal_id']);
            $proposal = $this->proposalFromReview((string) $plan['proposal_id'], $review);
        } else {
            $existing = null;
            $idempotencyKey = trim((string) ($plan['idempotency_key'] ?? ''));
            if ($idempotencyKey !== '' && method_exists($this->governance, 'findByIdempotencyKey')) $existing = $this->governance->findByIdempotencyKey($idempotencyKey);
            if ($existing instanceof Proposal && $existing->entityType === 'video' && $existing->operation === 'ingest' && !ProposalSubjectBindingValidator::isValid($existing)) {
                return $this->repairVideoProposal($existing);
            }
            $proposal = $existing instanceof Proposal ? $existing : $this->governance->createFromArguments($plan);
            $review = $this->governance->review($proposal->id);
        }
        if ($proposal->entityType === 'video' && $proposal->operation === 'ingest' && !ProposalSubjectBindingValidator::isValid($proposal)) {
            return $this->repairVideoProposal($proposal);
        }
        $lifecycle[] = 'PROPOSAL';
        if (($review['state'] ?? $proposal->state->value) === ProposalState::DRAFT->value) {
            $proposal = $this->governance->submit($proposal->id);
            $lifecycle[] = 'SUBMIT';
            $review = $this->governance->review($proposal->id);
        }
        $state = (string) ($review['state'] ?? $proposal->state->value);
        if ($state === ProposalState::SUBMITTED->value) {
            $mode = $this->policies->resolve((string) ($plan['entity_type'] ?? $review['entity_type'] ?? $proposal->entityType));
            if ($mode->value === 'REVIEW_REQUIRED' && !(bool) ($control['approval_confirmed'] ?? false)) return $this->pending($proposal, $review);
            if (!(bool) ($this->can)('nhk_internal_content_operations')) throw new \RuntimeException('INTERNAL_CAPABILITY_REQUIRED_FOR_CAPTURE_GOVERNANCE_APPROVAL');
            $proposal = $this->governance->approve($proposal->id, (string) ($review['content_fingerprint'] ?? $proposal->contentFingerprint), (string) ($review['dependency_fingerprint'] ?? $proposal->dependencyFingerprint), $this->actor());
            $lifecycle[] = 'APPROVE';
        }
        if ($proposal->state === ProposalState::APPLIED || $state === ProposalState::APPLIED->value) {
            $applied = ($this->apply)($proposal->id);
            $lifecycle[] = 'CONTROLLED_APPLY';
            return $this->applied($proposal, $applied);
        }
        $eligibility = $this->governance->eligibility($proposal->id);
        $lifecycle[] = 'ELIGIBILITY';
        if (($eligibility['ready'] ?? false) !== true) return ['proposal_id' => $proposal->id, 'status' => 'SYSTEM_BLOCKED', 'blockers' => array_values(array_map('strval', (array) ($eligibility['reasons'] ?? ['PROPOSAL_NOT_ELIGIBLE'])))];
        $applied = ($this->apply)($proposal->id);
        $lifecycle[] = 'CONTROLLED_APPLY';
        return $this->applied($proposal, $applied);
    }

    /** @return array<string,mixed> */
    private function repairVideoProposal(Proposal $proposal): array
    {
        if ($this->videoReconciliation === null) throw new \RuntimeException('VIDEO_PROPOSAL_REPAIR_REQUIRED');
        $repaired = ($this->videoReconciliation)->reconcile($proposal->id);
        if (in_array((string) ($repaired['status'] ?? ''), ['REBUILT_AND_APPLIED', 'REUSED_CANONICAL'], true)) {
            $replacementId = (string) ($repaired['replaced_proposal_id'] ?? '');
            return ['proposal_id' => $replacementId !== '' ? $replacementId : $proposal->id, 'status' => 'APPLIED', 'canonical_id' => $repaired['canonical_id'] ?? null, 'canonical_readback' => $repaired['canonical_readback'] ?? null, 'repair' => $repaired, 'idempotent' => false];
        }
        throw new \RuntimeException((string) ($repaired['reason'] ?? 'VIDEO_PROPOSAL_REPAIR_REQUIRED'));
    }

    /** @return array<string,mixed> */
    private function classifiedFailure(array $plan, \Throwable $error): array
    {
        $code = strtoupper(trim((string) $error->getCode()));
        if ($code === '' || preg_match('/^[A-Z][A-Z0-9_]{2,63}$/', $code) !== 1) {
            $message = strtoupper(trim($error->getMessage()));
            $code = preg_match('/(?:^|:)([A-Z][A-Z0-9_]{2,63})$/', $message, $match) === 1 ? $match[1] : 'CAPTURE_GOVERNANCE_FAILED';
        }
        $blocked = preg_match('/(?:SUBJECT_BINDING|IDEMPOTENCY_STALE|IDEMPOTENCY_CONFLICT|BINDING_CONFLICT|REPAIR_REQUIRED|APPLIED_PROPOSAL_FORBIDDEN|INVARIANT|SCHEMA|CONTRACT|CAPABILITY|NOT_FOUND)/', $code) === 1;
        $review = preg_match('/(?:EVIDENCE_REQUIRED|CANONICAL_EVIDENCE_REQUIRED|SUBJECT_UNRESOLVED|SOURCE_UNAVAILABLE|APPROVAL_REQUIRED|GOVERNANCE_APPROVAL_REQUIRED|DEPENDENCY_REQUIRED|REVIEW_REQUIRED)/', $code) === 1;
        $status = $blocked ? 'SYSTEM_BLOCKED' : ($review ? 'REVIEW_REQUIRED' : 'FAILED_RETRYABLE');
        return ['proposal_id' => (string) ($plan['proposal_id'] ?? ''), 'status' => $status, 'blockers' => [$code]];
    }

    /** @return array<string,array<string,mixed>> */
    private function previousVideoChildren(array $context): array
    {
        $children = $context['prior_diagnostics']['semantic_write_back']['video_children'] ?? [];
        $indexed = [];
        foreach ((array) $children as $child) {
            if (!is_array($child) || trim((string) ($child['fingerprint'] ?? '')) === '') continue;
            $indexed[(string) $child['fingerprint']] = $child;
        }
        return $indexed;
    }

    private function videoPlanFingerprint(array $plan, array $context): string
    {
        $state = $context['video_dependency_fingerprint'] ?? null;
        if ($this->videoDependencyState !== null) {
            try { $state = ($this->videoDependencyState)($plan); } catch (\Throwable) { $state = 'DEPENDENCY_STATE_UNAVAILABLE'; }
        }
        return hash('sha256', CommandCanonicalizer::canonicalize([$plan, $state]));
    }

    /** @return list<array<string,mixed>> */
    private function reusedClaims(array $context): array
    {
        if ($this->claimReuse === null) return [];
        $resolved = is_array($context['subject_resolution']['resolved'] ?? null) ? $context['subject_resolution']['resolved'] : [];
        $variant = array_values(array_filter($resolved, static fn (mixed $item): bool => is_array($item) && ($item['type'] ?? '') === 'variant'))[0] ?? null;
        if (!is_array($variant)) return [];
        $reused = [];
        foreach ((array) ($context['interpretation']['user_claim_candidates'] ?? []) as $candidate) {
            if (!is_array($candidate)) continue;
            $claim = $this->claimReuse->find(['text' => (string) ($candidate['text'] ?? ''), 'subject_id' => (string) ($variant['id'] ?? ''), 'scope' => (string) ($candidate['scope'] ?? 'variant')], $this->retrievedClaims($context));
            if ($claim !== null) $reused[] = ['claim_id' => $claim['claim_id'] ?? '', 'claim_revision' => $claim['claim_revision'] ?? 1, 'subject_id' => $claim['subject_id'] ?? '', 'scope' => $claim['scope'] ?? ''];
        }
        return $reused;
    }

    /** @return list<array<string,mixed>> */
    private function retrievedClaims(array $context): array
    {
        return is_array($context['retrieval']['selected_claims'] ?? null) ? $context['retrieval']['selected_claims'] : [];
    }

    /** @return array<string,mixed> */
    private function arguments(string $entityType, string $operation, string $subjectId, array $payload, string $idempotencyKey): array
    {
        return ['operation' => $operation, 'entity_type' => $entityType, 'subject_id' => $subjectId, 'payload' => $payload, 'idempotency_key' => $idempotencyKey];
    }

    private function proposalFromReview(string $id, array $review): Proposal
    {
        return new Proposal($id, (string) ($review['subject_id'] ?? ''), (string) ($review['operation'] ?? ''), (array) ($review['payload'] ?? []), (string) ($review['content_fingerprint'] ?? ''), isset($review['expected_revision']) ? (int) $review['expected_revision'] : null, (string) ($review['dependency_fingerprint'] ?? ''), ProposalState::from((string) ($review['state'] ?? 'submitted')), revision: (int) ($review['revision'] ?? 1), targetUuid: isset($review['target_uuid']) ? (string) $review['target_uuid'] : null, entityType: (string) ($review['entity_type'] ?? ''));
    }

    private function pending(Proposal $proposal, array $review): array { return ['proposal_id' => $proposal->id, 'status' => 'REVIEW_REQUIRED', 'proposal_state' => (string) ($review['state'] ?? $proposal->state->value), 'content_fingerprint' => $proposal->contentFingerprint, 'dependency_fingerprint' => $proposal->dependencyFingerprint]; }

    private function applied(Proposal $proposal, array $applied): array
    {
        if (!is_array($applied['canonical_readback'] ?? null)) throw new \RuntimeException('CANONICAL_READBACK_VERIFICATION_FAILED');
        return ['proposal_id' => $proposal->id, 'status' => 'APPLIED', 'canonical_id' => $applied['canonical_id'] ?? null, 'canonical_readback' => $applied['canonical_readback'], 'idempotent' => (bool) ($applied['idempotent'] ?? false)];
    }

    private function actor(): string { return function_exists('get_current_user_id') ? (string) get_current_user_id() : 'capture-continuation'; }
}
