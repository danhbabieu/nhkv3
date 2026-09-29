<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

final class DictionaryTermDetector
{
    public function __construct(private ?DictionaryTermNormalizer $normalizer = null, private ?DictionaryLexicalQualityGate $qualityGate = null)
    {
        $this->normalizer ??= new DictionaryTermNormalizer();
        $this->qualityGate ??= new DictionaryLexicalQualityGate();
    }

    public function detect(string $text, array $approvedLabels = [], array $hints = []): array
    {
        if (trim($text) === '') return [];
        $out = [];
        $hintLabels = $this->hintLabels($hints);
        $lexicalLabels = array_merge($approvedLabels, $hintLabels);

        foreach ($this->longestPresentLabels($text, $approvedLabels) as $label) {
            $this->addIfPresent($out, $text, $label, 'KNOWN_LABEL', 'STRONG');
        }
        foreach ($hintLabels as $hint) $this->addIfPresent($out, $text, $hint, 'HINT', 'NORMAL');

        foreach ($this->identifierSpans($text) as $span) {
            $this->add($out, $span, 'IDENTIFIER_SPAN', 'STRONG');
        }
        foreach ($this->properNameSpans($text) as $span) {
            $this->add($out, $span, 'PROPER_NAME_SPAN', 'STRONG');
        }

        if (preg_match_all('/[“"\']([^“”"\']{2,80})[”"\']/u', $text, $quoted)) {
            foreach ($quoted[1] as $value) {
                $phrase = $this->qualityGate->filter((string) $value, $lexicalLabels);
                if ($phrase !== null) $this->add($out, $phrase, 'QUOTED_PHRASE', 'NORMAL');
            }
        }
        if (preg_match_all('/\b[\p{Lu}][\p{L}]{0,11}[-\/]?\d{1,4}(?:[-\/]\d{1,4})*\b/u', $text, $models)) {
            foreach ($models[0] as $value) $this->add($out, (string) $value, 'TECHNICAL_PATTERN', 'WEAK');
        }
        if (preg_match_all('/(?<![\p{L}\p{N}])([\p{L}]{2,}(?:-[\p{L}\p{N}]+)+)(?![\p{L}\p{N}])/u', $text, $hyphenated)) {
            foreach ($hyphenated[1] as $value) $this->add($out, (string) $value, 'HYPHENATED_NAME', 'WEAK');
        }
        $eligibleUnits = $this->eligibleStructuralUnits($text, $approvedLabels, $hints, $lexicalLabels);
        if (preg_match_all('/\b(\d{1,3}\s+[\p{L}][\p{L}-]*(?:\s+\d{1,3}\s+[\p{L}][\p{L}-]*){0,2})\b/iu', $text, $configurations)) {
            foreach ($configurations[1] as $value) {
                $phrase = $this->qualityGate->filter((string) $value, $lexicalLabels);
                if ($phrase !== null && $this->isEligibleStructuralConfiguration($phrase, $eligibleUnits)) {
                    $this->add($out, $phrase, 'STRUCTURAL_CONFIGURATION', 'NORMAL');
                }
            }
        }

        $word = '[\p{L}\p{N}][\p{L}\p{N}\-]*';
        foreach ($this->domainPhraseSpans($text, $lexicalLabels) as $phrase) {
            $this->add($out, $phrase, 'DOMAIN_PHRASE', 'NORMAL');
        }

        if (preg_match_all('/\bbản\s+nhạc\s+(' . $word . '(?:\s+' . $word . '){0,3})\b/iu', $text, $music)) {
            foreach ($music[1] as $value) {
                $knownName = $this->knownLabelInWindow((string) $value, $approvedLabels);
                if ($knownName !== null) {
                    $this->add($out, $knownName, 'MUSIC_NAME', 'STRONG');
                    continue;
                }
                $phrase = $this->qualityGate->filter((string) $value, $lexicalLabels);
                if ($phrase !== null) $this->add($out, $phrase, 'MUSIC_NAME', 'NORMAL');
            }
        }

        return array_values($this->removeWeakerSubspans($out));
    }

    private function addIfPresent(array &$out, string $text, string $term, string $origin, string $strength): void
    {
        $term = trim($term);
        if ($term === '' || !$this->present($text, $term)) return;
        $this->add($out, $term, $origin, $strength);
    }

