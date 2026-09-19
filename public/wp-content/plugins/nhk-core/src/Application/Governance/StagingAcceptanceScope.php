<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Domain\Governance\{CommandCanonicalizer, Proposal};
use NHK\Core\Shared\Uuid\UuidCodec;

/** Exact binding rules for a verified staging Capture acceptance scope. */
final class StagingAcceptanceScope
{
    /** @var array<string,string> */
    private const OPERATION_FAMILIES = [
        'authority:create' => 'governed_authority_plan',
        'authority:ingest' => 'governed_authority_plan',
        'authority:update' => 'governed_authority_plan',
        'authority:rename' => 'governed_authority_plan',
        'authority:rekey' => 'governed_authority_plan',
        'authority:merge' => 'governed_authority_plan',
        'authority:retire' => 'governed_authority_plan',
        'authority:reactivate' => 'governed_authority_plan',
        'media:representative_bind' => 'media_usage_reconciliation',
        'media:add' => 'media_usage_reconciliation',
        'media:replace' => 'media_usage_reconciliation',
        'media:remove' => 'media_usage_reconciliation',
        'media:ingest' => 'media_usage_reconciliation',
        'media:update' => 'media_metadata_reconciliation',
        'relation:relation_create' => 'governed_relation_reconciliation',
        'relation:relation_retire' => 'governed_relation_reconciliation',
        'relation:relation_reactivate' => 'governed_relation_reconciliation',
        'knowledge:create' => 'knowledge_delta',
        'knowledge:ingest' => 'knowledge_delta',
        'knowledge:update' => 'knowledge_delta',
        'source:create' => 'source_evidence_reconciliation',
        'source:ingest' => 'source_evidence_reconciliation',
        'evidence:create' => 'source_evidence_reconciliation',
        'evidence:ingest' => 'source_evidence_reconciliation',
        'video:update' => 'governed_video_plan',
        'video:ingest' => 'governed_video_plan',
        'video:source_refresh' => 'video_source_refresh',
        'video:retire' => 'governed_video_plan',
        'video:reactivate' => 'governed_video_plan',
    ];

