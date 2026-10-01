<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

use NHK\Core\Application\Semantic\StructuredSemanticInterpreter;
use NHK\Core\Contracts\Dictionary\DictionaryCandidateRepository;
use NHK\Core\Domain\Dictionary\DictionaryCandidate;

/** Coordinates bounded canonical source reads into an ephemeral Seed v1 plan. */
final class DictionarySeedCorpusAuditCoordinator
{
    private const SCOPES = ['KNOWLEDGE', 'ARTICLE', 'ALL'];
    private const MAX_SOURCE_DIAGNOSTICS = 50;

    /** @param array<string,DictionaryCorpusSourceReader> $readers */
    public function __construct(
        private array $readers,
        private StructuredSemanticInterpreter $interpreter,
        private DictionarySeedPlanner $planner,
        private ?DictionaryCandidateRepository $legacyCandidates = null,
    ) {}

    /** @return array<string,mixed> */
    public function audit(string $scope = 'KNOWLEDGE', ?string $cursor = null, int $limit = 50): array
    {
        $scope = strtoupper(trim($scope));
        if (!in_array($scope, self::SCOPES, true)) throw new \InvalidArgumentException('DICTIONARY_SEED_CORPUS_SCOPE_INVALID');
        if ($limit < 1 || $limit > 100) throw new \InvalidArgumentException('DICTIONARY_SEED_CORPUS_LIMIT_INVALID');
        $state = $this->decodeCursor($cursor, $scope);
        $selected = $scope === 'ALL' ? array_keys($this->readers) : [$scope];
        $items = [];
        $sourcesScanned = 0;
        $lexicalObservations = 0;
        $next = null;
        $hasMore = false;
        $diagnostics = [];
        foreach ($selected as $sourceScope) {
            $reader = $this->readers[$sourceScope] ?? null;
            if (!$reader instanceof DictionaryCorpusSourceReader) continue;
            try { $page = $reader->page($state['after'][$sourceScope] ?? null, $limit); }
            catch (\Throwable $error) { $this->diagnostic($diagnostics, ['code' => 'CORPUS_SOURCE_PAGE_FAILED', 'source_scope' => $sourceScope, 'error' => get_class($error)]); continue; }
            foreach ((array) ($page['diagnostics'] ?? []) as $diagnostic) if (is_array($diagnostic)) $this->diagnostic($diagnostics, $diagnostic + ['source_scope' => $sourceScope]);
            $seenSources = [];
            foreach ((array) ($page['items'] ?? []) as $rowIndex => $source) {
                if (!is_array($source)) {
                    $this->diagnostic($diagnostics, ['code' => 'CORPUS_SOURCE_SHAPE_INVALID', 'source_scope' => $sourceScope, 'source_id' => $sourceScope . ':row:' . (string) $rowIndex]);
                    $sourcesScanned++;
                    continue;
                }
                $sourceId = trim((string) ($source['source_id'] ?? ''));
                if ($sourceId === '') {
                    $sourceId = $sourceScope . ':row:' . (string) $rowIndex;
                    $this->diagnostic($diagnostics, ['code' => 'CORPUS_SOURCE_ID_MISSING', 'source_scope' => $sourceScope, 'source_id' => $sourceId]);
                }
                $text = trim((string) ($source['raw_text'] ?? ''));
                if (isset($seenSources[$sourceId])) continue;
                $seenSources[$sourceId] = true;
                $sourcesScanned++;
                if (isset($source['source_error'])) $this->diagnostic($diagnostics, ['code' => (string) $source['source_error'], 'source_scope' => $sourceScope, 'source_id' => $sourceId]);
                $family = trim((string) ($source['source_family'] ?? $sourceId)) ?: $sourceId;
                $context = is_array($source['context'] ?? null) ? $source['context'] : [];
                if (preg_match('//u', $text) !== 1) {
                    $this->diagnostic($diagnostics, ['code' => 'CORPUS_SOURCE_TEXT_ENCODING_INVALID', 'source_scope' => $sourceScope, 'source_id' => $sourceId]);
                    $next = $sourceId;
                    continue;
                }
                try { $packet = $this->interpreter->interpret([
                    'raw_text' => $text,
                    'source_kind' => $sourceScope,
                    'source_identity' => ['source_id' => $sourceId, 'source_family' => $family],
                    'lineage' => is_array($source['lineage'] ?? null) ? $source['lineage'] : [],
                    'raw_or_derived' => (string) ($source['raw_or_derived'] ?? 'RAW'),
                    'metadata' => $context,
                    'locale' => (string) ($source['locale'] ?? 'vi-VN'),
                ]); } catch (\Throwable $error) { $this->diagnostic($diagnostics, ['code' => 'CORPUS_SOURCE_INTERPRETATION_FAILED', 'source_scope' => $sourceScope, 'source_id' => $sourceId, 'error' => get_class($error)]); $next = $sourceId; continue; }
                try {
                    $plan = $this->planner->plan($packet, ['source_family' => $family, 'context' => $context]);
                } catch (\Throwable $error) {
                    $this->diagnostic($diagnostics, ['code' => 'CORPUS_SOURCE_PLANNING_FAILED', 'source_scope' => $sourceScope, 'source_id' => $sourceId, 'error' => get_class($error)]);
                    $next = $sourceId;
                    continue;
                }
                foreach ((array) ($plan['items'] ?? []) as $row) {
                    if (!is_array($row)) continue;
                    $lexicalObservations += (int) ($row['occurrences'] ?? 0);
                    $this->merge($items, $row, $sourceId, $family);
                }
                $next = $sourceId;
            }
            $hasMore = $hasMore || (bool) ($page['has_more'] ?? false);
            if ($hasMore) $state['after'][$sourceScope] = (string) ($page['next_cursor'] ?? $next);
        }
        ksort($items);
        $rows = array_values($items);
        $aggregateCounts = ['resolved_existing' => 0, 'alias_to_existing' => 0, 'new_lexical_candidate' => 0, 'ambiguous' => 0, 'suppressed_editorial' => 0, 'suppressed_noise' => 0];
        foreach ($rows as $row) {
            $key = match ($row['classification']) {
                'RESOLVED_EXISTING' => 'resolved_existing', 'ALIAS_TO_EXISTING' => 'alias_to_existing',
                'NEW_LEXICAL_CANDIDATE' => 'new_lexical_candidate', 'AMBIGUOUS' => 'ambiguous',
                'EDITORIAL_ONLY' => 'suppressed_editorial', 'NOISE', 'SUPPRESSED' => 'suppressed_noise', default => null,
            };
            if ($key !== null) $aggregateCounts[$key]++;
        }
        $aggregate = ['sources_scanned' => $sourcesScanned, 'lexical_observations' => $lexicalObservations, 'unique_normalized_terms' => count($rows)] + $aggregateCounts;
        $comparison = $this->compareLegacyQueue($rows);
        $cursorOut = $hasMore ? $this->encodeCursor($scope, $state['after']) : null;
        return [
            'status' => 'AVAILABLE', 'read_only' => true, 'mutated' => false,
            'source_scope' => $scope, 'sources_scanned' => $sourcesScanned,
            'lexical_observations' => $lexicalObservations, 'unique_normalized_terms' => count($rows),
            'items' => array_map([$this, 'safeRow'], $rows), 'aggregate' => $aggregate,
            'legacy_candidate_comparison' => $comparison, 'next_cursor' => $cursorOut, 'has_more' => $cursorOut !== null,
            'diagnostics' => ['bounded_limit' => $limit, 'ordering' => 'source_id_ascending', 'legacy_queue' => 'comparison_only', 'source_diagnostics' => $diagnostics],
        ];
    }

