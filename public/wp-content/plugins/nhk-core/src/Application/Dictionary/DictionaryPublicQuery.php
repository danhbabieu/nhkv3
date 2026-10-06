<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

use NHK\Core\Contracts\Dictionary\DictionaryConceptRepository;
use NHK\Core\Domain\Dictionary\{DictionaryConcept, DictionaryLabel};
use NHK\Core\Domain\Dictionary\LexicalEntry;
use NHK\Core\Shared\Encoding\Utf8String;

final class DictionaryPublicQuery
{
    public function __construct(private DictionaryConceptRepository $concepts, private $imageResolver = null, private $destinationValidator = null, private $entries = null, private $entrySenseReady = null, private $semanticProjection = null, private ?DictionaryDetailQuery $detailQuery = null) {}

    public function hub(int $limit = 500, string $query = '', string $initial = ''): array
    {
        $query = $this->normalize($query);
        $initial = $this->normalizeInitial($initial);
        if ($this->entrySenseAvailable() && is_object($this->entries) && method_exists($this->entries, 'listEntries')) {
            $entryItems = [];
            $coveredConceptIds = [];
            foreach ((array) $this->entries->listEntries($limit) as $entry) {
                if (!$entry instanceof LexicalEntry) continue;
                $allSenses = array_values(array_filter((array) $this->entries->listSenses($entry), static fn (mixed $sense): bool => $sense instanceof DictionaryConcept));
                foreach ($allSenses as $sense) $coveredConceptIds[$sense->conceptId] = true;
                $senses = array_values(array_filter($allSenses, static fn (DictionaryConcept $sense): bool => $sense->approved()));
                $item = $this->entryHubItem($entry, $senses);
                if (($item['eligible'] ?? false) === true) $entryItems[] = $item;
            }
            $compatibilityItems = [];
            foreach ($this->concepts->listApproved($limit) as $concept) {
                if (!$concept instanceof DictionaryConcept || !$concept->approved() || isset($coveredConceptIds[$concept->conceptId])) continue;
                if (method_exists($this->entries, 'findDurableForConcept') && $this->entries->findDurableForConcept($concept->conceptId) instanceof LexicalEntry) continue;
                $item = $this->item($concept);
                if (($item['eligible'] ?? false) === true) $compatibilityItems[] = $item;
            }
            $items = $this->markAmbiguousCompatibilityItems(array_merge($entryItems, $compatibilityItems));
            $items = $this->filterAndRank($items, $query, $initial);
            return ['status' => 'AVAILABLE', 'items' => $items, 'count' => count($items), 'total_count' => count($items), 'query' => $query, 'initial' => $initial, 'canonical_url' => '/tu-dien/', 'warnings' => []];
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
        if ($this->detailQuery !== null) return $this->detailQuery->detail($slug);
        $slug = $this->slug($slug);
        if ($slug === '') return ['status' => 'NOT_FOUND'];
        if ($this->entrySenseAvailable() && is_object($this->entries) && method_exists($this->entries, 'listEntries')) {
            $matches = [];
            foreach ((array) $this->entries->listEntries(2000) as $entry) {
                if (!$entry instanceof LexicalEntry || $this->slug((string) ($entry->context['public_slug'] ?? '')) !== $slug) continue;
                $matches[] = $entry;
            }
            if (count($matches) > 1) return ['status' => 'AMBIGUOUS', 'slug' => $slug, 'match_count' => count($matches)];
            foreach ($matches as $entry) {
                $senses = array_values(array_filter((array) $this->entries->listSenses($entry), static fn (mixed $sense): bool => $sense instanceof DictionaryConcept && $sense->approved()));
                $item = $this->entryItem($entry, $senses);
                $item['related_terms'] = $this->relatedTerms($entry, $senses[0] ?? null, 12);
                if (($item['eligible'] ?? false) !== true) return ['status' => 'INCOMPLETE', 'reason' => 'DICTIONARY_ENTRY_NOT_PUBLIC'];
                return ['status' => 'READY', 'item' => $item, 'labels' => $item['labels'], 'canonical_url' => $item['url'], 'indexable' => (bool) ($item['indexable'] ?? false)];
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

    /** Lightweight Entry summary used by hub/search; no dossier, Graph or source reads. */
    private function entryHubItem(LexicalEntry $entry, array $senses): array
    {
        $slug = $this->slug((string) ($entry->context['public_slug'] ?? ''));
        if ($senses === []) return ['eligible' => false, 'entry_id' => $entry->entryId];
        $forms = $this->entryForms($entry);
        $labels = [];
        foreach ($senses as $sense) foreach ($this->concepts->listLabels($sense->conceptId) as $label) {
            if ($label instanceof DictionaryLabel && $label->active) $labels[] = ['label' => $label->label, 'kind' => $label->kind, 'locale' => $label->locale];
        }
        $searchLabels = array_merge($labels, array_map(static fn (array $form): array => ['label' => $form['form']], $forms));
        $ownerBacked = false;
        $ownerUrl = null;
        foreach ($senses as $sense) {
            [$semanticType, $semanticId, $reference] = $this->senseSemanticReference($entry, $sense);
            if ($semanticType === null || $semanticId === null) continue;
            $ownerBacked = true;
            if (count($senses) !== 1) continue;
            if (!is_callable($this->destinationValidator)) continue;
            try {
                $validated = ($this->destinationValidator)($semanticType, $semanticId, null);
                if (is_string($validated) && trim($validated) !== '') $ownerUrl = trim($validated);
                else return ['eligible' => false, 'entry_id' => $entry->entryId];
            } catch (\Throwable) {
                return ['eligible' => false, 'entry_id' => $entry->entryId];
            }
        }
        $url = $ownerUrl ?? ($slug !== '' ? '/tu-dien/' . $slug . '/' : null);
        if ($url === null) return ['eligible' => false, 'entry_id' => $entry->entryId];
        return ['entry_id' => $entry->entryId, 'title' => $entry->preferredForm, 'description' => count($senses) === 1 ? $senses[0]->definition : '', 'term_type' => 'ENTRY', 'labels' => array_values(array_filter($labels, static fn (array $label): bool => ($label['kind'] ?? '') !== 'HIDDEN'),), 'search_labels' => $searchLabels, 'url' => $url, 'dedicated' => !$ownerBacked || count($senses) > 1, 'indexable' => !$ownerBacked, 'eligible' => true, 'forms' => $forms, 'senses' => array_map(static fn (DictionaryConcept $sense): array => ['sense_id' => $sense->conceptId, 'title' => $sense->preferredLabel, 'description' => $sense->definition, 'context' => $sense->context], $senses), 'image' => null];
    }

    /** Keep ambiguous compatibility inventory visible without selecting an owner. */
    private function markAmbiguousCompatibilityItems(array $items): array
    {
        $groups = [];
        foreach ($items as $index => $item) {
            if (($item['term_type'] ?? '') === 'ENTRY' || !is_array($item)) continue;
            $key = $this->normalize((string) ($item['title'] ?? ''));
            if ($key === '') continue;
            $groups[$key][] = $index;
        }
        foreach ($groups as $indexes) {
            $destinations = [];
            foreach ($indexes as $index) $destinations[(string) ($items[$index]['destination_type'] ?? '') . ':' . (string) ($items[$index]['destination_id'] ?? '')] = true;
            if (count($destinations) < 2) continue;
            foreach ($indexes as $index) {
                $items[$index]['url'] = null;
                $items[$index]['eligible'] = true;
                $items[$index]['indexable'] = false;
                $items[$index]['ambiguous'] = true;
                $items[$index]['destination_candidates'] = array_keys($destinations);
            }
        }
        return $items;
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
        $entrySlug = $this->slug((string) ($entry->context['public_slug'] ?? ''));
        $senseItems = [];
        foreach ($senses as $sense) {
            $item = $this->item($sense);
            $hasDelegatedDestination = trim((string) ($sense->destinationType ?? '')) !== '' || trim((string) ($sense->destinationId ?? '')) !== '';
            if ($hasDelegatedDestination && ($item['eligible'] ?? false) !== true) return ['eligible' => false, 'entry_id' => $entry->entryId];
            [$semanticType, $semanticId, $semanticReference] = $this->senseSemanticReference($entry, $sense);
            $senseItem = ['sense_id' => $sense->conceptId, 'title' => $sense->preferredLabel, 'description' => $sense->definition, 'context' => $sense->context, 'url' => $item['url'], 'destination_type' => $semanticType, 'destination_id' => $semanticId, 'semantic_reference' => $semanticReference, 'labels' => $item['labels'], 'search_labels' => $item['search_labels'] ?? $item['labels']];
            $senseItem += $this->semanticSections($semanticType, $semanticId);
            $senseItems[] = $senseItem;
        }
        if ($senseItems === []) return ['eligible' => false, 'entry_id' => $entry->entryId];
        $first = $senseItems[0];
        $singleSense = count($senseItems) === 1;
        $delegated = $singleSense && trim((string) ($first['destination_type'] ?? '')) !== '' && trim((string) ($first['destination_id'] ?? '')) !== '';
        $url = $entrySlug !== '' ? '/tu-dien/' . $entrySlug . '/' : null;
        if ($delegated) {
            if (is_callable($this->destinationValidator)) {
                try {
                    $validated = ($this->destinationValidator)((string) $first['destination_type'], (string) $first['destination_id'], null);
                    if (!is_string($validated) || trim($validated) === '') return ['eligible' => false, 'entry_id' => $entry->entryId];
                    $url = trim($validated);
                } catch (\Throwable) {
                    return ['eligible' => false, 'entry_id' => $entry->entryId];
                }
            }
        }
        $forms = $this->entryForms($entry);
        return [
            'entry_id' => $entry->entryId,
            'title' => $entry->preferredForm,
            'description' => $singleSense ? $first['description'] : '',
            'term_type' => 'ENTRY',
            'labels' => $first['labels'],
            'search_labels' => array_values(array_merge($first['search_labels'], array_map(static fn (array $form): array => ['label' => $form['form']], $forms))),
            'url' => $url,
            'dedicated' => !$delegated,
            'indexable' => !$delegated && $url !== null,
            'eligible' => $url !== null,
            'forms' => $forms,
            'senses' => $senseItems,
            'canonical_owner' => $singleSense ? ($first['canonical_owner'] ?? null) : null,
            'knowledge' => $singleSense ? ($first['knowledge'] ?? ['items' => []]) : ['items' => []],
            'semantic_relations' => $singleSense ? ($first['semantic_relations'] ?? ['groups' => []]) : ['groups' => []],
            'brands' => $singleSense ? ($first['brands'] ?? ['items' => []]) : ['items' => []],
            'models' => $singleSense ? ($first['models'] ?? ['items' => []]) : ['items' => []],
            'specimens' => $singleSense ? ($first['specimens'] ?? ['items' => []]) : ['items' => []],
            'media' => $singleSense ? ($first['media'] ?? ['items' => []]) : ['items' => []],
            'videos' => $singleSense ? ($first['videos'] ?? ['items' => []]) : ['items' => []],
            'articles' => $singleSense ? ($first['articles'] ?? ['items' => []]) : ['items' => []],
            'mentions' => ['groups' => []],
            'image' => null,
        ];
    }

    /** @return array{0:?string,1:?string,2:array<string,mixed>} */
    private function senseSemanticReference(LexicalEntry $entry, DictionaryConcept $sense): array
    {
        if (is_object($this->entries) && method_exists($this->entries, 'semanticReference')) {
            try {
                $reference = ($this->entries)->semanticReference($entry->entryId, $sense->conceptId);
                if (is_array($reference)) {
                    $type = trim((string) ($reference['type'] ?? ''));
                    $id = trim((string) ($reference['id'] ?? ''));
                    if ($type !== '' && $id !== '') return [$type, $id, $reference];
                    if (strtoupper((string) ($reference['status'] ?? 'ABSENT')) !== 'ABSENT') return [null, null, $reference];
                }
            } catch (\Throwable) {
                // Compatibility fallback below is intentionally read-only.
            }
        }
        $type = trim((string) ($sense->destinationType ?? ''));
        $id = trim((string) ($sense->destinationId ?? ''));
        return [$type !== '' ? $type : null, $id !== '' ? $id : null, [
            'status' => $type !== '' && $id !== '' ? 'AVAILABLE' : 'ABSENT',
            'type' => $type !== '' ? $type : null,
            'id' => $id !== '' ? $id : null,
            'revision' => null,
            'source' => $type !== '' && $id !== '' ? 'LEGACY_CONCEPT_SNAPSHOT' : 'NONE',
        ]];
    }

    /** @return list<array<string,mixed>> */
    private function entryForms(LexicalEntry $entry): array
    {
        if (!is_object($this->entries) || !method_exists($this->entries, 'listForms')) return [['form' => $entry->preferredForm, 'kind' => 'PREFERRED', 'locale' => $entry->locale, 'context' => $entry->context]];
        $forms = [];
        foreach ((array) $this->entries->listForms($entry) as $form) {
            if (is_object($form) && property_exists($form, 'form')) $forms[] = ['form' => (string) $form->form, 'kind' => (string) ($form->kind ?? 'ALTERNATE'), 'locale' => $form->locale ?? null, 'context' => is_array($form->context ?? null) ? $form->context : []];
            elseif (is_array($form) && trim((string) ($form['form'] ?? '')) !== '') $forms[] = ['form' => (string) $form['form'], 'kind' => (string) ($form['kind'] ?? 'ALTERNATE'), 'locale' => $form['locale'] ?? null, 'context' => is_array($form['context'] ?? null) ? $form['context'] : []];
        }
        return $forms !== [] ? $forms : [['form' => $entry->preferredForm, 'kind' => 'PREFERRED', 'locale' => $entry->locale, 'context' => $entry->context]];
    }

    /** @return array<string,mixed> */
    private function semanticSections(?string $type, ?string $id): array
    {
        $empty = ['canonical_owner' => null, 'knowledge' => ['items' => []], 'semantic_relations' => ['groups' => []], 'brands' => ['items' => []], 'models' => ['items' => []], 'specimens' => ['items' => []], 'media' => ['items' => []], 'videos' => ['items' => []], 'articles' => ['items' => []]];
        if (!is_callable($this->semanticProjection) || trim((string) $type) === '' || trim((string) $id) === '') return $empty;
        try {
            $projection = ($this->semanticProjection)((string) $type, (string) $id);
            if (!is_array($projection)) return $empty;
            $owner = is_array($projection['identity'] ?? null) ? $projection['identity'] : null;
            $relations = is_array($projection['relation_sections'] ?? null) ? $projection['relation_sections'] : [];
            $knowledge = is_array($projection['knowledge'] ?? null) ? $projection['knowledge'] : [];
            $empty['canonical_owner'] = $owner;
            $knowledgeItems = is_array($knowledge['items'] ?? null) ? $knowledge['items'] : [];
            if ($knowledgeItems === [] && is_array($knowledge['facets'] ?? null)) foreach ($knowledge['facets'] as $facet) foreach ((array) $facet as $item) if (is_array($item)) $knowledgeItems[] = $item;
            $empty['knowledge'] = ['items' => array_slice($knowledgeItems, 0, 6), 'has_more' => count($knowledgeItems) > 6];
            $empty['semantic_relations'] = ['groups' => $relations];
            foreach (['brands', 'models', 'specimens', 'media', 'videos', 'articles'] as $group) $empty[$group] = ['items' => is_array($relations[$group] ?? null) ? $relations[$group] : []];
            return $empty;
        } catch (\Throwable) { return $empty; }
    }

    /** @return list<array<string,mixed>> */
    private function relatedTerms(LexicalEntry $entry, ?DictionaryConcept $sense, int $limit): array
    {
        if (!is_object($this->entries) || !method_exists($this->entries, 'listEntries')) return [];
        $items = [];
        $ownerType = $sense?->destinationType;
        $ownerId = $sense?->destinationId;
        if (trim((string) $ownerType) === '' || trim((string) $ownerId) === '') return [];
        foreach ((array) $this->entries->listEntries(max(1, min(2000, $limit + 1))) as $candidate) {
            if (!$candidate instanceof LexicalEntry || $candidate->entryId === $entry->entryId) continue;
            $candidateSenses = (array) $this->entries->listSenses($candidate);
            $sharedOwner = false;
            foreach ($candidateSenses as $candidateSense) if ($candidateSense instanceof DictionaryConcept && $candidateSense->destinationType === $ownerType && $candidateSense->destinationId === $ownerId) { $sharedOwner = true; break; }
            if (!$sharedOwner) continue;
            $slug = $this->slug((string) ($candidate->context['public_slug'] ?? ''));
            if ($slug === '') continue;
            $items[] = ['entry_id' => $candidate->entryId, 'title' => $candidate->preferredForm, 'url' => '/tu-dien/' . $slug . '/'];
            if (count($items) >= $limit) break;
        }
        return $items;
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
        $initial = Utf8String::slice($value, 0, 1, 'dictionary.public.query', 'initial');
        return function_exists('mb_strtoupper') ? mb_strtoupper($initial, 'UTF-8') : strtoupper($initial);
    }

    private function entrySenseAvailable(): bool
    {
        if (!is_callable($this->entrySenseReady)) return true;
        try { return (bool) ($this->entrySenseReady)(); } catch (\Throwable) { return false; }
    }
}
