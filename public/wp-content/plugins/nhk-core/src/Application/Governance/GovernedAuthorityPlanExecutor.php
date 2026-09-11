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

        $proposalIds = [];
        $applyResults = [];
        $authorityProposalIds = [];
        $authorityProposalCandidates = [];
        $relationCandidates = [];
        foreach ($selected as $candidateId) {
            $candidate = $byId[(string) $candidateId];
            if (strtoupper((string) ($candidate['action'] ?? '')) === 'REUSE') continue;
            if ($this->isRelation($candidate)) {
                $relationCandidates[] = $candidate;
                continue;
            }
            $proposal = $this->createAndSubmit($candidate, $approvedFingerprint, $actor);
            $proposalIds[] = $proposal->id;
            $authorityProposalIds[] = $proposal->id;
            $authorityProposalCandidates[$proposal->id] = $candidate;
        }

        if ($policy === ConversationalAuthorityPolicy::AUTO_APPROVE_AFTER_OWNER_CONFIRMATION) {
            $applyResults = $this->approveAndApply($authorityProposalIds, $actor);
            $resolved = $this->canonicalEndpoints($applyResults, $authorityProposalCandidates);
        } else {
            $resolved = [];
        }

        $relationProposalIds = [];
        foreach ($relationCandidates as $candidate) {
            try {
                $candidate = $this->bindCreatedEndpoints($candidate, $resolved);
            } catch (\InvalidArgumentException $error) {
                if ($policy === ConversationalAuthorityPolicy::REVIEW_REQUIRED) return [
                    'status' => 'REVIEW_REQUIRED', 'proposal_ids' => $proposalIds,
                    'approved_candidate_ids' => $selected, 'apply_results' => $applyResults,
                    'blockers' => [['code' => 'RELATION_DEPENDENCY_CANONICAL_READBACK_REQUIRED', 'candidate_id' => $candidate['candidate_id'] ?? null]],
                    'idempotent' => false,
                ];
                throw $error;
            }
            $proposal = $this->createAndSubmit($candidate, $approvedFingerprint, $actor);
            $proposalIds[] = $proposal->id;
            $relationProposalIds[] = $proposal->id;
        }

        if ($policy === ConversationalAuthorityPolicy::AUTO_APPROVE_AFTER_OWNER_CONFIRMATION) $applyResults = array_merge($applyResults, $this->approveAndApply($relationProposalIds, $actor));
        return ['status' => $policy === ConversationalAuthorityPolicy::REVIEW_REQUIRED ? 'REVIEW_REQUIRED' : 'APPLIED', 'proposal_ids' => $proposalIds, 'approved_candidate_ids' => $selected, 'apply_results' => $applyResults, 'idempotent' => false];
    }

    private function isRelation(array $candidate): bool
    {
        return isset($candidate['predicate']) || isset($candidate['source_type']);
    }

    private function createAndSubmit(array $candidate, string $fingerprint, string $actor): \NHK\Core\Domain\Governance\Proposal
    {
        $proposal = $this->governance->createFromArguments($this->proposalArguments($candidate, $fingerprint, $actor));
        return $proposal->state === ProposalState::DRAFT ? $this->governance->submit($proposal->id) : $proposal;
    }

    /** @param list<string> $proposalIds @return list<array<string,mixed>> */
    private function approveAndApply(array $proposalIds, string $actor): array
    {
        if ($proposalIds === []) return [];
        foreach ($proposalIds as $proposalId) {
            $proposal = $this->governance->review($proposalId);
            if (($proposal['state'] ?? '') === ProposalState::SUBMITTED->value) $this->governance->approve($proposalId, (string) $proposal['content_fingerprint'], (string) $proposal['dependency_fingerprint'], $actor);
        }
        return $this->governance->applyMany($proposalIds);
    }

    /** @param list<array<string,mixed>> $results @param array<string,array<string,mixed>> $candidates @return array<string,array{uuid:string,revision:int}> */
    private function canonicalEndpoints(array $results, array $candidates): array
    {
        $resolved = [];
        foreach ($results as $result) {
            $candidate = $candidates[(string) ($result['proposal_id'] ?? '')] ?? null;
            $candidateId = is_array($candidate) ? (string) ($candidate['candidate_id'] ?? '') : '';
            $uuid = trim((string) ($result['result_entity_uuid'] ?? ''));
            if ($candidateId === '' || $uuid === '') continue;
            $resolved[$candidateId] = ['uuid' => $uuid, 'revision' => max(1, (int) ($result['canonical_readback']['revision'] ?? 1))];
        }
        return $resolved;
    }

    /** @param array<string,mixed> $candidate @param array<string,array{uuid:string,revision:int}> $resolved @return array<string,mixed> */
    private function bindCreatedEndpoints(array $candidate, array $resolved): array
    {
        foreach (['source', 'target'] as $side) {
            $candidateKey = trim((string) ($candidate[$side . '_candidate_id'] ?? ''));
            if ($candidateKey === '') continue;
            if (!isset($resolved[$candidateKey])) throw new \InvalidArgumentException('RELATION_DEPENDENCY_CANONICAL_READBACK_REQUIRED');
            $candidate[$side . '_uuid'] = $resolved[$candidateKey]['uuid'];
            $candidate[$side . '_revision'] = $resolved[$candidateKey]['revision'];
        }
        return $candidate;
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