    private function add(array &$out, string $term, string $origin, string $strength): void
    {
        $term = trim($term, " \t\n\r\0\x0B,.;:!?()[]{}\"");
        $normalized = $this->normalizer->normalize($term);
        if ($normalized === '' || preg_match('/^\p{P}+$/u', $normalized)) return;
        if (isset($out[$normalized]) && $this->strengthRank((string) ($out[$normalized]['strength'] ?? '')) >= $this->strengthRank($strength)) return;
        $out[$normalized] = ['term' => $term, 'normalized_term' => $normalized, 'origin' => $origin, 'strength' => $strength];
    }

    private function strengthRank(string $strength): int
    {
        return ['WEAK' => 1, 'NORMAL' => 2, 'STRONG' => 3][$strength] ?? 0;
    }

    private function present(string $text, string $term): bool
    {
        return preg_match('/(?<![\p{L}\p{N}_])' . preg_quote($term, '/') . '(?![\p{L}\p{N}_])/iu', $text) === 1;
    }

    private function knownLabelInWindow(string $window, array $approvedLabels): ?string
    {
        $matches = [];
        foreach ($approvedLabels as $label) {
            $label = trim((string) $label);
            if ($label === '' || !$this->present($window, $label)) continue;
            $matches[$this->normalizer->normalize($label)] = $label;
        }
        if ($matches === []) return null;

        uasort($matches, static function (string $left, string $right): int {
            return mb_strlen($right, 'UTF-8') <=> mb_strlen($left, 'UTF-8');
        });
        return (string) reset($matches);
    }

    private function longestPresentLabels(string $text, array $approvedLabels): array
    {
        $labels = [];
        foreach ($approvedLabels as $label) {
            $label = trim((string) $label);
            if ($label === '' || !$this->present($text, $label)) continue;
            $labels[$this->normalizer->normalize($label)] = $label;
        }
        uasort($labels, static function (string $left, string $right): int {
            return mb_strlen($right, 'UTF-8') <=> mb_strlen($left, 'UTF-8');
        });

        $selected = [];
        foreach ($labels as $label) {
            $nested = false;
            foreach ($selected as $longer) {
                if ($this->present($longer, $label)) {
                    $nested = true;
                    break;
                }
            }
            if (!$nested) $selected[] = $label;
        }
        return $selected;
    }

