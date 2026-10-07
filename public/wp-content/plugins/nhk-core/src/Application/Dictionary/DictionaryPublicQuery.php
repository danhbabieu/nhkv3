<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

use NHK\Core\Contracts\Dictionary\DictionaryConceptRepository;
use NHK\Core\Domain\Dictionary\{DictionaryConcept, DictionaryLabel};
use NHK\Core\Domain\Dictionary\LexicalEntry;
use NHK\Core\Shared\Encoding\Utf8String;

final class DictionaryPublicQuery
{
    private DictionaryLexicalBrowsePolicy $browsePolicy;

    public function __construct(private DictionaryConceptRepository $concepts, private $imageResolver = null, private $destinationValidator = null, private $entries = null, private $entrySenseReady = null, private $semanticProjection = null, private ?DictionaryDetailQuery $detailQuery = null, private ?DictionaryDetailPresentationComposer $presentationComposer = null)
    {
        $this->browsePolicy = new DictionaryLexicalBrowsePolicy();
    }

    /**
     * Complete read-only public archive projection. This is intentionally
     * separate from the legacy hub signature so existing bounded consumers can
     * migrate without creating a second eligibility or destination policy.
     *
     * @param array{query?:string,initial?:string,page_size?:int,cursor?:string|null} $request
     * @return array<string,mixed>
     */
    public function archive(array $request = []): array
    {
        $query = $this->browsePolicy->searchKey((string) ($request['query'] ?? ''));
        $initial = $this->browsePolicy->normalizeInitial(isset($request['initial']) ? (string) $request['initial'] : null);
        $pageSize = max(1, min(500, (int) ($request['page_size'] ?? 24)));
        if ($initial === null) return $this->archiveConflict('DICTIONARY_INITIAL_INVALID');

        $cursor = null;
        if (isset($request['cursor']) && trim((string) $request['cursor']) !== '') {
            $cursor = $this->decodeArchiveCursor((string) $request['cursor'], $query, $initial);
            if ($cursor === null) return $this->archiveConflict('DICTIONARY_CURSOR_INVALID');
        }

        $alphabet = $this->browsePolicy->emptyAlphabet();
        $seen = [];
        $selected = [];
        $total = 0;
        $remaining = 0;
        $cursorSeen = $cursor === null;
        try {
            foreach ($this->archiveItemStream($query, $initial) as $item) {
                $identity = (($item['destination_mode'] ?? '') === 'DELEGATED')
                    ? 'delegated:' . $this->browsePolicy->searchKey((string) ($item['title'] ?? '')) . ':' . trim((string) ($item['url'] ?? ''))
                    : 'entry:' . trim((string) ($item['entry_id'] ?? $item['concept_id'] ?? ''));
                if ($identity === 'entry:' || isset($seen[$identity])) continue;
                $seen[$identity] = true;
                $item['_browse_sort_key'] = $this->browsePolicy->sortKey((string) ($item['title'] ?? ''));
                $total++;
                $bucket = $this->browsePolicy->initial((string) ($item['title'] ?? ''));
                if ($bucket === '#' && array_filter($alphabet, static fn (array $metadata): bool => $metadata['key'] === '#') === []) $alphabet[] = ['key' => '#', 'label' => '#', 'count' => 0, 'available' => false];
                foreach ($alphabet as &$metadata) if ($metadata['key'] === $bucket) { $metadata['count']++; $metadata['available'] = true; break; }
                unset($metadata);
                if ($cursor !== null) {
                    $tuple = $this->archiveItemTuple($item);
                    if ($tuple === $cursor['last']) { $cursorSeen = true; continue; }
                    if (!$cursorSeen && $this->compareArchiveTuples($tuple, $cursor['last']) <= 0) continue;
                }
                $remaining++;
                $selected[] = $item;
                usort($selected, fn (array $left, array $right): int => $this->browsePolicy->compare($left, $right));
                if (count($selected) > $pageSize) array_pop($selected);
            }
        } catch (\Throwable) {
            return ['status' => 'UNAVAILABLE', 'canonical_url' => '/tu-dien/', 'items' => [], 'alphabet' => $this->browsePolicy->emptyAlphabet(), 'pagination' => ['page_size' => $pageSize, 'next_cursor' => null, 'has_next' => false, 'total_count' => null, 'total_exact' => false], 'warnings' => ['DICTIONARY_ARCHIVE_READ_FAILED']];
        }
        if (!$cursorSeen) return $this->archiveConflict('DICTIONARY_CURSOR_STALE');
        $alphabet = array_values(array_filter($alphabet, static fn (array $metadata): bool => $metadata['key'] !== '#' || $metadata['count'] > 0));
        usort($selected, fn (array $left, array $right): int => $this->browsePolicy->compare($left, $right));
        $hasNext = $remaining > $pageSize;
        $nextCursor = $hasNext && $selected !== [] ? $this->encodeArchiveCursor($query, $initial, $this->archiveItemTuple($selected[array_key_last($selected)])) : null;
        $pagination = ['page_size' => $pageSize, 'next_cursor' => $nextCursor, 'has_next' => $hasNext, 'total_count' => $total, 'total_exact' => true];
        return ['status' => $total === 0 ? 'EMPTY' : 'AVAILABLE', 'canonical_url' => '/tu-dien/', 'items' => $selected, 'alphabet' => $alphabet, 'pagination' => $pagination, 'count' => count($selected), 'total_count' => $total, 'query' => $query, 'initial' => $initial, 'warnings' => []];
    }

