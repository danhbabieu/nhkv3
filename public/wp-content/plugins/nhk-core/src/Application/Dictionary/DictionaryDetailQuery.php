<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

use NHK\Core\Domain\Dictionary\{DictionaryConcept, DictionaryLabel, LexicalEntry};

/** Entry-centric public detail composition. Domain owners remain authoritative. */
final class DictionaryDetailQuery
{
    public function __construct(
        private object $concepts,
        private object $entries,
        private $destinationValidator = null,
        private $semanticProjection = null,
        private $mentionProjection = null,
        private $relatedProjection = null,
    ) {}

    /** @return array<string,mixed> */
    public function detail(string $slug): array
    {
        $slug = $this->slug($slug);
        if ($slug === '') return ['status' => 'NOT_FOUND'];
        $matches = $this->resolveEntries($slug);
        if (count($matches) !== 1) return ['status' => count($matches) > 1 ? 'AMBIGUOUS' : 'NOT_FOUND', 'slug' => $slug];
        $entry = $matches[0];
        $senses = array_values(array_filter((array) $this->entries->listSenses($entry), static fn (mixed $sense): bool => $sense instanceof DictionaryConcept && $sense->approved()));
        if ($senses === []) return ['status' => 'INCOMPLETE', 'reason' => 'DICTIONARY_ENTRY_NOT_PUBLIC'];
        $sensePackets = [];
        $semanticCache = [];
        $relatedCache = [];
        $semanticKeys = [];
        $mentionGroups = [];
        foreach ($senses as $sense) {
            $reference = $this->reference($entry, $sense);
            $type = trim((string) ($reference['type'] ?? ''));
            $id = trim((string) ($reference['id'] ?? ''));
            $key = $type . ':' . $id;
            $semantic = $semanticCache[$key] ??= $this->projectSemantic($type, $id);
            if ($key !== ':') $semanticKeys[$key] = true;
            $mentions = $this->projectMentions($sense->conceptId);
            foreach ((array) ($mentions['groups'] ?? []) as $kind => $items) foreach ((array) $items as $item) {
                if (!is_array($item)) continue;
                $itemKey = strtoupper((string) $kind) . ':' . (string) ($item['id'] ?? $item['url'] ?? $item['title'] ?? '');
                if ($itemKey !== ':' && isset($semantic['source_keys'][$itemKey])) continue;
                $mentionGroups[$kind][$itemKey] = $item;
            }
            $sensePackets[] = [
                'sense_id' => $sense->conceptId,
                'title' => $sense->preferredLabel,
                'description' => $sense->definition,
                'context' => $sense->context,
                'semantic_reference' => $reference,
                'canonical_owner' => $semantic['canonical_owner'],
                'knowledge' => $semantic['knowledge'],
                'media' => $semantic['media'],
                'videos' => $semantic['videos'],
                'articles' => $semantic['articles'],
                'semantic_relations' => $semantic['semantic_relations'],
                'derived_entities' => $semantic['derived_entities'],
                'brands' => $semantic['derived_entities']['brands'],
                'models' => $semantic['derived_entities']['models'],
                'specimens' => $semantic['derived_entities']['specimens'],
                'related_terms' => ($type !== '' && $id !== '' && is_object($this->relatedProjection) && method_exists($this->relatedProjection, 'forReference')) ? ($relatedCache[$key] ??= $this->relatedProjection->forReference($entry->entryId, $type, $id, 12)) : [],
                'mentions' => ['status' => $mentions['status'] ?? 'AVAILABLE_EMPTY', 'groups' => []],
            ];
        }
        foreach ($mentionGroups as $kind => $items) $mentionGroups[$kind] = array_values($items);
        $forms = $this->forms($entry);
        $url = '/tu-dien/' . $slug . '/';
        $item = [
            'entry_id' => $entry->entryId, 'title' => $entry->preferredForm,
            'description' => count($sensePackets) === 1 ? $sensePackets[0]['description'] : '',
            'term_type' => 'ENTRY', 'url' => $url, 'canonical_url' => $url,
            'forms' => $forms, 'labels' => $this->labels($senses[0]), 'senses' => $sensePackets,
            'mentions' => ['status' => $mentionGroups === [] ? 'AVAILABLE_EMPTY' : 'AVAILABLE_WITH_ITEMS', 'groups' => $mentionGroups],
            'eligible' => true, 'image' => null,
        ];
        $seo = $this->seo($item, $sensePackets);
        return ['status' => $seo['state'] === 'REDIRECT' ? 'REDIRECT' : 'READY', 'item' => $item, 'labels' => $item['labels'], 'canonical_url' => $seo['canonical'], 'seo' => $seo, 'indexable' => $seo['state'] === 'INDEXABLE'];
    }

