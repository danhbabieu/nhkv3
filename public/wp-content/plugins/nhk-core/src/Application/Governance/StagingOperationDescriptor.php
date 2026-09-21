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
        if ($entity === 'video') {
            unset($payload['capture_revision']);
            $payload = self::withoutVideoRetrievalVolatility($payload);
        }
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
            'knowledge:create', 'knowledge:ingest', 'knowledge:update', 'knowledge:retire' => 'knowledge_delta',
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

    /**
     * Test/debug-only structural comparison of the two normalized descriptors.
     * This is intentionally not exposed by an MCP or REST response.
     *
     * @return list<array{path:string,signed:mixed,verified:mixed}>
     */
    public static function diff(self $signed, self $verified): array
    {
        return self::diffValue($signed->debugValue(), $verified->debugValue());
    }

    /**
     * Return only bounded descriptor metadata for staging diagnostics. Payload
     * values are deliberately reduced to hashes and key names.
     *
     * @return array<string,mixed>
     */
    public function diagnosticValue(): array
    {
        return [
            'entrypoint' => $this->entrypoint,
            'capture_id' => $this->captureId,
            'capture_fingerprint' => $this->captureFingerprint,
            'entity_type' => $this->entityType,
            'operation' => $this->operation,
            'operation_family' => $this->operationFamily,
            'create_semantics' => $this->createSemantics,
            'subject_id' => $this->subjectId,
            'target_uuid' => $this->targetUuid,
            'proposed_uuid' => $this->proposedUuid,
            'expected_revision' => $this->expectedRevision,
            'idempotency_key' => $this->idempotencyKey,
            'payload_fingerprint' => $this->payloadFingerprint,
            'dependency_fingerprint' => $this->dependencyFingerprint,
            'payload_keys' => array_keys($this->payload),
        ];
    }

    /** @return array{path:string,signed_type:string,verified_type:string,signed_hash:string,verified_hash:string}[] */
    public static function diagnosticDiff(self $signed, self $verified): array
    {
        return array_map(static function (array $diff): array {
            $signed = $diff['signed'] ?? '<missing>';
            $verified = $diff['verified'] ?? '<missing>';
            return [
                'path' => (string) ($diff['path'] ?? ''),
                'signed_type' => get_debug_type($signed),
                'verified_type' => get_debug_type($verified),
                'signed_hash' => hash('sha256', CommandCanonicalizer::canonicalize($signed)),
                'verified_hash' => hash('sha256', CommandCanonicalizer::canonicalize($verified)),
            ];
        }, self::diff($signed, $verified));
    }

    /** @return array<string,mixed> */
    private function debugValue(): array
    {
        return [
            'entrypoint' => $this->entrypoint,
            'capture_id' => $this->captureId,
            'capture_fingerprint' => $this->captureFingerprint,
            'entity_type' => $this->entityType,
            'operation' => $this->operation,
            'operation_family' => $this->operationFamily,
            'create_semantics' => $this->createSemantics,
            'subject_id' => $this->subjectId,
            'target_uuid' => $this->targetUuid,
            'proposed_uuid' => $this->proposedUuid,
            'expected_revision' => $this->expectedRevision,
            'idempotency_key' => $this->idempotencyKey,
            'payload_fingerprint' => $this->payloadFingerprint,
            'dependency_fingerprint' => $this->dependencyFingerprint,
            'payload' => $this->payload,
        ];
    }

    /** @return list<array{path:string,signed:mixed,verified:mixed}> */
    private static function diffValue(mixed $signed, mixed $verified, string $path = '', bool $signedExists = true, bool $verifiedExists = true): array
    {
        if ($signedExists && $verifiedExists && is_array($signed) && is_array($verified)) {
            $diff = [];
            foreach (array_unique(array_merge(array_keys($signed), array_keys($verified)), SORT_REGULAR) as $key) {
                $keyPath = $path === '' ? (string) $key : $path . '.' . $key;
                $diff = array_merge($diff, self::diffValue($signed[$key] ?? null, $verified[$key] ?? null, $keyPath, array_key_exists($key, $signed), array_key_exists($key, $verified)));
            }
            return $diff;
        }
        if ($signedExists === $verifiedExists && $signed === $verified) return [];
        return [['path' => $path, 'signed' => $signedExists ? $signed : '<missing>', 'verified' => $verifiedExists ? $verified : '<missing>']];
    }

    /**
     * Source retrieval receipts are persisted for provenance, but are not
     * semantic Video command inputs. A retry must not require a new semantic
     * approval merely because the same source was fetched or thumbnail-probed
     * at a different time. Keep this normalization in the shared descriptor
     * so scope issuance and Proposal verification apply exactly the same law.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function withoutVideoRetrievalVolatility(array $payload): array
    {
        if (!is_array($payload['metadata'] ?? null)) return $payload;
        $metadata = $payload['metadata'];
        $source = is_array($metadata['source'] ?? null) ? $metadata['source'] : null;
        if ($source !== null) {
            unset($source['fetched_at'], $source['source_hash']);
            foreach (['thumbnail_selection', 'thumbnail_presentation'] as $key) {
                if (is_array($source[$key] ?? null)) unset($source[$key]['probed_at']);
            }
            $metadata['source'] = $source;
        }
        $payload['metadata'] = $metadata;
        return $payload;
    }
}
