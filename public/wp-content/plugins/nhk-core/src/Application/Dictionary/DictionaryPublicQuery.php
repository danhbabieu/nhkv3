<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

use NHK\Core\Contracts\Dictionary\DictionaryConceptRepository;
use NHK\Core\Domain\Dictionary\{DictionaryConcept, DictionaryLabel};
use NHK\Core\Domain\Dictionary\LexicalEntry;

final class DictionaryPublicQuery
{
    public function __construct(private DictionaryConceptRepository $concepts, private $imageResolver = null, private $destinationValidator = null, private $entries = null, private $entrySenseReady = null) {}

    public function hub(int $limit = 500, string $query = '', string $initial = ''): array
    {
        $query = $this->normalize($query);
        $initial = $this->normalizeInitial($initial);
        if ($this->entrySenseAvailable() && is_object($this->entries) && method_exists($this->entries, 'listEntries')) {
            $entryItems = [];
            foreach ((array) $this->entries->listEntries($limit) as $entry) {
                if (!$entry instanceof LexicalEntry) continue;
                $senses = array_values(array_filter((array) $this->entries->listSenses($entry), static fn (mixed $sense): bool => $sense instanceof DictionaryConcept && $sense->approved()));
                $item = $this->entryItem($entry, $senses);
                if (($item['eligible'] ?? false) === true) $entryItems[] = $item;
            }
            $entryItems = $this->filterAndRank($entryItems, $query, $initial);
            return ['status' => 'AVAILABLE', 'items' => $entryItems, 'count' => count($entryItems), 'total_count' => count($entryItems), 'query' => $query, 'initial' => $initial, 'canonical_url' => '/tu-dien/', 'warnings' => []];
        }
        $items = [];
        $warnings = [];
        foreach ($this->concepts->listApproved($limit) as $concept) {
            if (!$concept instanceof DictionaryConcept || !$concept->approved()) continue;
            $item = $this->item($concept);
            if (($item['eligible'] ?? false) !== true) { $warnings[] = 'DICTIONARY_DESTINATION_INCOMPLETE:' . $concept->conceptId; continue; }
            $items[] = $item;
        }
        $items = $this->filterAndRank($items, $query, $initial);
        return ['status' => 'AVAILABLE', 'items' => $items, 'count' => count($items), 'total_count' => count($items), 'query' => $query, 'initial' => $initial, 'canonical_url' => '/tu-dien/', 'warnings' => $warnings];
    }

    public function detail(string $slug): array
    {
        $slug = $this->slug($slug);
        if ($slug === '') return ['status' => 'NOT_FOUND'];
        if ($this->entrySenseAvailable() && is_object($this->entries) && method_exists($this->entries, 'listEntries')) {
            $matches = [];
            foreach ((array) $this->entries->listEntries(2000) as $entry) {
                if (!$entry instanceof LexicalEntry || $this->slug((string) ($entry->context['public_slug'] ?? $entry->preferredForm)) !== $slug) continue;
                $matches[] = $entry;
            }
            if (count($matches) > 1) return ['status' => 'AMBIGUOUS', 'slug' => $slug, 'match_count' => count($matches)];
            foreach ($matches as $entry) {
                $senses = array_values(array_filter((array) $this->entries->listSenses($entry), static fn (mixed $sense): bool => $sense instanceof DictionaryConcept && $sense->approved()));
                $item = $this->entryItem($entry, $senses);
                if (($item['eligible'] ?? false) !== true) return ['status' => 'INCOMPLETE', 'reason' => 'DICTIONARY_ENTRY_NOT_PUBLIC'];
                if (($item['dedicated'] ?? true) === false) return ['status' => 'REDIRECT', 'destination_url' => $item['url'], 'entry_id' => $entry->entryId];
                return ['status' => 'READY', 'item' => $item, 'labels' => $item['labels'], 'canonical_url' => $item['url'], 'indexable' => true];
            }
        }
        $matches = [];
        foreach ($this->concepts->listApproved(2000) as $concept) {
            if (!$concept instanceof DictionaryConcept || !$concept->approved()) continue;
            $publicSlug = $this->slug((string) ($concept->context['public_slug'] ?? ''));
            if ($publicSlug === $slug) $matches[] = $concept;
        }
        if (count($matches) !== 1) return ['status' => count($matches) > 1 ? 'AMBIGUOUS' : 'NOT_FOUND'];
        $concept = $matches[0];
        $item = $this->item($concept);
        if (($item['eligible'] ?? false) !== true) return ['status' => 'INCOMPLETE', 'reason' => 'CANONICAL_DESTINATION_NOT_READY', 'concept_id' => $concept->conceptId];
        if (($item['dedicated'] ?? true) === false) return ['status' => 'REDIRECT', 'destination_url' => $item['url'], 'concept_id' => $concept->conceptId];
        return ['status' => 'READY', 'item' => $item, 'labels' => $item['labels'], 'canonical_url' => $item['url'], 'indexable' => true];
    }