    /** @return list<LexicalEntry> */
    private function resolveEntries(string $slug): array
    {
        if (method_exists($this->entries, 'findByPublicSlug')) {
            $entry = $this->entries->findByPublicSlug($slug);
            if ($entry instanceof LexicalEntry) return [$entry];
        }
        $out = [];
        foreach ((array) $this->entries->listEntries(2000) as $entry) if ($entry instanceof LexicalEntry && $this->slug((string) ($entry->context['public_slug'] ?? $entry->preferredForm)) === $slug) $out[] = $entry;
        return $out;
    }

    /** @return array<string,mixed> */
    private function reference(LexicalEntry $entry, DictionaryConcept $sense): array
    {
        if (method_exists($this->entries, 'semanticReference')) {
            try {
                $reference = $this->entries->semanticReference($entry->entryId, $sense->conceptId);
                if (is_array($reference) && trim((string) ($reference['type'] ?? '')) !== '' && trim((string) ($reference['id'] ?? '')) !== '') return $reference + ['source' => 'MAPPING'];
                if (is_array($reference) && strtoupper((string) ($reference['status'] ?? '')) !== 'ABSENT') return $reference + ['source' => 'MAPPING'];
            } catch (\Throwable) { return ['status' => 'BLOCKED', 'source' => 'MAPPING', 'reason' => 'MAPPING_READ_FAILED', 'type' => null, 'id' => null]; }
        }
        $type = trim((string) ($sense->destinationType ?? '')); $id = trim((string) ($sense->destinationId ?? ''));
        return ['status' => $type !== '' && $id !== '' ? 'AVAILABLE_WITH_ITEMS' : 'AVAILABLE_EMPTY', 'source' => $type !== '' && $id !== '' ? 'LEGACY_CONCEPT_SNAPSHOT' : 'NONE', 'type' => $type ?: null, 'id' => $id ?: null, 'revision' => null];
    }

    /** @return array<string,mixed> */
    private function projectSemantic(string $type, string $id): array
    {
        $empty = ['canonical_owner' => null, 'knowledge' => $this->bucket(), 'media' => $this->bucket(), 'videos' => $this->bucket(), 'articles' => $this->bucket(), 'semantic_relations' => $this->bucket(), 'derived_entities' => ['brands' => $this->bucket(), 'models' => $this->bucket(), 'specimens' => $this->bucket()], 'source_keys' => []];
        if ($type === '' || $id === '') return $empty;
        if (!is_callable($this->semanticProjection)) return $this->unavailableSemantic();
        try { $packet = ($this->semanticProjection)($type, $id); } catch (\Throwable) { return $this->unavailableSemantic(); }
        if (!is_array($packet)) return $this->unavailableSemantic();
        $relations = is_array($packet['relation_sections'] ?? null) ? $packet['relation_sections'] : [];
        $knowledge = is_array($packet['knowledge'] ?? null) ? $packet['knowledge'] : [];
        $result = $empty;
        $result['canonical_owner'] = is_array($packet['identity'] ?? null) ? $packet['identity'] : null;
        $result['knowledge'] = $this->bucket(is_array($knowledge['items'] ?? null) ? array_slice($knowledge['items'], 0, 6) : []);
        $result['semantic_relations'] = $this->bucket($relations);
        foreach (['brands', 'models', 'specimens'] as $name) $result['derived_entities'][$name] = $this->bucket(is_array($relations[$name] ?? null) ? $relations[$name] : []);
        foreach (['media', 'videos', 'articles'] as $name) $result[$name] = $this->bucket(is_array($relations[$name] ?? null) ? $relations[$name] : []);
        foreach (['media' => 'MEDIA', 'videos' => 'VIDEO', 'articles' => 'ARTICLE', 'knowledge' => 'KNOWLEDGE'] as $name => $kind) foreach ($result[$name]['items'] as $row) if (is_array($row)) $result['source_keys'][$kind . ':' . ($row['id'] ?? $row['canonical_id'] ?? $row['url'] ?? $row['title'] ?? '')] = true;
        return $result;
    }

