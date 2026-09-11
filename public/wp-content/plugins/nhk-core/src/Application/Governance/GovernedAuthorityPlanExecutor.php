<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Application\Mcp\McpGovernanceHandler;
use NHK\Core\Domain\Governance\{CommandCanonicalizer, ConversationalAuthorityPolicy, ProposalState};

/** Materializes only the exact, owner-approved plan through Governance. */
final class GovernedAuthorityPlanExecutor
{
    public function __construct(private McpGovernanceHandler $governance) {}

    /** @param array<string,mixed> $plan @param list<string> $approvedCandidateIds @return array<string,mixed> */
    public function execute(array $plan, string $approvedFingerprint, string $currentFingerprint, array $approvedCandidateIds, ConversationalAuthorityPolicy $policy, string $actor = '0'): array
    {
        if (!hash_equals($approvedFingerprint, $currentFingerprint)) return ['status' => 'PLAN_REAPPROVAL_REQUIRED', 'code' => 'PLAN_REAPPROVAL_REQUIRED', 'proposal_ids' => [], 'approved_candidate_ids' => []];
        if ($policy === ConversationalAuthorityPolicy::OFF) return ['status' => 'AUTHORITY_AUTOMATION_DISABLED', 'code' => 'AUTHORITY_AUTOMATION_DISABLED', 'proposal_ids' => [], 'approved_candidate_ids' => []];
        $candidates = $this->candidates($plan);
        $byId = [];
        foreach ($candidates as $candidate) if (isset($candidate['candidate_id'])) $byId[(string) $candidate['candidate_id']] = $candidate;
        foreach ($approvedCandidateIds as $candidateId) if (!isset($byId[(string) $candidateId])) return ['status' => 'APPROVED_CANDIDATE_UNKNOWN', 'code' => 'APPROVED_CANDIDATE_UNKNOWN', 'candidate_id' => (string) $candidateId, 'proposal_ids' => [], 'approved_candidate_ids' => []];
        $selected = array_values(array_filter($approvedCandidateIds, static fn (mixed $id): bool => isset($byId[(string) $id])));
        foreach ($selected as $candidateId) foreach ((array) ($byId[(string) $candidateId]['dependencies'] ?? []) as $dependency) if (!in_array((string) $dependency, $selected, true)) return ['status' => 'APPROVED_DEPENDENCY_MISSING', 'code' => 'APPROVED_DEPENDENCY_MISSING', 'candidate_id' => (string) $candidateId, 'dependency_id' => (string) $dependency, 'proposal_ids' => [], 'approved_candidate_ids' => $selected];

        $proposalIds = []; $pendingIds = []; $bindings = [];
        foreach ($selected as $candidateId) {
            $candidate = $byId[(string) $candidateId];
            if (strtoupper((string) ($candidate['action'] ?? '')) === 'REUSE') continue;
            $proposal = $this->governance->createFromArguments($this->proposalArguments($candidate, $approvedFingerprint, $actor));
            if ($proposal->state === ProposalState::DRAFT) $proposal = $this->governance->submit($proposal->id);
            $proposalIds[] = $proposal->id;
            if ($proposal->state !== ProposalState::APPLIED) { $pendingIds[] = $proposal->id; $bindings[$proposal->id] = [$proposal->contentFingerprint, $proposal->dependencyFingerprint, $proposal->state]; }
        }
        $applyResults = [];
        if ($policy === ConversationalAuthorityPolicy::AUTO_APPROVE_AFTER_OWNER_CONFIRMATION) {
            foreach ($pendingIds as $proposalId) {
                [$contentFingerprint, $dependencyFingerprint, $state] = $bindings[$proposalId];
                if ($state === ProposalState::SUBMITTED) $this->governance->approve($proposalId, $contentFingerprint, $dependencyFingerprint, $actor);
            }
            $applyResults = $this->governance->applyMany($pendingIds);
        }
        return ['status' => $policy === ConversationalAuthorityPolicy::REVIEW_REQUIRED ? 'REVIEW_REQUIRED' : 'APPLIED', 'proposal_ids' => $proposalIds, 'approved_candidate_ids' => $selected, 'apply_results' => $applyResults, 'idempotent' => false];
    }

    /** @return list<array<string,mixed>> */
    private function candidates(array $plan): array
    {
        $all = [];
        foreach (['reuse', 'create_candidates', 'update_candidates', 'relation_candidates'] as $bucket) foreach ((array) ($plan[$bucket] ?? []) as $candidate) if (is_array($candidate)) $all[] = $candidate;
        return $all;
    }

    /** @return array<string,mixed> */
    private function proposalArguments(array $candidate, string $planFingerprint, string $actor): array
    {
        $action = strtoupper((string) ($candidate['action'] ?? 'CREATE'));
        $isRelation = isset($candidate['predicate']) || isset($candidate['source_type']);
        $operation = $isRelation ? 'relation_create' : (strtolower($action) === 'create' ? 'create' : strtolower($action));
        $entityType = $isRelation ? 'relation' : (string) ($candidate['entity_type'] ?? '');
        $payload = $candidate;
        $payload['candidate_id'] = (string) ($candidate['candidate_id'] ?? '');
        if (!$isRelation) $payload = ['candidate_id' => $payload['candidate_id'], 'stable_key' => (string) ($candidate['stable_key_preview'] ?? ''), 'name' => (string) ($candidate['proposed_canonical_name'] ?? ''), 'entity_payload' => array_filter(['family' => $candidate['family'] ?? null], static fn (mixed $value): bool => $value !== null && $value !== '')];
        $contentFingerprint = hash('sha256', CommandCanonicalizer::canonicalize($payload));
        $dependencyIds = array_values(array_filter(array_map('strval', (array) ($candidate['dependencies'] ?? []))));
        return ['operation' => $operation, 'entity_type' => $entityType, 'subject_id' => (string) ($candidate['canonical_uuid'] ?? $candidate['source_uuid'] ?? $entityType), 'payload' => $payload, 'content_fingerprint' => $contentFingerprint, 'dependency_fingerprint' => hash('sha256', CommandCanonicalizer::canonicalize($dependencyIds)), 'dependency_ids' => $dependencyIds, 'idempotency_key' => 'authority-plan:' . $planFingerprint . ':' . (string) ($candidate['candidate_id'] ?? ''), 'actor' => $actor];
    }
}
