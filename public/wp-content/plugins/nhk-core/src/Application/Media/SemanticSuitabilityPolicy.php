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
        $expectedRevision = trim((string) ($target['subject_revision'] ?? $target['canonical_subject_revision'] ?? ''));
        $actualRevision = trim((string) ($candidate['subject_revision'] ?? $candidate['canonical_subject_revision'] ?? ''));
        $explicitPlacement = ($candidate['article_explicit_media'] ?? false) === true
            || (($candidate['selection_source'] ?? 'SYSTEM_AUTO') === 'USER_EXPLICIT' && ($candidate['current_capture_media'] ?? false) === true);
        $revisionMismatch = $expectedRevision !== '' && $actualRevision !== $expectedRevision;
        $basis = '';
        $suitability = self::UNKNOWN;
        $relationshipClass = 'UNKNOWN';
        $semanticTier = 'UNKNOWN_SCOPE';
        $relationPath = is_array($candidate['relation_path'] ?? null) ? array_values(array_map('strval', $candidate['relation_path'])) : [];
        if ($availability !== self::AVAILABLE) {
            $suitability = self::INELIGIBLE;
            $basis = 'availability_not_available';
            $relationshipClass = 'UNAVAILABLE';
            $semanticTier = 'UNAVAILABLE';
        } elseif ($expected !== [] && array_intersect($expected, $actual) !== [] && (!$revisionMismatch || $explicitPlacement)) {
            $suitability = self::EXACT;
            $basis = 'exact_canonical_subject_binding';
            $relationshipClass = 'EXACT';
            $semanticTier = 'EXACT_SUBJECT';
        } elseif ($expected !== [] && array_intersect($expected, $actual) !== [] && $revisionMismatch) {
            $suitability = self::INELIGIBLE;
            $basis = 'subject_revision_mismatch';
            $relationshipClass = 'STALE';
            $semanticTier = 'STALE_REVISION';
        } elseif (($candidate['compatibility_rule_registered'] ?? false) === true
            && in_array(strtoupper(trim((string) ($candidate['relation_class'] ?? ''))), ['IDENTITY_EQUIVALENT', 'REPRESENTATIVE_COMPATIBLE'], true)) {
            $suitability = self::COMPATIBLE;
            $basis = 'registered_compatibility_rule';
            $relationshipClass = strtoupper(trim((string) ($candidate['relation_class'] ?? 'REPRESENTATIVE_COMPATIBLE')));
            $semanticTier = 'REGISTERED_COMPATIBLE';
        } elseif ($expected !== [] && $actual !== []) {
            $suitability = self::INELIGIBLE;
            $basis = 'persisted_subject_scope_mismatch';
            $relationshipClass = 'UNRELATED';
            $semanticTier = 'CONTRADICTORY_SCOPE';
        } elseif ($expected === [] && in_array($candidate['role'] ?? '', ['featured_primary', 'inline_primary', 'inline_supporting'], true)
            && ($candidate['article_explicit_media'] ?? false) !== true
            && !(($candidate['selection_source'] ?? 'SYSTEM_AUTO') === 'USER_EXPLICIT' && ($candidate['current_capture_media'] ?? false) === true)) {
            // A generic Article may use editorial Media without claiming that
            // the asset proves a canonical semantic subject. Subject-bound
            // representative/entity reuse still requires explicit persisted
            // scope and is evaluated through the branches above.
            $basis = 'no_explainable_persisted_subject_basis';
            $relationshipClass = 'UNKNOWN';
            $semanticTier = 'UNKNOWN_SCOPE';
        } elseif ($expected === [] && ($candidate['article_explicit_media'] ?? false) === true) {
            // An explicit Article slot selection is valid editorial input when
            // there is no semantic target to validate. This does not grant
            // representative/entity reuse or create semantic scope proof.
            $suitability = self::COMPATIBLE;
            $basis = 'explicit_article_selection_without_semantic_target';
            $relationshipClass = 'EXPLICIT_ARTICLE';
            $semanticTier = 'EXPLICIT_ARTICLE';
        } elseif (($candidate['selection_source'] ?? 'SYSTEM_AUTO') === 'USER_EXPLICIT' && ($candidate['current_capture_media'] ?? false) === true) {
            // A Media supplied in the current Capture is publication-unit
            // provenance. It may satisfy the Article slot without inventing
            // persisted semantic scope; representative/entity reuse still
            // requires exact or registered compatible scope.
            $suitability = self::COMPATIBLE;
            $basis = 'current_capture_selection_provenance';
            $relationshipClass = 'CURRENT_CAPTURE';
            $semanticTier = 'CURRENT_CAPTURE';
        } elseif (($candidate['selection_source'] ?? 'SYSTEM_AUTO') === 'USER_EXPLICIT' && ($candidate['editorial_illustration'] ?? false) === true) {
            // An explicit illustration may be retained for editorial context,
            // but it is not semantic representative coverage.
            $suitability = self::REVIEW_REQUIRED;
            $basis = 'explicit_editorial_illustration_without_subject_binding';
            $relationshipClass = 'EDITORIAL_ILLUSTRATION';
            $semanticTier = 'EDITORIAL_REVIEW';
        } else {
            $basis = 'no_explainable_persisted_subject_basis';
        }

        $auto = $availability === self::AVAILABLE
            && in_array($suitability, [self::EXACT, self::COMPATIBLE], true)
            && in_array($basis, ['exact_canonical_subject_binding', 'registered_compatibility_rule', 'current_capture_selection_provenance', 'explicit_article_selection_without_semantic_target'], true);
        $scoreComponents = [
            'semantic_tier' => match ($semanticTier) {
                'EXACT_SUBJECT' => 500,
                'REGISTERED_COMPATIBLE' => 400,
                'CURRENT_CAPTURE' => 300,
                'EXPLICIT_ARTICLE' => 200,
                default => 0,
            },
            'relationship_class' => $relationshipClass,
        ];
        return [
            'requirement' => $requirement,
            'availability' => $availability,
            'suitability' => $suitability,
            'basis' => $basis,
            'auto_select' => $auto,
            'valid_for_completeness' => $auto,
            'diagnostic' => $auto ? null : ($suitability === self::INELIGIBLE ? 'MEDIA_CANDIDATE_INELIGIBLE' : 'MEDIA_USAGE_SEMANTIC_MISMATCH'),
            'relationship_class' => $relationshipClass,
            'relation_path' => $relationPath,
            'semantic_tier' => $semanticTier,
            'eligible' => $auto,
            'score_components' => $scoreComponents,
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
        if (($target['article_explicit_media'] ?? false) === true) $candidate['article_explicit_media'] = true;
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
            'subject_revision' => (string) ($media->provenance['subject_revision'] ?? $metadata['subject_revision'] ?? ''),
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
