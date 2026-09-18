<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Admission for a server-issued Authority plan scope.
 *
 * The exact Capture, plan fingerprint and candidate bindings are assembled by
 * StagingAcceptanceScopeVerifier from the approved plan, then signed. This
 * hook only admits a structurally valid packet; policy, eligibility, apply
 * and canonical read-back remain separate governance stages.
 */
final class AuthorityStagingAdmission
{
    private const ENTITY_TYPES = ['brand', 'model', 'variant', 'movement', 'music', 'component', 'classification', 'specimen', 'product'];
    private const OPERATIONS = ['create', 'ingest', 'update', 'rename', 'rekey', 'merge', 'retire', 'reactivate'];

    /** @param array<string,mixed> $scope @param array<string,mixed> $input @param list<array<string,mixed>> $assets */
    public function __invoke(bool $admitted, array $scope, CaptureRecord $capture, array $input, array $assets): bool
    {
        if ($admitted) return true;
        if (($scope['approved'] ?? false) !== true
            || ($scope['environment'] ?? '') !== 'staging'
            || ($scope['operation_family'] ?? '') !== 'governed_authority_plan'
            || ($scope['writer'] ?? '') !== 'canonical_governed'
            || ($scope['entrypoint'] ?? '') !== 'nhk.capture.ingest'
            || !in_array(strtoupper((string) ($scope['intent'] ?? '')), ['AUTHORITY', 'MIXED'], true)
            || ($scope['capture_id'] ?? '') !== $capture->captureId
            || ($scope['capture_fingerprint'] ?? '') !== $capture->requestFingerprint
            || preg_match('/^[a-f0-9]{64}$/i', (string) ($scope['plan_fingerprint'] ?? '')) !== 1
            || !in_array((string) ($capture->context['purpose'] ?? ''), ['AUTHORITY', 'MIXED'], true)) return false;

        $bindings = array_values(array_filter((array) ($scope['candidate_bindings'] ?? []), 'is_array'));
        if ($bindings === []) return false;
        $candidateIds = [];
        foreach ($bindings as $binding) {
            $candidateId = trim((string) ($binding['candidate_id'] ?? ''));
            $entityType = strtolower(trim((string) ($binding['entity_type'] ?? '')));
            $operation = strtolower(trim((string) ($binding['operation'] ?? '')));
            if ($candidateId === '' || str_contains($candidateId, '*') || in_array($candidateId, $candidateIds, true)
                || (!in_array($entityType, self::ENTITY_TYPES, true) && $entityType !== 'relation')
                || ($entityType !== 'relation' && !in_array($operation, self::OPERATIONS, true))) return false;
            $candidateIds[] = $candidateId;
            if ($entityType === 'relation' ? !$this->validRelationBinding($binding) : !$this->validAuthorityBinding($binding, $operation, $entityType)) return false;
        }

        // The persisted planning input is request context, not an approval
        // switch. Approval caused this issuer to run; structured requests keep
        // malformed or legacy Captures from receiving a staging scope.
        return array_values(array_filter((array) ($input['authority_intent']['requests'] ?? []), 'is_array')) !== [];
    }

    /** @param array<string,mixed> $binding */
    private function validAuthorityBinding(array $binding, string $operation, string $entityType): bool
    {
        if ($operation === 'create' && (string) ($binding['subject_id'] ?? '') !== $entityType) return false;
        if (in_array($operation, ['create', 'ingest'], true)) return ($binding['target_uuid'] ?? '') === '' && ($binding['expected_revision'] ?? null) === null;
        return UuidCodec::isValid((string) ($binding['target_uuid'] ?? '')) && (int) ($binding['expected_revision'] ?? 0) >= 1;
    }

    /** @param array<string,mixed> $binding */
    private function validRelationBinding(array $binding): bool
    {
        if (strtolower((string) ($binding['operation'] ?? '')) !== 'relation_create'
            || trim((string) ($binding['predicate'] ?? '')) === ''
            || trim((string) ($binding['source_type'] ?? '')) === ''
            || trim((string) ($binding['target_type'] ?? '')) === ''
            || !UuidCodec::isValid((string) ($binding['target_uuid'] ?? ''))
            || (int) ($binding['target_revision'] ?? 0) < 1) return false;
        // A relation source may be created by an earlier candidate in the
        // same plan, so source_uuid can legitimately bind later.
        return ($binding['source_uuid'] ?? '') === '' || UuidCodec::isValid((string) $binding['source_uuid']);
    }
}
