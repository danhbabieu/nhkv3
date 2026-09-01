<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Application\Governance\ControlledApplyService;
use NHK\Core\Application\Governance\ProposalEligibilityService;
use NHK\Core\Domain\Governance\Proposal;
use NHK\Core\Domain\Governance\CommandCanonicalizer;
use NHK\Core\Shared\Uuid\UuidCodec;

final class McpGovernanceHandler
{
    public function __construct(private GovernanceService $governance, private ?ProposalEligibilityService $eligibility = null, private ?ControlledApplyService $apply = null) {}

    public function create(Proposal $proposal): Proposal { return $this->governance->create($proposal); }
    public function createFromArguments(array $arguments): Proposal
    {
        $operation = (string) ($arguments['operation'] ?? '');
        $entityType = (string) ($arguments['entity_type'] ?? '');
        $subjectId = (string) ($arguments['subject_id'] ?? '');
        if ($subjectId === '' && in_array($operation, ['create', 'ingest', 'relation_create'], true)) $subjectId = $entityType !== '' ? $entityType : 'relation';
        $payload = is_array($arguments['payload'] ?? null) ? $arguments['payload'] : [];
        $expectedRevision = max(1, (int) ($arguments['expected_revision'] ?? 1));
        $targetUuid = isset($arguments['target_uuid']) ? trim((string) $arguments['target_uuid']) : null;
        $targetUuid = $targetUuid !== '' ? $targetUuid : null;
        $dependencyIds = is_array($arguments['dependency_ids'] ?? null) ? array_values(array_filter(array_map('strval', $arguments['dependency_ids']))) : [];
        $binding = ['operation' => $operation, 'entity_type' => $entityType, 'subject_id' => $subjectId, 'target_uuid' => $targetUuid, 'expected_revision' => $expectedRevision, 'payload' => $payload, 'dependency_ids' => $dependencyIds];
        $contentFingerprint = trim((string) ($arguments['content_fingerprint'] ?? '')) ?: hash('sha256', CommandCanonicalizer::canonicalize($binding));
        $dependencyFingerprint = trim((string) ($arguments['dependency_fingerprint'] ?? '')) ?: hash('sha256', CommandCanonicalizer::canonicalize($dependencyIds));
        $idempotencyKey = trim((string) ($arguments['idempotency_key'] ?? '')) ?: 'mcp-' . hash('sha256', CommandCanonicalizer::canonicalize($binding));
        return $this->create(new Proposal(
            UuidCodec::newV7(),
            $subjectId,
            $operation,
            $payload,
            $contentFingerprint,
            $expectedRevision,
            $dependencyFingerprint,
            actor: function_exists('get_current_user_id') ? (string) get_current_user_id() : '0',
            idempotencyKey: $idempotencyKey,
            targetUuid: $targetUuid,
            entityType: $entityType,
        ));
    }
    public function submit(string $id): Proposal { return $this->governance->submit($id); }
    public function approve(string $id, string $contentFingerprint, string $dependencyFingerprint, string $actor): Proposal { return $this->governance->approve($id, $contentFingerprint, $dependencyFingerprint, $actor); }
    public function reject(string $id, string $actor): Proposal { return $this->governance->reject($id, $actor); }
    public function eligibility(string $id): array
    {
        if (!$this->eligibility) throw new \RuntimeException('Eligibility service is not configured.');
        $result = $this->eligibility->check($id);
        return ['proposal_id' => $id, 'ready' => $result->ready, 'reasons' => $result->reasons];
    }
    public function apply(string $id): array
    {
        if (!$this->apply) throw new \RuntimeException('Controlled Apply service is not configured.');
        return $this->apply->apply($id);
    }
}
