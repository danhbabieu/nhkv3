<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Application\Completion\CompletionCoordinator;
use NHK\Core\Application\Governance\GovernanceAutomationPolicyResolver;
use NHK\Core\Application\Semantic\ClaimReusePolicy;
use NHK\Core\Application\Knowledge\CanonicalDependencyValidator;
use NHK\Core\Application\Knowledge\KnowledgeRepairPreviewService;
use NHK\Core\Contracts\Governance\GovernedLifecycle;
use NHK\Core\Contracts\Governance\PendingVideoProposalLookup;
use NHK\Core\Contracts\Governance\VideoProposalReconciliationPort;
use NHK\Core\Contracts\Knowledge\KnowledgeRepository;
use NHK\Core\Domain\Governance\{CommandCanonicalizer, Proposal, ProposalState};
use NHK\Core\Domain\Governance\ProposalSubjectBindingValidator;
use NHK\Core\Domain\Knowledge\KnowledgeFacetProfile;
use NHK\Core\Domain\Knowledge\DependencyValidationException;
use NHK\Core\Domain\Video\{VideoException, VideoRelationEvidenceRequired};
use NHK\Core\Application\Video\{VideoCompletenessReconciliationService, VideoRelationCandidatePlanner};
use NHK\Core\Application\Video\VideoEditorialResumePlanner;
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
        private ?VideoEditorialResumePlanner $videoEditorialResume = null,
        private ?VideoCompletenessReconciliationService $videoCompleteness = null,
        /** @var callable(Proposal,array<string,mixed>,array<string,mixed>):array<string,mixed>|null */
        private $proposalReconciliation = null,
        /** @var callable(array<string,mixed>):array<string,mixed>|bool|null */
        private $relationState = null,
        /** @var callable(string,array<string,mixed>):array<string,mixed>|null */
        private $videoScopeIssuer = null,
        private ?PendingVideoProposalLookup $pendingVideoProposals = null,
        /** @var callable(string,array<string,mixed>):array<string,mixed>|null */
        private $dependencyScopeIssuer = null,
        /** @var callable(string,array<string,mixed>):array<string,mixed>|null */
        private $relationScopeIssuer = null,
        private ?CanonicalDependencyValidator $canonicalDependencies = null,
        private ?KnowledgeRepository $knowledgeRepository = null,
        private ?KnowledgeRepairPreviewService $knowledgeRepairPreview = null,
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
        try {
            $plans = $proposalIds !== [] ? array_map(static fn (string $id): array => ['proposal_id' => $id], $proposalIds) : $this->plans($captureId, $continuationKey, $context, !$videoOnlyResume);
        } catch (\Throwable $error) {
            $failure = $this->classifiedFailure([], $error);
            $status = (string) ($failure['status'] ?? 'SYSTEM_BLOCKED');
            return ['status' => $status, 'writes' => [$failure], 'reused_claims' => $reusedClaims, 'video_children' => [], 'blockers' => (array) ($failure['blockers'] ?? ['CAPTURE_PLAN_FAILED']), 'governance' => $this->governanceReadback([$failure], [], $status, []), 'completion' => $this->completion->aggregateCapture($this->currentCaptureId, [], ['canonical_state' => 'COMPLETE', 'blockers' => (array) ($failure['blockers'] ?? [])])];
        }
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
            $semanticNotRequired = !$this->semanticDeltaRequested($context) && in_array(strtoupper(trim((string) ($context['content_intent']['intent'] ?? ''))), ['IMAGE_ARTICLE', 'TEXT_ARTICLE'], true);
            $blockers = $skippedVideoChildren !== [] ? ['VIDEO_CHILD_UNCHANGED_ON_TEXT_ADDENDUM'] : ($semanticNotRequired || $reusedClaims !== [] ? [] : ['SEMANTIC_SUBJECT_OR_DELTA_REQUIRED']);
            $status = $semanticNotRequired ? 'SKIPPED' : 'REVIEW_REQUIRED';
            return ['status' => $status, 'writes' => $skippedVideoChildren, 'reused_claims' => $reusedClaims, 'video_children' => $videoChildren, 'blockers' => $blockers, 'requirements' => ['semantic_delta' => ['applicability' => $semanticNotRequired ? 'NOT_REQUIRED' : 'REQUIRED', 'policy' => 'VERIFY', 'state' => $semanticNotRequired ? 'SKIPPED' : 'PENDING', 'evidence' => ['intent' => strtoupper(trim((string) ($context['content_intent']['intent'] ?? ''))), 'status' => strtoupper(trim((string) ($context['content_intent']['semantic_delta']['status'] ?? 'NONE')))]]], 'governance' => $this->governanceReadback([], [], $status, $skippedVideoChildren), 'completion' => $this->completion->aggregateCapture($this->currentCaptureId, [], ['canonical_state' => 'COMPLETE', 'blockers' => $blockers])];
        }

        $writes = [];
        $lifecycle = [];
        $canonicalByCandidate = [];
        foreach ($plans as $plan) {
            if (($plan['entity_type'] ?? '') === 'relation' && isset($plan['payload']['source_candidate_id'])) {
                $sourceCandidateId = (string) $plan['payload']['source_candidate_id'];
                $source = $canonicalByCandidate[$sourceCandidateId] ?? null;
                if (!is_array($source) || !$this->dependencyWriteCompleted($source)) {
                    $writes[] = ['entity_type' => 'relation', 'status' => 'REVIEW_REQUIRED', 'blockers' => ['KNOWLEDGE_RELATION_SOURCE_UNAVAILABLE'], 'candidate_id' => $sourceCandidateId];
                    continue;
                }
                $plan['payload']['source_uuid'] = (string) ($source['canonical_id'] ?? '');
                $plan['payload']['source_revision'] = (int) ($source['canonical_readback']['revision'] ?? 0);
                unset($plan['payload']['source_candidate_id']);
            }
            if (($plan['entity_type'] ?? '') === 'relation') {
                try {
                    $plan = $this->scopeRelationPlan($this->currentCaptureId, $plan);
                } catch (\Throwable $error) {
                    $writes[] = $this->classifiedFailure($plan, $error);
                    continue;
                }
                $payload = is_array($plan['payload'] ?? null) ? $plan['payload'] : [];
                if (trim((string) ($payload['predicate'] ?? '')) === '' || trim((string) ($payload['provenance']['origin'] ?? $payload['origin'] ?? '')) === '') {
                    $writes[] = ['entity_type' => 'relation', 'status' => 'REVIEW_REQUIRED', 'blockers' => ['RELATION_PROVENANCE_REQUIRED']];
                    continue;
                }
                if (($payload['require_evidence'] ?? false) === true && (array) ($payload['evidence_refs'] ?? []) === []) {
                    $writes[] = ['entity_type' => 'relation', 'status' => 'REVIEW_REQUIRED', 'blockers' => ['RELATION_EVIDENCE_REQUIRED']];
                    continue;
                }
                $reusedRelation = $this->reusedRelation($plan);
                if ($reusedRelation !== null) { $writes[] = $reusedRelation; continue; }
            }
            if (is_array($plan['video_editorial_reuse'] ?? null)) {
                $reuse = $plan['video_editorial_reuse'];
                if ($this->videoCompleteness !== null) {
                    try {
                        $reconciled = $this->videoCompleteness->reconcile((string) ($reuse['subject_id'] ?? ''));
                        $reuse['canonical_readback'] = [
                            'canonical_id' => $reconciled->canonicalId,
                            'entity_type' => 'video',
                            'platform' => $reconciled->platform,
                            'external_video_id' => $reconciled->externalVideoId,
                            'active' => $reconciled->active,
                            'revision' => $reconciled->revision,
                            'completeness' => $reconciled->metadata['completeness'] ?? [],
                        ];
                        $reuse['completeness_reconciled'] = true;
                    } catch (\Throwable $error) {
                        $writes[] = [
                            'entity_type' => 'video',
                            'status' => 'FAILED_RETRYABLE',
                            'canonical_id' => (string) ($reuse['subject_id'] ?? ''),
                            'blockers' => ['VIDEO_COMPLETENESS_RECONCILIATION_FAILED'],
                            'error' => $error->getMessage(),
                        ];
                        $videoChildren[] = ['fingerprint' => (string) ($reuse['fingerprint'] ?? ''), 'status' => 'FAILED_RETRYABLE', 'blockers' => ['VIDEO_COMPLETENESS_RECONCILIATION_FAILED']];
                        continue;
                    }
                }
                $writes[] = [
                    'entity_type' => 'video',
                    'status' => 'REUSED_VERIFIED',
                    'canonical_id' => (string) ($reuse['subject_id'] ?? ''),
                    'canonical_readback' => $reuse['canonical_readback'] ?? null,
                    'fingerprint' => (string) ($reuse['fingerprint'] ?? ''),
                    'idempotent' => true,
                    'reused' => true,
                ];
                $videoChildren[] = ['fingerprint' => (string) ($reuse['fingerprint'] ?? ''), 'status' => 'REUSED_VERIFIED', 'reason' => 'REUSE_EDITORIAL'];
                continue;
            }
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
                $videoWrite = $writes[array_key_last($writes)] ?? [];
                $videoChildren[] = $this->videoChildReceipt($plan, $context, is_array($videoWrite) ? $videoWrite : []);
                continue;
            }
            try {
                $write = $this->runGovernedPlan($plan, $control, $lifecycle);
            } catch (\Throwable $error) {
                $write = $this->classifiedFailure($plan, $error);
            }
            $writes[] = $write;
            if (isset($plan['candidate_id']) && $this->dependencyWriteCompleted($write)) $canonicalByCandidate[(string) $plan['candidate_id']] = $write;
            if (($plan['entity_type'] ?? '') === 'video') $videoChildren[] = $this->videoChildReceipt($plan, $context, $write);
        }

        $pending = array_values(array_filter($writes, static fn (array $write): bool => ($write['status'] ?? '') === 'REVIEW_REQUIRED'));
        $blocked = array_values(array_filter($writes, static fn (array $write): bool => ($write['status'] ?? '') === 'SYSTEM_BLOCKED'));
        $retryable = array_values(array_filter($writes, static fn (array $write): bool => ($write['status'] ?? '') === 'FAILED_RETRYABLE'));
        $applied = array_values(array_filter($writes, static fn (array $write): bool => ($write['status'] ?? '') === 'APPLIED'));
        $status = $blocked !== [] ? 'SYSTEM_BLOCKED' : ($retryable !== [] ? 'FAILED_RETRYABLE' : ($pending !== [] ? 'REVIEW_REQUIRED' : 'APPLIED'));
        $failureWrites = $blocked !== [] ? $blocked : $retryable;
        $failureBlockers = $failureWrites !== [] ? array_values(array_unique(array_merge(...array_map(static fn (array $write): array => (array) ($write['blockers'] ?? []), $failureWrites)))) : ($pending !== [] ? ['GOVERNANCE_APPROVAL_REQUIRED'] : ($skippedVideoChildren !== [] ? ['VIDEO_CHILD_UNCHANGED_ON_TEXT_ADDENDUM'] : []));
        $result = ['status' => $status, 'writes' => array_merge($skippedVideoChildren, $writes), 'reused_claims' => $reusedClaims, 'video_children' => $videoChildren, 'blockers' => $failureBlockers, 'governance' => $this->governanceReadback($writes, $lifecycle, $status, $skippedVideoChildren)];
        if ($pending !== []) {
            $identity = $pending[0];
            $result['proposal_id'] = $identity['proposal_id'] ?? null;
            $result['proposal_state'] = $identity['proposal_state'] ?? null;
            $result['target_uuid'] = $identity['target_uuid'] ?? null;
            $result['canonical_id'] = null;
        }
        $result['completion'] = $this->completion->aggregateCapture($this->currentCaptureId, $this->completionChildren($writes), [
            'canonical_state' => 'COMPLETE',
            'blockers' => $failureBlockers,
        ]);
        return $result;
    }

    /** @param list<array<string,mixed>> $writes @param list<string> $lifecycle @param list<array<string,mixed>> $skipped @return array<string,mixed> */
    private function governanceReadback(array $writes, array $lifecycle, string $status, array $skipped): array
    {
        $proposals = [];
        foreach ($writes as $write) {
            if (!is_array($write)) continue;
            $proposalId = trim((string) ($write['proposal_id'] ?? ''));
            // Proposal identity is accepted only from a Governance lifecycle
            // receipt. Never derive it from a Video or Capture UUID.
            if (!UuidCodec::isValid($proposalId)) continue;
            $proposals[$proposalId] = [
                'id' => $proposalId,
                'entity_type' => (string) ($write['entity_type'] ?? ''),
                'operation' => (string) ($write['operation'] ?? ''),
                'status' => (string) ($write['status'] ?? ''),
                'proposal_state' => (string) ($write['proposal_state'] ?? ''),
                'content_fingerprint' => (string) ($write['content_fingerprint'] ?? ''),
                'dependency_fingerprint' => (string) ($write['dependency_fingerprint'] ?? ''),
            ];
        }
        $proposals = array_values($proposals);
        $pending = array_values(array_filter($proposals, static fn (array $proposal): bool => $proposal['status'] === 'REVIEW_REQUIRED'));
        return [
            'lifecycle' => array_values(array_unique($lifecycle)),
            'status' => $status,
            'approval_required' => $pending !== [],
            'proposal_ids' => array_values(array_map(static fn (array $proposal): string => $proposal['id'], $proposals)),
            'proposals' => $proposals,
            'applied_count' => count(array_filter($proposals, static fn (array $proposal): bool => $proposal['status'] === 'APPLIED')),
            'pending_count' => count($pending),
            'retryable_count' => count(array_filter($proposals, static fn (array $proposal): bool => $proposal['status'] === 'FAILED_RETRYABLE')),
            'skipped_video_children' => count($skipped),
        ];
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
        $intent = strtoupper(trim((string) ($context['content_intent']['intent'] ?? '')));
        if ($intent === 'KNOWLEDGE_REPAIR') return [$this->knowledgeRepairPlan($captureId, $context)];
        $subjects = array_values(array_filter($resolved, static fn (mixed $item): bool => is_array($item) && UuidCodec::isValid((string) ($item['id'] ?? '')) && trim((string) ($item['type'] ?? '')) !== '' && ($item['active'] ?? true) === true));
        $variants = array_values(array_filter($subjects, static fn (array $item): bool => ($item['type'] ?? '') === 'variant'));
        $plans = [];
        $articleId = (int) ($context['article_id'] ?? 0);
        $articleEndpoint = trim((string) ($context['article_endpoint_key'] ?? ''));
        $primary = is_array($context['subject_resolution']['primary'] ?? null) ? $context['subject_resolution']['primary'] : ($subjects[0] ?? []);
        // Provenance packets are an explicit Capture continuation boundary.
        // They are intentionally separate from the claim text: Source and
        // Evidence remain governed canonical records, and an evidence packet
        // must name already-resolved claim/source UUIDs. A source packet can
        // be submitted first; its canonical read-back is then used by a later
        // idempotent continuation for evidence packets.
        foreach ((array) ($context['provenance_packets']['sources'] ?? []) as $source) {
            if (!is_array($source)) continue;
            $stableKey = trim((string) ($source['stable_key'] ?? ''));
            $title = trim((string) ($source['title'] ?? ''));
            if ($stableKey === '' || $title === '') continue;
            $payload = [
                'stable_key' => $stableKey,
                'title' => $title,
                'source_type' => trim((string) ($source['source_type'] ?? 'website')) ?: 'website',
                'locator' => isset($source['locator']) ? (string) $source['locator'] : null,
                'metadata' => is_array($source['metadata'] ?? null) ? $source['metadata'] : [],
            ];
            $plans[] = $this->scopeDependencyPlan($captureId, $this->arguments('source', 'ingest', $stableKey, $payload, 'capture:' . $captureId . ':source:' . hash('sha256', CommandCanonicalizer::canonicalize($payload))));
        }
        foreach ((array) ($context['provenance_packets']['evidence'] ?? []) as $evidence) {
            if (!is_array($evidence)) continue;
            $claimId = trim((string) ($evidence['claim_id'] ?? ''));
            $sourceId = trim((string) ($evidence['source_id'] ?? ''));
            $excerpt = trim((string) ($evidence['excerpt'] ?? ''));
            if (!UuidCodec::isValid($claimId) || !UuidCodec::isValid($sourceId) || $excerpt === '') continue;
            $payload = [
                'claim_id' => $claimId,
                'source_id' => $sourceId,
                'excerpt' => $excerpt,
                'relation' => trim((string) ($evidence['relation'] ?? 'supports')) ?: 'supports',
                'locator' => isset($evidence['locator']) ? (string) $evidence['locator'] : null,
                'metadata' => is_array($evidence['metadata'] ?? null) ? $evidence['metadata'] : [],
            ];
            $plans[] = $this->scopeDependencyPlan($captureId, $this->arguments('evidence', 'ingest', $claimId, $payload, 'capture:' . $captureId . ':evidence:' . hash('sha256', CommandCanonicalizer::canonicalize($payload))));
        }
        if ($includeSemanticChildren && $articleId > 0 && $articleEndpoint !== '' && in_array($intent, ['TEXT_ARTICLE', 'IMAGE_ARTICLE'], true) && UuidCodec::isValid((string) ($primary['id'] ?? '')) && trim((string) ($primary['type'] ?? '')) !== '') {
            // Article subject binding is a normal governed Graph child. The
            // stable idempotency key is owner/subject based so a later
            // continuation reuses the same edge/proposal instead of opening a
            // duplicate relation.
            $relationPlan = $this->arguments('relation', 'relation_create', 'relation', [
                'source_type' => 'wp_post',
                'source_uuid' => $articleEndpoint,
                'target_type' => (string) $primary['type'],
                'target_uuid' => (string) $primary['id'],
                'predicate' => 'about',
                'origin' => 'CAPTURE_ARTICLE_SUBJECT_BINDING',
            ], 'capture:article-about:' . $articleEndpoint . ':' . (string) $primary['type'] . ':' . (string) $primary['id']);
            $relationPlan['payload']['target_revision'] = max(1, (int) ($primary['revision'] ?? 0));
            $plans[] = $relationPlan;
        }
        if ($includeSemanticChildren && $this->semanticDeltaRequested($context) && ($intent === 'KNOWLEDGE_DELTA' || count($variants) === 1) && ($subject = $this->knowledgeSubject($subjects, $variants, $intent, $primary)) !== null) {
            $deltaText = trim((string) ($context['continuation_delta_text'] ?? ''));
            $candidates = $deltaText !== ''
                ? [['text' => $deltaText, 'provenance' => 'EXPLICIT_USER_KNOWLEDGE']]
                : (array) ($context['interpretation']['user_claim_candidates'] ?? []);
            foreach ($candidates as $candidate) {
                if (!is_array($candidate) || trim((string) ($candidate['text'] ?? '')) === '') continue;
                $scope = $this->knowledgeScope((string) ($subject['type'] ?? ''), (string) ($candidate['scope'] ?? ''), $candidate);
                if ($scope === null) continue;
                $facet = trim((string) ($candidate['facet'] ?? 'identity')) ?: 'identity';
                try {
                    new KnowledgeFacetProfile($facet, $scope);
                } catch (\Throwable) {
                    // Invalid interpreter output is a bounded review gap. Do
                    // not silently coerce it into another semantic facet.
                    continue;
                }
                if ($this->claimReuse?->find(['text' => (string) $candidate['text'], 'subject_id' => (string) $subject['id'], 'scope' => $scope], $this->retrievedClaims($context)) !== null) continue;
                $payload = [
                    'stable_key' => 'nhk:knowledge:capture.' . hash('sha256', CommandCanonicalizer::canonicalize([$captureId, $subject['id'], trim((string) $candidate['text'])])),
                    'text' => trim((string) $candidate['text']), 'claim_type' => 'fact',
                    'provenance' => ['metadata' => ['facet' => $facet, 'scope' => $scope, 'version' => 1, 'subject_id' => $subject['id'], 'subject_type' => $subject['type']], 'origin' => (string) ($candidate['provenance'] ?? 'EXPLICIT_USER_KNOWLEDGE')],
                ];
                $knowledgePlan = $this->arguments('knowledge', 'ingest', (string) $subject['id'], $payload, 'capture:' . $captureId . ':knowledge:' . hash('sha256', (string) $payload['stable_key']));
                $knowledgePlan['candidate_id'] = 'knowledge-candidate-' . hash('sha256', (string) $payload['stable_key']);
                $knowledgePlan = $this->scopeDependencyPlan($captureId, $knowledgePlan);
                $plans[] = $knowledgePlan;
                // A Knowledge claim is not semantically attached merely by
                // carrying subject_id. Its canonical about edge is a second,
                // governed Graph candidate whose source is bound only after
                // the Knowledge read-back succeeds.
                $relationPlan = $this->arguments('relation', 'relation_create', 'relation', [
                    'source_type' => 'knowledge',
                    'source_candidate_id' => $knowledgePlan['candidate_id'],
                    'target_type' => (string) $subject['type'],
                    'target_uuid' => (string) $subject['id'],
                    'target_revision' => (int) ($subject['revision'] ?? 0),
                    'predicate' => 'about',
                    'origin' => 'CAPTURE_KNOWLEDGE_SUBJECT_BINDING',
                    'provenance' => $payload['provenance'],
                    'evidence_refs' => (array) ($candidate['evidence_refs'] ?? ($context['relation_evidence_refs'] ?? [])),
                    'require_evidence' => (bool) ($context['relation_policy']['require_evidence'] ?? false),
                ], 'capture:' . $captureId . ':knowledge-about:' . hash('sha256', (string) $payload['stable_key']) . ':' . (string) $subject['id']);
                $relationPlan['candidate_id'] = 'relation-candidate-' . hash('sha256', (string) $relationPlan['idempotency_key']);
                $relationPlan['dependency_ids'] = [$knowledgePlan['candidate_id']];
                $plans[] = $relationPlan;
            }
            foreach ((array) ($context['observations'] ?? []) as $observation) {
                if (!is_array($observation)) continue;
                $componentId = trim((string) ($observation['component_id'] ?? ''));
                if (!UuidCodec::isValid($componentId)) continue;
                $payload = ['source_type' => (string) $subject['type'], 'source_uuid' => (string) $subject['id'], 'target_type' => 'component', 'target_uuid' => $componentId, 'predicate' => 'about', 'origin' => 'EXPLICIT_USER_RELATION'];
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
            // A persisted pending Video Proposal is already inside the
            // Governance lifecycle. Re-enter it by canonical Proposal UUID;
            // do not reconstruct a fresh pre-proposal staging packet from the
            // Capture-derived asset (which may be a stale derived projection).
            if (($context['existing_capture_continuation'] ?? false) === true && $entityType === 'video' && $operation === 'ingest') {
                // A persisted APPLIED dependency receipt is only a locator.
                // If its final attachment has no canonical Evidence, do not
                // convert the child directly into a Proposal-ID plan: that
                // would skip the provenance dependency validator and make the
                // historical receipt authoritative. Rebuild the provenance
                // plan so Source/Claim/Evidence are validated before reuse.
                $pending = $this->historicalEvidenceRecoveryRequired($context, $payload)
                    || $this->finalVideoPlanRebuildRequired($payload)
                    ? null
                    : $this->pendingVideoProposal($context, $video, $payload, $subjectId);
                if ($pending !== null) {
                    $plans[] = [
                        'proposal_id' => $pending['proposal_id'],
                        'entity_type' => 'video',
                        'operation' => 'ingest',
                        'subject_id' => $subjectId,
                        'payload' => $payload,
                    ];
                    continue;
                }
            }
            if (!$includeSemanticChildren && $this->videoEditorialResume !== null && $entityType === 'video') {
                $resume = $this->videoEditorialResume->plan($video, $context + ['capture_id' => $captureId]);
                if (($resume['status'] ?? '') === 'REUSE_EDITORIAL') {
                    $plans[] = ['video_editorial_reuse' => $resume];
                } else {
                    $plans[] = $this->scopeVideoPlan($captureId, $resume, $context);
                }
                continue;
            }
            if ($this->videoProvenance !== null && $entityType === 'video' && $operation === 'ingest') {
                $source = is_array($payload['metadata']['source'] ?? null) ? $payload['metadata']['source'] : (is_array($payload['metadata']['source_snapshot'] ?? null) ? $payload['metadata']['source_snapshot'] : []);
                $primary = is_array($context['subject_resolution']['primary'] ?? null) ? $context['subject_resolution']['primary'] : [];
                if ($primary === []) {
                    $resolvedVariants = array_values(array_filter($resolved, static fn (mixed $item): bool => is_array($item) && ($item['type'] ?? '') === 'variant'));
                    $primary = $resolvedVariants[0] ?? [];
                }
                $hint = is_array($payload['metadata']['provenance']['user_hint'] ?? null) ? (string) ($payload['metadata']['provenance']['user_hint']['value'] ?? '') : '';
                $plans[] = ['capture_video_provenance' => $this->videoProvenance->plan($captureId, $video, $source, $primary, [
                    'user_hint' => $hint,
                    'preserve_original_subject' => !$includeSemanticChildren,
                    'source_subject_candidates' => $resolved,
                ])];
                continue;
            }
            $plans[] = $this->arguments(
                $entityType,
                $operation,
                $subjectId,
                $payload,
                'capture:' . $captureId . ':video:' . hash('sha256', CommandCanonicalizer::canonicalize($payload)),
                array_key_exists('expected_revision', $video) && $video['expected_revision'] !== null
                    ? (int) $video['expected_revision']
                    : null,
            );
            $plans[array_key_last($plans)] = $this->scopeVideoPlan($captureId, $plans[array_key_last($plans)], $context);
        }
        return $plans;
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $payload */
    private function historicalEvidenceRecoveryRequired(array $context, array $payload): bool
    {
        $receipts = is_array($context['phase_receipts'] ?? null) ? $context['phase_receipts'] : [];
        $receipt = is_array($receipts['VIDEO_EVIDENCE_GOVERNANCE'] ?? null) ? $receipts['VIDEO_EVIDENCE_GOVERNANCE'] : [];
        $latest = is_array($receipt['latest'] ?? null) ? $receipt['latest'] : $receipt;
        if (strtoupper(trim((string) ($latest['status'] ?? ''))) !== 'COMPLETED'
            || strtoupper(trim((string) ($latest['result'] ?? ''))) !== 'APPLIED') return false;
        $attachments = is_array($payload['metadata']['semantic_attachments'] ?? null)
            ? $payload['metadata']['semantic_attachments']
            : (is_array($payload['semantic_attachments'] ?? null) ? $payload['semantic_attachments'] : []);
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) continue;
            $refs = is_array($attachment['evidence_refs'] ?? null) ? $attachment['evidence_refs'] : [];
            foreach ($refs as $reference) {
                $evidenceId = is_array($reference) ? trim((string) ($reference['evidence_id'] ?? '')) : trim((string) $reference);
                if ($evidenceId !== '' && $this->canonicalDependencies !== null) {
                    try { $this->canonicalDependencies->evidence($evidenceId); return false; } catch (\Throwable) { return true; }
                }
            }
        }
        return true;
    }

    /**
     * Find the exact persisted pending Video Proposal for this Capture child.
     * The receipt is only a locator: runGovernedPlan() performs the canonical
     * Governance read-back by UUID before any lifecycle transition.
     *
     * @return array{proposal_id:string,target_uuid:string,platform:string,external_video_id:string}|null
     */
    private function pendingVideoProposal(array $context, array $video, array $payload, string $subjectId): ?array
    {
        $videoId = trim((string) ($payload['canonical_id'] ?? $subjectId));
        $key = trim((string) ($video['idempotency_key'] ?? '')) ?: $this->currentCaptureId . ':video';
        if ($this->pendingVideoProposals !== null && $key !== '' && UuidCodec::isValid($videoId)) {
            $expectedValue = array_key_exists('expected_revision', $video) ? $video['expected_revision'] : ($payload['expected_revision'] ?? null);
            $expected = $expectedValue !== null ? (int) $expectedValue : null;
            $binding = [
                'capture_id' => $this->currentCaptureId,
                'idempotency_key' => $key,
                'video_id' => $videoId,
                'entity_type' => 'video',
                'operation' => 'ingest',
                'expected_revision' => $expected,
            ];
            $fingerprints = $this->pendingVideoFingerprints($video, $payload, $videoId, $expected);
            if ($fingerprints !== null) $binding += $fingerprints;
            $candidates = $this->pendingVideoProposals->findPendingVideoProposals($binding);
            // Adapters may implement only the identity portion of the lookup;
            // enforce the content/dependency binding again at this owner
            // boundary before treating a Proposal as reusable.
            if ($fingerprints !== null) {
                $candidates = array_values(array_filter($candidates, static fn (Proposal $proposal): bool =>
                    strtolower($proposal->contentFingerprint) === strtolower($fingerprints['content_fingerprint'])
                    && strtolower($proposal->dependencyFingerprint) === strtolower($fingerprints['dependency_fingerprint'])));
            }
            if (count($candidates) > 1) throw new \RuntimeException('AMBIGUOUS_PENDING_VIDEO_PROPOSAL');
            if (count($candidates) === 1) {
                $proposal = $candidates[0];
                $metadata = is_array($proposal->payload['metadata'] ?? null) ? $proposal->payload['metadata'] : [];
                $source = is_array($metadata['source'] ?? null) ? $metadata['source'] : (is_array($metadata['source_snapshot'] ?? null) ? $metadata['source_snapshot'] : []);
                return ['proposal_id' => $proposal->id, 'target_uuid' => $proposal->targetUuid, 'platform' => (string) ($source['platform'] ?? ''), 'external_video_id' => (string) ($source['external_video_id'] ?? '')];
            }
            return null;
        }
        $diagnostics = is_array($context['prior_diagnostics'] ?? null) ? $context['prior_diagnostics'] : [];
        $writeBack = is_array($diagnostics['semantic_write_back'] ?? null) ? $diagnostics['semantic_write_back'] : [];
        $rows = is_array($writeBack['writes'] ?? null) ? $writeBack['writes'] : [];
        $canonicalId = trim((string) ($payload['canonical_id'] ?? $subjectId));
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $source = is_array($metadata['source'] ?? null) ? $metadata['source'] : (is_array($metadata['source_snapshot'] ?? null) ? $metadata['source_snapshot'] : []);
        $platform = strtolower(trim((string) ($source['platform'] ?? '')));
        $externalId = trim((string) ($source['external_video_id'] ?? ''));
        foreach ($rows as $row) {
            if (!is_array($row)
                || strtolower(trim((string) ($row['entity_type'] ?? ''))) !== 'video'
                || strtolower(trim((string) ($row['operation'] ?? ''))) !== 'ingest'
                || (string) ($row['status'] ?? '') !== 'REVIEW_REQUIRED') continue;
            $proposalId = trim((string) ($row['proposal_id'] ?? ''));
            if (!UuidCodec::isValid($proposalId)) continue;
            $target = trim((string) ($row['target_uuid'] ?? ''));
            $external = is_array($row['external_video'] ?? null) ? $row['external_video'] : [];
            $rowPlatform = strtolower(trim((string) ($external['platform'] ?? '')));
            $rowExternalId = trim((string) ($external['external_video_id'] ?? ''));
            if (($target !== '' && $target !== $canonicalId)
                || ($rowPlatform !== '' && $platform !== '' && $rowPlatform !== $platform)
                || ($rowExternalId !== '' && $externalId !== '' && $rowExternalId !== $externalId)) continue;
            return ['proposal_id' => $proposalId, 'target_uuid' => $target, 'platform' => $rowPlatform, 'external_video_id' => $rowExternalId];
        }
        return null;
    }

    /** @return array{content_fingerprint:string,dependency_fingerprint:string}|null */
    private function pendingVideoFingerprints(array $video, array $payload, string $videoId, ?int $expectedRevision): ?array
    {
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $attachments = is_array($metadata['semantic_attachments'] ?? null)
            ? $metadata['semantic_attachments']
            : (is_array($payload['semantic_attachments'] ?? null) ? $payload['semantic_attachments'] : []);
        $dependencyIds = array_values(array_filter(array_map('strval', (array) ($video['dependency_ids'] ?? $payload['dependency_ids'] ?? [])), static fn (string $id): bool => UuidCodec::isValid($id)));
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) continue;
            foreach ((array) ($attachment['evidence_refs'] ?? []) as $reference) {
                $id = is_array($reference) ? (string) ($reference['evidence_id'] ?? '') : (string) $reference;
                if (UuidCodec::isValid($id)) $dependencyIds[] = $id;
            }
        }
        $dependencyIds = array_values(array_unique($dependencyIds));
        // A pre-provenance child has no final dependency closure to compare;
        // leave that legacy pending lookup available for ordinary approval
        // continuation. Final Evidence-backed commands always bind here.
        if ($dependencyIds === []) return null;
        sort($dependencyIds, SORT_STRING);
        $binding = [
            'operation' => (string) ($video['operation'] ?? 'ingest'),
            'entity_type' => (string) ($video['entity_type'] ?? 'video'),
            'subject_id' => $videoId,
            'target_uuid' => $video['target_uuid'] ?? null,
            'expected_revision' => $expectedRevision,
            'payload' => $payload,
            'dependency_ids' => $dependencyIds,
        ];
        return [
            'content_fingerprint' => hash('sha256', CommandCanonicalizer::canonicalize($binding)),
            'dependency_fingerprint' => hash('sha256', CommandCanonicalizer::canonicalize($dependencyIds)),
        ];
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $context @return array<string,mixed> */
    private function scopeVideoPlan(string $captureId, array $plan, array $context): array
    {
        if (($plan['entity_type'] ?? '') !== 'video' || !in_array((string) ($plan['operation'] ?? ''), ['ingest', 'update'], true) || !is_callable($this->videoScopeIssuer)) return $plan;
        if (!is_array($plan['payload'] ?? null)) $plan['payload'] = [];
        unset(
            $plan['payload']['staging_acceptance'],
            $plan['payload']['capture_fingerprint'],
            $plan['payload']['scope_fingerprint'],
            $plan['payload']['proposal_command_fingerprint'],
        );
        // A resumed Video may have been reconstructed after Source/Claim/
        // Evidence read-back. The staging packet must be derived from this
        // exact final command, never from the historical child scope.
        if (!preg_match('/^[a-f0-9]{64}$/i', (string) ($plan['plan_fingerprint'] ?? ''))) {
            $plan['plan_fingerprint'] = hash('sha256', CommandCanonicalizer::canonicalize([
                'capture_id' => $captureId,
                'entity_type' => $plan['entity_type'],
                'operation' => $plan['operation'],
                'subject_id' => $plan['subject_id'] ?? null,
                'target_uuid' => $plan['target_uuid'] ?? null,
                'expected_revision' => $plan['expected_revision'] ?? null,
                'dependency_ids' => array_values(array_map('strval', (array) ($plan['dependency_ids'] ?? []))),
                'payload' => $plan['payload'],
            ]));
        }
        $scope = ($this->videoScopeIssuer)($captureId, $plan);
        if (!is_array($scope)) throw new \RuntimeException('STAGING_SCOPE_REQUIRED');
        $plan['payload']['capture_id'] = $captureId;
        // The fingerprint is copied only from the server-issued packet; it is
        // never accepted from connector input.
        $plan['payload']['capture_fingerprint'] = (string) ($scope['capture_fingerprint'] ?? '');
        $plan['payload']['capture_revision'] = (int) ($scope['capture_revision'] ?? 0);
        $plan['payload']['staging_acceptance'] = $scope;
        return $plan;
    }

    /**
     * A persisted pending child is only a locator. Once its final payload
     * carries canonical Evidence references, retry must rebuild the governed
     * Video command so a stale scope/key cannot be reused.
     */
    private function finalVideoPlanRebuildRequired(array $payload): bool
    {
        if ($this->canonicalDependencies === null) return false;
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $attachments = is_array($metadata['semantic_attachments'] ?? null)
            ? $metadata['semantic_attachments']
            : (is_array($payload['semantic_attachments'] ?? null) ? $payload['semantic_attachments'] : []);
        foreach ($attachments as $attachment) {
            if (!is_array($attachment) || strtolower(trim((string) ($attachment['predicate'] ?? ''))) !== 'about') continue;
            foreach ((array) ($attachment['evidence_refs'] ?? []) as $reference) {
                $evidenceId = is_array($reference)
                    ? trim((string) ($reference['evidence_id'] ?? ''))
                    : trim((string) $reference);
                if (!UuidCodec::isValid($evidenceId)) continue;
                try {
                    $this->canonicalDependencies->evidence($evidenceId);
                    return true;
                } catch (\Throwable) {
                    return false;
                }
            }
        }
        return false;
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private function scopeDependencyPlan(string $captureId, array $plan): array
    {
        if (!in_array((string) ($plan['entity_type'] ?? ''), ['source', 'knowledge', 'evidence'], true)
            || !in_array((string) ($plan['operation'] ?? ''), ['ingest', 'create', 'update', 'retire'], true)
            || !is_callable($this->dependencyScopeIssuer)) return $plan;
        $payload = is_array($plan['payload'] ?? null) ? $plan['payload'] : [];
        unset($payload['staging_acceptance'], $payload['capture_fingerprint'], $payload['scope_fingerprint'], $payload['proposal_command_fingerprint']);
        $payload['capture_id'] = $captureId;
        $plan['payload'] = $payload;
        $scope = ($this->dependencyScopeIssuer)($captureId, $plan);
        if (!is_array($scope)) throw new \RuntimeException('STAGING_SCOPE_REQUIRED');
        $plan['payload']['capture_fingerprint'] = (string) ($scope['capture_fingerprint'] ?? '');
        $plan['payload']['capture_revision'] = (int) ($scope['capture_revision'] ?? 0);
        $plan['payload']['proposal_command_fingerprint'] = (string) ($scope['proposal_command_fingerprint'] ?? '');
        $plan['payload']['staging_acceptance'] = $scope;
        return $plan;
    }

    /** @return array<string,mixed> */
    private function knowledgeRepairPlan(string $captureId, array $context): array
    {
        if ($this->knowledgeRepository === null) throw new \RuntimeException('KNOWLEDGE_REPAIR_REPOSITORY_UNAVAILABLE');
        $repair = KnowledgeRepairIntent::fromArray((array) ($context['planning_input']['knowledge_repair'] ?? $context['knowledge_repair'] ?? []));
        $current = $this->knowledgeRepository->findByCanonicalId($repair->targetUuid);
        if ($current === null) throw new \RuntimeException('KNOWLEDGE_REPAIR_TARGET_NOT_FOUND');
        if ($current->revision !== $repair->expectedRevision) throw new \RuntimeException('KNOWLEDGE_REPAIR_REVISION_CHANGED');
        $payload = ['canonical_id' => $repair->targetUuid, 'text' => $repair->text ?? $current->claimText, 'claim_type' => $repair->claimType ?? $current->claimType, 'provenance' => $repair->provenance, 'repair' => ['cleanup_class' => $repair->cleanupClass, 'reason' => $repair->reason, 'target_uuid' => $repair->targetUuid, 'expected_revision' => $repair->expectedRevision]];
        if ($repair->operation === 'retire' && $this->knowledgeRepairPreview !== null) {
            $preview = $this->knowledgeRepairPreview->preview($repair->toArray());
            $payload['repair']['manual_review_required'] = ($preview['status'] ?? '') === 'REVIEW_REQUIRED';
            $payload['repair']['dependency_inventory'] = ['graph' => $preview['graph_dependencies'] ?? [], 'evidence' => $preview['evidence_dependencies'] ?? [], 'blockers' => $preview['blockers'] ?? []];
        }
        $plan = $this->arguments('knowledge', $repair->operation, $repair->targetUuid, $payload, 'capture:' . $captureId . ':knowledge-repair:' . hash('sha256', CommandCanonicalizer::canonicalize($repair->toArray())), $repair->expectedRevision);
        $plan['target_uuid'] = $repair->targetUuid;
        $plan['repair'] = true;
        return $this->scopeDependencyPlan($captureId, $plan);
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private function scopeRelationPlan(string $captureId, array $plan): array
    {
        if (($plan['entity_type'] ?? '') !== 'relation' || ($plan['operation'] ?? '') !== 'relation_create' || !is_callable($this->relationScopeIssuer)) return $plan;
        $scope = ($this->relationScopeIssuer)($captureId, $plan);
        if (!is_array($scope)) throw new \RuntimeException('STAGING_SCOPE_REQUIRED');
        if (is_array($scope['proposal_payload'] ?? null)) $plan['payload'] = $scope['proposal_payload'];
        $plan['payload']['capture_id'] = $captureId;
        $plan['payload']['staging_acceptance'] = $scope;
        return $plan;
    }

    /** @param list<array<string,mixed>> $subjects @param list<array<string,mixed>> $variants */
    private function knowledgeSubject(array $subjects, array $variants, string $intent, array $primary = []): ?array
    {
        if ($intent === 'KNOWLEDGE_DELTA' && UuidCodec::isValid((string) ($primary['id'] ?? '')) && trim((string) ($primary['type'] ?? '')) !== '') return $primary;
        if ($intent === 'KNOWLEDGE_DELTA') return $subjects[0] ?? null;
        return count($variants) === 1 ? $variants[0] : null;
    }

    private function semanticDeltaRequested(array $context): bool
    {
        $intent = strtoupper(trim((string) ($context['content_intent']['intent'] ?? '')));
        $status = strtoupper(trim((string) ($context['content_intent']['semantic_delta']['status'] ?? 'NONE')));
        if ($intent === 'KNOWLEDGE_DELTA' || $intent === '') return true;
        return $status === 'REQUIRED' && in_array($intent, ['KNOWLEDGE_DELTA', 'AUTHORITY', 'MIXED'], true);
    }

    private function knowledgeScope(string $subjectType, string $candidateScope, array $candidate = []): ?string
    {
        $default = match (strtolower(trim($subjectType))) {
            'classification', 'entity', 'product' => 'entity',
            'brand' => 'brand',
            'model' => 'model',
            'variant' => 'variant',
            'movement' => 'movement',
            'specimen' => 'specimen_observation',
            default => null,
        };
        if ($default === null) return null;
        $candidateScope = trim($candidateScope);
        if ($candidateScope === '' || strtolower($candidateScope) === 'unspecified') return $default;
        $basis = strtoupper(trim((string) ($candidate['scope_basis'] ?? '')));
        $facet = strtolower(trim((string) ($candidate['facet'] ?? 'identity')));
        // Older interpreters emitted variant as a fallback for identity
        // statements. Treat that value as unresolved only for non-explicit
        // brand/company facets; explicit incompatible scope remains review.
        if (strtolower($subjectType) === 'brand' && $candidateScope === 'variant' && !in_array($basis, ['EXPLICIT', 'EXPLICIT_CONTEXT', 'EXPLICIT_EVIDENCE'], true) && in_array($facet, ['identity', 'history', 'company', 'description'], true)) return $default;
        return $candidateScope === $default ? $default : null;
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
            $dependency = $this->scopeDependencyPlan($this->currentCaptureId, $dependency);
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
        $evidenceArguments = $this->scopeDependencyPlan($this->currentCaptureId, $evidenceArguments);
        try {
            $this->budget?->check('VIDEO_PROVENANCE_EVIDENCE');
        } catch (\Throwable $error) {
            $writes[] = $this->classifiedFailure($evidenceArguments, $error);
            return;
        }
        $evidenceReuse = $this->reusedDependency($evidenceArguments, $withEvidence, 'evidence', 'VIDEO_EVIDENCE_GOVERNANCE');
        if ($evidenceReuse === null && $this->canonicalDependencies !== null) {
            // A historical APPLIED receipt is not an owner. If canonical
            // Evidence is absent/incompatible, re-enter the same governed
            // dependency flow under a deterministic recovery identity.
            $evidenceArguments['idempotency_key'] = $this->evidenceRecoveryKey($evidenceArguments);
        }
        $evidenceWrite = $evidenceReuse ?? $this->runGovernedChild($evidenceArguments, $control, $lifecycle, 'VIDEO_EVIDENCE_GOVERNANCE');
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
                $origin = (string) ($relation['origin'] ?? 'EXPLICIT_USER_RELATION');
                $candidates = $this->videoRelations->plan($videoId, [[
                    'target_id' => (string) ($relation['target_uuid'] ?? ''),
                    'target_type' => (string) ($relation['target_type'] ?? ''),
                    'predicate' => (string) ($relation['predicate'] ?? 'about'),
                    'origin' => (string) ($relation['origin'] ?? 'EXPLICIT_USER_RELATION'),
                    'evidence_refs' => [['evidence_id' => $evidenceId]],
                    'reason' => (string) ($relation['reason'] ?? ''),
                    'confidence' => $origin === 'EXPLICIT_USER_RELATION' ? 1.0 : (float) ($relation['confidence'] ?? 1.0),
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
        $videoProposal = (array) ($complete['video_proposal'] ?? []);
        // The dependency-complete command is a new governed semantic
        // identity. Do not carry the historical/pending child key into a
        // payload that now contains canonical Evidence-backed attachments.
        // This remains deterministic so an identical final command reuses
        // its Proposal, while a legitimate semantic change gets a new key.
        $videoPayload = is_array($videoProposal['payload'] ?? null) ? $videoProposal['payload'] : [];
        $videoId = trim((string) ($videoPayload['canonical_id'] ?? $videoProposal['subject_id'] ?? ''));
        $videoProposal['entity_type'] = 'video';
        $videoProposal['operation'] = 'ingest';
        $videoProposal['subject_id'] = $videoId;
        // Bind the final command to the exact canonical Source/Claim/Evidence
        // closure just read back. These are server-derived identities.
        $videoProposal['dependency_ids'] = array_values(array_filter($canonicalIds, static fn (string $id): bool => UuidCodec::isValid($id)));
        // Dependency closure is part of the final Video command as well as
        // the orchestration plan. This makes issuance and Proposal
        // verification consume the same canonicalized input.
        $videoPayload['dependency_ids'] = $videoProposal['dependency_ids'];
        $videoProposal['payload'] = $videoPayload;
        // Leave create expected_revision null; the staging descriptor
        // normalizes Video ingest to revision zero without violating the
        // Proposal domain's positive-revision invariant.
        unset($videoProposal['expected_revision']);
        $videoProposal['idempotency_key'] = $this->finalVideoCommandIdempotencyKey($this->currentCaptureId, $videoProposal);
        $videoProposal = $this->scopeVideoPlan($this->currentCaptureId, $videoProposal, []);
        $videoWrite = $this->runGovernedChild($videoProposal, $control, $lifecycle, 'VIDEO_GOVERNANCE');
        $videoWrite['evidence_handoff'] = [
            'source_id' => $canonicalIds[0],
            'claim_id' => $canonicalIds[1],
            'evidence_id' => $evidenceId,
            'relation_evidence_refs' => [['evidence_id' => $evidenceId]],
        ];
        array_push($writes, ...array_merge($allWrites, [$videoWrite]));
    }

    /** @param array<string,mixed> $plan */
    private function finalVideoCommandIdempotencyKey(string $captureId, array $plan): string
    {
        $payload = is_array($plan['payload'] ?? null) ? $plan['payload'] : [];
        foreach (['staging_acceptance', 'capture_fingerprint', 'scope_fingerprint', 'proposal_command_fingerprint'] as $volatile) {
            unset($payload[$volatile]);
        }
        $canonicalId = trim((string) ($payload['canonical_id'] ?? $plan['subject_id'] ?? ''));
        $binding = [
            'capture_id' => $captureId,
            'operation' => (string) ($plan['operation'] ?? 'ingest'),
            'entity_type' => (string) ($plan['entity_type'] ?? 'video'),
            'canonical_id' => $canonicalId,
            'target_uuid' => $plan['target_uuid'] ?? null,
            'expected_revision' => $plan['expected_revision'] ?? null,
            'payload' => $payload,
            'dependency_ids' => array_values(array_map('strval', (array) ($plan['dependency_ids'] ?? []))),
        ];
        return 'video-governed:' . hash('sha256', CommandCanonicalizer::canonicalize($binding));
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
        if (!$this->canonicalOwnerMatches($kind, $canonicalId, (array) ($dependency['payload'] ?? []))) return null;
        $this->emitPhaseReceipt($phase, ['status' => 'COMPLETED', 'result' => 'REUSED_VERIFIED', 'canonical_id' => $canonicalId, 'revision' => $revision, 'idempotent' => true]);
        return [
            'canonical_id' => $canonicalId,
            'status' => 'REUSED_VERIFIED',
            'idempotent' => true,
            'reused' => true,
            'canonical_readback' => ['canonical_id' => $canonicalId, 'entity_type' => $kind === 'claim' ? 'knowledge' : $kind, 'active' => true, 'revision' => $revision],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function canonicalOwnerMatches(string $kind, string $canonicalId, array $payload): bool
    {
        if ($this->canonicalDependencies === null) return true;
        try {
            $owner = match ($kind) {
                'source' => $this->canonicalDependencies->source($canonicalId),
                'claim' => $this->canonicalDependencies->claim($canonicalId),
                'evidence' => $this->canonicalDependencies->evidence($canonicalId),
                default => null,
            };
            if ($owner === null) return false;
            if ($kind === 'evidence') {
                return $owner->claimId === (string) ($payload['claim_id'] ?? '')
                    && $owner->sourceId === (string) ($payload['source_id'] ?? '');
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $plan */
    private function evidenceRecoveryKey(array $plan): string
    {
        $payload = is_array($plan['payload'] ?? null) ? $plan['payload'] : [];
        return 'video-provenance:evidence:canonical-recovery:' . hash('sha256', CommandCanonicalizer::canonicalize([
            'source_id' => (string) ($payload['source_id'] ?? ''),
            'claim_id' => (string) ($payload['claim_id'] ?? ''),
            'excerpt' => (string) ($payload['excerpt'] ?? ''),
            'relation' => (string) ($payload['relation'] ?? ''),
            'locator' => $payload['locator'] ?? null,
            'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
        ]));
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
            // An uncertain retry must never invoke Controlled Apply again.
            // Reuse the persisted canonical read-back; if the lifecycle did
            // not expose it, stop until a read boundary can verify the owner.
            $readback = is_array($review['canonical_readback'] ?? null) ? $review['canonical_readback'] : (is_array($review['apply']['canonical_readback'] ?? null) ? $review['apply']['canonical_readback'] : $this->uncertainApplyReadback($plan, $proposal));
            if (!is_array($readback)) throw new \RuntimeException('CANONICAL_READBACK_REQUIRED_AFTER_APPLIED');
            $applied = ['canonical_id' => $readback['canonical_id'] ?? null, 'canonical_readback' => $readback, 'idempotent' => true];
            $lifecycle[] = 'CONTROLLED_APPLY';
            return $this->applied($proposal, $applied);
        }
        $eligibility = $this->governance->eligibility($proposal->id);
        $lifecycle[] = 'ELIGIBILITY';
        if (($eligibility['ready'] ?? false) !== true) {
            $reasons = array_values(array_map('strval', (array) ($eligibility['reasons'] ?? ['PROPOSAL_NOT_ELIGIBLE'])));
            // Hub classification is a publication/navigation concern. A
            // Video with an otherwise valid governed command must still cross
            // Proposal -> Eligibility -> Controlled Apply when this is the
            // only reported reason; the unresolved category remains in the
            // canonical metadata for publication readiness.
            $categoryOnly = $proposal->entityType === 'video'
                && $proposal->operation === 'ingest'
                && $reasons !== []
                && array_diff($reasons, ['CATEGORY_UNRESOLVED']) === [];
            if ($categoryOnly) {
                $eligibility['ready'] = true;
            }
            if (!$categoryOnly) {
            // A historical Video Proposal can remain subject-bound and
            // approved while its payload predates the canonical Evidence
            // attachment rebuilt during Capture resume. Re-enter the existing
            // governed reconciliation seam before returning the stale
            // eligibility blocker; never edit or silently execute that
            // historical command.
            if (($eligibility['ready'] ?? false) !== true
                && $this->videoReconciliation !== null
                && $proposal->entityType === 'video'
                && $proposal->operation === 'ingest'
                && $proposal->state === ProposalState::APPROVED
                && $this->videoProposalNeedsReconciliation($reasons)) {
                $repaired = $this->repairVideoProposal($proposal);
                $lifecycle[] = 'VIDEO_RECONCILIATION';
                return $repaired;
            }
            $reconciliationOperation = (string) ($proposal->operation ?? '');
            $isRelationProposal = $proposal->entityType === 'relation'
                && in_array($reconciliationOperation, ['relation_create', 'relation_retire', 'relation_reactivate'], true);
            if ($this->proposalReconciliation !== null && $isRelationProposal && in_array('TARGET_REVISION_CHANGED', $reasons, true)) {
                $reconciled = ($this->proposalReconciliation)($proposal, $eligibility, $control);
                if (is_array($reconciled) && ($reconciled['status'] ?? '') !== '') return $reconciled;
            }
            return ['proposal_id' => $proposal->id, 'status' => 'SYSTEM_BLOCKED', 'blockers' => $reasons];
            }
        }
        $applied = ($this->apply)($proposal->id);
        if (($plan['repair'] ?? false) === true && $this->knowledgeRepairPreview !== null) {
            $applied['dependency_readback'] = $this->knowledgeRepairPreview->readback((string) ($plan['target_uuid'] ?? $plan['subject_id'] ?? ''));
        }
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

    /** @param list<string> $reasons */
    private function videoProposalNeedsReconciliation(array $reasons): bool
    {
        return array_intersect($reasons, [
            'NO_SEMANTIC_ATTACHMENT',
            'GOVERNED_RECONCILIATION_REQUIRED',
            'EVIDENCE_REQUIRED',
            'CANONICAL_EVIDENCE_REQUIRED',
            'STAGING_VIDEO_BINDING_REQUIRED',
        ]) !== [];
    }

    /** @return array<string,mixed>|null */
    private function uncertainApplyReadback(array $plan, Proposal $proposal): ?array
    {
        if ($this->videoDependencyState === null) return null;
        try { $state = ($this->videoDependencyState)($plan + ['proposal_id' => $proposal->id]); } catch (\Throwable) { return null; }
        if (!is_array($state)) return null;
        $kind = $proposal->entityType === 'knowledge' ? 'claim' : $proposal->entityType;
        $record = $state[$kind] ?? null;
        [$canonicalId, $revision, $active] = $this->canonicalStateTuple($record);
        if (!UuidCodec::isValid($canonicalId) || !$active) return null;
        return ['canonical_id' => $canonicalId, 'entity_type' => $proposal->entityType, 'active' => true, 'revision' => $revision];
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
            return ['proposal_id' => (string) ($plan['proposal_id'] ?? ''), 'status' => 'SYSTEM_BLOCKED', 'blockers' => [$reason], 'error' => $error->getMessage()];
        }
        if ($error instanceof VideoRelationEvidenceRequired) {
            return ['proposal_id' => (string) ($plan['proposal_id'] ?? ''), 'status' => 'REVIEW_REQUIRED', 'blockers' => [VideoRelationEvidenceRequired::ERROR_CODE]];
        }
        if ($error instanceof VideoException && $error->getMessage() === 'VIDEO_INTENDED_CATEGORY_INVALID') {
            return ['proposal_id' => (string) ($plan['proposal_id'] ?? ''), 'status' => 'SYSTEM_BLOCKED', 'blockers' => ['VIDEO_INTENDED_CATEGORY_INVALID'], 'error' => $error->getMessage()];
        }
        if ($error instanceof CaptureOrchestrationBudgetExceeded || $error instanceof VideoException) {
            return ['proposal_id' => (string) ($plan['proposal_id'] ?? ''), 'status' => 'FAILED_RETRYABLE', 'blockers' => [$error instanceof CaptureOrchestrationBudgetExceeded ? 'CAPTURE_ORCHESTRATION_BUDGET_EXCEEDED' : 'VIDEO_EXTERNAL_TRANSIENT_FAILURE']];
        }
        if ($error instanceof DependencyValidationException) {
            return ['proposal_id' => (string) ($plan['proposal_id'] ?? ''), 'status' => 'REVIEW_REQUIRED', 'blockers' => [$error->errorCode]];
        }
        $rawMessage = strtoupper(trim($error->getMessage()));
        if ((int) $error->getCode() === 429 || preg_match('/(?:\b429\b|RATE[ _-]?LIMIT|TOO MANY REQUESTS|RETRY[- ]?AFTER)/', $rawMessage) === 1) {
            return ['proposal_id' => (string) ($plan['proposal_id'] ?? ''), 'status' => 'FAILED_RETRYABLE', 'blockers' => ['EXTERNAL_RATE_LIMIT'], 'error' => $error->getMessage()];
        }
        $code = strtoupper(trim((string) $error->getCode()));
        if ($code === '' || preg_match('/^[A-Z][A-Z0-9_]{2,63}$/', $code) !== 1) {
            $message = $rawMessage;
            $code = preg_match('/(?:^|:)([A-Z][A-Z0-9_]{2,63})$/', $message, $match) === 1 ? $match[1] : 'CAPTURE_GOVERNANCE_FAILED';
        }
        $blocked = preg_match('/(?:SUBJECT_BINDING|IDENTITY_CONFLICT|IDEMPOTENCY_STALE|IDEMPOTENCY_CONFLICT|BINDING_CONFLICT|REPAIR_REQUIRED|AMBIGUOUS|APPLIED_PROPOSAL_FORBIDDEN|INVARIANT|SCHEMA|CONTRACT|CAPABILITY|NOT_FOUND)/', $code) === 1;
        $review = preg_match('/(?:CATEGORY_UNRESOLVED|EVIDENCE_REQUIRED|CANONICAL_EVIDENCE_REQUIRED|SUBJECT_UNRESOLVED|SOURCE_UNAVAILABLE|APPROVAL_REQUIRED|GOVERNANCE_APPROVAL_REQUIRED|DEPENDENCY_REQUIRED|REVIEW_REQUIRED)/', $code) === 1;
        $status = $blocked ? 'SYSTEM_BLOCKED' : ($review ? 'REVIEW_REQUIRED' : 'FAILED_RETRYABLE');
        $result = ['proposal_id' => (string) ($plan['proposal_id'] ?? ''), 'status' => $status, 'blockers' => [$code], 'error' => $error->getMessage()];
        if (str_contains($code, 'STAGING_SCOPE') || str_contains($rawMessage, 'STAGING_SCOPE')) $result['admission'] = $this->admissionDiagnostics($plan, $code);
        return $result;
    }

    /** @return array<string,mixed> */
    private function admissionDiagnostics(array $plan, string $failure): array
    {
        $payload = is_array($plan['payload'] ?? null) ? $plan['payload'] : [];
        return [
            'admission_owner' => (string) ($plan['entity_type'] ?? 'relation'),
            'operation' => (string) ($plan['operation'] ?? ''),
            'source_type' => (string) ($payload['source_type'] ?? ''),
            'source_id' => (string) ($payload['source_uuid'] ?? ''),
            'predicate' => (string) ($payload['predicate'] ?? ''),
            'target_type' => (string) ($payload['target_type'] ?? ''),
            'target_id' => (string) ($payload['target_uuid'] ?? ''),
            'parent_capture_id' => $this->currentCaptureId,
            'required_scope_kind' => (string) ($plan['entity_type'] ?? '') . ':' . (string) ($plan['operation'] ?? ''),
            'scope_resolution' => is_array($plan['payload']['staging_acceptance'] ?? null) ? 'DERIVED_FROM_CAPTURE' : 'MISSING_PARENT_PROVENANCE',
            'failure_reason' => $failure,
        ];
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

    /** @return array<string,mixed> */
    private function videoChildReceipt(array $plan, array $context, array $write): array
    {
        $readback = is_array($write['canonical_readback'] ?? null) ? $write['canonical_readback'] : [];
        $canonicalId = trim((string) ($write['canonical_id'] ?? $write['result_entity_uuid'] ?? ($readback['canonical_id'] ?? '')));
        return [
            'fingerprint' => $this->videoPlanFingerprint($plan, $context),
            'status' => (string) ($write['status'] ?? 'FAILED_RETRYABLE'),
            'canonical_id' => $canonicalId,
            'canonical_readback' => $readback !== [] ? $readback : null,
            'blockers' => (array) ($write['blockers'] ?? []),
        ];
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

    /** @return array<string,mixed>|null */
    private function reusedRelation(array $plan): ?array
    {
        if ($this->relationState === null) return null;
        try { $state = ($this->relationState)($plan); } catch (\Throwable) { return null; }
        if ($state === true) return ['entity_type' => 'relation', 'status' => 'REVIEW_REQUIRED', 'blockers' => ['RELATION_ACTIVE_READBACK_ID_UNAVAILABLE']];
        if (!is_array($state) || (strtoupper((string) ($state['status'] ?? '')) !== 'ACTIVE' && ($state['active'] ?? false) !== true)) return null;
        $canonicalId = trim((string) ($state['canonical_id'] ?? $state['id'] ?? ''));
        if ($canonicalId === '') return null;
        return [
            'entity_type' => 'relation', 'status' => 'REUSED_VERIFIED', 'canonical_id' => $canonicalId,
            'canonical_readback' => [
                'canonical_id' => $canonicalId, 'entity_type' => 'relation', 'active' => true,
                'revision' => (int) ($state['revision'] ?? 0),
            ],
            'idempotent' => true, 'reused' => true,
        ];
    }

    /** @return array<string,mixed> */
    private function arguments(string $entityType, string $operation, string $subjectId, array $payload, string $idempotencyKey, ?int $expectedRevision = null): array
    {
        $arguments = ['operation' => $operation, 'entity_type' => $entityType, 'subject_id' => $subjectId, 'payload' => $payload, 'idempotency_key' => $idempotencyKey];
        if ($expectedRevision !== null) $arguments['expected_revision'] = $expectedRevision;
        return $arguments;
    }

    private function proposalFromReview(string $id, array $review): Proposal
    {
        return new Proposal($id, (string) ($review['subject_id'] ?? ''), (string) ($review['operation'] ?? ''), (array) ($review['payload'] ?? []), (string) ($review['content_fingerprint'] ?? ''), isset($review['expected_revision']) ? (int) $review['expected_revision'] : null, (string) ($review['dependency_fingerprint'] ?? ''), ProposalState::from((string) ($review['state'] ?? 'submitted')), revision: (int) ($review['revision'] ?? 1), targetUuid: isset($review['target_uuid']) ? (string) $review['target_uuid'] : null, entityType: (string) ($review['entity_type'] ?? ''));
    }

    private function pending(Proposal $proposal, array $review): array
    {
        $payload = is_array($review['payload'] ?? null) ? $review['payload'] : $proposal->payload;
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $subject = is_array($metadata['subject_resolution_packet'] ?? null) ? $metadata['subject_resolution_packet'] : null;
        $source = is_array($metadata['source'] ?? null)
            ? $metadata['source']
            : (is_array($metadata['source_snapshot'] ?? null) ? $metadata['source_snapshot'] : []);
        $targetUuid = $review['target_uuid'] ?? $proposal->targetUuid;
        return [
            'proposal_id' => $proposal->id,
            'status' => 'REVIEW_REQUIRED',
            'proposal_state' => (string) ($review['state'] ?? $proposal->state->value),
            'entity_type' => $proposal->entityType,
            'operation' => $proposal->operation,
            'subject_id' => $proposal->subjectId,
            'target_uuid' => $targetUuid !== null && trim((string) $targetUuid) !== '' ? (string) $targetUuid : null,
            // A governed proposal is not a canonical owner read-back. Keep
            // this null until Controlled Apply and the owner read boundary
            // have both verified the Video identity.
            'canonical_id' => null,
            'semantic_subject' => $subject,
            'external_video' => [
                'platform' => (string) ($source['platform'] ?? ''),
                'external_video_id' => (string) ($source['external_video_id'] ?? ''),
            ],
            'content_fingerprint' => $proposal->contentFingerprint,
            'dependency_fingerprint' => $proposal->dependencyFingerprint,
        ];
    }

    private function applied(Proposal $proposal, array $applied): array
    {
        if (!is_array($applied['canonical_readback'] ?? null)) throw new \RuntimeException('CANONICAL_READBACK_VERIFICATION_FAILED');
        $canonicalId = (string) ($applied['canonical_id'] ?? $applied['result_entity_uuid'] ?? ($applied['canonical_readback']['canonical_id'] ?? ''));
        return ['proposal_id' => $proposal->id, 'entity_type' => $proposal->entityType, 'operation' => $proposal->operation, 'status' => 'APPLIED', 'canonical_id' => $canonicalId !== '' ? $canonicalId : null, 'canonical_readback' => $applied['canonical_readback'], 'idempotent' => (bool) ($applied['idempotent'] ?? false), 'completion' => $this->completion->finalize($proposal->entityType, $canonicalId, ['proposal_state' => 'applied', 'canonical_readback' => $applied['canonical_readback']])];
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
