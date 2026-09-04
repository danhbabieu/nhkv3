<?php
declare(strict_types=1);

namespace NHK\Core\Application\Seo;

use NHK\Core\Domain\PublicIdentity\PublicUrlResult;

/** The single read-only URL package shared by public SEO and link consumers. */
final class PublicSeoProjection
{
    /** @param array<string,mixed> $page @return array<string,mixed> */
    public function project(PublicUrlResult $url, array $page = []): array
    {
        $path = $url->eligible ? $url->finalPath : null;
        $jsonLd = $path === null ? [] : ['url' => $path, 'mainEntityOfPage' => $path];
        if ($path !== null && isset($page['type']) && is_string($page['type']) && $page['type'] !== '') $jsonLd['@type'] = $page['type'];
        $title = trim((string) ($page['title'] ?? ''));
        $description = trim((string) ($page['description'] ?? ''));
        return [
            'canonical' => $path,
            'open_graph' => $path === null ? [] : ['url' => $path, 'title' => $title, 'description' => $description],
            'json_ld' => $jsonLd,
            'sitemap' => $path,
            'breadcrumb' => $path,
            'card' => $path,
            'search' => $path,
            'internal_link' => $path,
            'indexable' => $path !== null,
            'blockers' => $url->blockers,
            'warnings' => $url->warnings,
            'identity_revision' => $url->identityRevision,
        ];
    }
}
