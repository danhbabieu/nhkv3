<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

use NHK\Core\Contracts\Governance\GovernedLifecycle;

final class RelationBatchApplyOrchestrator
{
    /** @param callable(string):array<string,mixed> $apply
     *  @param callable(array<string,mixed>):bool $approvalPolicy */
    public function __construct(
        private GovernedLifecycle $governance,
        private $apply,
        private $approvalPolicy,
        private string $actor,
    ) {}

    /** @param list<array<string,mixed>> $candidates @return array{counters:array<string,int>,items:list<array<string,mixed>>} */
    public function run(array $candidates): array
    {
        $counters = ['created' => 0, 'idempotent' => 0, 'failed' => 0, 'denied' => 0];
        $items = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            $key = $this->idempotencyKey($candidate);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            if ((string) ($candidate['reason'] ?? '') !== 'STABLE_KEY_HIERARCHY' || (array_key_exists('confidence', $candidate) && (float) $candidate['confidence'] !== 1.0)) {
                $counters['failed']++;
                $items[] = ['idempotency_key' => $key, 'status' => 'failed', 'error' => 'NON_DETERMINISTIC_CANDIDATE'];
                continue;
            }
            try {
                $proposal = $this->governance->createFromArguments($this->proposalArguments($candidate, $key));
                $this->governance->submit($proposal->id);
                $review = $this->governance->review($proposal->id);
                if (($review['state'] ?? '') !== 'submitted') throw new \RuntimeException('PROPOSAL_NOT_SUBMITTED');
                if (!(bool) ($this->approvalPolicy)($review)) {
                    $counters['denied']++;
                    $items[] = ['idempotency_key' => $key, 'proposal_id' => $proposal->id, 'status' => 'denied', 'error' => 'MANUAL_APPROVAL_REQUIRED'];
                    continue;
                }
                $approved = $this->governance->approve($proposal->id, (string) ($review['content_fingerprint'] ?? ''), (string) ($review['dependency_fingerprint'] ?? ''), $this->actor);
                $eligibility = $this->governance->eligibility($approved->id);
                if (!(bool) ($eligibility['ready'] ?? false)) throw new \RuntimeException('PROPOSAL_NOT_ELIGIBLE');
                $applied = ($this->apply)($approved->id);
                if (!is_array($applied['canonical_readback'] ?? null)) throw new \RuntimeException('CANONICAL_READBACK_VERIFICATION_FAILED');
                $idempotent = (bool) ($applied['idempotent'] ?? false);
                $counters[$idempotent ? 'idempotent' : 'created']++;
                $items[] = ['idempotency_key' => $key, 'proposal_id' => $approved->id, 'status' => $idempotent ? 'idempotent' : 'created', 'readback' => $applied['canonical_readback']];
            } catch (\Throwable $error) {
                $counters['failed']++;
                $items[] = ['idempotency_key' => $key, 'status' => 'failed', 'error' => $error->getMessage()];
            }
        }
        return ['counters' => $counters, 'items' => $items];
    }

    private function idempotencyKey(array $candidate): string
    {
        return 'legacy-relation-remediation:' . (string) ($candidate['sourceUuid'] ?? '') . ':' . (string) ($candidate['predicate'] ?? '') . ':' . (string) ($candidate['targetUuid'] ?? '');
    }

    /** @return array<string,mixed> */
    private function proposalArguments(array $candidate, string $key): array
    {
        return [
            'operation' => 'relation_create',
            'entity_type' => 'relation',
            'subject_id' => 'relation',
            'payload' => [
                'source_type' => (string) ($candidate['sourceType'] ?? ''),
                'source_uuid' => (string) ($candidate['sourceUuid'] ?? ''),
                'target_type' => (string) ($candidate['targetType'] ?? ''),
                'target_uuid' => (string) ($candidate['targetUuid'] ?? ''),
                'predicate' => (string) ($candidate['predicate'] ?? ''),
                'evidence_refs' => [],
                'reason' => (string) ($candidate['reason'] ?? ''),
                'source_fingerprint' => hash('sha256', (string) ($candidate['sourceUuid'] ?? '') . '|' . (string) ($candidate['predicate'] ?? '') . '|' . (string) ($candidate['targetUuid'] ?? '')),
            ],
            'expected_revision' => null,
            'idempotency_key' => $key,
        ];
    }
}
