<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

use NHK\Core\Domain\Graph\PredicateRegistry;
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Shared admission rules for the two physical-catalogue relations.
 *
 * This is deliberately a policy/validation boundary, not a persistence
 * writer. Graph and Governance remain the only owners of durable relation
 * state.
 */
final class SpecimenProductRelationPolicy
{
    private const PROVENANCE = ['OBSERVED_FROM_MEDIA', 'EXPLICIT_USER_KNOWLEDGE', 'CATALOG_SUPPORTED', 'EXTERNAL_RESEARCH', 'SYSTEM_INFERENCE'];
    /** @return list<string> */
    public static function validate(array $payload, ?PredicateRegistry $registry = null, bool $requireEvidence = true): array
    {
        $registry ??= new PredicateRegistry();
        $predicate = strtolower(trim((string) ($payload['predicate'] ?? '')));
        $sourceType = strtolower(trim((string) ($payload['source_type'] ?? '')));
        $targetType = strtolower(trim((string) ($payload['target_type'] ?? '')));
        $errors = [];

        try {
            $definition = $registry->get($predicate);
            if (!$definition->allows($sourceType, $targetType)) $errors[] = 'RELATION_ENDPOINTS_NOT_ALLOWED';
        } catch (\Throwable) {
            return ['PREDICATE_UNREGISTERED'];
        }

        if (!in_array($predicate, ['specimen_of', 'lists_specimen'], true)) return $errors;
        if (!self::endpointKeyValid($sourceType, (string) ($payload['source_uuid'] ?? $payload['source_key'] ?? ''))) $errors[] = 'SOURCE_IDENTITY_INVALID';
        if (!self::endpointKeyValid($targetType, (string) ($payload['target_uuid'] ?? $payload['target_key'] ?? ''))) $errors[] = 'TARGET_IDENTITY_INVALID';
        if ((int) ($payload['source_revision'] ?? 0) < 1 || (int) ($payload['target_revision'] ?? 0) < 1) $errors[] = 'RELATION_ENDPOINT_REVISIONS_REQUIRED';
        if (trim((string) ($payload['scope_code'] ?? '')) === '') $errors[] = 'RELATION_SCOPE_REQUIRED';
        $provenance = trim((string) ($payload['provenance'] ?? ''));
        if ($provenance === '' || !in_array($provenance, self::PROVENANCE, true)) $errors[] = 'RELATION_PROVENANCE_REQUIRED';
        $refs = is_array($payload['evidence_refs'] ?? null) ? $payload['evidence_refs'] : [];
        if ($requireEvidence && $refs === []) $errors[] = 'RELATION_EVIDENCE_REQUIRED';
        foreach ($refs as $ref) {
            $id = is_array($ref) ? trim((string) ($ref['evidence_id'] ?? '')) : '';
            if (!UuidCodec::isValid($id)) $errors[] = 'RELATION_EVIDENCE_INVALID';
        }
        if ($predicate === 'lists_specimen' && (($payload['multi_object'] ?? false) === true || count((array) ($payload['specimen_targets'] ?? [])) > 1)) {
            $errors[] = 'PRODUCT_MULTI_OBJECT_LISTING_UNSUPPORTED';
        }
        return array_values(array_unique($errors));
    }

    private static function endpointKeyValid(string $type, string $key): bool
    {
        return $type === 'wp_post'
            ? preg_match('/^[1-9][0-9]*:[1-9][0-9]*$/', $key) === 1
            : UuidCodec::isValid($key);
    }
}