    /** @param array<string,array<string,mixed>> $items @param array<string,mixed> $row */
    private function merge(array &$items, array $row, string $sourceId, string $family): void
    {
        $key = trim((string) ($row['normalized_form'] ?? ''));
        if ($key === '') return;
        if (!isset($items[$key])) {
            $items[$key] = $row;
            $items[$key]['occurrences'] = 0;
            $items[$key]['source_ids'] = [];
            $items[$key]['source_families'] = [];
            $items[$key]['ambiguity_count'] = 0;
            $items[$key]['diagnostics'] = [];
        }
        $item =& $items[$key];
        $item['occurrences'] += (int) ($row['occurrences'] ?? 0);
        if (!in_array($sourceId, $item['source_ids'], true)) $item['source_ids'][] = $sourceId;
        if (!in_array($family, $item['source_families'], true)) $item['source_families'][] = $family;
        $item['ambiguity_count'] = max((int) $item['ambiguity_count'], (int) ($row['ambiguity_count'] ?? 0));
        $item['diagnostics'] = array_values(array_unique(array_merge($item['diagnostics'], (array) ($row['diagnostics'] ?? []))));
        if ($this->rank((string) ($row['classification'] ?? '')) > $this->rank((string) ($item['classification'] ?? ''))) {
            foreach (['classification', 'resolution_status', 'resolved_destination_type', 'resolved_destination_id', 'resolved_dictionary_concept_id', 'suggested_action'] as $field) if (array_key_exists($field, $row)) $item[$field] = $row[$field];
        }
        unset($item);
    }

