<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Application\Completion\CompletionCoordinator;
use NHK\Core\Application\Governance\GovernanceAutomationPolicyResolver;
use NHK\Core\Application\Semantic\ClaimReusePolicy;
use NHK\Core\Contracts\Governance\GovernedLifecycle;
use NHK\Core\Contracts\Governance\VideoProposalReconciliationPort;
use NHK\Core\Domain\Governance\{CommandCanonicalizer, Proposal, ProposalState};
use NHK\Core\Domain\Governance\ProposalSubjectBindingValidator;
use NHK\Core\Domain\Knowledge\KnowledgeFacetProfile;
use NHK\Core\Domain\Knowledge\DependencyValidationException;
use NHK\Core\Domain\Video\{VideoException, VideoRelationEvidenceRequired};
use NHK\Core\Application\Video\VideoRelationCandidatePlanner;
use NHK\Core\Governance\Exception\{GovernanceException, ProposalBindingConflict, ProposalIdempotencyConflict, ProposalIdempotencyStaleBinding, ProposalSubjectBindingInvalid};
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Runs the bounded semantic Governance lifecycle for an existing Capture.
 * Proposal abilities remain internal; the canonical Capture boundary owns
 * this continuation and only applies after its explicit gates pass.
 */
final class GovernedCaptureContinuationService
{
    private string $currentCaptureId = '';
    private CompletionCoordinator $completion;

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
        private ?CaptureOrchestrationBudget $budget = null,
        /** @var callable(string,string,array<string,mixed>):void|null */
        private $phaseReceipt = null,
        ?CompletionCoordinator $completion = null,
        private ?VideoRelationCandidatePlanner $videoRelations = null,
    ) {
        $this->completion = $completion ?? new CompletionCoordinator();
    }

    /** @return array<string,mixed> */
    public function execute(string $captureId, string $continuationKey, array $context, array $control = []): array
    {
        $this->currentCaptureId = $captureId;
        $this->budget?->begin();
        $proposalIds = array_values(array_filter(array_map('strval', (array) ($control['proposal_ids'] ?? [])), static fn (string $id): bool => UuidCodec::isValid($id)));
        $resumeChildren = array_values(array_unique(array_map('strtolower', array_map('strval', (array) ($control['resume_children'] ?? [])))));
        $reusedClaims = $this->reusedClaims($context);
        $videoOnlyResume = $proposalIds === []
            && ($context['existing_capture_continuation'] ?? false) === true
            && in_array('video', $resumeChildren, true);
        $plans = $proposalIds !== [] ? array_map(static fn (string $id): array => ['proposal_id' => $id], $proposalIds) : $this->plans($captureId, $continuationKey, $context, !$videoOnlyResume);
        $skippedVideoChildren = [];
        $videoChildren = [];
        if ($proposalIds === [] && ($context['existing_capture_continuation'] ?? false) === true) {
            $previousChildren = $this->previousVideoChildren($context);
            $plans = array_values(array_filter($plans, function (array $plan) use (&$skippedVideoChildren, &$videoChildren, $previousChildren, $context, $resumeChildren): bool {
                $isVideo = isset($plan['capture_video_provenance']) || (($plan['entity_type'] ?? '') === 'video');
                if (!$isVideo) return true;
                $fingerprint = $this->videoPlanFingerprint($plan, $context);
                $previous = $previousChildren[$fingerprint] ?? null;
                // Old Captures may predate the fingerprint receipt. Persisting
                // this fingerprint makes the next continuation state-driven:
                // unchanged work skips, changed dependency state resumes.
                $unchanged = $previousChildren === [] || ($previous !== null && ($previous['fingerprint'] ?? '') === $fingerprint);
                $previousStatus = (string) ($previous['status'] ?? 'FAILED_RETRYABLE');
                if (!$unchanged || !in_array($previousStatus, ['FAILED_RETRYABLE', 'SYSTEM_BLOCKED', 'APPLIED', 'REUSED_VERIFIED', 'SKIPPED_UNCHANGED'], true)) return true;
                if (in_array('video', $resumeChildren, true)) return true;
                $reason = in_array($previousStatus, ['APPLIED', 'REUSED_VERIFIED'], true) ? 'VIDEO_CHILD_ALREADY_VERIFIED' : 'VIDEO_CHILD_UNCHANGED_ON_TEXT_ADDENDUM';
                $skippedVideoChildren[] = ['status' => 'SKIPPED_UNCHANGED', 'reason' => $reason, 'fingerprint' => $fingerprint];
                $videoChildren[] = ['fingerprint' => $fingerprint, 'status' => $previousStatus === 'APPLIED' ? 'REUSED_VERIFIED' : 'SKIPPED_UNCHANGED', 'reason' => $reason];
                return false;
            }));
        }
        if ($plans === []) {
            $blockers = $skippedVideoChildren !== [] ? ['VIDEO_CHILD_UNCHANGED_ON_TEXT_ADDENDUM'] : ($reusedClaims === [] ? ['SEMANTIC_SUBJECT_OR_DELTA_REQUIRED'] : []);
            return ['status' => 'REVIEW_REQUIRED', 'writes' => $skippedVideoChildren, 'reused_claims' => $reusedClaims, 'video_children' => $videoChildren, 'blockers' => $blockers, 'governance' => ['lifecycle' => [], 'status' => 'REVIEW_REQUIRED', 'skipped_video_children' => count($skippedVideoChildren)], 'completion' => $this->completion->aggregateCapture($this->currentCaptureId, [], ['canonical_state' => 'COMPLETE', 'blockers' => $blockers])];
        }

        $writes = [];
        $lifecycle = [];
        foreach ($plans as $plan) {
            try {
                $this->budget?->check('VIDEO_CHILD_OR_GOVERNANCE');
            } catch (\Throwable $error) {
                $writes[] = $this->classifiedFailure($plan, $error);
                break;
            }
            if (isset($plan['capture_video_provenance']) && is_array($plan['capture_video_provenance'])) {
                $before = count($writes);
                $this->executeVideoProvenancePlan($plan['capture_video_provenance'], $control, $writes, $lifecycle);
                $childWrites = array_slice($writes, $before);
                $last = $childWrites === [] ? ['status' => 'FAILED_RETRYABLE', 'blockers' => ['VIDEO_CHILD_NO_RESULT']] : $childWrites[array_key_last($childWrites)];
                $videoChildren[] = ['fingerprint' => $this->videoPlanFingerprint($plan, $context), 'status' => (string) ($last['status'] ?? 'FAILED_RETRYABLE'), 'blockers' => (array) ($last['blockers'] ?? [])];
                continue;
            }
            if (($plan['entity_type'] ?? '') === 'video') {
                $writes[] = $this->runGovernedChild($plan, $control, $lifecycle, 'VIDEO_GOVERNANCE');
                $videoChildren[] = ['fingerprint' => $this->videoPlanFingerprint($plan, $context), 'status' => (string) ($writes[array_key_last($writes)]['status'] ?? 'FAILED_RETRYABLE'), 'blockers' => (array) ($writes[array_key_last($writes)]['blockers'] ?? [])];
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
        $result = ['status' => $status, 'writes' => array_merge($skippedVideoChildren, $writes), 'reused_claims' => $reusedClaims, 'video_children' => $videoChildren, 'blockers' => $failureBlockers, 'governance' => ['lifecycle' => array_values(array_unique($lifecycle)), 'status' => $status, 'applied_count' => count($applied), 'pending_count' => count($pending), 'retryable_count' => count($retryable), 'skipped_video_children' => count($skippedVideoChildren)]];
        $result['completion'] = $this->completion->aggregateCapture($this->currentCaptureId, $this->completionChildren($writes), [
            'canonical_state' => 'COMPLETE',
            'blockers' => $failureBlockers,
        ]);
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function plans(string $captureId, string $continuationKey, array $context, bool $includeSemanticChildren = true): array
    {
        $resolved = is_array($context['subject_resolution']['resolved'] ?? null) ? $context['subject_resolution']['resolved'] : [];
        // Explicit child resume is bound to the original Capture subject. A
        // text-only reparse may legitimately resolve nothing, so preserve the
        // canonical subject packet recorded before the failed child rather
        // than turning a valid resume into a semantic-delta error.
        if (!$includeSemanticChildren) {
            $priorSubjects = is_array($context['prior_diagnostics']['subjects'] ?? null) ? $context['prior_diagnostics']['subjects'] : [];
            $priorResolved = is_array($priorSubjects['resolved'] ?? null) ? $priorSubjects['resolved'] : (is_array($priorSubjects['subjects'] ?? null) ? $priorSubjects['subjects'] : []);
            if ($priorResolved !== []) $resolved = $priorResolved;
        }
        $variants = array_values(array_filter($resolved, static fn (mixed $item): bool => is_array($item) && ($item['type'] ?? '') === 'variant' && UuidCodec::isValid((string) ($item['id'] ?? ''))));
        $plans = [];
        if ($includeSemanticChildren && count($variants) === 1) {
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
                $plans[] = ['capture_video_provenance' => $this->videoProvenance->plan($captureId, $video, $source, $primary, ['user_hint' => $hint, 'preserve_original_subject' => !$includeSemanticChildren])];
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
                $this->budget?->check('VIDEO_PROVENANCE_DEPENDENCY');
            } catch (\Throwable $error) {
                $writes[] = $this->classifiedFailure($dependency, $error);
                return;
            }
            $kind = count($dependencyWrites) === 0 ? 'source' : 'claim';
            $phase = $kind === 'source' ? 'VIDEO_SOURCE_GOVERNANCE' : 'VIDEO_CLAIM_GOVERNANCE';
            $write = $this->reusedDependency($dependency, $provenancePlan, $kind, $phase)
                ?? $this->runGovernedChild($dependency, $control, $lifecycle, $phase);
            $dependencyWrites[] = $write;
            if (!$this->dependencyWriteCompleted($write)) {
                array_push($writes, ...$dependencyWrites);
                return;
            }
            $canonicalId = trim((string) ($write['canonical_id'] ?? ''));
            if (!$this->hasCanonicalReadback($write, $canonicalId)) {
                array_push($writes, ...array_merge($dependencyWrites, [['status' => 'SYSTEM_BLOCKED', 'blockers' => ['CANONICAL_READBACK_VERIFICATION_FAILED']]]));
                return;
            }
            if (!$this->dependencyReadback($provenancePlan, $kind, $canonicalId)) {
                array_push($writes, ...array_merge($dependencyWrites, [['status' => 'SYSTEM_BLOCKED', 'blockers' => ['CANONICAL_DEPENDENCY_READBACK_VERIFICATION_FAILED']]]));
                return;
            }
            $canonicalIds[] = $canonicalId;
        }
        if (count($canonicalIds) !== 2) {
            array_push($writes, ...array_merge($dependencyWrites, [['status' => 'SYSTEM_BLOCKED', 'blockers' => ['VIDEO_PROVENANCE_DEPENDENCY_READBACK_INCOMPLETE']]]));
            return;
        }
        $withEvidence = $this->videoProvenance?->attachEvidenceDependency($provenancePlan, $canonicalIds[0], $canonicalIds[1]);
        if (!is_array($withEvidence)) {
            array_push($writes, ...array_merge($dependencyWrites, [['status' => 'SYSTEM_BLOCKED', 'blockers' => ['VIDEO_PROVENANCE_PLANNER_UNAVAILABLE']]]));
            return;
        }
        $evidenceArguments = (array) ($withEvidence['dependencies'][2] ?? []);
        try {
            $this->budget?->check('VIDEO_PROVENANCE_EVIDENCE');
        } catch (\Throwable $error) {
            $writes[] = $this->classifiedFailure($evidenceArguments, $error);
            return;
        }
        $evidenceWrite = $this->reusedDependency($evidenceArguments, $withEvidence, 'evidence', 'VIDEO_EVIDENCE_GOVERNANCE')
            ?? $this->runGovernedChild($evidenceArguments, $control, $lifecycle, 'VIDEO_EVIDENCE_GOVERNANCE');
        $allWrites = array_merge($dependencyWrites, [$evidenceWrite]);
        if (!$this->dependencyWriteCompleted($evidenceWrite)) {
            array_push($writes, ...$allWrites);
            return;
        }
        $evidenceId = trim((string) ($evidenceWrite['canonical_id'] ?? ''));
        if (!$this->hasCanonicalReadback($evidenceWrite, $evidenceId)) {
            array_push($writes, ...array_merge($allWrites, [['status' => 'SYSTEM_BLOCKED', 'blockers' => ['CANONICAL_READBACK_VERIFICATION_FAILED']]]));
            return;
        }
        if (!$this->dependencyReadback($withEvidence, 'evidence', $evidenceId)) {
            array_push($writes, ...array_merge($allWrites, [['status' => 'SYSTEM_BLOCKED', 'blockers' => ['CANONICAL_DEPENDENCY_READBACK_VERIFICATION_FAILED']]]));
            return;
        }
        $complete = $this->videoProvenance->attachEvidence($provenancePlan, $canonicalIds[0], $canonicalIds[1], $evidenceId);
        if ($this->videoRelations !== null) {
            try {
                $videoProposal = (array) ($complete['video_proposal'] ?? []);
                $videoPayload = is_array($videoProposal['payload'] ?? null) ? $videoProposal['payload'] : [];
                $videoId = trim((string) ($videoPayload['canonical_id'] ?? $videoProposal['subject_id'] ?? ''));
                $relation = is_array($complete['relation'] ?? null) ? $complete['relation'] : [];
                $candidates = $this->videoRelations->plan($videoId, [[
                    'target_id' => (string) ($relation['target_uuid'] ?? ''),
                    'target_type' => (string) ($relation['target_type'] ?? ''),
                    'predicate' => (string) ($relation['predicate'] ?? 'about'),
                    'origin' => (string) ($relation['origin'] ?? 'EXPLICIT_USER_RELATION'),
                    'evidence_refs' => [['evidence_id' => $evidenceId]],
                    'reason' => (string) ($relation['reason'] ?? ''),
                    'confidence' => (float) ($relation['confidence'] ?? 1.0),
                ]]);
                if ($candidates === []) throw new VideoRelationEvidenceRequired();
                $videoPayload['metadata'] = is_array($videoPayload['metadata'] ?? null) ? $videoPayload['metadata'] : [];
                $videoPayload['metadata']['semantic_attachments'] = array_map(static fn ($candidate): array => $candidate->toProposalPayload(), $candidates);
                $videoProposal['payload'] = $videoPayload;
                $complete['video_proposal'] = $videoProposal;
            } catch (\Throwable $error) {
                $writes[] = $this->classifiedFailure((array) ($complete['video_proposal'] ?? []), $error);
                return;
            }
        }
        try {
            $this->budget?->check('VIDEO_PROPOSAL_GOVERNANCE');
        } catch (\Throwable $error) {
            $writes[] = $this->classifiedFailure((array) ($complete['video_proposal'] ?? []), $error);
            return;
        }
        $videoWrite = $this->runGovernedChild((array) ($complete['video_proposal'] ?? []), $control, $lifecycle, 'VIDEO_GOVERNANCE');
        $videoWrite['evidence_handoff'] = [
            'source_id' => $canonicalIds[0],
            'claim_id' => $canonicalIds[1],
            'evidence_id' => $evidenceId,
            'relation_evidence_refs' => [['evidence_id' => $evidenceId]],
        ];
        array_push($writes, ...array_merge($allWrites, [$videoWrite]));
    }

    /** @return array<string,mixed> */
    private function runGovernedChild(array $plan, array $control, array &$lifecycle, string $phase): array
    {
        $started = microtime(true);
        $this->emitPhaseReceipt($phase, ['status' => 'STARTED', 'result' => 'IN_PROGRESS', 'started_at' => gmdate('c')]);
        try {
            $write = $this->runGovernedPlan($plan, $control, $lifecycle);
            $this->emitPhaseReceipt($phase, ['status' => 'COMPLETED', 'result' => (string) ($write['status'] ?? 'UNKNOWN'), 'started_at' => gmdate('c', (int) $started), 'completed_at' => gmdate('c'), 'elapsed_ms' => max(0, (int) ((microtime(true) - $started) * 1000))]);
            return $write;
        } catch (\Throwable $error) {
            $write = $this->classifiedFailure($plan, $error);
            $this->emitPhaseReceipt($phase, ['status' => 'FAILED', 'result' => (string) ($write['status'] ?? 'FAILED_RETRYABLE'), 'failure_code' => (string) (($write['blockers'][0] ?? 'CAPTURE_GOVERNANCE_FAILED')), 'started_at' => gmdate('c', (int) $started), 'completed_at' => gmdate('c'), 'elapsed_ms' => max(0, (int) ((microtime(true) - $started) * 1000))]);
            return $write;
        }
    }

    /** @param array<string,mixed> $receipt */
    private function emitPhaseReceipt(string $phase, array $receipt): void
    {
        if ($this->phaseReceipt === null) return;
        try { ($this->phaseReceipt)($this->currentCaptureId, $phase, $receipt); } catch (\Throwable) { /* diagnostics must not change semantic outcome */ }
    }

    /** @param array<string,mixed> $write */
    private function hasCanonicalReadback(array $write, string $canonicalId): bool
    {
        if (!UuidCodec::isValid($canonicalId)) return false;
        $readback = is_array($write['canonical_readback'] ?? null) ? $write['canonical_readback'] : [];
        return (string) ($readback['canonical_id'] ?? '') === $canonicalId && ($readback['active'] ?? false) === true;
    }

    private function dependencyWriteCompleted(array $write): bool
    {
        return in_array((string) ($write['status'] ?? ''), ['APPLIED', 'REUSED_VERIFIED'], true);
    }

    /**
     * Search the canonical owner through the injected read boundary before
     * opening another child proposal. This is deliberately limited to the
     * source-specific provenance chain; it is not a generic semantic writer.
     *
     * @return array<string,mixed>|null
     */
    private function reusedDependency(array $dependency, array $plan, string $kind, string $phase): ?array
    {
        if ($this->videoDependencyState === null || !in_array($kind, ['source', 'claim', 'evidence'], true)) return null;
        try {
            $state = ($this->videoDependencyState)($plan);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($state)) return null;
        $record = $state[$kind] ?? null;
        if ($kind === 'evidence') {
            $sourceId = trim((string) (($dependency['payload']['source_id'] ?? '')));
            $claimId = trim((string) (($dependency['payload']['claim_id'] ?? '')));
            $record = $this->matchingEvidence($state['evidence'] ?? [], $sourceId, $claimId, (array) ($dependency['payload'] ?? []));
        }
        if (!$this->canonicalDependencyMatches($record, (array) ($dependency['payload'] ?? []), $kind)) return null;
        [$canonicalId, $revision, $active] = $this->canonicalStateTuple($record);
        if (!UuidCodec::isValid($canonicalId) || !$active) return null;
        $this->emitPhaseReceipt($phase, ['status' => 'COMPLETED', 'result' => 'REUSED_VERIFIED', 'canonical_id' => $canonicalId, 'revision' => $revision, 'idempotent' => true]);
        return [
            'canonical_id' => $canonicalId,
            'status' => 'REUSED_VERIFIED',
            'idempotent' => true,
            'reused' => true,
            'canonical_readback' => ['canonical_id' => $canonicalId, 'entity_type' => $kind === 'claim' ? 'knowledge' : $kind, 'active' => true, 'revision' => $revision],
        ];
    }

    private function dependencyReadback(array $plan, string $kind, string $expectedId): bool
    {
        if ($this->videoDependencyState === null) return true;
        try {
            $state = ($this->videoDependencyState)($plan);
        } catch (\Throwable) {
            return false;
        }
        if (!is_array($state)) return false;
        if ($kind === 'evidence') {
            $evidence = is_array($state['evidence'] ?? null) ? $state['evidence'] : [];
            foreach ($evidence as $record) {
                [$canonicalId, , $active] = $this->canonicalStateTuple($record);
                if ($canonicalId === $expectedId && $active) return true;
            }
            return false;
        }
        [$canonicalId, , $active] = $this->canonicalStateTuple($state[$kind] ?? null);
        return $canonicalId === $expectedId && $active;
    }

    /** @return array{0:string,1:int,2:bool} */
    private function canonicalStateTuple(mixed $record): array
    {
        if (!is_array($record)) return ['', 0, false];
        if (array_is_list($record)) return [(string) ($record[0] ?? ''), (int) ($record[1] ?? 0), (bool) ($record[2] ?? false)];
        return [
            (string) ($record['canonical_id'] ?? $record['id'] ?? $record['uuid'] ?? ''),
            (int) ($record['revision'] ?? 0),
            ($record['active'] ?? false) === true,
        ];
    }

    /** @return array<string,mixed>|list<mixed>|null */
    private function matchingEvidence(mixed $records, string $sourceId, string $claimId, array $payload): array|null
    {
        foreach (is_array($records) ? $records : [] as $record) {
            if (!is_array($record)) continue;
            $recordSource = array_is_list($record) ? (string) ($record[2] ?? '') : (string) ($record['source_id'] ?? '');
            $recordClaim = array_is_list($record) ? $claimId : (string) ($record['claim_id'] ?? $claimId);
            if ($recordSource === $sourceId && $recordClaim === $claimId && $this->canonicalDependencyMatches($record, $payload, 'evidence')) return $record;
        }
        return null;
    }

    private function canonicalDependencyMatches(mixed $record, array $payload, string $kind): bool
    {
        // Legacy tuple read-backs contain only identity/revision/state. They
        // remain usable as a compatibility read shape; the production Plugin
        // supplies the richer canonical fields below for strict reuse.
        if (!is_array($record) || array_is_list($record)) return true;
        $fields = match ($kind) {
            'source' => ['title' => 'title', 'source_type' => 'source_type', 'locator' => 'locator', 'metadata' => 'metadata'],
            'claim' => ['claim_text' => 'text', 'claim_type' => 'claim_type', 'provenance' => 'provenance'],
            'evidence' => ['relation' => 'relation', 'excerpt' => 'excerpt', 'locator' => 'locator', 'metadata' => 'metadata'],
            default => [],
        };
        foreach ($fields as $canonicalField => $payloadField) {
            if (array_key_exists($canonicalField, $record) && $record[$canonicalField] !== ($payload[$payloadField] ?? null)) return false;
        }
        return true;
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
        // Domain exception classes are authoritative. Message/code matching
        // below exists only for legacy adapters that still throw strings.
        if ($error instanceof ProposalSubjectBindingInvalid || $error instanceof ProposalBindingConflict || $error instanceof ProposalIdempotencyConflict || $error instanceof ProposalIdempotencyStaleBinding || $error instanceof GovernanceException) {
            $reason = match (true) {
                $error instanceof ProposalSubjectBindingInvalid => 'PROPOSAL_SUBJECT_BINDING_INVALID',
                $error instanceof ProposalIdempotencyConflict => 'PROPOSAL_IDEMPOTENCY_CONFLICT',
                $error instanceof ProposalIdempotencyStaleBinding => 'IDEMPOTENCY_STALE_BINDING',
                $error instanceof ProposalBindingConflict => 'PROPOSAL_BINDING_CONFLICT',
                default => 'PROPOSAL_GOVERNANCE_CONTRACT_INVALID',
            };
            return ['proposal_id' => (string) ($plan['proposal_id'] ?? ''), 'status' => 'SYSTEM_BLOCKED', 'blockers' => [$reason]];
        }
        if ($error instanceof VideoRelationEvidenceRequired) {
            return ['proposal_id' => (string) ($plan['proposal_id'] ?? ''), 'status' => 'REVIEW_REQUIRED', 'blockers' => [VideoRelationEvidenceRequired::ERROR_CODE]];
        }
        if ($error instanceof CaptureOrchestrationBudgetExceeded || $error instanceof VideoException) {
            return ['proposal_id' => (string) ($plan['proposal_id'] ?? ''), 'status' => 'FAILED_RETRYABLE', 'blockers' => [$error instanceof CaptureOrchestrationBudgetExceeded ? 'CAPTURE_ORCHESTRATION_BUDGET_EXCEEDED' : 'VIDEO_EXTERNAL_TRANSIENT_FAILURE']];
        }
        if ($error instanceof DependencyValidationException) {
            return ['proposal_id' => (string) ($plan['proposal_id'] ?? ''), 'status' => 'REVIEW_REQUIRED', 'blockers' => [$error->errorCode]];
        }
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
        $provenance = is_array($plan['capture_video_provenance'] ?? null) ? $plan['capture_video_provenance'] : $plan;
        $video = is_array($provenance['video_proposal'] ?? null) ? $provenance['video_proposal'] : $plan;
        $payload = is_array($video['payload'] ?? null) ? $video['payload'] : (is_array($provenance['payload'] ?? null) ? $provenance['payload'] : []);
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $source = is_array($metadata['source'] ?? null) ? $metadata['source'] : (is_array($metadata['source_snapshot'] ?? null) ? $metadata['source_snapshot'] : []);
        if ($source === [] && is_array(($provenance['dependencies'][0]['payload'] ?? null))) $source = (array) $provenance['dependencies'][0]['payload'];
        $relation = is_array($provenance['relation'] ?? null) ? $provenance['relation'] : [];
        $stableSource = array_intersect_key($source, array_flip(['platform', 'external_video_id', 'canonical_source_url', 'source_snapshot_hash', 'source_revision', 'source_id']));
        $stableState = is_array($state) ? $state : [];
        $packet = [
            'external' => [strtolower((string) ($source['platform'] ?? '')), (string) ($source['external_video_id'] ?? '')],
            'source_snapshot' => $stableSource,
            'subject' => $this->stableRecord($stableState['subject'] ?? ($metadata['subject_resolution_packet'] ?? ($relation === [] ? null : ['id' => $relation['target_uuid'] ?? null, 'type' => $relation['target_type'] ?? null]))),
            'source_dependency' => $this->stableRecord($stableState['source'] ?? null),
            'claim_dependency' => $this->stableRecord($stableState['claim'] ?? null),
            'evidence_dependency' => $this->stableEvidence($stableState['evidence'] ?? ($metadata['evidence_refs'] ?? [])),
            'proposal' => $this->stableRecord($stableState['proposal'] ?? null),
            'about' => $this->stableAbout($metadata['semantic_attachments'] ?? ($payload['semantic_attachments'] ?? (isset($relation['target_uuid']) ? [['predicate' => 'about', 'target_type' => $relation['target_type'] ?? '', 'target_uuid' => $relation['target_uuid'] ?? '']] : []))),
        ];
        return hash('sha256', CommandCanonicalizer::canonicalize($packet));
    }

    /** @return list<mixed> */
    private function stableEvidence(mixed $evidence): array
    {
        $rows = is_array($evidence) ? array_values(array_map(fn (mixed $row): mixed => $this->stableRecord($row), $evidence)) : [];
        usort($rows, static fn (mixed $a, mixed $b): int => strcmp(CommandCanonicalizer::canonicalize($a), CommandCanonicalizer::canonicalize($b)));
        return $rows;
    }

    private function stableRecord(mixed $record): mixed
    {
        if (!is_array($record) || array_is_list($record)) return $record;
        return array_intersect_key($record, array_flip(['id', 'canonical_id', 'uuid', 'revision', 'state', 'active', 'source_id', 'type', 'subject_id']));
    }

    /** @return list<array<string,mixed>> */
    private function stableAbout(mixed $attachments): array
    {
        $rows = [];
        foreach (is_array($attachments) ? $attachments : [] as $attachment) {
            if (!is_array($attachment) || strtolower((string) ($attachment['predicate'] ?? '')) !== 'about') continue;
            $row = array_intersect_key($attachment, array_flip(['predicate', 'target_type', 'target_uuid', 'evidence_refs', 'evidence_ids']));
            if (is_array($row['evidence_refs'] ?? null)) {
                usort($row['evidence_refs'], static fn (mixed $a, mixed $b): int => strcmp(CommandCanonicalizer::canonicalize($a), CommandCanonicalizer::canonicalize($b)));
            }
            if (is_array($row['evidence_ids'] ?? null)) sort($row['evidence_ids'], SORT_STRING);
            $rows[] = $row;
        }
        usort($rows, static fn (array $a, array $b): int => strcmp(CommandCanonicalizer::canonicalize($a), CommandCanonicalizer::canonicalize($b)));
        return $rows;
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
        $canonicalId = (string) ($applied['canonical_id'] ?? $applied['result_entity_uuid'] ?? ($applied['canonical_readback']['canonical_id'] ?? ''));
        return ['proposal_id' => $proposal->id, 'status' => 'APPLIED', 'canonical_id' => $canonicalId !== '' ? $canonicalId : null, 'canonical_readback' => $applied['canonical_readback'], 'idempotent' => (bool) ($applied['idempotent'] ?? false), 'completion' => $this->completion->finalize($proposal->entityType, $canonicalId, ['proposal_state' => 'applied', 'canonical_readback' => $applied['canonical_readback']])];
    }

    /** @param list<array<string,mixed>> $writes @return list<array<string,mixed>> */
    private function completionChildren(array $writes): array
    {
        $children = [];
        foreach ($writes as $write) {
            if (!is_array($write)) continue;
            if (is_array($write['completion'] ?? null)) { $children[] = ['completion' => $write['completion']]; continue; }
            $ownerId = trim((string) ($write['canonical_id'] ?? $write['result_entity_uuid'] ?? ''));
            if ($ownerId === '') continue;
            $children[] = ['owner_type' => trim((string) ($write['entity_type'] ?? 'knowledge')) ?: 'knowledge', 'owner_id' => $ownerId, 'canonical_readback' => $write['canonical_readback'] ?? null, 'dependency_state' => $this->dependencyWriteCompleted($write) ? 'COMPLETE' : 'PARTIAL', 'blockers' => (array) ($write['blockers'] ?? [])];
        }
        return $children;
    }

    private function actor(): string { return function_exists('get_current_user_id') ? (string) get_current_user_id() : 'capture-continuation'; }
}