    private function projectMentions(string $conceptId): array
    {
        if (!is_callable($this->mentionProjection)) return ['status' => 'UNAVAILABLE_IMPLEMENTATION_GAP', 'groups' => []];
        try { $value = ($this->mentionProjection)($conceptId); return is_array($value) ? $value : ['status' => 'AVAILABLE_EMPTY', 'groups' => []]; } catch (\Throwable) { return ['status' => 'BLOCKED', 'groups' => []]; }
    }

    private function bucket(array $items = []): array { return ['status' => $items === [] ? 'AVAILABLE_EMPTY' : 'AVAILABLE_WITH_ITEMS', 'items' => array_values($items)]; }
    private function unavailableSemantic(): array
    {
        $bucket = ['status' => 'UNAVAILABLE_IMPLEMENTATION_GAP', 'items' => []];
        return ['canonical_owner' => null, 'knowledge' => $bucket, 'media' => $bucket, 'videos' => $bucket, 'articles' => $bucket, 'semantic_relations' => $bucket, 'derived_entities' => ['brands' => $bucket, 'models' => $bucket, 'specimens' => $bucket], 'source_keys' => []];
    }
    private function labels(DictionaryConcept $sense): array { return array_values(array_filter(array_map(static fn (mixed $label): ?array => $label instanceof DictionaryLabel && $label->active ? ['label' => $label->label, 'kind' => $label->kind, 'locale' => $label->locale] : null, (array) $this->concepts->listLabels($sense->conceptId)), 'is_array')); }
    private function forms(LexicalEntry $entry): array { if (!method_exists($this->entries, 'listForms')) return [['form' => $entry->preferredForm, 'kind' => 'PREFERRED', 'locale' => $entry->locale]]; $out = []; foreach ((array) $this->entries->listForms($entry) as $form) { if (is_object($form) && trim((string) ($form->form ?? '')) !== '') $out[] = ['form' => $form->form, 'kind' => $form->kind ?? 'ALTERNATE', 'locale' => $form->locale ?? null]; elseif (is_array($form) && trim((string) ($form['form'] ?? '')) !== '') $out[] = ['form' => $form['form'], 'kind' => $form['kind'] ?? 'ALTERNATE', 'locale' => $form['locale'] ?? null]; } return $out !== [] ? $out : [['form' => $entry->preferredForm, 'kind' => 'PREFERRED', 'locale' => $entry->locale]]; }
    private function seo(array $item, array $senses): array { $hasOwner = false; foreach ($senses as $sense) if (is_array($sense['canonical_owner'] ?? null)) { $hasOwner = true; break; } return ['state' => $hasOwner ? (count($senses) > 1 ? 'NOINDEX' : 'NOINDEX') : 'INDEXABLE', 'canonical' => $item['url'], 'robots' => $hasOwner ? 'noindex,follow' : 'index,follow', 'sitemap' => !$hasOwner]; }
    private function slug(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        if (function_exists('sanitize_title')) return (string) sanitize_title($value);
        $value = function_exists('iconv') ? (string) (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value) : $value;
        return trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower($value)), '-');
    }
}
