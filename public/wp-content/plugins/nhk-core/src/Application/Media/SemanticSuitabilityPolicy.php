<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Domain\Media\{Media, MediaAsset};

/**
 * Shared truth gate for automatic Media reuse and completeness.
 *
 * Availability and suitability are deliberately independent.  A readable
 * asset with no persisted subject proof is not an eligible enrichment.
 */
final class SemanticSuitabilityPolicy
{
    public const REQUIRED = 'REQUIRED';
    public const OPTIONAL = 'OPTIONAL';
    public const NOT_APPLICABLE = 'NOT_APPLICABLE';

    public const EXACT = 'EXACT';
    public const COMPATIBLE = 'COMPATIBLE';
    public const REVIEW_REQUIRED = 'REVIEW_REQUIRED';
    public const INELIGIBLE = 'INELIGIBLE';
    public const UNKNOWN = 'UNKNOWN';

    public const AVAILABLE = 'AVAILABLE';
    public const MISSING = 'MISSING';
    public const STALE = 'STALE';
    public const BROKEN = 'BROKEN';
    public const DEFERRED = 'DEFERRED';

    /** @param array<string,mixed> $candidate @param array<string,mixed> $target @return array<string,mixed> */
    public function evaluate(array $candidate, array $target = []): array
    {
        $requirement = strtoupper(trim((string) ($candidate['requirement'] ?? self::OPTIONAL)));
        if (!in_array($requirement, [self::REQUIRED, self::OPTIONAL, self::NOT_APPLICABLE], true)) $requirement = self::OPTIONAL;
        $availability = strtoupper(trim((string) ($candidate['availability'] ?? self::AVAILABLE)));
        if (!in_array($availability, [self::AVAILABLE, self::MISSING, self::STALE, self::BROKEN, self::DEFERRED], true)) $availability = self::UNKNOWN;

        $expected = $this->strings($target['subject_ids'] ?? $target['canonical_subject_ids'] ?? []);
        $actual = $this->strings(array_merge(
            $this->strings($candidate['subject_ids'] ?? $candidate['canonical_subject_ids'] ?? []),
            $this->strings($candidate['persisted_subject_ids'] ?? []),
            $this->strings([
                $candidate['subject_id'] ?? null,
                $candidate['subject_uuid'] ?? null,
                $candidate['canonical_subject_id'] ?? null,
                $candidate['canonical_subject_uuid'] ?? null,
            ]),
        ));
        $basis = '';
        $suitability = self::UNKNOWN;
        if ($availability !== self::AVAILABLE) {
            $suitability = self::INELIGIBLE;
            $basis = 'availability_not_available';
        } elseif ($expected !== [] && array_intersect($expected, $actual) !== []) {
            $suitability = self::EXACT;
            $basis = 'exact_canonical_subject_binding';
        } elseif (($candidate['compatibility_rule_registered'] ?? false) === true
            && in_array(strtoupper(trim((string) ($candidate['relation_class'] ?? ''))), ['IDENTITY_EQUIVALENT', 'REPRESENTATIVE_COMPATIBLE'], true)) {
            $suitability = self::COMPATIBLE;
            $basis = 'registered_compatibility_rule';
        } elseif ($expected !== [] && $actual !== []) {
            $suitability = self::INELIGIBLE;
            $basis = 'persisted_subject_scope_mismatch';
        } elseif (($candidate['selection_source'] ?? 'SYSTEM_AUTO') === 'USER_EXPLICIT' && ($candidate['current_capture_media'] ?? false) === true) {
            // A Media supplied in the current Capture is publication-unit
            // provenance. It may satisfy the Article slot without inventing
            // persisted semantic scope; representative/entity reuse still
            // requires exact or registered compatible scope.
            $suitability = self::COMPATIBLE;
            $basis = 'current_capture_selection_provenance';
        } elseif (($candidate['selection_source'] ?? 'SYSTEM_AUTO') === 'USER_EXPLICIT' && ($candidate['editorial_illustration'] ?? false) === true) {
            // An explicit illustration may be retained for editorial context,
            // but it is not semantic representative coverage.
            $suitability = self::REVIEW_REQUIRED;
            $basis = 'explicit_editorial_illustration_without_subject_binding';
        } else {
            $basis = 'no_explainable_persisted_subject_basis';
        }

        $auto = $availability === self::AVAILABLE
            && in_array($suitability, [self::EXACT, self::COMPATIBLE], true)
            && in_array($basis, ['exact_canonical_subject_binding', 'registered_compatibility_rule', 'current_capture_selection_provenance'], true);
        return [
            'requirement' => $requirement,
            'availability' => $availability,
            'suitability' => $suitability,
            'basis' => $basis,
            'auto_select' => $auto,
            'valid_for_completeness' => $auto,
            'diagnostic' => $auto ? null : ($suitability === self::INELIGIBLE ? 'MEDIA_CANDIDATE_INELIGIBLE' : 'MEDIA_USAGE_SEMANTIC_MISMATCH'),
        ];
    }

    /** @param list<MediaAsset> $assets @param array<string,mixed> $target @return array<string,mixed> */
    public function evaluateMedia(Media $media, array $assets, array $target, string $selectionSource = 'SYSTEM_AUTO', string $role = 'representative'): array
    {
        $available = !$media->active ? self::BROKEN : ($media->readiness !== 'ready' ? self::STALE : ($assets === [] ? self::MISSING : self::AVAILABLE));
        $candidate = $this->scopeFromMedia($media) + [
            'availability' => $available,
            'selection_source' => $selectionSource,
            'role' => $role,
        ];
        if (($target['current_capture_media'] ?? false) === true) $candidate['current_capture_media'] = true;
        return $this->evaluate($candidate, $target);
    }

    /** @return array<string,mixed> */
    private function scopeFromMedia(Media $media): array
    {
        $metadata = is_array($media->provenance['metadata'] ?? null) ? $media->provenance['metadata'] : [];
        return [
            'subject_ids' => array_merge(
                $this->strings($media->provenance['subject_ids'] ?? []),
                $this->strings($metadata['subject_ids'] ?? []),
                $this->strings([
                    $media->provenance['subject_id'] ?? null,
                    $media->provenance['subject_uuid'] ?? null,
                    $media->provenance['canonical_subject_id'] ?? null,
                    $media->provenance['canonical_subject_uuid'] ?? null,
                    $metadata['subject_id'] ?? null,
                    $metadata['subject_uuid'] ?? null,
                    $metadata['canonical_subject_id'] ?? null,
                    $metadata['canonical_subject_uuid'] ?? null,
                ]),
            ),
        ];
    }

    /** @param mixed $values @return list<string> */
    private function strings(mixed $values): array
    {
        $values = is_array($values) ? $values : [$values];
        $result = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') $result[$value] = true;
        }
        return array_keys($result);
    }
}