    private function item(DictionaryConcept $concept): array
    {
        $labels = [];
        foreach ($this->concepts->listLabels($concept->conceptId) as $label) {
            if (!$label instanceof DictionaryLabel || !$label->active) continue;
            $labels[] = ['label' => $label->label, 'kind' => $label->kind, 'locale' => $label->locale, 'context' => $label->context];
        }
        $delegated = trim((string) $concept->destinationUrl) !== '';
        $slug = $this->slug((string) ($concept->context['public_slug'] ?? ''));
        $url = $delegated ? $concept->destinationUrl : ($slug !== '' ? '/tu-dien/' . $slug . '/' : null);
        $eligible = $url !== null;
        if ($delegated && is_callable($this->destinationValidator)) {
            try {
                $validated = ($this->destinationValidator)($concept->destinationType, $concept->destinationId, $concept->destinationUrl);
                if (is_string($validated) && trim($validated) !== '') {
                    $url = trim($validated);
                    $eligible = true;
                } else {
                    $eligible = $validated === true;
                    if (!$eligible) $url = null;
                }
            } catch (\Throwable) {
                $eligible = false;
                $url = null;
            }
        }
        $image = null;
        if ($eligible && is_callable($this->imageResolver)) {
            try { $value = ($this->imageResolver)($concept->conceptId); if (is_array($value)) $image = $value; }
            catch (\Throwable) { $image = null; }
        }
        return [
            'concept_id' => $concept->conceptId,
            'title' => $concept->preferredLabel,
            'description' => $concept->definition,
            'term_type' => (string) ($concept->context['term_type'] ?? 'GENERAL'),
            'category' => (string) ($concept->context['category'] ?? ''),
            'usage_scope' => is_array($concept->context['usage_scope'] ?? null) ? $concept->context['usage_scope'] : [],
            'labels' => array_values(array_filter($labels, static fn (array $label): bool => (string) ($label['kind'] ?? '') !== 'HIDDEN')),
            'search_labels' => $labels,
            'url' => $eligible ? $url : null,
            'dedicated' => !$delegated,
            'destination_type' => $concept->destinationType,
            'destination_id' => $concept->destinationId,
            'image' => $image,
            'eligible' => $eligible,
            'indexable' => $eligible && !$delegated,
        ];
    }

    /** @param list<array<string,mixed>> $items @return list<array<string,mixed>> */
    private function filterAndRank(array $items, string $query, string $initial): array
    {
        $ranked = [];
        foreach ($items as $item) {
            $title = $this->normalize((string) ($item['title'] ?? ''));
            if ($initial !== '' && $this->normalizeInitial($title) !== $initial) continue;
            $fields = [$title];
            foreach ((array) ($item['search_labels'] ?? $item['labels'] ?? []) as $label) if (is_array($label)) $fields[] = $this->normalize((string) ($label['label'] ?? ''));
            $fields[] = $this->normalize((string) ($item['description'] ?? ''));
            $score = $query === '' ? 0 : $this->matchScore($title, $fields, $query);
            if ($query !== '' && $score === null) continue;
            $item['_public_search_score'] = $score ?? 0;
            $ranked[] = $item;
        }
        usort($ranked, static function (array $a, array $b): int {
            $score = ((int) ($b['_public_search_score'] ?? 0)) <=> ((int) ($a['_public_search_score'] ?? 0));
            if ($score !== 0) return $score;
            return strnatcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
        });
        foreach ($ranked as &$item) unset($item['_public_search_score']);
        unset($item);
        return $ranked;
    }

