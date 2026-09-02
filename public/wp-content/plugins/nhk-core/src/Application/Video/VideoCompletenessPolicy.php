<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Domain\Video\{VideoCompletenessResult, VideoSourceRights};

final class VideoCompletenessPolicy
{
    /** @param array<string,mixed> $package */
    public function evaluate(array $package): VideoCompletenessResult
    {
        $blockers = [];
        $warnings = [];
        $source = is_array($package['source'] ?? null) ? $package['source'] : [];
        if (($source['identity_valid'] ?? true) !== true) $blockers[] = 'INVALID_SOURCE_IDENTITY';
        if (($source['availability'] ?? 'unknown') !== 'available') $blockers[] = 'SOURCE_UNAVAILABLE';
        if (($source['embeddable'] ?? true) === false) $blockers[] = 'SOURCE_NOT_EMBEDDABLE';
        if (!VideoSourceRights::isValid((string) ($package['source_rights'] ?? ''))) $blockers[] = 'SOURCE_RIGHTS_UNRESOLVED';
        $editorial = is_array($package['editorial'] ?? null) ? $package['editorial'] : [];
        foreach (['title', 'summary', 'body'] as $field) if (trim((string) ($editorial[$field] ?? '')) === '') $blockers[] = 'EDITORIAL_INCOMPLETE';
        $category = is_array($package['category'] ?? null) ? $package['category'] : [];
        if (!is_array($category['primary'] ?? null) || trim((string) ($category['primary']['key'] ?? '')) === '') $blockers[] = 'CATEGORY_UNRESOLVED';
        if (!is_array($package['semantic_attachments'] ?? null) || $package['semantic_attachments'] === []) $blockers[] = 'NO_SEMANTIC_ATTACHMENT';
        if (filter_var((string) ($package['embed_url'] ?? ''), FILTER_VALIDATE_URL) === false) $blockers[] = 'INVALID_EMBED_URL';
        $seo = is_array($package['seo'] ?? null) ? $package['seo'] : [];
        if (trim((string) ($seo['title'] ?? '')) === '' || trim((string) ($seo['description'] ?? '')) === '') $blockers[] = 'SEO_INCOMPLETE';
        if (($package['transcript_policy'] ?? 'NO_TRANSCRIPT') === 'NO_TRANSCRIPT') $warnings[] = 'TRANSCRIPT_UNAVAILABLE';
        if (!is_array($package['provenance'] ?? null) || $package['provenance'] === []) $warnings[] = 'PROVENANCE_INCOMPLETE';
        return new VideoCompletenessResult($blockers === [], array_values(array_unique($blockers)), array_values(array_unique($warnings)));
    }
}
