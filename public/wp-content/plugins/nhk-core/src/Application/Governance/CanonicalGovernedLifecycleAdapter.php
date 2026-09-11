<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Contracts\Governance\GovernedLifecycle;
use NHK\Core\Domain\Governance\{CommandCanonicalizer, Proposal, ProposalState};
use NHK\Core\Shared\Uuid\UuidCodec;

/** Internal adapter for application services that need the canonical lifecycle without MCP transport. */
final class CanonicalGovernedLifecycleAdapter implements GovernedLifecycle
{
    public function __construct(private GovernanceService $governance, private ProposalEligibilityService $eligibility) {}

    public function createFromArguments(array $arguments): Proposal
    {
        $operation = trim((string) ($arguments['operation'] ?? ''));
        $entityType = trim((string) ($arguments['entity_type'] ?? ''));
        $subjectId = trim((string) ($arguments['subject_id'] ?? '')) ?: $entityType;
        $payload = is_array($arguments['payload'] ?? null) ? $arguments['payload'] : [];
        $targetUuid = trim((string) ($arguments['target_uuid'] ?? '')) ?: null;
        $expectedRevision = array_key_exists('expected_revision', $arguments) && $arguments['expected_revision'] !== null ? (int) $arguments['expected_revision'] : null;
        $dependencies = array_values(array_filter(array_map('strval', (array) ($arguments['dependency_ids'] ?? [])), static fn (string $id): bool => UuidCodec::isValid($id)));
        $binding = ['operation' => $operation, 'entity_type' => $entityType, 'subject_id' => $subjectId, 'target_uuid' => $targetUuid, 'expected_revision' => $expectedRevision, 'payload' => $payload, 'dependency_ids' => $dependencies];
        $content = trim((string) ($arguments['content_fingerprint'] ?? '')) ?: hash('sha256', CommandCanonicalizer::canonicalize($binding));
        $dependency = trim((string) ($arguments['dependency_fingerprint'] ?? '')) ?: hash('sha256', CommandCanonicalizer::canonicalize($dependencies));
        $idempotency = trim((string) ($arguments['idempotency_key'] ?? '')) ?: 'video-reconcile-' . hash('sha256', CommandCanonicalizer::canonicalize($binding));
        return $this->governance->create(new Proposal(UuidCodec::newV7(), $subjectId, $operation, $payload, $content, $expectedRevision, $dependency, idempotencyKey: $idempotency, targetUuid: $targetUuid, entityType: $entityType));
    }

    public function submit(string $id): Proposal { return $this->governance->submit($id); }
    public function review(string $id): array
    {
        $proposal = $this->governance->review($id);
        return ['proposal_id' => $proposal->id, 'state' => $proposal->state->value, 'entity_type' => $proposal->entityType, 'operation' => $proposal->operation, 'subject_id' => $proposal->subjectId, 'target_uuid' => $proposal->targetUuid, 'payload' => $proposal->payload, 'expected_revision' => $proposal->expectedRevision, 'revision' => $proposal->revision, 'content_fingerprint' => $proposal->contentFingerprint, 'dependency_fingerprint' => $proposal->dependencyFingerprint];
    }
    public function approve(string $id, string $contentFingerprint, string $dependencyFingerprint, string $actor): Proposal { return $this->governance->approve($id, $contentFingerprint, $dependencyFingerprint, $actor); }
    public function eligibility(string $id): array
    {
        $result = $this->eligibility->check($id);
        return ['proposal_id' => $id, 'ready' => $result->ready, 'reasons' => $result->reasons];
    }
}
