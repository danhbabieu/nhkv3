<?php
declare(strict_types=1);

namespace NHK\Core\Application\Article;

final class ArticleSeoGate
{
    public function evaluate(array $preflight): array
    {
        $blockers = [];
        foreach (['intent', 'subject', 'canonical_url', 'title', 'h1'] as $field) if (($preflight[$field] ?? null) === null || $preflight[$field] === '' || $preflight[$field] === []) $blockers[] = 'MISSING_' . strtoupper($field);
        if (($preflight['indexable'] ?? false) !== true) $blockers[] = 'INDEXABILITY_BLOCKED';
        if (($preflight['media_complete'] ?? true) !== true) $blockers[] = 'MEDIA_INCOMPLETE';
        if (strtoupper((string) ($preflight['compliance'] ?? 'PASS')) === 'BLOCKED') $blockers[] = 'COMPLIANCE_BLOCKED';
        return ['ready' => $blockers === [], 'canonical_url' => $preflight['canonical_url'] ?? null, 'blockers' => array_values(array_unique($blockers))];
    }
}
