<?php
declare(strict_types=1);

namespace NHK\Core\Application\Seo;

use NHK\Core\Domain\Seo\SeoReadinessResult;

/** Read-only, shared public URL package for SEO and visitor-facing links. */
final class PublicSeoProjection
{
    /** @param array<string,mixed> $urlResult @param array<string,mixed> $page @return array<string,mixed> */
    public function project(array $urlResult, array $page = []): array
    {
        $path = isset($urlResult['path']) && is_string($urlResult['path']) ? trim($urlResult['path']) : '';
        $canonicalInput = isset($urlResult['canonical_url']) && is_string($urlResult['canonical_url']) ? trim($urlResult['canonical_url']) : $path;
        $renderedInput = isset($urlResult['rendered_url']) && is_string($urlResult['rendered_url']) ? trim($urlResult['rendered_url']) : $canonicalInput;
        $canonicalUrl = $this->publicUrl($canonicalInput);
        $renderedUrl = $this->publicUrl($renderedInput);
        $readiness = $urlResult['readiness'] ?? (($urlResult['eligible'] ?? false) === true ? SeoReadinessResult::READY : SeoReadinessResult::BLOCKED);
        $indexability = (new SeoIndexabilityPolicy())->evaluate([
            'readiness' => $readiness,
            'public_eligible' => $urlResult['public_eligible'] ?? (($urlResult['eligible'] ?? false) === true),
            'canonical_url' => $canonicalUrl,
            'rendered_url' => $renderedUrl,
        ]);
        $eligible = ($urlResult['eligible'] ?? false) === true && $path !== '' && $indexability->indexable();
        $canonicalUrl = $eligible ? $canonicalUrl : null;
        $canonicalPath = $eligible ? $path : null;
        $title = trim((string) ($page['title'] ?? ''));
        $description = trim((string) ($page['description'] ?? ''));
        $jsonLd = [];
        if ($eligible) {
            $jsonLd = ['url' => $canonicalUrl, '@id' => $canonicalUrl];
            if (isset($page['type']) && is_string($page['type']) && $page['type'] !== '') $jsonLd['@type'] = $page['type'];
            $jsonLd['mainEntityOfPage'] = $canonicalUrl;
        }
        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonicalUrl,
            'canonical_path' => $canonicalPath,
            'canonical_url' => $canonicalUrl,
            'open_graph' => $eligible ? ['url' => $canonicalUrl, 'title' => $title, 'description' => $description] : [],
            'json_ld' => $jsonLd,
            'sitemap' => $canonicalUrl,
            'breadcrumb' => $canonicalUrl,
            'card' => $canonicalPath,
            'search' => $canonicalPath,
            'internal_link' => $canonicalPath,
            'indexable' => $eligible,
            'readiness' => $readiness,
            'blockers' => array_values(array_unique(array_map('strval', is_array($urlResult['blockers'] ?? null) ? $urlResult['blockers'] : []))),
            'warnings' => array_values(array_unique(array_map('strval', is_array($urlResult['warnings'] ?? null) ? $urlResult['warnings'] : []))),
            'revision' => $urlResult['revision'] ?? ($urlResult['identity_revision'] ?? null),
        ];
    }

    /** @return array{path:string,eligible:true,blockers:list<string>,warnings:list<string>} */
    public function eligibleUrl(string $path): array
    {
        return ['path' => $path, 'eligible' => true, 'blockers' => [], 'warnings' => []];
    }

    public function publicUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('#^https?://#i', $value) === 1) return $value;
        if (function_exists('home_url')) return (string) home_url('/' . ltrim($value, '/'));
        return $value;
    }
}
