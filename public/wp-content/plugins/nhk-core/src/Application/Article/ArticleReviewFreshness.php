<?php
declare(strict_types=1);

namespace NHK\Core\Application\Article;

use NHK\Core\Application\Capture\CaptureDecisionDependencyFingerprint;
use NHK\Core\Domain\Capture\CaptureRecord;

/** Shared freshness policy for persisted Article pre-create review decisions. */
final class ArticleReviewFreshness
{
    public const PRODUCER = 'ArticleResearchPreflight';
    public const POLICY_VERSION = 'article-pre-create-overlap-policy-2';
    public const POLICY_FINGERPRINT = 'sha256:' . 'article-pre-create-overlap-policy-2-exact-or-same-intent';

    /** @param array<string,mixed> $diagnostics */
    public static function isReevaluatable(?CaptureRecord $capture, array $diagnostics, array $input = []): bool
    {
        $resolution = is_array($diagnostics['article_resolution'] ?? null) ? $diagnostics['article_resolution'] : [];
        $research = is_array($resolution['research'] ?? null) ? $resolution['research'] : [];
        if (($research['ready_for_draft'] ?? false) !== true || (array) ($research['blockers'] ?? []) !== []) return false;
        $overlap = self::overlap($diagnostics);
        $classification = strtoupper(trim((string) ($overlap['classification'] ?? '')));
        $provenance = is_array($diagnostics['article_review_provenance'] ?? null) ? $diagnostics['article_review_provenance'] : [];
        // Older persisted Article reviews may not contain overlap metadata at
        // all. Preserve the existing conservative recovery path for those
        // rows; a new/current review always persists its classification.
        if ($classification !== '' && $classification !== 'SUBSTANTIAL_OVERLAP') return false;
        if ($classification === '' && $provenance !== []) return false;
        if (self::hasHardBlock($diagnostics, $research)) return false;

        if ($provenance === []) return true;
        $policy = trim((string) ($provenance['policy_fingerprint'] ?? ''));
        if ($policy === '' || !hash_equals(self::POLICY_FINGERPRINT, $policy)) return true;
        $persistedDependency = trim((string) ($provenance['decision_dependency_fingerprint'] ?? ''));
        if ($persistedDependency === '' || $capture === null) return $persistedDependency === '';
        $currentDependency = CaptureDecisionDependencyFingerprint::current($capture, $input);
        return !hash_equals($persistedDependency, $currentDependency);
    }

    /** @param array<string,mixed> $diagnostics @return array<string,mixed> */
    public static function persistedMetadata(CaptureRecord $capture, array $diagnostics, array $input = []): array
    {
        $overlap = self::overlap($diagnostics);
        $candidates = [];
        foreach ((array) ($overlap['candidates'] ?? []) as $candidate) {
            if (!is_array($candidate)) continue;
            $candidates[] = array_filter([
                'article_id' => isset($candidate['article_id']) ? (int) $candidate['article_id'] : null,
                'post_id' => isset($candidate['post_id']) ? (int) $candidate['post_id'] : null,
                'title' => isset($candidate['title']) ? (string) $candidate['title'] : null,
                'route' => isset($candidate['route']) ? (string) $candidate['route'] : null,
                'slug' => isset($candidate['slug']) ? (string) $candidate['slug'] : null,
                'classification' => isset($candidate['classification']) ? (string) $candidate['classification'] : null,
                'overlap_score' => isset($candidate['overlap_score']) ? (float) $candidate['overlap_score'] : null,
                'matched_dimensions' => array_values(array_map('strval', (array) ($candidate['matched_dimensions'] ?? []))),
                'reason' => isset($candidate['reason']) ? (string) $candidate['reason'] : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
            if (count($candidates) >= 100) break;
        }
        return [
            'producer' => self::PRODUCER,
            'policy_version' => self::POLICY_VERSION,
            'policy_fingerprint' => self::POLICY_FINGERPRINT,
            'decision_dependency_fingerprint' => CaptureDecisionDependencyFingerprint::current($capture, $input),
            'classification' => (string) ($overlap['classification'] ?? ''),
            'reason' => (string) ($overlap['reason'] ?? ''),
            'candidate_ids' => array_values(array_unique(array_filter(array_map(
                static fn (array $candidate): string => (string) ($candidate['article_id'] ?? $candidate['post_id'] ?? ''),
                $candidates,
            )))),
            'candidates' => $candidates,
        ];
    }

    /** @param array<string,mixed> $diagnostics @return array<string,mixed> */
    public static function overlap(array $diagnostics): array
    {
        $resolution = is_array($diagnostics['article_resolution'] ?? null) ? $diagnostics['article_resolution'] : [];
        $research = is_array($resolution['research'] ?? null) ? $resolution['research'] : [];
        return is_array($research['overlap_analysis'] ?? null)
            ? $research['overlap_analysis']
            : (is_array($resolution['overlap'] ?? null) ? $resolution['overlap'] : []);
    }

    /** @param array<string,mixed> $diagnostics @param array<string,mixed> $research */
    private static function hasHardBlock(array $diagnostics, array $research): bool
    {
        if (in_array('OWNER_REVIEW_REQUIRED', array_map('strval', (array) ($research['blockers'] ?? [])), true)
            || in_array('SYSTEM_BLOCKED', array_map('strval', (array) ($research['blockers'] ?? [])), true)) return true;
        $failure = is_array($diagnostics['failure'] ?? null) ? $diagnostics['failure'] : [];
        if (strtoupper(trim((string) ($failure['classification'] ?? ''))) === 'HARD_BLOCK') return true;
        $preparation = is_array($diagnostics['content_preparation'] ?? null) ? $diagnostics['content_preparation'] : [];
        return strtoupper(trim((string) ($preparation['quality_decision'] ?? ''))) === 'HARD_BLOCK'
            || in_array('HARD_BLOCK', array_map('strval', (array) ($preparation['blockers'] ?? [])), true);
    }
}
