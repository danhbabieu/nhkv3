<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

use NHK\Core\Contracts\Knowledge\{KnowledgePageReader, KnowledgeRepository};
use NHK\Core\Domain\Knowledge\KnowledgeClaim;

/** Reads canonical Knowledge claim text internally; callers receive no body. */
final class KnowledgeDictionaryCorpusReader implements DictionaryCorpusSourceReader
{
    public function __construct(private KnowledgeRepository $claims) {}

    public function page(?string $after, int $limit): array
    {
        if ($this->claims instanceof KnowledgePageReader) $page = $this->claims->page(false, $after, $limit);
        else {
            $items = array_values(array_filter($this->claims->list(false), static fn ($claim): bool => $claim instanceof KnowledgeClaim && ($after === null || strcmp($claim->stableKey, $after) > 0)));
            usort($items, static fn (KnowledgeClaim $a, KnowledgeClaim $b): int => strcmp($a->stableKey, $b->stableKey));
            $page = ['items' => array_slice($items, 0, $limit), 'has_more' => count($items) > $limit];
        }
        $items = [];
        foreach ((array) ($page['items'] ?? []) as $claim) {
            if (!$claim instanceof KnowledgeClaim) continue;
            $metadata = is_array($claim->provenance['metadata'] ?? null) ? $claim->provenance['metadata'] : [];
            $family = trim((string) ($metadata['source_family'] ?? $metadata['source_id'] ?? '')) ?: 'knowledge:' . $claim->stableKey;
            $items[] = ['source_id' => $claim->stableKey, 'source_family' => $family, 'source_kind' => 'KNOWLEDGE', 'raw_text' => $claim->claimText, 'raw_or_derived' => strtoupper((string) ($metadata['raw_or_derived'] ?? 'RAW')), 'lineage' => is_array($metadata['lineage'] ?? null) ? $metadata['lineage'] : [], 'context' => $metadata, 'locale' => (string) ($metadata['locale'] ?? 'vi-VN')];
        }
        return ['items' => $items, 'has_more' => (bool) ($page['has_more'] ?? false)];
    }
}