    /** @param array<string,mixed> $scope */
    public static function assertProposal(Proposal $proposal, array $scope): void
    {
        if (($scope['approved'] ?? false) !== true) throw new \RuntimeException('STAGING_SCOPE_NOT_APPROVED');
        $captureId = trim((string) ($scope['capture_id'] ?? ''));
        if (!UuidCodec::isValid($captureId)) throw new \RuntimeException('STAGING_CAPTURE_SCOPE_INVALID');
        $audit = is_array($proposal->payload['project_build_audit'] ?? null) ? $proposal->payload['project_build_audit'] : [];
        $proposalCaptureId = trim((string) ($audit['capture_id'] ?? $proposal->payload['capture_id'] ?? ''));
        if ($proposalCaptureId === '' || !hash_equals(strtolower($captureId), strtolower($proposalCaptureId))) throw new \RuntimeException('STAGING_CAPTURE_SCOPE_MISMATCH');

        $operationKey = $proposal->entityType . ':' . $proposal->operation;
        // Keep the executable operation vocabulary in one place. The legacy
        // table remains for non-Capture operations, while Capture-owned
        // Source/Knowledge/Evidence/Video commands use the same descriptor
        // as issuance and admission.
        $expectedFamily = StagingOperationDescriptor::family($proposal->entityType, $proposal->operation)
            ?: (self::OPERATION_FAMILIES[$operationKey] ?? (self::isAuthorityEntity($proposal->entityType) && in_array($proposal->operation, ['create', 'ingest', 'update', 'rename', 'rekey', 'merge', 'retire', 'reactivate'], true) ? 'governed_authority_plan' : null));
        $authorityPlanPacket = (string) ($scope['operation_family'] ?? '') === 'governed_authority_plan' && is_array($scope['candidate_bindings'] ?? null);
        if ($expectedFamily === null || ((string) ($scope['operation_family'] ?? '') !== $expectedFamily && !$authorityPlanPacket)) throw new \RuntimeException('STAGING_OPERATION_SCOPE_MISMATCH');
        if ((string) ($scope['writer'] ?? '') !== 'canonical_governed') throw new \RuntimeException('STAGING_DIRECT_WRITER_BLOCKED');

        if ($expectedFamily === 'governed_authority_plan' || $authorityPlanPacket) {
            self::assertAuthorityBinding($proposal, $scope);
            self::assertNoFuzzyLocator($scope);
            return;
        }
        if ((string) ($scope['entity_type'] ?? '') !== $proposal->entityType || (string) ($scope['operation'] ?? '') !== $proposal->operation) throw new \RuntimeException('STAGING_OPERATION_SCOPE_MISMATCH');

        if ($expectedFamily === 'video_source_refresh') {
            if ((string) ($scope['writer'] ?? '') !== 'canonical_governed'
                || (string) ($scope['entity_type'] ?? '') !== 'video'
                || (string) ($scope['operation'] ?? '') !== 'source_refresh'
                || (string) ($scope['target_uuid'] ?? '') !== (string) ($proposal->targetUuid ?? $proposal->subjectId)
                || (int) ($scope['expected_revision'] ?? -1) !== (int) $proposal->expectedRevision
                || !hash_equals((string) ($scope['request_fingerprint'] ?? ''), (string) ($proposal->payload['request_fingerprint'] ?? ''))) {
                throw new \RuntimeException('STAGING_VIDEO_SOURCE_REFRESH_SCOPE_MISMATCH');
            }
            return;
        }

        if ($expectedFamily === 'governed_video_plan') {
            $proposalCaptureId = trim((string) ($proposal->payload['capture_id'] ?? ''));
            if ($proposalCaptureId === '' || !hash_equals($captureId, $proposalCaptureId)) throw new \RuntimeException('STAGING_CAPTURE_SCOPE_MISMATCH');
            if (!hash_equals((string) ($scope['capture_fingerprint'] ?? ''), (string) ($proposal->payload['capture_fingerprint'] ?? ''))) throw new \RuntimeException('STAGING_CAPTURE_FINGERPRINT_MISMATCH');
            if ((string) ($scope['entity_type'] ?? '') !== 'video' || (string) ($scope['operation'] ?? '') !== $proposal->operation) throw new \RuntimeException('STAGING_OPERATION_SCOPE_MISMATCH');
            $scopeTarget = (string) ($scope['target_uuid'] ?? $scope['proposed_uuid'] ?? '');
            if ($scopeTarget !== (string) ($proposal->targetUuid ?? $proposal->subjectId)) throw new \RuntimeException('STAGING_TARGET_SCOPE_MISMATCH');
            $scopeRevision = (int) ($scope['expected_revision'] ?? 0);
            $proposalRevision = (int) ($proposal->expectedRevision ?? 0);
            if ($scopeRevision !== $proposalRevision) throw new \RuntimeException('STAGING_EXPECTED_REVISION_SCOPE_MISMATCH');
            foreach (['platform', 'external_video_id', 'canonical_source_url', 'plan_fingerprint', 'proposal_command_fingerprint'] as $field) {
                if (!array_key_exists($field, $scope)) continue;
                $proposalValue = $proposal->payload[$field] ?? null;
                if ($proposalValue !== null && (string) $scope[$field] !== (string) $proposalValue) throw new \RuntimeException('STAGING_VIDEO_BINDING_MISMATCH');
            }
            $descriptor = StagingOperationDescriptor::fromProposal($proposal, $scope);
            if (!hash_equals((string) ($scope['proposal_command_fingerprint'] ?? ''), $descriptor->payloadFingerprint)) {
                throw new \RuntimeException('STAGING_VIDEO_PAYLOAD_MISMATCH');
            }
            if (array_key_exists('capture_revision', $scope) && array_key_exists('capture_revision', $proposal->payload) && (int) $scope['capture_revision'] !== (int) $proposal->payload['capture_revision']) {
                throw new \RuntimeException('STAGING_CAPTURE_REVISION_MISMATCH');
            }
            $scopeDependencies = array_values(array_map('strval', (array) ($scope['dependency_ids'] ?? [])));
            $descriptorDependencies = array_values(array_map('strval', (array) ($descriptor->payload['dependency_ids'] ?? [])));
            sort($scopeDependencies, SORT_STRING);
            sort($descriptorDependencies, SORT_STRING);
            if ($scopeDependencies !== $descriptorDependencies
                || !hash_equals((string) ($scope['dependency_fingerprint'] ?? ''), $descriptor->dependencyFingerprint)) {
                throw new \RuntimeException('STAGING_VIDEO_DEPENDENCY_MISMATCH');
            }
            if (is_array($scope['subject'] ?? null)) {
                $subject = is_array($proposal->payload['metadata']['subject_resolution_packet'] ?? null) ? $proposal->payload['metadata']['subject_resolution_packet'] : [];
                if (($subject['id'] ?? null) !== null && (string) ($scope['subject']['uuid'] ?? '') !== (string) $subject['id']) throw new \RuntimeException('STAGING_SUBJECT_SCOPE_MISMATCH');
                if (($subject['type'] ?? null) !== null && (string) ($scope['subject']['type'] ?? '') !== (string) $subject['type']) throw new \RuntimeException('STAGING_SUBJECT_SCOPE_MISMATCH');
            }
            self::assertNoFuzzyLocator($scope);
            return;
        }

        if ($expectedFamily === 'capture_child_relation') {
            $payload = $proposal->payload;
            $sourceType = (string) ($payload['source_type'] ?? '');
            $sourceUuid = (string) ($payload['source_uuid'] ?? '');
            $targetType = (string) ($payload['target_type'] ?? '');
            $targetUuid = (string) ($payload['target_uuid'] ?? '');
            if ($sourceType !== (string) ($scope['source_type'] ?? '')
                || $sourceUuid !== (string) ($scope['source_id'] ?? '')
                || (string) ($payload['predicate'] ?? '') !== (string) ($scope['predicate'] ?? '')
                || $targetType !== (string) ($scope['target_type'] ?? '')
                || $targetUuid !== (string) ($scope['target_id'] ?? '')
                || (int) ($payload['source_revision'] ?? 0) !== (int) ($scope['source_revision'] ?? 0)
                || (int) ($payload['target_revision'] ?? 0) !== (int) ($scope['target_revision'] ?? 0)) {
                throw new \RuntimeException('STAGING_CAPTURE_CHILD_BINDING_MISMATCH');
            }
            if ((int) ($scope['capture_revision'] ?? 0) !== (int) ($proposal->payload['capture_revision'] ?? 0)) throw new \RuntimeException('STAGING_CAPTURE_REVISION_MISMATCH');
            if (!hash_equals((string) ($scope['idempotency_key'] ?? ''), (string) $proposal->idempotencyKey)) throw new \RuntimeException('STAGING_RELATION_IDEMPOTENCY_MISMATCH');
            $payloadFingerprint = hash('sha256', CommandCanonicalizer::canonicalize(self::withoutAuthorization($payload)));
            if (!hash_equals((string) ($scope['payload_fingerprint'] ?? ''), $payloadFingerprint)
                || !hash_equals((string) ($scope['proposal_command_fingerprint'] ?? ''), $payloadFingerprint)) {
                throw new \RuntimeException('STAGING_CAPTURE_CHILD_PAYLOAD_MISMATCH');
            }
            return;
        }

        if (in_array($expectedFamily, ['source_evidence_reconciliation', 'knowledge_delta'], true)) {
            if (!hash_equals($captureId, (string) ($proposal->payload['capture_id'] ?? ''))
                || !hash_equals((string) ($scope['capture_fingerprint'] ?? ''), (string) ($proposal->payload['capture_fingerprint'] ?? ''))
                || (int) ($scope['capture_revision'] ?? 0) !== (int) ($proposal->payload['capture_revision'] ?? 0)
                || (string) ($scope['entity_type'] ?? '') !== $proposal->entityType
                || (string) ($scope['operation'] ?? '') !== $proposal->operation
                || (string) ($scope['subject_id'] ?? '') !== $proposal->subjectId
                || (int) ($scope['expected_revision'] ?? 0) !== (int) ($proposal->expectedRevision ?? 0)) {
                throw new \RuntimeException('STAGING_DEPENDENCY_SCOPE_MISMATCH');
            }
            $descriptor = StagingOperationDescriptor::fromProposal($proposal, $scope);
            $payloadFingerprint = $descriptor->payloadFingerprint;
            if (!hash_equals((string) ($scope['payload_fingerprint'] ?? ''), $payloadFingerprint)
                || !hash_equals((string) ($scope['proposal_command_fingerprint'] ?? ''), $payloadFingerprint)) {
                throw new \RuntimeException('STAGING_DEPENDENCY_PAYLOAD_MISMATCH');
            }
            self::assertNoFuzzyLocator($scope);
            return;
        }

        if ($expectedFamily === 'media_metadata_reconciliation') {
            if (!hash_equals($captureId, (string) ($proposal->payload['capture_id'] ?? ''))
                || !hash_equals((string) ($scope['capture_fingerprint'] ?? ''), (string) ($proposal->payload['capture_fingerprint'] ?? ''))
                || (string) ($scope['entity_type'] ?? '') !== 'media'
                || (string) ($scope['operation'] ?? '') !== 'update'
                || (string) ($scope['target_uuid'] ?? '') !== $proposal->subjectId
                || (int) ($scope['expected_revision'] ?? 0) !== (int) $proposal->expectedRevision) {
                throw new \RuntimeException('STAGING_MEDIA_METADATA_SCOPE_MISMATCH');
            }
            $payload = $proposal->payload;
            unset($payload['staging_acceptance'], $payload['proposal_command_fingerprint']);
            $payloadFingerprint = hash('sha256', CommandCanonicalizer::canonicalize($payload));
            if (!hash_equals((string) ($scope['payload_fingerprint'] ?? ''), $payloadFingerprint)) {
                throw new \RuntimeException('STAGING_MEDIA_METADATA_PAYLOAD_MISMATCH');
            }
            self::assertNoFuzzyLocator($scope);
            return;
        }

        $mediaIds = $scope['media_ids'] ?? [];
        if (!is_array($mediaIds) || $mediaIds === [] || !array_is_list($mediaIds)) throw new \RuntimeException('STAGING_MEDIA_SCOPE_INVALID');
        $mediaIds = array_values(array_map('strval', $mediaIds));
        foreach ($mediaIds as $mediaId) if (!UuidCodec::isValid($mediaId)) throw new \RuntimeException('STAGING_MEDIA_SCOPE_INVALID');
        if (count(array_unique($mediaIds)) !== count($mediaIds) || !in_array($proposal->subjectId, $mediaIds, true)) throw new \RuntimeException('STAGING_MEDIA_SCOPE_MISMATCH');
        $bindingMediaId = trim((string) (($proposal->payload['binding']['media']['id'] ?? $proposal->payload['media']['id'] ?? $proposal->payload['media_id'] ?? $proposal->subjectId)));
        if ($bindingMediaId !== $proposal->subjectId || !in_array($bindingMediaId, $mediaIds, true)) throw new \RuntimeException('STAGING_MEDIA_SCOPE_MISMATCH');

        // Existing Authority representative bindings retain their original
        // UUID-only target contract. Article MediaUsage mutations below use
        // the exact WordPress endpoint key (for example 1:617).
        if ($proposal->operation === 'representative_bind') {
            $target = $scope['target'] ?? null;
            if (!is_array($target) || !UuidCodec::isValid((string) ($target['id'] ?? '')) || (string) ($target['type'] ?? '') === '') throw new \RuntimeException('STAGING_TARGET_SCOPE_INVALID');
            if ((string) $target['id'] !== (string) ($proposal->targetUuid ?? '') || (string) $target['type'] !== (string) ($proposal->payload['binding']['target']['type'] ?? $target['type'])) throw new \RuntimeException('STAGING_TARGET_SCOPE_MISMATCH');
            if (isset($proposal->payload['binding']['target']['id']) && (string) $proposal->payload['binding']['target']['id'] !== (string) $target['id']) throw new \RuntimeException('STAGING_TARGET_SCOPE_MISMATCH');
            if (isset($target['stable_key'], $proposal->payload['binding']['target']['stable_key']) && (string) $target['stable_key'] !== (string) $proposal->payload['binding']['target']['stable_key']) throw new \RuntimeException('STAGING_TARGET_SCOPE_MISMATCH');
            if (isset($target['stable_key']) && trim((string) $target['stable_key']) === '') throw new \RuntimeException('STAGING_TARGET_SCOPE_INVALID');
            self::assertNoFuzzyLocator($scope);
            return;
        }

        $target = $scope['target'] ?? null;
        $proposalTarget = is_array($proposal->payload['target'] ?? null) ? $proposal->payload['target'] : (array) ($proposal->payload['binding']['target'] ?? []);
        if (!is_array($target) || (string) ($target['type'] ?? '') === '') throw new \RuntimeException('STAGING_TARGET_SCOPE_INVALID');
        $targetType = (string) ($target['type'] ?? '');
        $targetId = (string) ($target['id'] ?? '');
        if ($targetType === 'wp_post') {
            if (preg_match('/^[1-9][0-9]*:[1-9][0-9]*$/', $targetId) !== 1) throw new \RuntimeException('STAGING_TARGET_SCOPE_INVALID');
        } elseif (!UuidCodec::isValid($targetId)) throw new \RuntimeException('STAGING_TARGET_SCOPE_INVALID');
        if ($targetId !== (string) ($proposal->targetUuid ?? ($proposalTarget['id'] ?? '')) || $targetType !== (string) ($proposalTarget['type'] ?? $targetType)) throw new \RuntimeException('STAGING_TARGET_SCOPE_MISMATCH');
        if (in_array($proposal->operation, ['replace', 'remove'], true)) {
            if ((string) ($scope['usage_id'] ?? '') !== (string) ($proposal->payload['usage_id'] ?? '') || (int) ($scope['expected_usage_revision'] ?? 0) !== (int) ($proposal->payload['expected_usage_revision'] ?? 0)) throw new \RuntimeException('STAGING_MEDIA_USAGE_REVISION_SCOPE_MISMATCH');
        }
        $payload = self::withoutAuthorization($proposal->payload);
        if (!hash_equals((string) ($scope['payload_fingerprint'] ?? ''), hash('sha256', CommandCanonicalizer::canonicalize($payload)))) throw new \RuntimeException('STAGING_MEDIA_USAGE_PAYLOAD_MISMATCH');
        if (isset($target['stable_key']) && trim((string) $target['stable_key']) === '') throw new \RuntimeException('STAGING_TARGET_SCOPE_INVALID');

        self::assertNoFuzzyLocator($scope);
    }

