<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Application\PublicIdentity\CanonicalPublicSlugPolicy;
use NHK\Core\Domain\Video\Video;

final class VideoUrlPolicy
{
    /** @return array{path:?string,eligible:bool,blockers:list<string>,warnings:list<string>} */
    public function project(Video $video, VideoPublicContextSelector $selector): array
    {
        $metadata = is_array($video->metadata) ? $video->metadata : [];
        $identity = is_array($metadata['public_identity'] ?? null) ? $metadata['public_identity'] : [];
        $blockers = [];
        $slug = trim((string) ($identity['current_slug'] ?? ''));
        if (!CanonicalPublicSlugPolicy::isCanonical($slug)) $blockers[] = 'PUBLIC_IDENTITY_NOT_PERSISTED';
        if ($video->platform !== 'youtube' || preg_match('/^[A-Za-z0-9_-]{11}$/', $video->externalVideoId) !== 1 || !$video->hasValidPublicReference()) $blockers[] = 'SOURCE_IDENTITY_INVALID';

        $source = is_array($metadata['source_snapshot'] ?? null) ? $metadata['source_snapshot'] : [];
        if (($source['availability'] ?? 'unknown') !== 'available') $blockers[] = 'SOURCE_UNAVAILABLE';
        if (($source['embeddable'] ?? null) !== true) $blockers[] = 'SOURCE_NOT_EMBEDDABLE';
        $editorial = is_array($metadata['editorial'] ?? null) ? $metadata['editorial'] : [];
        if (trim((string) ($editorial['title'] ?? '')) === '' || trim((string) ($editorial['summary'] ?? '')) === '') $blockers[] = 'EDITORIAL_CONTEXT_INCOMPLETE';
        $hub = is_array($metadata['hub'] ?? ($metadata['category'] ?? null)) ? ($metadata['hub'] ?? $metadata['category']) : [];
        $hubPrimary = is_array($hub['primary'] ?? null) ? ($hub['primary']['key'] ?? $hub['primary']['label'] ?? '') : ($hub['primary'] ?? '');
        if (trim((string) $hubPrimary) === '') $blockers[] = 'VIDEO_HUB_UNRESOLVED';
        $provenance = is_array($metadata['provenance'] ?? ($source['provenance'] ?? null)) ? ($metadata['provenance'] ?? $source['provenance']) : [];
        if (trim((string) ($provenance['kind'] ?? '')) === '') $blockers[] = 'VIDEO_PROVENANCE_MISSING';
        if (!is_array($metadata['semantic_attachments'] ?? null) || $metadata['semantic_attachments'] === []) $blockers[] = 'NO_SEMANTIC_ATTACHMENT';

        $context = $this->context($metadata);
        if ($selector->select($context) === null && $slug === '') $blockers[] = 'GOVERNED_CONTEXT_MISSING';
        $eligible = $blockers === [];
        return [
            'path' => $eligible ? '/video/' . $slug . '-' . strtolower($video->externalVideoId) . '/' : null,
            'eligible' => $eligible,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function context(array $metadata): array
    {
        $context = is_array($metadata['governed_context'] ?? null) ? $metadata['governed_context'] : [];
        foreach (['variant', 'model', 'brand', 'music', 'editorial_context', 'user_hint'] as $key) if (array_key_exists($key, $metadata)) $context[$key] = $metadata[$key];
        if (!isset($context['editorial_context']) && is_array($metadata['editorial']['context'] ?? null)) $context['editorial_context'] = $metadata['editorial']['context'];
        return $context;
    }
}
