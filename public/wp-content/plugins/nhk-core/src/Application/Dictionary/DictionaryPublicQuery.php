<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

use NHK\Core\Contracts\Dictionary\DictionaryConceptRepository;
use NHK\Core\Domain\Dictionary\{DictionaryConcept, DictionaryLabel};

final class DictionaryPublicQuery
{
    public function __construct(private DictionaryConceptRepository $concepts, private $imageResolver = null, private $destinationValidator = null) {}

    public function hub(int $limit = 500): array
    {
        $items = [];
        $warnings = [];
        foreach ($this->concepts->listApproved($limit) as $concept) {
            if (!$concept instanceof DictionaryConcept || !$concept->approved()) continue;
            $item = $this->item($concept);
            if (($item['eligible'] ?? false) !== true) { $warnings[] = 'DICTIONARY_DESTINATION_INCOMPLETE:' . $concept->conceptId; continue; }
            $items[] = $item;
        }
        usort($items, static fn (array $a, array $b): int => strnatcasecmp((string) $a['title'], (string) $b['title']));
        return ['status' => 'AVAILABLE', 'items' => $items, 'count' => count($items), 'canonical_url' => '/tu-dien/', 'warnings' => $warnings];
    }

    public function detail(string $slug): array
    {
        $slug = $this->slug($slug);
        if ($slug === '') return ['status' => 'NOT_FOUND'];
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
            try { $eligible = (bool) ($this->destinationValidator)($concept->destinationType, $concept->destinationId, $concept->destinationUrl); }
            catch (\Throwable) { $eligible = false; }
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
            'labels' => $labels,
            'url' => $eligible ? $url : null,
            'dedicated' => !$delegated,
            'destination_type' => $concept->destinationType,
            'destination_id' => $concept->destinationId,
            'image' => $image,
            'eligible' => $eligible,
            'indexable' => $eligible && !$delegated,
        ];
    }

    private function slug(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        if (function_exists('sanitize_title')) return (string) sanitize_title($value);
        $value = function_exists('iconv') ? (string) (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value) : $value;
        return trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower($value)), '-');
    }
}
