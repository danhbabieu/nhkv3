<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

use NHK\Core\Application\Seo\PublicSeoProjection;

/** Presentation-only normalization for Dictionary detail pages. */
final class DictionaryDetailPresentationComposer
{
    /** @param array<string,mixed> $source @param array<string,mixed> $options @return array<string,mixed> */
    public function compose(array $source, array $options = []): array
    {
        $item = is_array($source['item'] ?? null) ? $source['item'] : [];
        $rawSenses = array_values(array_filter((array) ($item['senses'] ?? []), 'is_array'));
        $senses = array_map(fn (array $sense): array => $this->sense($sense), $rawSenses);
        $title = trim((string) ($item['title'] ?? ''));
        $canonical = trim((string) ($options['canonical_url'] ?? $source['seo']['canonical'] ?? $item['canonical_url'] ?? $item['url'] ?? ''));
        $delegated = ($options['mode'] ?? '') === 'delegated';
        $forms = $this->forms($item, $title);
        $knowledge = $this->mergeBuckets(array_map(static fn (array $sense): mixed => $sense['knowledge'] ?? null, $rawSenses));
        $media = $this->mergeBuckets(array_map(static fn (array $sense): mixed => $sense['media'] ?? null, $rawSenses));
        $videos = $this->mergeBuckets(array_map(static fn (array $sense): mixed => $sense['videos'] ?? null, $rawSenses));
        $articles = $this->mergeBuckets(array_map(static fn (array $sense): mixed => $sense['articles'] ?? null, $rawSenses));
        $related = $this->mergeLists(array_map(static fn (array $sense): mixed => $sense['related_terms'] ?? null, $rawSenses));
        $relations = $this->relations($rawSenses);
        $mentions = $this->mentions($item, $rawSenses);
        $owner = $this->owner($rawSenses);
        $definition = trim((string) ($item['description'] ?? ''));
        if ($definition === '' && count($senses) === 1) $definition = (string) ($senses[0]['definition'] ?? '');
        $description = $this->description($definition, $senses);
        $seo = $this->seo($source, $title, $description, $canonical, $delegated, $forms);

        return [
            'status' => ($source['status'] ?? '') === 'READY' ? 'READY' : (string) ($source['status'] ?? 'UNAVAILABLE'),
            'route' => ['mode' => $delegated ? 'DELEGATED' : 'DEDICATED', 'canonical_url' => $canonical, 'delegated' => $delegated],
            'identity' => ['title' => $title, 'term_type' => (string) ($item['term_type'] ?? 'ENTRY'), 'locale' => $item['locale'] ?? null],
            'lexical' => ['preferred_form' => $title, 'alternate_forms' => $forms, 'aliases' => $this->aliases($item, $title)],
            'senses' => $senses,
            'canonical_owner' => $owner,
            'knowledge' => $knowledge,
            'relations' => $relations,
            'media' => $media,
            'videos' => $videos,
            'articles' => $articles,
            'related_terms' => $related,
            'mentions' => $mentions,
            'seo' => $seo,
            'availability' => [
                'lexical' => $senses === [] ? 'AVAILABLE_EMPTY' : 'AVAILABLE_WITH_ITEMS',
                'semantic' => $owner['status'] ?? 'AVAILABLE_EMPTY',
            ],
            'warnings' => array_values(array_filter(array_map('strval', (array) ($source['warnings'] ?? [])))),
        ];
    }

    /** @param array<string,mixed> $item @return list<array<string,mixed>> */
    private function forms(array $item, string $preferred): array
    {
        $forms = [];
        foreach (array_merge((array) ($item['forms'] ?? []), (array) ($item['labels'] ?? [])) as $row) {
            if (!is_array($row)) continue;
            $text = trim((string) ($row['form'] ?? $row['label'] ?? ''));
            $kind = strtoupper(trim((string) ($row['kind'] ?? 'ALTERNATE')));
            if ($text === '' || $kind === 'HIDDEN' || $kind === 'PREFERRED' || $this->sameText($text, $preferred)) continue;
            $key = $this->key($text);
            if ($key === '' || isset($forms[$key])) continue;
            $forms[$key] = ['form' => $text, 'kind' => $kind, 'locale' => $row['locale'] ?? null];
        }
        return array_values($forms);
    }