    private static function isAuthorityEntity(string $entityType): bool
    {
        return in_array($entityType, ['brand', 'model', 'variant', 'movement', 'music', 'component', 'classification', 'specimen', 'product'], true);
    }

    /** @param array<string,mixed> $scope */
    private static function assertAuthorityBinding(Proposal $proposal, array $scope): void
    {
        $planFingerprint = trim((string) ($scope['plan_fingerprint'] ?? ''));
        $audit = is_array($proposal->payload['project_build_audit'] ?? null) ? $proposal->payload['project_build_audit'] : [];
        if (!preg_match('/^[a-f0-9]{64}$/i', $planFingerprint) || !hash_equals($planFingerprint, (string) ($audit['plan_fingerprint'] ?? ''))) throw new \RuntimeException('STAGING_PLAN_SCOPE_MISMATCH');
        $candidateId = trim((string) ($proposal->payload['candidate_id'] ?? ''));
        if ($candidateId === '') throw new \RuntimeException('STAGING_CANDIDATE_SCOPE_REQUIRED');
        foreach ((array) ($scope['candidate_bindings'] ?? []) as $binding) {
            if (!is_array($binding) || (string) ($binding['candidate_id'] ?? '') !== $candidateId) continue;
            if ((string) ($binding['entity_type'] ?? '') !== $proposal->entityType || (string) ($binding['operation'] ?? '') !== $proposal->operation) continue;
            if ($proposal->entityType === 'relation') {
                $payload = $proposal->payload;
                if ((string) ($binding['source_type'] ?? '') !== (string) ($payload['source_type'] ?? '')
                    || (string) ($binding['source_uuid'] ?? '') !== (string) ($payload['source_uuid'] ?? '')
                    || (string) ($binding['predicate'] ?? '') !== (string) ($payload['predicate'] ?? '')
                    || (string) ($binding['target_type'] ?? '') !== (string) ($payload['target_type'] ?? '')
                    || (string) ($binding['target_uuid'] ?? '') !== (string) ($payload['target_uuid'] ?? '')
                    || (int) ($binding['source_revision'] ?? 0) !== (int) ($payload['source_revision'] ?? 0)
                    || (int) ($binding['target_revision'] ?? 0) !== (int) ($payload['target_revision'] ?? 0)) continue;
                return;
            }
            if ((string) ($binding['subject_id'] ?? '') !== $proposal->subjectId || (string) ($binding['target_uuid'] ?? '') !== (string) ($proposal->targetUuid ?? '')) continue;
            $expected = $binding['expected_revision'] ?? null;
            if ($expected !== null && (int) $expected !== (int) $proposal->expectedRevision) continue;
            return;
        }
        throw new \RuntimeException('STAGING_CANDIDATE_SCOPE_MISMATCH');
    }

    /** @param array<string,mixed> $scope */
    private static function assertNoFuzzyLocator(array $scope): void
    {
        $forbidden = ['name', 'filename', 'url', 'match', 'similarity', 'fuzzy', 'locator'];
        $target = is_array($scope['target'] ?? null) ? $scope['target'] : [];
        if (array_intersect($forbidden, array_keys($target)) !== []) throw new \RuntimeException('STAGING_EXACT_TARGET_REQUIRED');
        foreach (['media', 'media_ref'] as $key) {
            if (is_array($scope[$key] ?? null) && array_intersect($forbidden, array_keys($scope[$key])) !== []) throw new \RuntimeException('STAGING_EXACT_MEDIA_REQUIRED');
        }
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    public static function withoutAuthorization(array $value): array
    {
        foreach (['staging_acceptance', 'signature', 'fingerprint', 'approved', 'scope_fingerprint', 'proposal_command_fingerprint'] as $key) unset($value[$key]);
        return $value;
    }
}
