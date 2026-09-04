<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

final class DictionaryLinkPlanner
{
    public function plan(string $text, array $items): array
    {
        if ($text === '' || $items === []) return [];

        $terms = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $term = trim((string) ($item['term'] ?? ''));
            $url = trim((string) ($item['url'] ?? ''));
            $conceptId = trim((string) ($item['concept_id'] ?? ''));
            if ($term === '' || $url === '' || $conceptId === '') continue;
            $terms[] = ['term' => $term, 'url' => $url, 'concept_id' => $conceptId];
        }

        usort($terms, static fn (array $a, array $b): int => self::length($b['term']) <=> self::length($a['term']));
        $matches = [];
        $seenConcepts = [];

        foreach ($terms as $item) {
            if (isset($seenConcepts[$item['concept_id']])) continue;
            $quoted = preg_quote($item['term'], '/');
            $pattern = '/(?<![\p{L}\p{N}_])(' . $quoted . ')(?![\p{L}\p{N}_])/iu';
            if (!preg_match_all($pattern, $text, $found, PREG_OFFSET_CAPTURE)) continue;

            foreach ($found[1] as [$matchedText, $start]) {
                $end = $start + strlen($matchedText);
                $overlap = false;
                foreach ($matches as $existing) {
                    $existingEnd = $existing['start'] + $existing['length'];
                    if ($start < $existingEnd && $end > $existing['start']) {
                        $overlap = true;
                        break;
                    }
                }
                if ($overlap) continue;

                $matches[] = [
                    'start' => $start,
                    'length' => strlen($matchedText),
                    'text' => $matchedText,
                    'concept_id' => $item['concept_id'],
                    'url' => $item['url'],
                ];
                $seenConcepts[$item['concept_id']] = true;
                break;
            }
        }

        usort($matches, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        return $matches;
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