    /** @param array<string,mixed> $item @return list<array<string,mixed>> */
    private function aliases(array $item, string $preferred): array
    {
        $aliases = [];
        foreach ((array) ($item['labels'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $text = trim((string) ($row['label'] ?? ''));
            $kind = strtoupper(trim((string) ($row['kind'] ?? 'ALTERNATE')));
            if ($text === '' || $kind === 'HIDDEN' || $kind === 'PREFERRED' || $this->sameText($text, $preferred)) continue;
            $key = $this->key($text);
            if ($key !== '' && !isset($aliases[$key])) $aliases[$key] = ['label' => $text, 'kind' => $kind, 'locale' => $row['locale'] ?? null];
        }
        return array_values($aliases);
    }

    /** @param array<string,mixed> $sense @return array<string,mixed> */
    private function sense(array $sense): array
    {
        $context = is_array($sense['context'] ?? null) ? $sense['context'] : [];
        return [
            'title' => trim((string) ($sense['title'] ?? '')),
            'definition' => trim((string) ($sense['description'] ?? $sense['definition'] ?? '')),
            'usage_scope' => $this->texts($context['usage_scope'] ?? []),
            'usage_notes' => $this->usageNotes($context),
            'canonical_owner' => $this->ownerRow($sense['canonical_owner'] ?? null),
            'knowledge' => $this->bucket($sense['knowledge'] ?? null),
            'relations' => $this->bucket($sense['semantic_relations'] ?? null),
            'media' => $this->bucket($sense['media'] ?? null),
            'videos' => $this->bucket($sense['videos'] ?? null),
            'articles' => $this->bucket($sense['articles'] ?? null),
            'related_terms' => $this->publicItems((array) ($sense['related_terms'] ?? [])),
        ];
    }

    /** @param list<array<string,mixed>> $senses @return array<string,mixed> */
    private function owner(array $senses): array
    {
        $owners = [];
        foreach ($senses as $sense) {
            $owner = $this->ownerRow($sense['canonical_owner'] ?? null);
            $url = trim((string) ($owner['url'] ?? ''));
            if ($url !== '') $owners[$url] = $owner;
        }
        if (count($owners) === 1) return ['status' => 'AVAILABLE_WITH_ITEMS'] + array_values($owners)[0];
        if (count($owners) > 1) return ['status' => 'BLOCKED', 'reason' => 'AMBIGUOUS_LEXICAL_OWNER', 'items' => array_values($owners)];
        return ['status' => 'AVAILABLE_EMPTY'];
    }

    /** @param list<mixed> $buckets @return array<string,mixed> */
    private function mergeBuckets(array $buckets): array
    {
        $items = [];
        $unavailable = false;
        $blocked = false;
        foreach ($buckets as $bucket) {
            $normalized = $this->bucket($bucket);
            if ($normalized['status'] === 'UNAVAILABLE') $unavailable = true;
            if ($normalized['status'] === 'BLOCKED') $blocked = true;
            foreach ($normalized['items'] as $item) $items[] = $item;
        }
        $items = $this->dedupe($items);
        if ($items !== []) return ['status' => 'AVAILABLE_WITH_ITEMS', 'items' => $items];
        if ($blocked) return ['status' => 'BLOCKED', 'items' => []];
        if ($unavailable) return ['status' => 'UNAVAILABLE', 'availability' => 'UNAVAILABLE', 'items' => []];
        return ['status' => 'AVAILABLE_EMPTY', 'items' => []];
    }

    /** @param list<mixed> $lists @return list<array<string,mixed>> */
    private function mergeLists(array $lists): array
    {
        $items = [];
        foreach ($lists as $list) foreach ((array) $list as $item) if (is_array($item)) $items[] = $item;
        return $this->dedupe($items);
    }

    /** @param list<array<string,mixed>> $senses @return array<string,mixed> */
    private function relations(array $senses): array
    {
        $facets = [];
        $items = [];
        foreach ($senses as $sense) {
            foreach ((array) ($sense['relation_facets'] ?? []) as $name => $bucket) $facets[$name] = $this->mergeBuckets([$facets[$name] ?? null, $bucket]);
            $bucket = $this->bucket($sense['semantic_relations'] ?? null);
            foreach ($bucket['items'] as $item) $items[] = $item;
        }
        $items = $this->dedupe($items);
        return ['status' => ($items !== [] || $facets !== []) ? 'AVAILABLE_WITH_ITEMS' : 'AVAILABLE_EMPTY', 'facets' => $facets, 'items' => $items];
    }

    /** @param array<string,mixed> $item @param list<array<string,mixed>> $senses @return array<string,mixed> */
    private function mentions(array $item, array $senses): array
    {
        $groups = [];
        foreach ((array) ($item['mentions']['groups'] ?? []) as $kind => $rows) foreach ((array) $rows as $row) if (is_array($row)) $groups[$kind][] = $row;
        if ($groups === []) return ['status' => 'AVAILABLE_EMPTY', 'groups' => []];
        foreach ($groups as $kind => $rows) $groups[$kind] = $this->dedupe($rows);
        return ['status' => 'AVAILABLE_WITH_ITEMS', 'groups' => $groups];
    }

    /** @param array<string,mixed> $source @param list<array<string,mixed>> $forms @return array<string,mixed> */
    private function seo(array $source, string $title, string $description, string $canonical, bool $delegated, array $forms): array
    {
        $sourceSeo = is_array($source['seo'] ?? null) ? $source['seo'] : [];
        $indexable = !$delegated && (($sourceSeo['indexable'] ?? false) === true);
        $projection = (new PublicSeoProjection())->project([
            'path' => $canonical,
            'eligible' => $canonical !== '',
            'public_eligible' => $canonical !== '',
            'readiness' => $canonical !== '' ? 'READY' : 'BLOCKED',
        ], ['title' => $title . ' — Từ điển — Đồng Hồ Nhà Kho', 'description' => $description, 'type' => 'DefinedTerm']);
        $projection['indexable'] = $indexable;
        $projection['sitemap'] = $indexable ? $projection['canonical'] : false;
        $projection['title'] = $title . ' — Từ điển — Đồng Hồ Nhà Kho';
        $projection['meta_description'] = $description;
        $projection['robots'] = $indexable ? 'index,follow' : 'noindex,follow';
        if (!$indexable) $projection['json_ld'] = [];
        if ($indexable) {
            $publicCanonical = (string) ($projection['canonical'] ?? $canonical);
            $projection['json_ld'] = [
                '@context' => 'https://schema.org', '@type' => 'DefinedTerm', '@id' => $publicCanonical,
                'url' => $publicCanonical, 'mainEntityOfPage' => $publicCanonical, 'name' => $title,
                'description' => $description, 'inDefinedTermSet' => (new PublicSeoProjection())->publicUrl('/tu-dien/'),
            ];
            $alternate = array_values(array_filter(array_map(static fn (array $row): string => trim((string) ($row['form'] ?? '')), $forms)));
            if ($alternate !== []) $projection['json_ld']['alternateName'] = $alternate;
        }
        return $projection + ['state' => $sourceSeo['state'] ?? ($indexable ? 'INDEXABLE' : 'NOINDEX')];
    }

    /** @param list<array<string,mixed>> $senses */
    private function description(string $definition, array $senses): string
    {
        if ($definition !== '') return $this->limit($definition);
        foreach ($senses as $sense) if (trim((string) ($sense['definition'] ?? '')) !== '') return $this->limit((string) $sense['definition']);
        return '';
    }

    /** @return array<string,mixed> */
    private function ownerRow(mixed $owner): array
    {
        if (!is_array($owner)) return [];
        $url = trim((string) ($owner['url'] ?? $owner['canonical_url'] ?? ''));
        $title = trim((string) ($owner['title'] ?? $owner['name'] ?? ''));
        return array_filter(['status' => $url !== '' ? 'AVAILABLE_WITH_ITEMS' : 'AVAILABLE_EMPTY', 'type' => $owner['type'] ?? null, 'title' => $title, 'url' => $url], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @return array<string,mixed> */
    private function bucket(mixed $bucket): array
    {
        if (!is_array($bucket)) return ['status' => 'AVAILABLE_EMPTY', 'items' => []];
        $status = strtoupper((string) ($bucket['status'] ?? 'AVAILABLE_EMPTY'));
        if (str_starts_with($status, 'UNAVAILABLE')) $status = 'UNAVAILABLE';
        elseif (!in_array($status, ['AVAILABLE_WITH_ITEMS', 'AVAILABLE_EMPTY', 'BLOCKED'], true)) $status = 'AVAILABLE_EMPTY';
        $items = $this->publicItems((array) ($bucket['items'] ?? []));
        if ($items !== []) $status = 'AVAILABLE_WITH_ITEMS';
        return ['status' => $status, 'items' => $items] + (isset($bucket['has_more']) ? ['has_more' => (bool) $bucket['has_more']] : []);
    }

    /** @param list<array<string,mixed>> $items @return list<array<string,mixed>> */
    private function dedupe(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $key = $this->key((string) ($item['url'] ?? $item['title'] ?? $item['name'] ?? json_encode($item)));
            if ($key === '' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $item;
        }
        return $out;
    }

    /** @param list<mixed> $items @return list<array<string,mixed>> */
    private function publicItems(array $items): array
    {
        return array_values(array_filter(array_map(fn (mixed $item): ?array => is_array($item) ? $this->stripInternal($item) : null, $items)));
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function stripInternal(array $item): array
    {
        foreach (['id', 'uuid', 'canonical_id', 'stable_key', 'revision', 'source_id', 'entry_id', 'sense_id', 'concept_id', 'semantic_reference', 'provenance'] as $key) unset($item[$key]);
        foreach ($item as $key => $value) if (is_array($value)) $item[$key] = $this->stripInternal($value);
        return $item;
    }

    /** @param mixed $value @return list<string> */
    private function texts(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        return array_values(array_filter(array_map(static fn (mixed $item): string => trim(is_scalar($item) ? (string) $item : ''), $values)));
    }

    /** @param array<string,mixed> $context @return list<string> */
    private function usageNotes(array $context): array
    {
        $notes = $this->texts($context['usage_notes'] ?? []);
        if ($notes !== []) return $notes;
        $notes = $this->texts($context['usage_note'] ?? []);
        if ($notes !== []) return $notes;
        return ($context['reader_facing_notes'] ?? false) === true ? $this->texts($context['notes'] ?? []) : [];
    }

    private function sameText(string $left, string $right): bool { return $this->key($left) !== '' && $this->key($left) === $this->key($right); }
    private function key(string $value): string { return function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value)); }
    private function limit(string $value): string { return function_exists('mb_substr') ? mb_substr(trim($value), 0, 320, 'UTF-8') : substr(trim($value), 0, 320); }
}
