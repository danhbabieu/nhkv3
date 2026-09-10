<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Application\Governance\GovernanceAutomationPolicyResolver;
use NHK\Core\Application\Semantic\ClaimReusePolicy;
use NHK\Core\Contracts\Governance\GovernedLifecycle;
use NHK\Core\Domain\Governance\{CommandCanonicalizer, Proposal, ProposalState};
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
    ) {}

    /** @return array<string,mixed> */
    public function execute(string $captureId, string $continuationKey, array $context, array $control = []): array
    {
        $proposalIds = array_values(array_filter(array_map('strval', (array) ($control['proposal_ids'] ?? [])), static fn (string $id): bool => UuidCodec::isValid($id)));
        $reusedClaims = $this->reusedClaims($context);
        $plans = $proposalIds !== [] ? array_map(static fn (string $id): array => ['proposal_id' => $id], $proposalIds) : $this->plans($captureId, $continuationKey, $context);
        if ($plans === []) return ['status' => 'REVIEW_REQUIRED', 'writes' => [], 'reused_claims' => $reusedClaims, 'blockers' => $reusedClaims === [] ? ['SEMANTIC_SUBJECT_OR_DELTA_REQUIRED'] : [], 'governance' => ['lifecycle' => [], 'status' => 'REVIEW_REQUIRED']];

        $writes = [];
        $lifecycle = [];
        foreach ($plans as $plan) {
            try {
                if (isset($plan['proposal_id'])) {
                    $review = $this->governance->review((string) $plan['proposal_id']);
                    $proposal = $this->proposalFromReview((string) $plan['proposal_id'], $review);
                } else {
                    $proposal = $this->governance->createFromArguments($plan);
                    $review = $this->governance->review($proposal->id);
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
                    if ($mode->value === 'REVIEW_REQUIRED' && !(bool) ($control['approval_confirmed'] ?? false)) {
                        $writes[] = $this->pending($proposal, $review);
                        continue;
                    }
                    if (!(bool) ($this->can)('nhk_internal_content_operations')) throw new \RuntimeException('INTERNAL_CAPABILITY_REQUIRED_FOR_CAPTURE_GOVERNANCE_APPROVAL');
                    $proposal = $this->governance->approve($proposal->id, (string) ($review['content_fingerprint'] ?? $proposal->contentFingerprint), (string) ($review['dependency_fingerprint'] ?? $proposal->dependencyFingerprint), $this->actor());
                    $lifecycle[] = 'APPROVE';
                }
                if ($proposal->state === ProposalState::APPLIED || $state === ProposalState::APPLIED->value) {
                    $applied = ($this->apply)($proposal->id);
                    $lifecycle[] = 'CONTROLLED_APPLY';
                    $writes[] = $this->applied($proposal, $applied);
                    continue;
                }
                $eligibility = $this->governance->eligibility($proposal->id);
                $lifecycle[] = 'ELIGIBILITY';
                if (($eligibility['ready'] ?? false) !== true) {
                    $writes[] = ['proposal_id' => $proposal->id, 'status' => 'SYSTEM_BLOCKED', 'blockers' => array_values(array_map('strval', (array) ($eligibility['reasons'] ?? ['PROPOSAL_NOT_ELIGIBLE'])))];
                    continue;
                }
                $applied = ($this->apply)($proposal->id);
                $lifecycle[] = 'CONTROLLED_APPLY';
                $writes[] = $this->applied($proposal, $applied);
            } catch (\Throwable $error) {
                $writes[] = ['proposal_id' => (string) ($plan['proposal_id'] ?? ''), 'status' => 'SYSTEM_BLOCKED', 'blockers' => [$error->getMessage()]];
            }
        }

        $pending = array_values(array_filter($writes, static fn (array $write): bool => ($write['status'] ?? '') === 'REVIEW_REQUIRED'));
        $blocked = array_values(array_filter($writes, static fn (array $write): bool => ($write['status'] ?? '') === 'SYSTEM_BLOCKED'));
        $applied = array_values(array_filter($writes, static fn (array $write): bool => ($write['status'] ?? '') === 'APPLIED'));
        $status = $blocked !== [] ? 'SYSTEM_BLOCKED' : ($pending !== [] ? 'REVIEW_REQUIRED' : 'APPLIED');
        return ['status' => $status, 'writes' => $writes, 'reused_claims' => $reusedClaims, 'blockers' => $blocked !== [] ? array_values(array_unique(array_merge(...array_map(static fn (array $write): array => (array) ($write['blockers'] ?? []), $blocked)))) : ($pending !== [] ? ['GOVERNANCE_APPROVAL_REQUIRED'] : []), 'governance' => ['lifecycle' => array_values(array_unique($lifecycle)), 'status' => $status, 'applied_count' => count($applied), 'pending_count' => count($pending)]];
    }

    /** @return list<array<string,mixed>> */
    private function plans(string $captureId, string $continuationKey, array $context): array
    {
        $resolved = is_array($context['subject_resolution']['resolved'] ?? null) ? $context['subject_resolution']['resolved'] : [];
        $variants = array_values(array_filter($resolved, static fn (mixed $item): bool => is_array($item) && ($item['type'] ?? '') === 'variant' && UuidCodec::isValid((string) ($item['id'] ?? ''))));
        if (count($variants) !== 1) return [];
        $variant = $variants[0];
        $plans = [];
        $deltaText = trim((string) ($context['continuation_delta_text'] ?? ''));
        $candidates = $deltaText !== ''
            ? [['text' => $deltaText, 'provenance' => 'EXPLICIT_USER_KNOWLEDGE']]
            : (array) ($context['interpretation']['user_claim_candidates'] ?? []);
        foreach ($candidates as $index => $candidate) {
            if (!is_array($candidate) || trim((string) ($candidate['text'] ?? '')) === '') continue;
            if ($this->claimReuse?->find(['text' => (string) $candidate['text'], 'subject_id' => (string) $variant['id'], 'scope' => 'variant'], $this->retrievedClaims($context)) !== null) continue;
            $payload = [
                'stable_key' => 'nhk:knowledge:capture.' . hash('sha256', CommandCanonicalizer::canonicalize([$captureId, $variant['id'], trim((string) $candidate['text'])])),
                'text' => trim((string) $candidate['text']),
                'claim_type' => 'fact',
                'provenance' => [
                    'metadata' => ['facet' => 'recognition', 'scope' => 'variant', 'version' => 1, 'subject_id' => $variant['id'], 'subject_type' => 'variant'],
                    'origin' => (string) ($candidate['provenance'] ?? 'EXPLICIT_USER_KNOWLEDGE'),
                ],
            ];
            $plans[] = $this->arguments('knowledge', 'ingest', (string) $variant['id'], $payload, 'capture:' . $captureId . ':knowledge:' . hash('sha256', (string) $payload['stable_key']));
        }
        foreach ((array) ($context['observations'] ?? []) as $index => $observation) {
            if (!is_array($observation)) continue;
            $componentId = trim((string) ($observation['component_id'] ?? ''));
            if (!UuidCodec::isValid($componentId)) continue;
            $payload = ['source_type' => 'variant', 'source_uuid' => (string) $variant['id'], 'target_type' => 'component', 'target_uuid' => $componentId, 'predicate' => 'about', 'origin' => 'EXPLICIT_USER_RELATION'];
            $plans[] = $this->arguments('relation', 'relation_create', 'relation', $payload, 'capture:' . $captureId . ':component:' . $componentId);
        }
        return $plans;
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
            $claim = $this->claimReuse->find(['text' => (string) ($candidate['text'] ?? ''), 'subject_id' => (string) ($variant['id'] ?? ''), 'scope' => 'variant'], $this->retrievedClaims($context));
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