    /** @param list<string> $fields */
    private function matchScore(string $title, array $fields, string $query): ?int
    {
        if ($title === $query) return 700;
        foreach (array_slice($fields, 1) as $field) if ($field === $query) return 600;
        if (str_starts_with($title, $query)) return 500;
        foreach (array_slice($fields, 1) as $field) if (str_starts_with($field, $query)) return 400;
        if (str_contains($title, $query)) return 300;
        foreach (array_slice($fields, 1) as $field) if (str_contains($field, $query)) return 200;
        $definition = end($fields);
        return is_string($definition) && str_contains($definition, $query) ? 100 : null;
    }

    private function entryItem(LexicalEntry $entry, array $senses): array
    {
        $entrySlug = $this->slug((string) ($entry->context['public_slug'] ?? $entry->preferredForm));
        $senseItems = [];
        foreach ($senses as $sense) {
            $item = $this->item($sense);
            $hasDelegatedDestination = trim((string) ($sense->destinationType ?? '')) !== '' || trim((string) ($sense->destinationId ?? '')) !== '';
            if ($hasDelegatedDestination && ($item['eligible'] ?? false) !== true) return ['eligible' => false, 'entry_id' => $entry->entryId];
            $senseItems[] = ['sense_id' => $sense->conceptId, 'title' => $sense->preferredLabel, 'description' => $sense->definition, 'context' => $sense->context, 'url' => $item['url'], 'destination_type' => $sense->destinationType, 'destination_id' => $sense->destinationId, 'labels' => $item['labels'], 'search_labels' => $item['search_labels'] ?? $item['labels']];
        }
        if ($senseItems === []) return ['eligible' => false, 'entry_id' => $entry->entryId];
        $delegated = count($senseItems) === 1 && trim((string) ($senseItems[0]['destination_type'] ?? '')) !== '';
        $url = $delegated ? $senseItems[0]['url'] : ($entrySlug !== '' ? '/tu-dien/' . $entrySlug . '/' : null);
        return ['entry_id' => $entry->entryId, 'title' => $entry->preferredForm, 'description' => $senseItems[0]['description'], 'term_type' => 'ENTRY', 'labels' => $senseItems[0]['labels'], 'search_labels' => $senseItems[0]['search_labels'], 'url' => $url, 'dedicated' => !$delegated, 'indexable' => !$delegated && $url !== null, 'eligible' => $url !== null, 'senses' => $senseItems, 'image' => null];
    }

    private function slug(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        if (function_exists('sanitize_title')) return (string) sanitize_title($value);
        $value = function_exists('iconv') ? (string) (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value) : $value;
        return trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower($value)), '-');
    }

    private function normalize(string $value): string
    {
        return (new DictionaryTermNormalizer())->normalize($value);
    }

    private function normalizeInitial(string $value): string
    {
        $value = $this->normalize($value);
        if ($value === '') return '';
        $initial = function_exists('mb_substr') ? mb_substr($value, 0, 1, 'UTF-8') : substr($value, 0, 1);
        return function_exists('mb_strtoupper') ? mb_strtoupper($initial, 'UTF-8') : strtoupper($initial);
    }

    private function entrySenseAvailable(): bool
    {
        if (!is_callable($this->entrySenseReady)) return true;
        try { return (bool) ($this->entrySenseReady)(); } catch (\Throwable) { return false; }
    }
}
