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
        $readiness = $urlResult['readiness'] ?? (($urlResult['eligible'] ?? false) === true ? SeoReadinessResult::READY : SeoReadinessResult::BLOCKED);
        $eligible = ($urlResult['eligible'] ?? false) === true && $readiness === SeoReadinessResult::READY && $path !== '';
        $path = $eligible ? $path : null;
        $title = trim((string) ($page['title'] ?? ''));
        $description = trim((string) ($page['description'] ?? ''));
        $jsonLd = [];
        if ($eligible) {
            $jsonLd = ['url' => $path];
            if (isset($page['type']) && is_string($page['type']) && $page['type'] !== '') $jsonLd['@type'] = $page['type'];
            $jsonLd['mainEntityOfPage'] = $path;
        }
        return [
            'canonical' => $path,
            'open_graph' => $eligible ? ['url' => $path, 'title' => $title, 'description' => $description] : [],
            'json_ld' => $jsonLd,
            'sitemap' => $path,
            'breadcrumb' => $path,
            'card' => $path,
            'search' => $path,
            'internal_link' => $path,
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
}
