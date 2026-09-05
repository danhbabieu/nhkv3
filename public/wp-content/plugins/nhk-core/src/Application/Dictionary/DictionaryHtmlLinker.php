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
        usort($terms, static fn (array $a, array $b): int => self::length((string) $b['label']) <=> self::length((string) $a['label']));
        if ($terms === []) return $html;

        $parts = preg_split('/(<[^>]+>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) return $html;
        $skipDepth = 0;
        $stack = [];
        $seen = [];
        $skipTags = ['a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'code', 'pre', 'script', 'style', 'textarea'];

        foreach ($parts as $index => $part) {
            if ($part === '') continue;
            if ($part[0] === '<') {
                if (preg_match('/^<\s*\/\s*([a-z0-9]+)/i', $part, $m)) {
                    $tag = strtolower($m[1]);
                    if ($stack !== [] && end($stack) === $tag) array_pop($stack);
                    if (in_array($tag, $skipTags, true) && $skipDepth > 0) $skipDepth--;
                } elseif (preg_match('/^<\s*([a-z0-9]+)/i', $part, $m) && !preg_match('/\/\s*>$/', $part)) {
                    $tag = strtolower($m[1]);
                    $stack[] = $tag;
                    if (in_array($tag, $skipTags, true)) $skipDepth++;
                }
                continue;
            }
            if ($skipDepth > 0 || trim($part) === '') continue;

            foreach ($terms as $term) {
                $conceptId = (string) $term['concept_id'];
                if (isset($seen[$conceptId])) continue;
                $label = (string) $term['label'];
                $pattern = '/(?<![\p{L}\p{N}_])(' . preg_quote($label, '/') . ')(?![\p{L}\p{N}_])/iu';
                if (preg_match($pattern, $part) !== 1) continue;
                $replacement = '<a class="nhk-dictionary-link" href="' . htmlspecialchars((string) $term['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">$1</a>';
                $parts[$index] = preg_replace($pattern, $replacement, $part, 1) ?? $part;
                $seen[$conceptId] = true;
                break;
            }
        }
        return implode('', $parts);
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
