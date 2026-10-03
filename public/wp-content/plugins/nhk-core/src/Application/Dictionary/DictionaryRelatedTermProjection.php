<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

/** Public, bounded related-term projection; it never infers lexical similarity. */
final class DictionaryRelatedTermProjection
{
    public function __construct(private object $entries) {}

    /** @return list<array<string,mixed>> */
    public function forReference(string $currentEntryId, string $type, string $id, int $limit = 12): array
    {
        $limit = max(1, min(12, $limit));
        if (!method_exists($this->entries, 'findEntriesBySemanticReference')) return [];
        try {
            $rows = $this->entries->findEntriesBySemanticReference($type, $id, $limit + 1);
        } catch (\Throwable) { return []; }
        $items = [];
        foreach ((array) $rows as $entry) {
            if (!is_object($entry) || (string) ($entry->entryId ?? '') === $currentEntryId) continue;
            $slug = $this->slug((string) (($entry->context['public_slug'] ?? '') ?: ($entry->preferredForm ?? '')));
            $title = trim((string) ($entry->preferredForm ?? ''));
            if ($slug === '' || $title === '') continue;
            $key = (string) ($entry->entryId ?? $slug);
            $items[$key] = ['entry_id' => $key, 'title' => $title, 'url' => '/tu-dien/' . $slug . '/'];
            if (count($items) >= $limit) break;
        }
        ksort($items, SORT_STRING);
        return array_values($items);
    }

    private function slug(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        if (function_exists('sanitize_title')) return (string) sanitize_title($value);
        return trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower($value)), '-');
    }
}
