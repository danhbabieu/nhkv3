<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Deterministic, read-only evaluation of whether public copy keeps its topic promise. */
final class TopicFulfillment
{
    /** @param list<array<string,mixed>> $claims @return array<string,mixed> */
    public function evaluate(string $topic, array $claims, string $copy): array
    {
        $promise = $this->promise($topic);
        if ($promise['kind'] === 'none') return ['fulfilled' => true, 'promise' => $promise, 'diagnostic' => null, 'missing' => [], 'covered' => []];

        $eligible = array_values(array_filter($claims, static fn (mixed $claim): bool => is_array($claim) && ($claim['eligibility'] ?? 'eligible') === 'eligible'));
        $supported = implode(' ', array_map(static fn (array $claim): string => (string) ($claim['text'] ?? $claim['claim_text'] ?? ''), $eligible));
        $copyKey = $this->key($copy);
        $supportedKey = $this->key($supported);
        $covered = [];
        $missing = [];
        $candidateConcepts = [];
        foreach ($eligible as $claim) $candidateConcepts = array_merge($candidateConcepts, $this->candidateConcepts($claim));
        if ($promise['kind'] === 'enumeration') {
            $promise['terms'] = array_values(array_unique($candidateConcepts));
        }
        foreach ($promise['terms'] as $term) {
            if (str_contains($copyKey, $this->key($term)) && str_contains($supportedKey, $this->key($term))) $covered[] = $term;
            else $missing[] = $term;
        }

        $fulfilled = match ($promise['kind']) {
            'enumeration' => $promise['required_count'] > 0 && count($covered) >= $promise['required_count'],
            'comparison' => count($covered) >= 2 && $this->hasAny($copy, ['khác biệt', 'so sánh', 'difference', 'compare', 'versus', 'vs']),
            'explanation' => $this->hasAny($copy, ['hoạt động', 'nguyên lý', 'bằng cách', 'cách', 'works', 'how']) && $this->supportedByCopy($copy, $supported),
            'features' => count($covered) >= 2,
            default => true,
        };
        $diagnostic = $fulfilled ? null : match ($promise['kind']) {
            'enumeration' => 'ENUMERATION_PROMISE_UNFULFILLED',
            'comparison' => 'COMPARISON_PROMISE_UNFULFILLED',
            'explanation' => 'EXPLANATION_PROMISE_WEAK',
            default => 'TITLE_BODY_COVERAGE_GAP',
        };
        return ['fulfilled' => $fulfilled, 'promise' => $promise, 'diagnostic' => $diagnostic, 'missing' => array_values($missing), 'covered' => array_values($covered), 'eligible_claim_ids' => array_values(array_map(static fn (array $claim): string => (string) ($claim['claim_id'] ?? ''), $eligible))];
    }

    /** @return array<string,mixed> */
    public function promise(string $topic): array
    {
        $value = trim($topic);
        $lower = $this->lower($value);
        if (preg_match('/\b(\d+)\s+(?:types?|kinds?|versions?|features?|items?|loại|phiên bản|đặc điểm|tính năng)\b/iu', $lower, $match) === 1) {
            $count = (int) $match[1];
            return ['kind' => 'enumeration', 'required_count' => $count, 'terms' => $this->enumerationTerms($value)];
        }
        if (preg_match('/\b(?:three|four|five|ba|bốn|năm)\s+(?:types?|kinds?|versions?|đặc điểm|phiên bản)\b/iu', $lower, $match) === 1) {
            $count = ['three' => 3, 'four' => 4, 'five' => 5, 'ba' => 3, 'bốn' => 4, 'năm' => 5][$this->lower($match[1])] ?? 3;
            return ['kind' => 'enumeration', 'required_count' => $count, 'terms' => $this->enumerationTerms($value)];
        }
        if (preg_match('/\b(?:differences?|comparison|compare|khác biệt|so sánh)\b/iu', $lower) === 1) return ['kind' => 'comparison', 'required_count' => 2, 'terms' => $this->comparisonTerms($value)];
        if (preg_match('/\b(?:how|cách|nguyên lý)\b.{0,80}\b(?:work|hoạt động)\b|\bhow\b.{0,80}\bwork/iu', $lower) === 1) return ['kind' => 'explanation', 'required_count' => 1, 'terms' => $this->topicTerms($value)];
        if (preg_match('/\b(?:features?|đặc điểm|tính năng)\b/iu', $lower) === 1) return ['kind' => 'features', 'required_count' => 2, 'terms' => $this->topicTerms($value)];
        return ['kind' => 'none', 'required_count' => 0, 'terms' => []];
    }

    /** @return list<string> */
    public function candidateConcepts(array $claim): array
    {
        $concepts = [];
        foreach (['topic_concepts', 'semantic_concepts', 'completion_concepts'] as $field) foreach ((array) ($claim[$field] ?? []) as $concept) if (is_string($concept) && trim($concept) !== '') $concepts[] = trim($concept);
        $text = (string) ($claim['text'] ?? $claim['claim_text'] ?? '');
        if (preg_match('/\b(?:types?|kinds?|versions?|loại|phiên bản)\b[^:：]*[:：]\s*(.+)$/iu', $text, $match) !== 1) preg_match('/\b(?:gồm|include|includes)\s*[:：]?\s*(.+)$/iu', $text, $match);
        if (isset($match[1])) {
            foreach (preg_split('/\s*(?:,|;|\band\b|\bvà\b)\s*/iu', trim($match[1], " .!?") ) ?: [] as $concept) if (trim($concept) !== '') $concepts[] = trim($concept);
        }
        return array_values(array_unique($concepts));
    }

    /** @return list<string> */
    private function enumerationTerms(string $topic): array
    {
        $terms = $this->topicTerms($topic);
        return array_values(array_filter($terms, static fn (string $term): bool => !preg_match('/^(?:types?|kinds?|versions?|features?|loại|phiên bản)$/iu', $term)));
    }

    /** @return list<string> */
    private function comparisonTerms(string $topic): array
    {
        $topic = preg_replace('/^.*?\b(?:between|giữa)\b/iu', '', $topic) ?? $topic;
        $parts = preg_split('/\b(?:and|và|versus|vs|với)\b/iu', $topic) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn (string $part): bool => $part !== '' && mb_strlen($part) >= 2));
    }

    /** @return list<string> */
    private function topicTerms(string $topic): array
    {
        return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $this->lower($topic)) ?: [], static fn (string $term): bool => mb_strlen($term) >= 3 && !in_array($term, ['the', 'and', 'của', 'và', 'các', 'how', 'what', 'types', 'types'], true)));
    }

    private function supportedByCopy(string $copy, string $supported): bool
    {
        $copyTokens = array_unique($this->topicTerms($copy));
        $supportedTokens = array_unique($this->topicTerms($supported));
        return $supportedTokens !== [] && count(array_intersect($copyTokens, $supportedTokens)) / count($supportedTokens) >= 0.35;
    }

    private function hasAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) if (str_contains($this->lower($value), $this->lower($needle))) return true;
        return false;
    }

    private function key(string $value): string
    {
        return trim((string) (preg_replace('/[^\p{L}\p{N}]+/u', ' ', $this->lower($value)) ?? $value));
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }
}