    /** @return \Generator<int,array<string,mixed>,void,void> */
    private function archiveItemStream(string $query, string $initial): \Generator
    {
        $hasBatchReaders = $this->entrySenseAvailable() && is_object($this->entries) && method_exists($this->entries, 'readArchiveCandidates') && method_exists($this->concepts, 'readApprovedArchiveCandidates');
        if (!$hasBatchReaders) {
            foreach ($this->collectArchiveItems($query, $initial) as $item) yield $item;
            return;
        }
        $cursor = null;
        do {
            $page = $this->entries->readArchiveCandidates(100, $cursor, $query);
            foreach ((array) ($page['rows'] ?? []) as $row) {
                $entry = $row['entry'] ?? null;
                $senses = array_values(array_filter((array) ($row['senses'] ?? []), static fn (mixed $sense): bool => $sense instanceof DictionaryConcept && $sense->approved()));
                if (!$entry instanceof LexicalEntry) continue;
                $item = $this->entryHubItem($entry, $senses, is_array($row['forms'] ?? null) ? $row['forms'] : null, is_array($row['labels'] ?? null) ? $row['labels'] : []);
                if (($item['eligible'] ?? false) !== true || trim((string) ($item['url'] ?? '')) === '') continue;
                if (!$this->archiveMatches($item, $query, $initial)) continue;
                yield $item;
            }
            $cursor = $page['next_cursor'] ?? null;
        } while ($cursor !== null);

        $cursor = null;
        do {
            $page = $this->concepts->readApprovedArchiveCandidates(100, $cursor, $query);
            foreach ((array) ($page['rows'] ?? []) as $row) {
                $concept = $row['concept'] ?? null;
                if (!$concept instanceof DictionaryConcept || !$concept->approved()) continue;
                $item = $this->item($concept, is_array($row['labels'] ?? null) ? $row['labels'] : null);
                if (($item['eligible'] ?? false) !== true || trim((string) ($item['url'] ?? '')) === '') continue;
                if (!$this->archiveMatches($item, $query, $initial)) continue;
                yield $item;
            }
            $cursor = $page['next_cursor'] ?? null;
        } while ($cursor !== null);
    }

    private function archiveMatches(array $item, string $query, string $initial): bool
    {
        if ($initial !== '' && $this->browsePolicy->initial((string) ($item['title'] ?? '')) !== $initial) return false;
        return $query === '' || $this->filterAndRank([$item], $query, '') !== [];
    }

