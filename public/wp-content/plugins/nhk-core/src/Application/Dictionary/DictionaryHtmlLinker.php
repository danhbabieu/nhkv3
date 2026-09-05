<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

final class DictionaryHtmlLinker
{
    /** @param list<array{concept_id:string,label:string,url:string}> $terms */
    public function link(string $html, array $terms): string
    {
        if ($html === '' || $terms === []) return $html;
        $terms = array_values(array_filter($terms, static fn (array $item): bool => trim((string) ($item['concept_id'] ?? '')) !== '' && trim((string) ($item['label'] ?? '')) !== '' && trim((string) ($item['url'] ?? '')) !== ''));
        if ($terms === []) return $html;

        $parts = preg_split('/(<[^>]+>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) return $html;
        $skipDepth = 0;
        $seen = [];
        $skipTags = ['a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'code', 'pre', 'script', 'style', 'textarea'];
        $planner = new DictionaryLinkPlanner();

        foreach ($parts as $index => $part) {
            if ($part === '') continue;
            if ($part[0] === '<') {
                if (preg_match('/^<\s*\/\s*([a-z0-9]+)/i', $part, $m)) {
                    if (in_array(strtolower($m[1]), $skipTags, true) && $skipDepth > 0) $skipDepth--;
                } elseif (preg_match('/^<\s*([a-z0-9]+)/i', $part, $m) && !preg_match('/\/\s*>$/', $part)) {
                    if (in_array(strtolower($m[1]), $skipTags, true)) $skipDepth++;
                }
                continue;
            }
            if ($skipDepth > 0 || trim($part) === '') continue;

            $available = [];
            foreach ($terms as $term) {
                if (isset($seen[(string) $term['concept_id']])) continue;
                $available[] = ['concept_id' => (string) $term['concept_id'], 'term' => (string) $term['label'], 'url' => (string) $term['url']];
            }
            $matches = $planner->plan($part, $available);
            if ($matches === []) continue;
            for ($i = count($matches) - 1; $i >= 0; $i--) {
                $match = $matches[$i];
                $anchor = '<a class="nhk-dictionary-link" href="' . htmlspecialchars((string) $match['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . htmlspecialchars((string) $match['text'], ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
                $part = substr_replace($part, $anchor, (int) $match['start'], (int) $match['length']);
                $seen[(string) $match['concept_id']] = true;
            }
            $parts[$index] = $part;
        }
        return implode('', $parts);
    }
}