    private function identifierSpans(string $text): array
    {
        $spans = [];
        $patterns = [
            '/(?<![\p{L}\p{N}_])([\p{Lu}][\p{L}\p{N}-]*\s+\d[\p{L}\p{N}]*(?:[\/.][\p{L}\p{N}.-]+)+)(?![\p{L}\p{N}_])/u',
            '/(?<![\p{L}\p{N}_])([\p{Lu}][\p{L}\p{N}-]*(?:[\/.][\p{L}\p{N}.-]+)+)(?![\p{L}\p{N}_])/u',
        ];
        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $text, $matches)) continue;
            foreach ($matches[1] as $value) $spans[$this->normalizer->normalize((string) $value)] = trim((string) $value);
        }
        return array_values($spans);
    }

    private function hintLabels(array $hints): array
    {
        $labels = [];
        foreach ($hints as $hint) {
            if (is_string($hint) || is_numeric($hint)) {
                $label = trim((string) $hint);
                if ($label !== '') $labels[] = $label;
                continue;
            }
        }
        return array_values(array_unique($labels));
    }

    private function structuralHintLabels(array $hints): array
    {
        $labels = [];
        foreach ($hints as $hint) {
            if (!is_array($hint) || ($hint['kind'] ?? '') !== 'STRUCTURAL_UNIT') continue;
            $label = trim((string) ($hint['term'] ?? ''));
            if ($label !== '') $labels[] = $label;
        }
        return array_values(array_unique($labels));
    }

    private function eligibleStructuralUnits(string $text, array $approvedLabels, array $hints, array $lexicalLabels): array
    {
        $units = [];
        foreach (array_merge($approvedLabels, $this->structuralHintLabels($hints)) as $label) {
            $parts = preg_split('/\s+/u', trim((string) $label)) ?: [];
            if (count($parts) === 1 && preg_match('/^[\p{L}][\p{L}-]*$/u', (string) $parts[0])) {
                $units[$this->normalizer->normalize((string) $parts[0])] = true;
            }
        }
        foreach ($this->domainPhraseSpans($text, $lexicalLabels) as $phrase) {
            $parts = preg_split('/\s+/u', trim($phrase)) ?: [];
            if (count($parts) === 1 || (isset($parts[1]) && preg_match('/^\d+$/u', (string) $parts[1]))) {
                $units[$this->normalizer->normalize((string) $parts[0])] = true;
            }
        }
        return $units;
    }

    private function isEligibleStructuralConfiguration(string $phrase, array $eligibleUnits): bool
    {
        $parts = preg_split('/\s+/u', trim($phrase)) ?: [];
        if (count($parts) < 2 || count($parts) % 2 !== 0) return false;
        for ($index = 0; $index < count($parts); $index += 2) {
            if (!preg_match('/^\d{1,3}$/u', (string) $parts[$index])) return false;
            $unit = $this->normalizer->normalize((string) $parts[$index + 1]);
            if (!isset($eligibleUnits[$unit])) return false;
        }
        return true;
    }

    private function domainPhraseSpans(string $text, array $lexicalLabels): array
    {
        $word = '[\p{L}\p{N}][\p{L}\p{N}\-]*';
        $patterns = [
            '/\b(côn(?:\s+' . $word . '){0,3})\b/iu',
            '/\b(ngắt\s+chuông(?:\s+' . $word . '){0,3})\b/iu',
            '/\b(điểm\s+(?:giờ|chuông)(?:\s+' . $word . '){0,3})\b/iu',
            '/\b(quả\s+lắc(?:\s+' . $word . '){0,3})\b/iu',
            '/\b(dây\s+tóc(?:\s+' . $word . '){0,3})\b/iu',
            '/\b(khóa\s+ngựa(?:\s+' . $word . '){0,3})\b/iu',
            '/\b(bánh\s+thoát(?:\s+' . $word . '){0,3})\b/iu',
            '/\b(bộ\s+thoát(?:\s+' . $word . '){0,3})\b/iu',
            '/\b(hộp\s+cộng\s+hưởng(?:\s+' . $word . '){0,3})\b/iu',
            '/\b(mặt\s+số(?:\s+' . $word . '){1,3})\b/iu',
            '/\b(gông(?:\s+' . $word . '){1,3})\b/iu',
            '/\b(búa(?:\s+' . $word . '){1,2})\b/iu',
            '/\b(cọc(?:\s+' . $word . '){1,2})\b/iu',
            '/\b(vách(?:\s+' . $word . '){1,3})\b/iu',
        ];
        $spans = [];
        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $text, $matches)) continue;
            foreach ($matches[1] as $value) {
                $phrase = $this->qualityGate->filter((string) $value, $lexicalLabels);
                if ($phrase !== null) $spans[$this->normalizer->normalize($phrase)] = $phrase;
            }
        }
        return array_values($spans);
    }

    private function properNameSpans(string $text): array
    {
        $pattern = '/(?<![\p{L}])((?:\p{Lu}[\p{L}]+(?:-\p{Lu}[\p{L}]+)+)(?:\s+\p{Lu}[\p{L}]+(?:-\p{Lu}[\p{L}]+)?){1,3})(?![\p{L}])/u';
        if (!preg_match_all($pattern, $text, $matches)) return [];
        return array_values(array_unique(array_map(static fn (string $value): string => trim($value), $matches[1])));
    }

    private function removeWeakerSubspans(array $items): array
    {
        $containers = array_values(array_unique(array_map(
            static fn (array $item): string => (string) $item['normalized_term'],
            $items,
        )));

        foreach ($items as $normalized => $item) {
            if (in_array($item['origin'] ?? '', ['KNOWN_LABEL', 'HINT', 'MUSIC_NAME'], true)) continue;
            foreach ($containers as $strong) {
                if ((string) $normalized === $strong) continue;
                if ($this->isTokenSubspan((string) $normalized, $strong) || $this->isIdentifierFragment((string) $normalized, $strong)) {
                    unset($items[$normalized]);
                    break;
                }
            }
        }
        return $items;
    }

    private function isTokenSubspan(string $candidate, string $container): bool
    {
        $candidateParts = preg_split('/\s+/u', trim($candidate)) ?: [];
        $containerParts = preg_split('/\s+/u', trim($container)) ?: [];
        if ($candidateParts === [] || count($candidateParts) >= count($containerParts)) return false;
        for ($offset = 0; $offset <= count($containerParts) - count($candidateParts); $offset++) {
            if (array_slice($containerParts, $offset, count($candidateParts)) === $candidateParts) return true;
        }
        return false;
    }

    private function isIdentifierFragment(string $candidate, string $container): bool
    {
        if (!preg_match('/[\/.]/u', $container)) return false;
        if (preg_match('/^\d+$/u', $candidate)) {
            return preg_match('/(?:^|[\/.\-])' . preg_quote($candidate, '/') . '(?:$|[\/.\-])/u', $container) === 1;
        }
        return str_starts_with($container, $candidate . '/') || str_starts_with($container, $candidate . '.');
    }

}
