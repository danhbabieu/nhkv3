<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Graph\PredicateRegistry;
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
    private const OPERATIONS = ['create', 'update', 'rename', 'rekey', 'retire', 'reactivate'];

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
            || ($scope['request_fingerprint'] ?? $scope['capture_fingerprint'] ?? '') !== $capture->requestFingerprint
            || preg_match('/^[a-f0-9]{64}$/i', (string) ($scope['plan_fingerprint'] ?? '')) !== 1
            || !in_array((string) ($capture->context['purpose'] ?? ''), ['AUTHORITY', 'MIXED'], true)) return false;

        $bindings = array_values(array_filter((array) ($scope['candidate_bindings'] ?? []), 'is_array'));
        if ($bindings === []) return false;
        $candidateIds = [];
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $predicates = new PredicateRegistry();
        foreach ($bindings as $binding) {
            $candidateId = trim((string) ($binding['candidate_id'] ?? ''));
            $entityType = strtolower(trim((string) ($binding['entity_type'] ?? '')));
            $operation = strtolower(trim((string) ($binding['operation'] ?? '')));
            if ($candidateId === '' || str_contains($candidateId, '*') || in_array($candidateId, $candidateIds, true)
                || ($entityType !== 'relation' && !$types->has($entityType))
                || ($entityType === 'relation' && $operation !== 'relation_create')
                || ($entityType !== 'relation' && !in_array($operation, self::OPERATIONS, true))) return false;
            $candidateIds[] = $candidateId;
            if (!preg_match('/^[a-f0-9]{64}$/i', (string) ($binding['candidate_payload_fingerprint'] ?? ''))
                || !preg_match('/^[a-f0-9]{64}$/i', (string) ($binding['dependency_fingerprint'] ?? ''))
                || !$this->bindingFingerprintMatches($binding)) return false;
            if ($entityType === 'relation' ? !$this->validRelationBinding($binding, $predicates) : !$this->validAuthorityBinding($binding, $operation, $entityType)) return false;
        }

        $approvedIds = array_values(array_unique(array_map('strval', (array) ($scope['approved_candidate_ids'] ?? []))));
        sort($candidateIds, SORT_STRING); sort($approvedIds, SORT_STRING);
        $dependencyClosure = array_map(static fn (array $binding): array => [$binding['candidate_id'] ?? '', $binding['dependencies'] ?? []], $bindings);
        if ($approvedIds !== $candidateIds
            || !preg_match('/^[a-f0-9]{64}$/i', (string) ($scope['dependency_fingerprint'] ?? ''))
            || !hash_equals((string) $scope['dependency_fingerprint'], hash('sha256', \NHK\Core\Domain\Governance\CommandCanonicalizer::canonicalize($dependencyClosure)))) return false;

        // The immutable approved plan has already been reduced to the exact
        // signed candidate bindings above. Do not require a particular input
        // shape here: relation-only plans legitimately contain
        // `relation_intents` without entity `requests`. The scope is admitted
        // from its Capture/plan/candidate bindings, never from a data-specific
        // request allowlist.
        return true;
    }

    /** @param array<string,mixed> $binding */
    private function validAuthorityBinding(array $binding, string $operation, string $entityType): bool
    {
        if ($operation === 'create' && (string) ($binding['subject_id'] ?? '') !== $entityType) return false;
        if (in_array($operation, ['create', 'ingest'], true)) return ($binding['target_uuid'] ?? '') === '' && ($binding['expected_revision'] ?? null) === null;
        return UuidCodec::isValid((string) ($binding['target_uuid'] ?? '')) && (int) ($binding['expected_revision'] ?? 0) >= 1;
    }

    /** @param array<string,mixed> $binding */
    private function validRelationBinding(array $binding, PredicateRegistry $predicates): bool
    {
        $predicate = trim((string) ($binding['predicate'] ?? ''));
        $sourceType = strtolower(trim((string) ($binding['source_type'] ?? '')));
        $targetType = strtolower(trim((string) ($binding['target_type'] ?? '')));
        if (strtolower((string) ($binding['operation'] ?? '')) !== 'relation_create'
            || $predicate === '' || $sourceType === '' || $targetType === ''
            || !$this->registeredRelation($predicates, $predicate, $sourceType, $targetType)
            || !UuidCodec::isValid((string) ($binding['target_uuid'] ?? ''))
            || (int) ($binding['target_revision'] ?? 0) < 1) return false;
        // A relation source may be created by an earlier candidate in the
        // same plan, so source_uuid can legitimately bind later.
        return ($binding['source_uuid'] ?? '') === '' || UuidCodec::isValid((string) $binding['source_uuid']);
    }

    private function registeredRelation(PredicateRegistry $predicates, string $predicate, string $sourceType, string $targetType): bool
    {
        try { return $predicates->get($predicate)->allows($sourceType, $targetType); } catch (\Throwable) { return false; }
    }

    private function bindingFingerprintMatches(array $binding): bool
    {
        $fingerprint = (string) ($binding['binding_fingerprint'] ?? '');
        unset($binding['binding_fingerprint']);
        return preg_match('/^[a-f0-9]{64}$/i', $fingerprint) === 1
            && hash_equals($fingerprint, hash('sha256', \NHK\Core\Domain\Governance\CommandCanonicalizer::canonicalize($binding)));
    }
}