    /** @param array{sort_key:string,title:string,identity:string} $left @param array{sort_key:string,title:string,identity:string} $right */
    private function compareArchiveTuples(array $left, array $right): int
    {
        $sort = strcmp($left['sort_key'], $right['sort_key']);
        if ($sort !== 0) return $sort;
        $title = strcmp($this->browsePolicy->searchKey($left['title']), $this->browsePolicy->searchKey($right['title']));
        return $title !== 0 ? $title : strcmp($left['identity'], $right['identity']);
    }

    /** @return list<array<string,mixed>> */
    private function collectArchiveItems(string $query, string $initial): array
    {
        $items = [];
        $coveredConceptIds = [];
        $entryRows = [];
        if ($this->entrySenseAvailable() && is_object($this->entries)) {
            if (method_exists($this->entries, 'readArchiveCandidates')) {
                $cursor = null;
                do {
                    $page = $this->entries->readArchiveCandidates(100, $cursor, $query);
                    foreach ((array) ($page['rows'] ?? []) as $row) $entryRows[] = $row;
                    $cursor = $page['next_cursor'] ?? null;
                } while ($cursor !== null);
            } elseif (method_exists($this->entries, 'listEntries')) {
                foreach ((array) $this->entries->listEntries(2000) as $entry) {
                    if (!$entry instanceof LexicalEntry) continue;
                    $senses = array_values(array_filter((array) $this->entries->listSenses($entry), static fn (mixed $sense): bool => $sense instanceof DictionaryConcept && $sense->approved()));
                    $entryRows[] = ['kind' => 'ENTRY', 'entry' => $entry, 'senses' => $senses];
                }
            }
        }
        foreach ($entryRows as $row) {
            $entry = $row['entry'] ?? null;
            if (!$entry instanceof LexicalEntry) continue;
            $senses = array_values(array_filter((array) ($row['senses'] ?? []), static fn (mixed $sense): bool => $sense instanceof DictionaryConcept && $sense->approved()));
            foreach ($senses as $sense) $coveredConceptIds[$sense->conceptId] = true;
            $item = $this->entryHubItem($entry, $senses, is_array($row['forms'] ?? null) ? $row['forms'] : null, is_array($row['labels'] ?? null) ? $row['labels'] : []);
            if (($item['eligible'] ?? false) !== true || trim((string) ($item['url'] ?? '')) === '') continue;
            $items[] = $item;
        }

        $conceptRows = [];
        if (method_exists($this->concepts, 'readApprovedArchiveCandidates')) {
            $cursor = null;
            do {
                $page = $this->concepts->readApprovedArchiveCandidates(100, $cursor, $query);
                foreach ((array) ($page['rows'] ?? []) as $row) $conceptRows[] = $row;
                $cursor = $page['next_cursor'] ?? null;
            } while ($cursor !== null);
        } else {
            foreach ((array) $this->concepts->listApproved(2000) as $concept) $conceptRows[] = ['kind' => 'CONCEPT', 'concept' => $concept];
        }
        foreach ($conceptRows as $row) {
            $concept = $row['concept'] ?? null;
            if (!$concept instanceof DictionaryConcept || !$concept->approved() || isset($coveredConceptIds[$concept->conceptId])) continue;
            if (is_object($this->entries) && method_exists($this->entries, 'findDurableForConcept') && $this->entries->findDurableForConcept($concept->conceptId) instanceof LexicalEntry) continue;
            $item = $this->item($concept, is_array($row['labels'] ?? null) ? $row['labels'] : null);
            if (($item['eligible'] ?? false) !== true || trim((string) ($item['url'] ?? '')) === '') continue;
            $items[] = $item;
        }

        $filtered = $this->filterAndRank($items, $query, '');
        $deduped = [];
        foreach ($filtered as $item) {
            if ($initial !== '' && $this->browsePolicy->initial((string) ($item['title'] ?? '')) !== $initial) continue;
            $item['_browse_sort_key'] = $this->browsePolicy->sortKey((string) ($item['title'] ?? ''));
            $identity = (($item['destination_mode'] ?? '') === 'DELEGATED')
                ? 'delegated:' . $this->browsePolicy->searchKey((string) ($item['title'] ?? '')) . ':' . trim((string) ($item['url'] ?? ''))
                : 'entry:' . trim((string) ($item['entry_id'] ?? $item['concept_id'] ?? ''));
            if (isset($deduped[$identity])) continue;
            $deduped[$identity] = $item;
        }
        return array_values($deduped);
    }

