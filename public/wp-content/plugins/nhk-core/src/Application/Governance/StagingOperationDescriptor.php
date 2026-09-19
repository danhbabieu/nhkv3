<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Domain\Governance\CommandCanonicalizer;
use NHK\Core\Domain\Governance\Proposal;

/**
 * The one normalized identity shared by staging issuance and verification.
 * Authorization fields are deliberately excluded from command fingerprints.
 */
final readonly class StagingOperationDescriptor
{
    /** @param array<string,mixed> $payload @param array<string,mixed> $source */
    private function __construct(
        public string $entrypoint,
        public string $captureId,
        public string $captureFingerprint,
        public string $entityType,
        public string $operation,
        public string $operationFamily,
        public ?string $createSemantics,
        public string $subjectId,
        public ?string $targetUuid,
        public ?string $proposedUuid,
        public ?int $expectedRevision,
        public string $idempotencyKey,
        public string $payloadFingerprint,
        public string $dependencyFingerprint,
        public array $payload,
    ) {}

    /** @param array<string,mixed> $plan */
    public static function fromPlan(array $plan, string $captureId, string $captureFingerprint): self
    {
        $entity = strtolower(trim((string) ($plan['entity_type'] ?? '')));
        $operation = strtolower(trim((string) ($plan['operation'] ?? '')));
        $payload = is_array($plan['payload'] ?? null) ? $plan['payload'] : [];
        $payload['capture_id'] = $captureId;
        $payload['capture_fingerprint'] = $captureFingerprint;
        $payload = self::withoutAuthorization($payload);
        $dependencies = array_values(array_unique(array_map('strval', (array) ($payload['dependency_ids'] ?? $plan['dependency_ids'] ?? []))));
        sort($dependencies, SORT_STRING);
        $family = self::family($entity, $operation);
        $expected = array_key_exists('expected_revision', $plan) && $plan['expected_revision'] !== null ? (int) $plan['expected_revision'] : null;
        if ($operation === 'ingest' && in_array($entity, ['source', 'knowledge', 'evidence', 'video'], true)) $expected = 0;
        return new self(
            'nhk.capture.ingest', $captureId, $captureFingerprint, $entity, $operation, $family,
            $operation === 'ingest' ? 'ingest' : null,
            (string) ($plan['subject_id'] ?? ''),
            isset($plan['target_uuid']) && trim((string) $plan['target_uuid']) !== '' ? (string) $plan['target_uuid'] : null,
            isset($plan['proposed_uuid']) && trim((string) $plan['proposed_uuid']) !== '' ? (string) $plan['proposed_uuid'] : (isset($payload['canonical_id']) ? (string) $payload['canonical_id'] : null),
            $expected,
            (string) ($plan['idempotency_key'] ?? ''),
            hash('sha256', CommandCanonicalizer::canonicalize($payload)),
            hash('sha256', CommandCanonicalizer::canonicalize($dependencies)),
            $payload,
        );
    }

    /** @param array<string,mixed> $plan */
    public static function fromProposal(Proposal $proposal, array $scope): self
    {
        $captureId = (string) ($scope['capture_id'] ?? $proposal->payload['capture_id'] ?? '');
        $captureFingerprint = (string) ($scope['capture_fingerprint'] ?? $proposal->payload['capture_fingerprint'] ?? '');
        return self::fromPlan([
            'entity_type' => $proposal->entityType,
            'operation' => $proposal->operation,
            'subject_id' => $proposal->subjectId,
            'target_uuid' => $proposal->targetUuid,
            'expected_revision' => $proposal->expectedRevision,
            'idempotency_key' => $proposal->idempotencyKey,
            'payload' => $proposal->payload,
        ], $captureId, $captureFingerprint);
    }

    public static function family(string $entityType, string $operation): string
    {
        return match ($entityType . ':' . $operation) {
            'source:create', 'source:ingest', 'source:update', 'evidence:create', 'evidence:ingest', 'evidence:update' => 'source_evidence_reconciliation',
            'knowledge:create', 'knowledge:ingest', 'knowledge:update' => 'knowledge_delta',
            'video:ingest', 'video:update', 'video:retire', 'video:reactivate' => 'governed_video_plan',
            'video:source_refresh' => 'video_source_refresh',
            'relation:relation_create' => 'capture_child_relation',
            default => '',
        };
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    public static function withoutAuthorization(array $value): array
    {
        foreach (['staging_acceptance', 'signature', 'fingerprint', 'approved', 'scope_fingerprint', 'proposal_command_fingerprint'] as $key) unset($value[$key]);
        return $value;
    }
}
