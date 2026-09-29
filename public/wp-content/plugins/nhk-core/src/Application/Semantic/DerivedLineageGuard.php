<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Prevents derived editorial copies from becoming independent corroboration. */
final class DerivedLineageGuard
{
    /** @param array<string,mixed> $context */
    public function isIndependent(array $context): bool
    {
        $kind = strtoupper(trim((string) ($context['source_kind'] ?? '')));
        $lineage = is_array($context['lineage'] ?? null) ? $context['lineage'] : [];
        if (($lineage['derived'] ?? false) === true) return false;
        if (($lineage['parent_claim_ids'] ?? []) !== []) return false;
        if (($lineage['parent_source_ids'] ?? []) !== []) return false;
        if (in_array($kind, ['ARTICLE', 'ARTICLE_SUMMARY', 'SEO', 'GENERATED_ARTICLE', 'GENERATED_SUMMARY'], true)) return false;
        return true;
    }
}