    /** @return array{sort_key:string,title:string,identity:string} */
    private function archiveItemTuple(array $item): array
    {
        return ['sort_key' => (string) ($item['_browse_sort_key'] ?? $this->browsePolicy->sortKey((string) ($item['title'] ?? ''))), 'title' => (string) ($item['title'] ?? ''), 'identity' => (string) ($item['entry_id'] ?? $item['concept_id'] ?? '')];
    }

    private function encodeArchiveCursor(string $query, string $initial, array $last): string
    {
        $payload = ['version' => 1, 'query' => $query, 'initial' => $initial, 'sort' => 'LEXICAL_ASC_V1', 'last' => $last];
        $json = function_exists('wp_json_encode') ? wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return rtrim(strtr(base64_encode((string) $json), '+/', '-_'), '=');
    }

    /** @return array{last:array{sort_key:string,title:string,identity:string}}|null */
    private function decodeArchiveCursor(string $cursor, string $query, string $initial): ?array
    {
        $encoded = strtr($cursor, '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || $decoded === '') return null;
        $payload = json_decode($decoded, true);
        if (!is_array($payload) || ($payload['version'] ?? null) !== 1 || ($payload['query'] ?? null) !== $query || ($payload['initial'] ?? null) !== $initial || ($payload['sort'] ?? null) !== 'LEXICAL_ASC_V1') return null;
        $last = $payload['last'] ?? null;
        return is_array($last) && isset($last['sort_key'], $last['title'], $last['identity']) ? ['last' => ['sort_key' => (string) $last['sort_key'], 'title' => (string) $last['title'], 'identity' => (string) $last['identity']]] : null;
    }

    private function archiveConflict(string $reason): array
    {
        return ['status' => 'CONFLICT', 'reason' => $reason, 'canonical_url' => '/tu-dien/', 'items' => [], 'alphabet' => $this->browsePolicy->emptyAlphabet(), 'pagination' => ['page_size' => 0, 'next_cursor' => null, 'has_next' => false, 'total_count' => null, 'total_exact' => false], 'warnings' => [$reason]];
    }

    public function hub(int $limit = 500, string $query = '', string $initial = ''): array
    {
        $query = $this->normalize($query);
        $initial = $this->normalizeInitial($initial);
        if ($this->entrySenseAvailable() && is_object($this->entries) && method_exists($this->entries, 'readArchiveCandidates') && method_exists($this->concepts, 'readApprovedArchiveCandidates')) {
            return $this->archive(['query' => $query, 'initial' => $initial, 'page_size' => max(1, min(500, $limit))]);
        }
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
        if ($this->detailQuery !== null) return $this->decorateDetail($this->detailQuery->detail($slug));
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

    /** Read-only reverse lexical projection for one canonical semantic owner. */
    public function detailForOwner(string $type, string $id): array
    {
        $type = trim($type); $id = trim($id);
        if ($type === '' || $id === '' || !is_object($this->entries) || !method_exists($this->entries, 'findEntriesBySemanticReference')) return ['status' => 'UNAVAILABLE', 'reason' => 'REVERSE_MAPPING_UNAVAILABLE'];
        try { $matches = array_values(array_filter((array) $this->entries->findEntriesBySemanticReference($type, $id, 2), static fn (mixed $entry): bool => $entry instanceof LexicalEntry)); }
        catch (\Throwable) { return ['status' => 'UNAVAILABLE', 'reason' => 'REVERSE_MAPPING_READ_FAILED']; }
        if ($matches === []) return ['status' => 'EMPTY', 'reason' => 'NO_DICTIONARY_MAPPING'];
        if (count($matches) !== 1) return ['status' => 'AMBIGUOUS_LEXICAL_OVERLAY', 'reason' => 'MULTIPLE_DICTIONARY_ENTRIES'];
        $slug = trim((string) ($matches[0]->context['public_slug'] ?? ''));
        if ($slug === '') return ['status' => 'BLOCKED', 'reason' => 'DICTIONARY_PUBLIC_IDENTITY_MISSING'];
        try { $ownerUrl = is_callable($this->destinationValidator) ? ($this->destinationValidator)($type, $id, null) : null; }
        catch (\Throwable) { return ['status' => 'BLOCKED', 'reason' => 'CANONICAL_OWNER_READ_FAILED']; }
        if (!is_string($ownerUrl) || trim($ownerUrl) === '') return ['status' => 'BLOCKED', 'reason' => 'CANONICAL_OWNER_UNAVAILABLE'];
        $raw = $this->detail($slug);
        if ($this->presentationComposer === null || !is_array($raw['item'] ?? null)) return ['status' => 'UNAVAILABLE', 'reason' => 'PRESENTATION_COMPOSER_UNAVAILABLE'];
        $raw['status'] = 'READY';
        $presentation = $this->presentationComposer->compose($raw, ['mode' => 'delegated', 'canonical_url' => trim($ownerUrl)]);
        return ['status' => 'READY', 'presentation' => $presentation, 'canonical_url' => trim($ownerUrl), 'seo_projection' => $presentation['seo'] ?? []];
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function decorateDetail(array $result): array
    {
        if ($this->presentationComposer === null || !is_array($result['item'] ?? null)) return $result;
        $result['presentation'] = $this->presentationComposer->compose($result);
        $result['seo_projection'] = $result['presentation']['seo'] ?? [];
        return $result;
    }

    /** Lightweight Entry summary used by hub/search; no dossier, Graph or source reads. */
    private function entryHubItem(LexicalEntry $entry, array $senses, ?array $preloadedForms = null, array $preloadedLabels = []): array
    {
        $slug = $this->slug((string) ($entry->context['public_slug'] ?? ''));
        if ($senses === []) return ['eligible' => false, 'entry_id' => $entry->entryId];
        $forms = $this->entryForms($entry, $preloadedForms);
        $labels = [];
        foreach ($senses as $sense) foreach (($preloadedLabels[$sense->conceptId] ?? $this->concepts->listLabels($sense->conceptId)) as $label) {
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
        return ['entry_id' => $entry->entryId, 'title' => $entry->preferredForm, 'description' => count($senses) === 1 ? $senses[0]->definition : '', 'term_type' => 'ENTRY', 'labels' => array_values(array_filter($labels, static fn (array $label): bool => ($label['kind'] ?? '') !== 'HIDDEN'),), 'search_labels' => $searchLabels, 'url' => $url, 'dedicated' => !$ownerBacked || count($senses) > 1, 'destination_mode' => $ownerBacked && $ownerUrl !== null ? 'DELEGATED' : 'DEDICATED', 'indexable' => !$ownerBacked, 'eligible' => true, 'forms' => $forms, 'senses' => array_map(static fn (DictionaryConcept $sense): array => ['sense_id' => $sense->conceptId, 'title' => $sense->preferredLabel, 'description' => $sense->definition, 'context' => $sense->context], $senses), 'image' => null];
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

    private function item(DictionaryConcept $concept, ?array $preloadedLabels = null): array
    {
        $labels = [];
        foreach (($preloadedLabels ?? $this->concepts->listLabels($concept->conceptId)) as $label) {
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
            'destination_mode' => $delegated ? 'DELEGATED' : 'DEDICATED',
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
    private function entryForms(LexicalEntry $entry, ?array $preloadedForms = null): array
    {
        if (!is_object($this->entries) || !method_exists($this->entries, 'listForms')) return [['form' => $entry->preferredForm, 'kind' => 'PREFERRED', 'locale' => $entry->locale, 'context' => $entry->context]];
        $forms = [];
        foreach ((array) ($preloadedForms ?? $this->entries->listForms($entry)) as $form) {
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
