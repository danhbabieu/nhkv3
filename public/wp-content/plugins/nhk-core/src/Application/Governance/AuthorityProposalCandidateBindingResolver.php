<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Domain\Governance\Proposal;

/**
 * Reconstructs only an exact Authority-plan candidate binding from persisted
 * Capture governance state. It never invents or selects an arbitrary candidate.
 */
final class AuthorityProposalCandidateBindingResolver
{
    /** @return array{status:string,candidate_id:string,candidate:array<string,mixed>,plan_fingerprint:string} */
    public function resolve(Proposal $proposal, CaptureRecord $capture): array
    {
        $audit = is_array($proposal->payload['project_build_audit'] ?? null) ? $proposal->payload['project_build_audit'] : [];
        $proposalFingerprint = trim((string) ($audit['plan_fingerprint'] ?? ''));
        $plan = is_array($capture->context['authority_plan'] ?? null) ? $capture->context['authority_plan'] : [];
        $captureFingerprint = trim((string) ($plan['plan_fingerprint'] ?? $capture->context['plan_fingerprint'] ?? ''));
        if ($proposalFingerprint === '' || !hash_equals(strtolower($captureFingerprint), strtolower($proposalFingerprint))) {
            throw new \RuntimeException('STAGING_PLAN_SCOPE_MISMATCH');
        }

        $approved = is_array($capture->context['authority_result'] ?? null) ? $capture->context['authority_result'] : [];
        $approvedFingerprint = trim((string) ($approved['approved_plan_fingerprint'] ?? ''));
        if ($approvedFingerprint === '' || !hash_equals(strtolower($approvedFingerprint), strtolower($proposalFingerprint))) {
            throw new \RuntimeException('LEGACY_PROPOSAL_MISSING_CANDIDATE_BINDING');
        }
        $approvedIds = array_values(array_unique(array_filter(array_map('strval', (array) ($approved['approved_candidate_ids'] ?? [])))));

        $candidates = [];
        foreach (['reuse', 'create_candidates', 'update_candidates', 'relation_candidates', 'relation_reuse'] as $bucket) {
            foreach ((array) ($plan[$bucket] ?? []) as $candidate) {
                if (is_array($candidate) && trim((string) ($candidate['candidate_id'] ?? '')) !== '') {
                    $candidates[(string) $candidate['candidate_id']] = $candidate;
                }
            }
        }

        $explicitId = trim((string) ($proposal->payload['candidate_id'] ?? ''));
        if ($explicitId !== '') {
            if (!in_array($explicitId, $approvedIds, true) || !isset($candidates[$explicitId]) || !$this->matches($proposal, $candidates[$explicitId])) {
                throw new \RuntimeException('STAGING_CANDIDATE_SCOPE_MISMATCH');
            }
            return ['status' => 'PERSISTED_PROPOSAL_BINDING', 'candidate_id' => $explicitId, 'candidate' => $candidates[$explicitId], 'plan_fingerprint' => $proposalFingerprint];
        }

        $matches = [];
        foreach ($approvedIds as $candidateId) {
            $candidate = $candidates[$candidateId] ?? null;
            if (is_array($candidate) && $this->matches($proposal, $candidate)) $matches[$candidateId] = $candidate;
        }
        if (count($matches) === 1) {
            $candidateId = (string) array_key_first($matches);
            return ['status' => 'RECOVERED_FROM_PERSISTED_CAPTURE', 'candidate_id' => $candidateId, 'candidate' => $matches[$candidateId], 'plan_fingerprint' => $proposalFingerprint];
        }
        if (count($matches) > 1) throw new \RuntimeException('STAGING_CANDIDATE_SCOPE_AMBIGUOUS');
        throw new \RuntimeException('LEGACY_PROPOSAL_MISSING_CANDIDATE_BINDING');
    }

    /** @param array<string,mixed> $candidate */
    private function matches(Proposal $proposal, array $candidate): bool
    {
        $action = strtolower(trim((string) ($candidate['action'] ?? '')));
        $operation = $action === 'remove' ? 'retire' : ($action === 'reactivate' ? 'reactivate' : $action);
        if ($operation !== $proposal->operation || (string) ($candidate['entity_type'] ?? '') !== $proposal->entityType) return false;
        $subject = (string) ($candidate['canonical_uuid'] ?? $candidate['source_uuid'] ?? $candidate['source_id'] ?? $candidate['entity_type'] ?? '');
        $target = (string) ($candidate['canonical_uuid'] ?? $candidate['target_uuid'] ?? '');
        if ($subject !== $proposal->subjectId || $target !== (string) ($proposal->targetUuid ?? '')) return false;
        $expected = $candidate['expected_revision'] ?? $candidate['canonical_revision'] ?? null;
        return $expected === null || (int) $expected === (int) $proposal->expectedRevision;
    }
}
