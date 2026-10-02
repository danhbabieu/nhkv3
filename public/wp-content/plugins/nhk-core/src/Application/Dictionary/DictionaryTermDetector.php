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

        $identifierSpans = $this->identifierSpans($text);
        foreach ($identifierSpans as $span) {
            $this->add($out, $span, 'IDENTIFIER_SPAN', 'STRONG');
        }
        for ($index = 0; $index < count($identifierSpans) - 1; $index++) {
            $left = mb_strpos($text, (string) $identifierSpans[$index]);
            $right = mb_strpos($text, (string) $identifierSpans[$index + 1], ($left === false ? 0 : $left + mb_strlen((string) $identifierSpans[$index], 'UTF-8')));
            if ($left === false || $right === false) continue;
            $between = mb_substr($text, $left + mb_strlen((string) $identifierSpans[$index], 'UTF-8'), $right - $left);
            foreach ($this->tokens($between) as $token) if ($this->qualityGate->isPredicateWord($token)) $this->add($out, $token, 'DOMAIN_PHRASE', 'WEAK');
        }
        foreach ($this->properNameSpans($text) as $span) {
            if ($this->containsApprovedLabel($span, $approvedLabels)) continue;
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
        foreach ($approvedLabels as $label) {
            $label = trim((string) $label);
            if ($label === '' || !preg_match('/(?<![\p{L}\p{N}])' . preg_quote($label, '/') . '\s+\d{1,3}\s+([\p{L}][\p{L}-]*)(?![\p{L}\p{N}])/iu', $text, $match)) continue;
            if (!$this->qualityGate->isBoundaryWord((string) ($match[1] ?? ''))) $this->add($out, (string) $match[0], 'STRUCTURAL_CONFIGURATION', 'NORMAL');
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($label, '/') . '\s+\d{1,3}(?![\p{L}\p{N}])/iu', $text, $numberMatch)) $this->add($out, (string) $numberMatch[0], 'IDENTIFIER_SPAN', 'NORMAL');
        }

        foreach ($this->genericPhraseSpans($text, $lexicalLabels) as $phrase) {
            $this->add($out, $phrase, 'DOMAIN_PHRASE', 'NORMAL');
        }

        $strongIdentifierTerms = array_values(array_filter(array_column($out, null), static fn (array $item): bool => in_array($item['origin'] ?? '', ['IDENTIFIER_SPAN', 'TECHNICAL_PATTERN'], true)));
        usort($strongIdentifierTerms, static fn (array $left, array $right): int => mb_strpos($text, (string) ($left['term'] ?? '')) <=> mb_strpos($text, (string) ($right['term'] ?? '')));
        for ($index = 0; $index < count($strongIdentifierTerms) - 1; $index++) {
            $leftTerm = (string) ($strongIdentifierTerms[$index]['term'] ?? '');
            $rightTerm = (string) ($strongIdentifierTerms[$index + 1]['term'] ?? '');
            $left = mb_strpos($text, $leftTerm);
            $right = mb_strpos($text, $rightTerm, ($left === false ? 0 : $left + mb_strlen($leftTerm, 'UTF-8')));
            if ($left === false || $right === false) continue;
            foreach ($this->tokens(mb_substr($text, $left + mb_strlen($leftTerm, 'UTF-8'), $right - $left)) as $token) if ($this->qualityGate->isPredicateWord($token)) $this->add($out, $token, 'DOMAIN_PHRASE', 'WEAK');
        }

        $out = $this->removeWeakerSubspans($out);
        foreach ($out as &$item) {
            $item['occurrences'] = $this->occurrences($text, (string) ($item['term'] ?? ''));
        }
        unset($item);
        return array_values($out);
    }

    /** @return list<array{term:string,normalized_term:string,origin:string,strength:string}> */
    public function editorialSignals(string $text): array
    {
        $signals = [];
        foreach (preg_split('/[.!?;,:\n“”"\']+/u', $text) ?: [] as $clause) {
            $tokens = $this->tokens($clause);
            if ($tokens === []) continue;
            $boundary = null;
            foreach ($tokens as $index => $token) {
                if ($this->qualityGate->isBoundaryWord($token)) {
                    $boundary = $index;
                    break;
                }
            }
            if ($boundary === null) continue;
            $prefix = array_slice($tokens, 0, $boundary);
            while ($prefix !== [] && in_array(mb_strtolower((string) $prefix[0], 'UTF-8'), ['con', 'chiếc', 'một', 'mẫu', 'the', 'a', 'an'], true)) array_shift($prefix);
            if (count($prefix) < 2) continue;
            $tail = array_slice($tokens, $boundary);
            while ($tail !== [] && in_array(mb_strtolower((string) $tail[0], 'UTF-8'), ['mà', 'và', 'cũng', 'thì', 'nghe', 'nhìn'], true)) array_shift($tail);
            if ($tail === []) continue;
            $term = trim(implode(' ', $tail));
            $normalized = $this->normalizer->normalize($term);
            if ($normalized !== '') $signals[$normalized] = ['term' => $term, 'normalized_term' => $normalized, 'origin' => 'EDITORIAL_SIGNAL', 'strength' => 'NORMAL'];
        }
        $patterns = [
            '/\b(?:hãy\s+\p{L}+|đừng\s+vội\s+\p{L}+|chỉ\s+cần\s+\p{L}+|cảm\s+thấy)\b/iu',
            '/\b(?:\p{Lu}[\p{L}-]+\s+){1,3}(?:bắt\s+nguồn|ghi\s+nhận|được\s+ghi\s+nhận)\b/u',
        ];
        foreach ($patterns as $pattern) if (preg_match_all($pattern, $text, $matches)) foreach ($matches[0] as $term) {
            $normalized = $this->normalizer->normalize((string) $term);
            if ($normalized !== '') $signals[$normalized] = ['term' => trim((string) $term), 'normalized_term' => $normalized, 'origin' => 'EDITORIAL_SIGNAL', 'strength' => 'NORMAL'];
        }
        return array_values($signals);
    }

    public function noiseSignals(string $text): array
    {
        $signals = [];
        if (preg_match_all('/\b(?:[\p{L}]+\s+){0,3}(?:mang\s+đồng\s+thời|hoàn\s+toàn)\s+[\p{L}]+(?:\s+[\p{L}]+){0,2}\b/iu', $text, $matches)) foreach ($matches[0] as $term) {
            $term = trim((string) preg_replace('/^(?:và|nhưng|mà|có|là)\s+/iu', '', (string) $term));
            $normalized = $this->normalizer->normalize($term);
            if ($normalized !== '') $signals[$normalized] = ['term' => $term, 'normalized_term' => $normalized, 'origin' => 'NOISE', 'strength' => 'NORMAL'];
        }
        return array_values($signals);
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
        $evidenceStatus = $this->evidenceStatus($term, $origin);
        if (isset($out[$normalized])) {
            if (($out[$normalized]['resolver_eligible'] ?? false) === true && $evidenceStatus !== 'QUALIFIED') return;
            if ($this->evidenceRank((string) ($out[$normalized]['origin'] ?? '')) > $this->evidenceRank($origin)) return;
            if ($this->strengthRank((string) ($out[$normalized]['strength'] ?? '')) >= $this->strengthRank($strength)) return;
        }
        $out[$normalized] = [
            'term' => $term,
            'normalized_term' => $normalized,
            'origin' => $origin,
            'strength' => $strength,
            'evidence_status' => $evidenceStatus,
            'evidence_reason' => $this->evidenceReason($term, $origin),
            'resolver_eligible' => $evidenceStatus === 'QUALIFIED',
        ];
    }

    private function strengthRank(string $strength): int
    {
        return ['WEAK' => 1, 'NORMAL' => 2, 'STRONG' => 3][$strength] ?? 0;
    }

    private function evidenceRank(string $origin): int
    {
        return in_array($origin, ['KNOWN_LABEL', 'HINT', 'PROPER_NAME_SPAN', 'IDENTIFIER_SPAN', 'TECHNICAL_PATTERN', 'STRUCTURAL_CONFIGURATION', 'MUSIC_NAME'], true)
            ? 3
            : (in_array($origin, ['HYPHENATED_NAME', 'QUOTED_PHRASE'], true) ? 2 : 1);
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
        foreach ($this->genericPhraseSpans($text, $lexicalLabels) as $phrase) {
            $parts = preg_split('/\s+/u', trim($phrase)) ?: [];
            if (count($parts) === 1 || (isset($parts[1]) && preg_match('/^\d+$/u', (string) $parts[1]))) {
                $units[$this->normalizer->normalize((string) $parts[0])] = true;
            }
        }
        if (preg_match_all('/(?<![\p{L}\p{N}])\d{1,3}\s+([\p{L}][\p{L}-]*)(?=\s+\d{1,3}\s+[\p{L}])/u', $text, $matches)) {
            foreach ($matches[1] as $unit) {
                $normalized = $this->normalizer->normalize((string) $unit);
                if ($normalized !== '' && !in_array($normalized, ['câu', 'trang', 'mục', 'phần'], true) && !$this->qualityGate->isBoundaryWord($normalized)) $units[$normalized] = true;
            }
            if (preg_match_all('/(?<![\p{L}\p{N}])\d{1,3}\s+[\p{L}][\p{L}-]*\s+\d{1,3}\s+([\p{L}][\p{L}-]*)/u', $text, $tailMatches)) {
                foreach ($tailMatches[1] as $unit) {
                    $normalized = $this->normalizer->normalize((string) $unit);
                    if ($normalized !== '' && !in_array($normalized, ['câu', 'trang', 'mục', 'phần'], true) && !$this->qualityGate->isBoundaryWord($normalized)) $units[$normalized] = true;
                }
            }
        }
        return $units;
    }

    private function isEligibleStructuralConfiguration(string $phrase, array $eligibleUnits): bool
    {
        $parts = preg_split('/\s+/u', trim($phrase)) ?: [];
        if (count($parts) < 4 || count($parts) % 2 !== 0) return false;
        for ($index = 0; $index < count($parts); $index += 2) {
            if (!preg_match('/^\d{1,3}$/u', (string) $parts[$index])) return false;
            $unit = $this->normalizer->normalize((string) $parts[$index + 1]);
            if (!isset($eligibleUnits[$unit])) return false;
        }
        return true;
    }

    private function genericPhraseSpans(string $text, array $lexicalLabels): array
    {
        $spans = [];
        foreach (preg_split('/[.!?;,:\n“”"\']+/u', $text) ?: [] as $clause) {
            $current = [];
            $suppressUntilCapital = false;
            $suppressPredicateTail = false;
            $skipToken = false;
            $flush = function () use (&$spans, &$current, $lexicalLabels): void {
                if ($current === []) return;
                $bounded = $this->qualityGate->filter(implode(' ', $current), $lexicalLabels);
                if ($bounded !== null) $this->appendGenericSpan($spans, $this->tokens($bounded));
                $current = [];
            };
            $clauseTokens = $this->tokens(trim($clause, " \t,()[]{}\"'"));
            foreach ($clauseTokens as $index => $token) {
                $nextToken = $clauseTokens[$index + 1] ?? '';
                $nextAfterToken = $clauseTokens[$index + 2] ?? '';
                $previousToken = $clauseTokens[$index - 1] ?? '';
                if ($suppressPredicateTail) {
                    if ($this->isCoordinatingToken($token)) $suppressPredicateTail = false;
                    continue;
                }
                if ($skipToken) {
                    $skipToken = false;
                    continue;
                }
                if ($this->qualityGate->isBoundaryPhrase($token, $nextToken)) {
                    if (count($current) === 2 && !$this->qualityGate->isStandaloneLexicalWord((string) $current[0]) && $this->qualityGate->isStandaloneLexicalWord((string) $current[1])) {
                        $current = [(string) $current[1]];
                    }
                    $flush();
                    $suppressUntilCapital = false;
                    $skipToken = true;
                    continue;
                }
                if ($suppressUntilCapital) {
                    if (preg_match('/^\p{Lu}/u', $token) !== 1) continue;
                    $suppressUntilCapital = false;
                }
                if ($this->qualityGate->isDiscourseStart($token)) {
                    $flush();
                    $suppressUntilCapital = true;
                    continue;
                }
                if (preg_match('/\d/u', $token)) {
                    $flush();
                    continue;
                }
                if ($current !== [] && count($current) >= 2 && $this->qualityGate->isCompoundLead($token, $nextToken)) {
                    $flush();
                }
                if ($current !== [] && $this->qualityGate->isLexicalContinuationTail($previousToken, $token)) {
                    $current[] = $token;
                    continue;
                }
                if ($current !== [] && count($current) >= 2 && preg_match('/^\p{Lu}/u', $token) === 1 && $this->qualityGate->isCompoundLead((string) $current[0], (string) $current[1])) {
                    $flush();
                }
                if (mb_strtolower($token, 'UTF-8') === 'làm') {
                    if (count($current) < 2) $flush();
                    else $current[] = $token;
                    continue;
                }
                if (mb_strtolower($token, 'UTF-8') === 'cần' && $this->qualityGate->isPredicateWord($nextToken)) {
                    $flush();
                    continue;
                }
                if ($this->qualityGate->isPredicateBoundary($token, $nextToken)) {
                    if ($nextToken !== ''
                        && $current !== []
                        && $this->qualityGate->isTechnicalCompound(implode(' ', $current))) {
                        $flush();
                        $suppressPredicateTail = true;
                        continue;
                    }
                    if ($this->qualityGate->isLexicalContinuationBoundary($token, $nextToken, count($clauseTokens) - $index - 1, $nextAfterToken, count($current))) {
                        $current[] = $token;
                        continue;
                    }
                    if ($this->qualityGate->isCompoundLead($token, $nextToken)) {
                        if ($current !== [] && !$this->qualityGate->isStandaloneLexicalWord((string) $current[0])) $flush();
                        $current[] = $token;
                        continue;
                    }
                    if ($this->qualityGate->isWeakDiscourseBoundary($token)) {
                        $current = [];
                        continue;
                    }
                    $flush();
                    continue;
                }
                if ($current !== [] && count($current) >= 2 && $this->qualityGate->isCompoundLead((string) $current[0], (string) $current[1]) && $this->qualityGate->isNonLexicalSingleWord($token)) {
                    $flush();
                }
                if ($this->qualityGate->isBoundaryWord($token) && !$this->qualityGate->isModifierWord($token)) {
                    if ($this->qualityGate->isLexicalContinuationBoundary($token, $nextToken, count($clauseTokens) - $index - 1, $nextAfterToken, count($current))) {
                        $current[] = $token;
                        continue;
                    }
                    if ($this->qualityGate->isWeakDiscourseBoundary($token)) {
                        $current = [];
                        continue;
                    }
                    if ($this->qualityGate->isCompoundLead($token, $nextToken)) {
                        if ($current !== [] && !$this->qualityGate->isStandaloneLexicalWord((string) $current[0])) $flush();
                        $current[] = $token;
                        continue;
                    }
                    $flush();
                    continue;
                }
                $current[] = $token;
            }
            $flush();
        }
        foreach (preg_split('/[.!?;,:\n“”"\']+/u', $text) ?: [] as $clause) {
            $tokens = $this->tokens(trim($clause, " \t,()[]{}\"'"));
            $blockedPredicateTail = $this->predicateTailIndexes($tokens);
            $coveredUntil = 0;
            for ($start = 0; $start < count($tokens); $start++) {
                if ($start < $coveredUntil || isset($blockedPredicateTail[$start])) continue;
                if ($start > 0 && $this->qualityGate->isBoundaryPhrase((string) $tokens[$start - 1], (string) $tokens[$start])) continue;
                if ($this->qualityGate->isDiscourseStart((string) ($tokens[$start] ?? ''))) continue;
                for ($length = min(6, count($tokens) - $start); $length >= 2; $length--) {
                    $candidateTokens = array_slice($tokens, $start, $length);
                    $blocked = false;
                    for ($offset = $start; $offset < $start + $length; $offset++) if (isset($blockedPredicateTail[$offset])) {
                        $blocked = true;
                        break;
                    }
                    if ($blocked) continue;
                    $candidate = implode(' ', $candidateTokens);
                    if ($start + $length < count($tokens) && $this->qualityGate->isBoundaryPhrase((string) $tokens[$start + $length], (string) ($tokens[$start + $length + 1] ?? ''))) continue;
                    $hasBoundaryPhrase = false;
                    for ($offset = $start; $offset < $start + $length - 1; $offset++) if ($this->qualityGate->isBoundaryPhrase((string) $tokens[$offset], (string) $tokens[$offset + 1])) $hasBoundaryPhrase = true;
                    if ($hasBoundaryPhrase) continue;
                    if (!$this->qualityGate->isTechnicalCompound($candidate)) continue;
                    $bounded = $this->qualityGate->filter($candidate, $lexicalLabels);
                    if ($bounded !== null && $bounded === $candidate) {
                        $this->appendGenericSpan($spans, $this->tokens($candidate));
                        $coveredUntil = $start + $length;
                        break;
                    }
                }
            }
        }
        return array_values($spans);
    }

    /** @param list<string> $tokens @return array<int,true> */
    private function predicateTailIndexes(array $tokens): array
    {
        $blocked = [];
        $segmentStart = 0;
        foreach ($tokens as $index => $token) {
            if ($this->isCoordinatingToken((string) $token)) {
                $segmentStart = $index + 1;
                continue;
            }
            $next = (string) ($tokens[$index + 1] ?? '');
            if ($next === '' || !$this->qualityGate->isPredicateBoundary((string) $token, $next)) continue;
            $previous = (string) ($tokens[$index - 1] ?? '');
            if ($this->qualityGate->isLexicalContinuationTail($previous, (string) $token)) continue;
            $prefixTokens = array_slice($tokens, $segmentStart, $index - $segmentStart);
            if ($prefixTokens === [] || !$this->qualityGate->isTechnicalCompound(implode(' ', $prefixTokens))) continue;
            for ($tail = $index; $tail < count($tokens); $tail++) {
                if ($tail > $index && $this->isCoordinatingToken((string) $tokens[$tail])) break;
                $blocked[$tail] = true;
            }
        }
        return $blocked;
    }

    private function isCoordinatingToken(string $token): bool
    {
        return in_array(mb_strtolower($token, 'UTF-8'), ['và', 'hoặc', 'nhưng'], true);
    }

    /** @param array<string,string> $spans @param list<string> $tokens */
    private function appendGenericSpan(array &$spans, array $tokens): void
    {
        if ($tokens === []) return;
        if (count($tokens) > 6 || $this->containsNumber($tokens)) return;
        foreach ($tokens as $token) {
            if (preg_match('/[\/.]/u', (string) $token)) return;
        }
        if (count($tokens) > 2 && in_array(mb_strtolower((string) $tokens[0], 'UTF-8') . ' ' . mb_strtolower((string) $tokens[1], 'UTF-8'), ['cơ chế', 'tình trạng', 'mô tả', 'cách gọi'], true)) {
            $tokens = array_slice($tokens, 2);
        }
        if (count($tokens) > 1 && in_array(mb_strtolower((string) $tokens[0], 'UTF-8'), ['bản'], true)) array_shift($tokens);
        while (count($tokens) > 1 && in_array(mb_strtolower((string) $tokens[0], 'UTF-8'), ['the', 'a', 'an', 'chiếc', 'một', 'mẫu', 'con'], true)) array_shift($tokens);
        $hasStandaloneLexicalToken = false;
        foreach ($tokens as $token) if ($this->qualityGate->isStandaloneLexicalWord((string) $token)) {
            $hasStandaloneLexicalToken = true;
            break;
        }
        if (!$hasStandaloneLexicalToken && !$this->qualityGate->isTechnicalCompound(implode(' ', $tokens))) return;
        if (count($tokens) === 1 && $this->qualityGate->isStandaloneLexicalWord((string) $tokens[0])) {
            $normalized = $this->normalizer->normalize((string) $tokens[0]);
            if ($normalized !== '') $spans[$normalized] = (string) $tokens[0];
            return;
        }
        if (count($tokens) === 1 && preg_match('/^\p{Lu}/u', (string) $tokens[0]) && !in_array(mb_strtolower((string) $tokens[0], 'UTF-8'), ['con', 'chiếc', 'một', 'mẫu'], true)) {
            $normalized = $this->normalizer->normalize((string) $tokens[0]);
            if ($normalized !== '' && $this->qualityGate->isStandaloneLexicalWord((string) $tokens[0])) $spans[$normalized] = (string) $tokens[0];
            return;
        }
        if (count($tokens) < 2) return;
        $value = implode(' ', $tokens);
        if ($this->isEditorialOrNoisePhrase($value)) return;
        $spans[$this->normalizer->normalize($value)] = $value;
    }

    private function isEditorialOrNoisePhrase(string $value): bool
    {
        $normalized = $this->normalizer->normalize($value);
        return preg_match('/^(?:hãy\s+|đừng\s+vội\s+|chỉ\s+cần\s+|cảm\s+thấy\b)/u', $normalized) === 1
            || preg_match('/\b(?:mang\s+đồng\s+thời|hoàn\s+toàn)\b/u', $normalized)
            || preg_match('/^(?:[\p{Lu}][\p{L}-]+\s+){1,3}(?:bắt\s+nguồn|ghi\s+nhận|được\s+ghi\s+nhận)$/u', $value) === 1;
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\-\/.]*/u', $text, $matches);
        return array_values(array_map('strval', $matches[0] ?? []));
    }

    /** @param list<string> $tokens */
    private function containsNumber(array $tokens): bool
    {
        foreach ($tokens as $token) if (preg_match('/\d/u', $token)) return true;
        return false;
    }

    private function properNameSpans(string $text): array
    {
        $pattern = '/(?<![\p{L}])((?:\p{Lu}[\p{L}]+(?:-\p{Lu}[\p{L}]+)+)(?:\s+\p{Lu}[\p{L}]+(?:-\p{Lu}[\p{L}]+)?){1,3})(?![\p{L}])/u';
        $spans = [];
        if (preg_match_all($pattern, $text, $matches)) foreach ($matches[1] as $value) {
            $value = trim((string) $value);
            $first = preg_split('/\s+/u', $value)[0] ?? '';
            if (!$this->qualityGate->isDiscourseStart($first) && !in_array(mb_strtolower($first, 'UTF-8'), ['bản', 'mẫu', 'chiếc', 'một', 'các', 'những'], true)) $spans[] = $value;
        }
        $contextPattern = '/\b(?:chiếc|mẫu|hãng|chữ|tên|thương\s+hiệu)\s+(\p{Lu}[\p{L}]{2,})\b/u';
        if (preg_match_all($contextPattern, $text, $contextMatches)) foreach ($contextMatches[1] as $value) $spans[] = trim((string) $value);
        $namePattern = '/(?<![\p{L}])((?:\p{Lu}[\p{L}]{1,})(?:\s+\p{Lu}[\p{L}]{1,}){1,3})(?![\p{L}])/u';
        if (preg_match_all($namePattern, $text, $nameMatches)) foreach ($nameMatches[1] as $value) {
            $value = trim((string) $value);
            $first = preg_split('/\s+/u', $value)[0] ?? '';
            if (!$this->qualityGate->isDiscourseStart($first) && !in_array(mb_strtolower($first, 'UTF-8'), ['bản', 'mẫu', 'chiếc', 'một', 'các', 'những'], true)) $spans[] = $value;
        }
        return array_values(array_unique($spans));
    }

    private function containsApprovedLabel(string $span, array $approvedLabels): bool
    {
        foreach ($approvedLabels as $label) if ($this->present($span, trim((string) $label))) return true;
        return false;
    }

    private function removeWeakerSubspans(array $items): array
    {
        $containers = array_values(array_unique(array_map(
            static fn (array $item): string => (string) $item['normalized_term'],
            $items,
        )));

        foreach ($items as $normalized => $item) {
            $candidateParts = preg_split('/\s+/u', trim((string) $normalized)) ?: [];
            foreach ($containers as $strong) {
                if ((string) $normalized === $strong) continue;
                $strongItem = $items[$strong] ?? null;
                if (($item['origin'] ?? '') === 'DOMAIN_PHRASE' && is_array($strongItem) && in_array($strongItem['origin'] ?? '', ['KNOWN_LABEL', 'HINT', 'MUSIC_NAME', 'PROPER_NAME_SPAN', 'IDENTIFIER_SPAN', 'TECHNICAL_PATTERN'], true) && $this->isTokenSubspan($strong, (string) $normalized)) {
                    unset($items[$normalized]);
                    break;
                }
                $strongItem = $items[$strong] ?? null;
                $strongerEvidence = is_array($strongItem) && $this->evidenceRankForItem($strongItem) > $this->evidenceRankForItem($item);
                $strongParts = preg_split('/\s+/u', trim($strong)) ?: [];
                $longerSameEvidence = is_array($strongItem) && $this->evidenceRankForItem($strongItem) === $this->evidenceRankForItem($item) && (count($strongParts) > count($candidateParts) || mb_strlen($strong, 'UTF-8') > mb_strlen((string) $normalized, 'UTF-8'));
                if (($this->isTokenSubspan((string) $normalized, $strong) && ($strongerEvidence || $longerSameEvidence)) || $this->isIdentifierFragment((string) $normalized, $strong)) {
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
        if ($candidateParts === []) return false;
        if (count($candidateParts) < count($containerParts)) {
            for ($offset = 0; $offset <= count($containerParts) - count($candidateParts); $offset++) {
                if (array_slice($containerParts, $offset, count($candidateParts)) === $candidateParts) return true;
            }
        }
        $candidateParts = preg_split('/\s+/u', preg_replace('/[-‐‑‒–—―]/u', ' ', trim($candidate)) ?? trim($candidate)) ?: [];
        $containerParts = preg_split('/\s+/u', preg_replace('/[-‐‑‒–—―]/u', ' ', trim($container)) ?? trim($container)) ?: [];
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

    private function evidenceRankForItem(array $item): int
    {
        $origin = (string) ($item['origin'] ?? '');
        if (in_array($origin, ['KNOWN_LABEL', 'HINT', 'IDENTIFIER_SPAN'], true)) return 4;
        if (in_array($origin, ['PROPER_NAME_SPAN', 'TECHNICAL_PATTERN', 'STRUCTURAL_CONFIGURATION', 'MUSIC_NAME'], true)) return 3;
        if (in_array($origin, ['QUOTED_PHRASE', 'HYPHENATED_NAME'], true)) return 2;
        if ($origin === 'DOMAIN_PHRASE' && ($item['evidence_status'] ?? '') === 'QUALIFIED' && $this->qualityGate->isTechnicalCompound((string) ($item['term'] ?? ''))) return 2;
        return 1;
    }

    private function evidenceStatus(string $term, string $origin): string
    {
        if (in_array($origin, ['KNOWN_LABEL', 'HINT', 'PROPER_NAME_SPAN', 'IDENTIFIER_SPAN', 'TECHNICAL_PATTERN', 'HYPHENATED_NAME', 'QUOTED_PHRASE', 'STRUCTURAL_CONFIGURATION', 'MUSIC_NAME'], true)) return 'QUALIFIED';
        if ($origin === 'DOMAIN_PHRASE' && $this->qualityGate->isTechnicalCompound($term)) return 'QUALIFIED';
        if ($origin === 'DOMAIN_PHRASE') return 'OBSERVATION_ONLY';
        return 'OBSERVATION_ONLY';
    }

    private function evidenceReason(string $term, string $origin): string
    {
        return match ($origin) {
            'KNOWN_LABEL' => 'APPROVED_LABEL',
            'HINT' => 'EXPLICIT_HINT',
            'PROPER_NAME_SPAN' => 'PROPER_NAME_STRUCTURE',
            'IDENTIFIER_SPAN', 'TECHNICAL_PATTERN' => 'IDENTIFIER_STRUCTURE',
            'STRUCTURAL_CONFIGURATION' => 'NUMERIC_CONFIGURATION',
            'QUOTED_PHRASE' => 'QUOTED_DEFINITION_CONTEXT',
            'MUSIC_NAME' => 'KNOWN_MUSIC_NAME',
            'DOMAIN_PHRASE' => $this->qualityGate->isTechnicalCompound($term) ? 'TECHNICAL_COMPOUND_STRUCTURE' : 'INSUFFICIENT_GENERIC_CONTEXT',
            'EDITORIAL_SIGNAL' => 'EDITORIAL_CONTEXT',
            'NOISE' => 'NOISE_SIGNAL',
            default => 'UNSUPPORTED_EVIDENCE',
        };
    }

    private function occurrences(string $text, string $term): int
    {
        if ($term === '') return 0;
        $count = preg_match_all('/(?<![\p{L}\p{N}_])' . preg_quote($term, '/') . '(?![\p{L}\p{N}_])/iu', $text, $matches);
        return max(1, (int) $count);
    }

}