    /** @return array<string,mixed> */
    private function safeRow(array $row): array
    {
        return ['normalized_form' => $this->safeString($row['normalized_form'] ?? '', ''), 'category' => $this->safeString($row['category'] ?? '', 'LEXICAL_OBSERVATION'), 'classification' => $this->safeString($row['classification'] ?? '', 'NEW_LEXICAL_CANDIDATE'), 'resolution_status' => $this->safeString($row['resolution_status'] ?? 'UNRESOLVED', 'UNRESOLVED'), 'resolved_destination_type' => $this->safeNullableString($row['resolved_destination_type'] ?? null), 'resolved_destination_id' => $this->safeNullableString($row['resolved_destination_id'] ?? null), 'resolved_dictionary_concept_id' => $this->safeNullableString($row['resolved_dictionary_concept_id'] ?? null), 'occurrences' => (int) ($row['occurrences'] ?? 0), 'source_count' => count($row['source_ids'] ?? []), 'source_family_count' => count($row['source_families'] ?? []), 'ambiguity_count' => (int) ($row['ambiguity_count'] ?? 0), 'suggested_action' => $this->safeNullableString($row['suggested_action'] ?? null), 'diagnostics' => array_values(array_filter(array_map(fn (mixed $value): string => $this->safeString($value, ''), (array) ($row['diagnostics'] ?? [])), static fn (string $value): bool => $value !== ''))];
    }

    private function diagnostic(array &$diagnostics, array $diagnostic): void
    {
        if (count($diagnostics) < self::MAX_SOURCE_DIAGNOSTICS) $diagnostics[] = $diagnostic;
    }

    private function safeString(mixed $value, string $fallback): string
    {
        $value = is_scalar($value) ? (string) $value : $fallback;
        return preg_match('//u', $value) === 1 ? $value : $fallback;
    }

    private function safeNullableString(mixed $value): ?string
    {
        if ($value === null) return null;
        $safe = $this->safeString($value, '');
        return $safe !== '' ? $safe : null;
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private function compareLegacyQueue(array $rows): array
    {
        $legacy = [];
        if ($this->legacyCandidates !== null) foreach ($this->legacyCandidates->listForReview(500) as $candidate) if ($candidate instanceof DictionaryCandidate) $legacy[$candidate->normalizedTerm] = true;
        $current = array_fill_keys(array_map(static fn (array $row): string => (string) $row['normalized_form'], $rows), true);
        return ['legacy_only' => array_values(array_diff(array_keys($legacy), array_keys($current))), 'current_also_legacy' => array_values(array_intersect(array_keys($current), array_keys($legacy))), 'current_only' => array_values(array_diff(array_keys($current), array_keys($legacy)))];
    }

    private function rank(string $classification): int { return ['NEW_LEXICAL_CANDIDATE' => 1, 'RESOLVED_EXISTING' => 2, 'ALIAS_TO_EXISTING' => 3, 'EDITORIAL_ONLY' => 4, 'NOISE' => 4, 'SUPPRESSED' => 4, 'AMBIGUOUS' => 5][$classification] ?? 0; }
    /** @return array{after:array<string,?string>} */
    private function decodeCursor(?string $cursor, string $scope): array
    {
        if ($cursor === null || $cursor === '') return ['after' => []];
        $decoded = base64_decode(strtr($cursor, '-_', '+/') . str_repeat('=', (4 - strlen($cursor) % 4) % 4), true);
        $value = is_string($decoded) ? json_decode($decoded, true) : null;
        if (!is_array($value) || ($value['scope'] ?? null) !== $scope || !is_array($value['after'] ?? null)) throw new \InvalidArgumentException('DICTIONARY_SEED_CORPUS_CURSOR_INVALID');
        return ['after' => array_map(static fn (mixed $item): ?string => is_string($item) && $item !== '' ? $item : null, $value['after'])];
    }
    private function encodeCursor(string $scope, array $after): string { return rtrim(strtr(base64_encode((string) json_encode(['scope' => $scope, 'after' => $after], JSON_THROW_ON_ERROR)), '+/', '-_'), '='); }
}
