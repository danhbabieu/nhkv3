<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

/** Public, bounded related-term projection; it never infers lexical similarity. */
final class DictionaryRelatedTermProjection
{
    public function __construct(private object $entries, private $graphResolver = null) {}

    /** @return list<array<string,mixed>> */
    public function forReference(string $currentEntryId, string $type, string $id, int $limit = 12): array
    {
        $limit = max(1, min(12, $limit));
        if (!method_exists($this->entries, 'findEntriesBySemanticReference')) return [];
        $items = [];
        $ownerCandidates = [['type' => $type, 'id' => $id, 'origin' => ['kind' => 'DIRECT', 'hop_count' => 0]]];
        if (is_callable($this->graphResolver)) {
            try {
                foreach ((array) ($this->graphResolver)($type, $id) as $candidate) {
                    if (!is_array($candidate)) continue;
                    $candidateType = trim((string) ($candidate['type'] ?? ''));
                    $candidateId = trim((string) ($candidate['id'] ?? $candidate['canonical_id'] ?? ''));
                    $origin = is_array($candidate['origin'] ?? null) ? $candidate['origin'] : ['kind' => 'DERIVED', 'hop_count' => 2];
                    if ($candidateType !== '' && $candidateId !== '' && (int) ($origin['hop_count'] ?? 99) <= 2) $ownerCandidates[] = ['type' => $candidateType, 'id' => $candidateId, 'origin' => $origin];
                }
            } catch (\Throwable) { $ownerCandidates = [reset($ownerCandidates)]; }
        }
        $ownerCandidates = array_values(array_reduce($ownerCandidates, static function (array $carry, array $candidate): array { $key = $candidate['type'] . ':' . $candidate['id']; $carry[$key] ??= $candidate; return $carry; }, []));
        foreach ($ownerCandidates as $owner) {
            try { $rows = $this->entries->findEntriesBySemanticReference($owner['type'], $owner['id'], $limit + 1); } catch (\Throwable) { continue; }
            foreach ((array) $rows as $entry) {
                if (!is_object($entry) || (string) ($entry->entryId ?? '') === $currentEntryId) continue;
                $slug = $this->slug((string) (($entry->context['public_slug'] ?? '') ?: ($entry->preferredForm ?? '')));
                $title = trim((string) ($entry->preferredForm ?? ''));
                if ($slug === '' || $title === '') continue;
                $key = (string) ($entry->entryId ?? $slug);
                $items[$key] = ['entry_id' => $key, 'title' => $title, 'url' => '/tu-dien/' . $slug . '/', 'origin' => $owner['origin']];
            }
        }
        uasort($items, static fn (array $a, array $b): int => [($a['origin']['kind'] ?? '') === 'DIRECT' ? 0 : 1, (int) ($a['origin']['hop_count'] ?? 99), (string) $a['title'], (string) $a['entry_id']] <=> [($b['origin']['kind'] ?? '') === 'DIRECT' ? 0 : 1, (int) ($b['origin']['hop_count'] ?? 99), (string) $b['title'], (string) $b['entry_id']]);
        return array_slice(array_values($items), 0, $limit);
    }

    private function slug(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        if (function_exists('sanitize_title')) return (string) sanitize_title($value);
        return trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower($value)), '-');
    }
}
