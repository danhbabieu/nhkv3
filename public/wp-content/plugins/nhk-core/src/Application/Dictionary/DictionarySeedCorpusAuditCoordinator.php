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
    private const MAX_SOURCE_LOOKUP_COST = 64;
    private const ARTICLE_LOOKUP_COST_PER_SEED = 4;

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
        $selected = $state['active'] !== null ? [$state['active']['source_scope']] : ($scope === 'ALL' ? array_keys($this->readers) : [$scope]);
        $items = [];
        $sourcesScanned = 0;
        $lexicalObservations = 0;
        $observationOnlyCount = 0;
        $next = null;
        $hasMore = false;
        $diagnostics = [];
        foreach ($selected as $sourceScope) {
            $reader = $this->readers[$sourceScope] ?? null;
            if (!$reader instanceof DictionaryCorpusSourceReader) continue;
            $active = is_array($state['active']) && ($state['active']['source_scope'] ?? null) === $sourceScope ? $state['active'] : null;
            $pageAfter = $active['page_after'] ?? ($state['after'][$sourceScope] ?? null);
            try { $page = $reader->page($pageAfter, $limit); }
            catch (\Throwable $error) { $this->diagnostic($diagnostics, ['code' => 'CORPUS_SOURCE_PAGE_FAILED', 'stage' => 'article_reader', 'source_scope' => $sourceScope, 'error' => get_class($error)]); continue; }
            foreach ((array) ($page['diagnostics'] ?? []) as $diagnostic) if (is_array($diagnostic)) $this->diagnostic($diagnostics, $diagnostic + ['source_scope' => $sourceScope]);
            $seenSources = [];
            $activeFound = false;
            foreach ((array) ($page['items'] ?? []) as $rowIndex => $source) {
                if (!is_array($source)) {
                    $this->diagnostic($diagnostics, ['code' => 'CORPUS_SOURCE_SHAPE_INVALID', 'stage' => 'article_reader', 'source_scope' => $sourceScope, 'source_id' => $sourceScope . ':row:' . (string) $rowIndex]);
                    $sourcesScanned++;
                    continue;
                }
                $sourceIdValue = $source['source_id'] ?? '';
                $sourceId = is_scalar($sourceIdValue) ? trim((string) $sourceIdValue) : '';
                $sourceIdentityKnown = $sourceId !== '';
                if ($sourceId === '') {
                    $sourceId = $sourceScope . ':row:' . (string) $rowIndex;
                    $this->diagnostic($diagnostics, ['code' => 'CORPUS_SOURCE_ID_MISSING', 'stage' => 'article_reader', 'source_scope' => $sourceScope, 'source_id' => $sourceId]);
                }
                if (isset($seenSources[$sourceId])) continue;
                $seenSources[$sourceId] = true;
                if ($active !== null && $sourceId !== (string) ($active['source_id'] ?? '')) continue;
                if ($active !== null) $activeFound = true;
                $sourcesScanned++;
                try {
                    $text = trim((string) ($source['raw_text'] ?? ''));
                    if (isset($source['source_error'])) $this->diagnostic($diagnostics, ['code' => (string) $source['source_error'], 'stage' => 'article_reader', 'source_scope' => $sourceScope, 'source_id' => $sourceId]);
                    $family = trim((string) ($source['source_family'] ?? $sourceId)) ?: $sourceId;
                    $context = is_array($source['context'] ?? null) ? $source['context'] : [];
                    if (preg_match('//u', $text) !== 1) {
                        $this->diagnostic($diagnostics, ['code' => 'CORPUS_SOURCE_TEXT_ENCODING_INVALID', 'stage' => 'article_reader', 'source_scope' => $sourceScope, 'source_id' => $sourceId]);
                        continue;
                    }
                    $sourceFingerprint = $this->sourceFingerprint($sourceId, $family, $text, $context, (string) ($source['locale'] ?? 'vi-VN'), [
                        'raw_or_derived' => $source['raw_or_derived'] ?? 'RAW',
                        'lineage' => is_array($source['lineage'] ?? null) ? $source['lineage'] : [],
                        'canonical_origin_id' => $source['canonical_origin_id'] ?? null,
                    ]);
                    if ($active !== null && !hash_equals((string) ($active['fingerprint'] ?? ''), $sourceFingerprint)) throw new \InvalidArgumentException('DICTIONARY_SEED_CORPUS_CURSOR_INVALIDATED');
                    try { $packet = $this->interpreter->interpret([
                    'raw_text' => $text,
                    'source_kind' => $sourceScope,
                    'source_identity' => ['source_id' => $sourceId, 'source_family' => $family],
                    'lineage' => is_array($source['lineage'] ?? null) ? $source['lineage'] : [],
                    'raw_or_derived' => (string) ($source['raw_or_derived'] ?? 'RAW'),
                    'metadata' => $context,
                    'locale' => (string) ($source['locale'] ?? 'vi-VN'),
                    ]); } catch (\Throwable $error) { $this->diagnostic($diagnostics, ['code' => 'CORPUS_SOURCE_INTERPRETATION_FAILED', 'stage' => 'structured_interpreter_detector', 'source_scope' => $sourceScope, 'source_id' => $sourceId, 'error' => get_class($error)]); continue; }
                    foreach ((array) ($packet->toArray()['lexical_spans'] ?? []) as $span) {
                        if (is_array($span) && ($span['evidence_status'] ?? null) === 'OBSERVATION_ONLY') $observationOnlyCount++;
                    }
                    $source['_source_identity_known'] = $sourceIdentityKnown;
                    $planOptions = ['source_family' => $family, 'context' => $context];
                    if ($sourceScope === 'ARTICLE') {
                        $planOptions['max_lookup_cost'] = self::MAX_SOURCE_LOOKUP_COST;
                        $planOptions['lookup_cost_per_seed'] = self::ARTICLE_LOOKUP_COST_PER_SEED;
                    }
                    if ($active !== null) $planOptions['seed_offset'] = (int) ($active['seed_offset'] ?? 0);
                    try {
                        $plan = $this->planner->plan($packet, $planOptions);
                    } catch (\Throwable $error) {
                        $this->diagnostic($diagnostics, ['code' => 'CORPUS_SOURCE_PLANNING_FAILED', 'stage' => 'resolver_planner', 'source_scope' => $sourceScope, 'source_id' => $sourceId, 'error' => get_class($error)]);
                        continue;
                    }
                    if (isset($plan['diagnostics']['truncated_seed_count'])) $this->diagnostic($diagnostics, ['code' => 'CORPUS_SOURCE_SEED_BUDGET_REACHED', 'stage' => 'resolver_planner', 'source_scope' => $sourceScope, 'source_id' => $sourceId]);
                    foreach ((array) ($plan['items'] ?? []) as $row) {
                        if (!is_array($row)) continue;
                        $lexicalObservations += (int) ($row['occurrences'] ?? 0);
                        try { $this->merge($items, $row, $sourceId, $family, $source); }
                        catch (\Throwable $error) { $this->diagnostic($diagnostics, ['code' => 'CORPUS_SOURCE_AGGREGATION_FAILED', 'stage' => 'result_aggregation', 'source_scope' => $sourceScope, 'source_id' => $sourceId, 'error' => get_class($error)]); break; }
                    }
                    if ((bool) ($plan['diagnostics']['has_more_seeds'] ?? false)) {
                        $state['active'] = ['source_scope' => $sourceScope, 'source_id' => $sourceId, 'page_after' => $pageAfter, 'fingerprint' => $sourceFingerprint, 'seed_offset' => (int) ($plan['diagnostics']['next_seed_offset'] ?? 0)];
                        $hasMore = true;
                    } else {
                        $state['active'] = null;
                        $state['after'][$sourceScope] = $sourceId;
                    }
                } catch (\Throwable $error) {
                    if ($error instanceof \InvalidArgumentException && $error->getMessage() === 'DICTIONARY_SEED_CORPUS_CURSOR_INVALIDATED') throw $error;
                    $this->diagnostic($diagnostics, ['code' => 'CORPUS_SOURCE_FAILED', 'stage' => 'source_pipeline', 'source_scope' => $sourceScope, 'source_id' => $sourceId, 'error' => get_class($error)]);
                }
                finally { $next = $sourceId; }
                if ($active !== null || $state['active'] !== null) break;
            }
            if ($active !== null && !$activeFound) throw new \InvalidArgumentException('DICTIONARY_SEED_CORPUS_CURSOR_INVALIDATED');
            if ($state['active'] !== null) break;
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
        $aggregate = ['sources_scanned' => $sourcesScanned, 'lexical_observations' => $lexicalObservations, 'observation_only_count' => $observationOnlyCount, 'unique_normalized_terms' => count($rows), 'independent_source_count_scope' => 'AUDIT_PAGE'] + $aggregateCounts;
        try { $comparison = $this->compareLegacyQueue($rows); }
        catch (\Throwable $error) {
            $this->diagnostic($diagnostics, ['code' => 'CORPUS_RESULT_SERIALIZATION_FAILED', 'stage' => 'result_serialization', 'source_scope' => $scope, 'error' => get_class($error)]);
            $comparison = ['legacy_only' => [], 'current_also_legacy' => [], 'current_only' => []];
        }
        try { $safeItems = array_map([$this, 'safeRow'], $rows); }
        catch (\Throwable $error) {
            $this->diagnostic($diagnostics, ['code' => 'CORPUS_RESULT_SERIALIZATION_FAILED', 'stage' => 'result_serialization', 'source_scope' => $scope, 'error' => get_class($error)]);
            $safeItems = [];
        }
        $cursorOut = $hasMore ? $this->encodeCursor($scope, $state['after'], $state['active']) : null;
        return [
            'status' => 'AVAILABLE', 'read_only' => true, 'mutated' => false,
            'source_scope' => $scope, 'sources_scanned' => $sourcesScanned,
            'lexical_observations' => $lexicalObservations, 'observation_only_count' => $observationOnlyCount, 'unique_normalized_terms' => count($rows),
            'items' => $safeItems, 'aggregate' => $aggregate,
            'legacy_candidate_comparison' => $comparison, 'next_cursor' => $cursorOut, 'has_more' => $cursorOut !== null,
            'diagnostics' => ['bounded_limit' => $limit, 'ordering' => 'source_id_ascending', 'legacy_queue' => 'comparison_only', 'source_diagnostics' => $diagnostics],
        ];
    }

    /** @param array<string,array<string,mixed>> $items @param array<string,mixed> $row */
    private function merge(array &$items, array $row, string $sourceId, string $family, array $source): void
    {
        $key = trim((string) ($row['normalized_form'] ?? ''));
        if ($key === '') return;
        if (!isset($items[$key])) {
            $items[$key] = $row;
            $items[$key]['occurrences'] = 0;
            $items[$key]['source_ids'] = [];
            $items[$key]['source_families'] = [];
            $items[$key]['independent_source_ids'] = [];
            $items[$key]['independent_origin_ids'] = [];
            $items[$key]['derived_lineage'] = [];
            $items[$key]['ambiguity_count'] = 0;
            $items[$key]['diagnostics'] = [];
            $items[$key]['provenance_uncertain'] = false;
        }
        $item =& $items[$key];
        $item['occurrences'] += (int) ($row['occurrences'] ?? 0);
        foreach ((array) ($row['raw_forms'] ?? []) as $rawForm) {
            $rawForm = $this->safeString($rawForm, '');
            if ($rawForm !== '' && !in_array($rawForm, $item['raw_forms'], true)) $item['raw_forms'][] = $rawForm;
        }
        if (!in_array($sourceId, $item['source_ids'], true)) $item['source_ids'][] = $sourceId;
        if (!in_array($family, $item['source_families'], true)) $item['source_families'][] = $family;
        $provenance = $this->provenance($source, $sourceId);
        if ($provenance['uncertain']) {
            $item['provenance_uncertain'] = true;
            $item['diagnostics'][] = 'PROVENANCE_INDEPENDENCE_UNCERTAIN';
        }
        if ($provenance['derived']) {
            if ($item['derived_lineage'] === []) $item['derived_lineage'] = $provenance['lineage'];
        } elseif (!in_array($provenance['origin_id'], $item['independent_origin_ids'], true)) {
            $item['independent_origin_ids'][] = $provenance['origin_id'];
            $item['independent_source_ids'][] = $sourceId;
        }
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
        return [
            'normalized_form' => $this->safeString($row['normalized_form'] ?? '', ''),
            'category' => $this->safeString($row['category'] ?? '', 'LEXICAL_OBSERVATION'),
            'classification' => $this->safeString($row['classification'] ?? '', 'NEW_LEXICAL_CANDIDATE'),
            'resolution_status' => $this->safeString($row['resolution_status'] ?? 'UNRESOLVED', 'UNRESOLVED'),
            'resolved_destination_type' => $this->safeNullableString($row['resolved_destination_type'] ?? null),
            'resolved_destination_id' => $this->safeNullableString($row['resolved_destination_id'] ?? null),
            'resolved_dictionary_concept_id' => $this->safeNullableString($row['resolved_dictionary_concept_id'] ?? null),
            'occurrences' => (int) ($row['occurrences'] ?? 0),
            'raw_forms' => array_values(array_filter(array_map(fn (mixed $value): string => $this->safeString($value, ''), (array) ($row['raw_forms'] ?? [])), static fn (string $value): bool => $value !== '')),
            'source_ids' => array_values(array_filter(array_map(fn (mixed $value): string => $this->safeString($value, ''), (array) ($row['source_ids'] ?? [])), static fn (string $value): bool => $value !== '')),
            'source_families' => array_values(array_filter(array_map(fn (mixed $value): string => $this->safeString($value, ''), (array) ($row['source_families'] ?? [])), static fn (string $value): bool => $value !== '')),
            'source_count' => count($row['source_ids'] ?? []),
            'source_family_count' => count($row['source_families'] ?? []),
            'independent_source_count' => count($row['independent_source_ids'] ?? []),
            'independent_source_count_scope' => 'AUDIT_PAGE',
            'derived_lineage' => $this->safeLineage((array) ($row['derived_lineage'] ?? [])),
            'provenance_status' => !empty($row['provenance_uncertain']) ? 'UNCERTAIN' : (!empty($row['derived_lineage']) ? 'DERIVED_PRESENT' : 'KNOWN'),
            'ambiguity_count' => (int) ($row['ambiguity_count'] ?? 0),
            'suggested_action' => $this->safeNullableString($row['suggested_action'] ?? null),
            'diagnostics' => array_values(array_filter(array_map(fn (mixed $value): string => $this->safeString($value, ''), (array) ($row['diagnostics'] ?? [])), static fn (string $value): bool => $value !== '')),
        ];
    }

    /** @param array<string,mixed> $lineage @return array<string,string> */
    private function safeLineage(array $lineage): array
    {
        $safe = [];
        if (!isset($lineage['parent_source_id']) && is_array($lineage['parent_source_ids'] ?? null)) {
            $lineage['parent_source_id'] = $lineage['parent_source_ids'][0] ?? null;
        }
        if (!isset($lineage['parent_claim_id']) && is_array($lineage['parent_claim_ids'] ?? null)) {
            $lineage['parent_claim_id'] = $lineage['parent_claim_ids'][0] ?? null;
        }
        foreach (['parent_source_id', 'parent_claim_id', 'canonical_origin_id', 'source_family'] as $field) {
            $value = $this->safeString($lineage[$field] ?? '', '');
            if ($value !== '') $safe[$field] = $value;
        }
        return $safe;
    }

    /** @return array{derived:bool,uncertain:bool,origin_id:string,lineage:array<string,string>} */
    private function provenance(array $source, string $sourceId): array
    {
        $lineage = is_array($source['lineage'] ?? null) ? $source['lineage'] : [];
        $rawOrDerived = strtoupper(trim((string) ($source['raw_or_derived'] ?? 'RAW')));
        $hasParent = trim((string) ($lineage['parent_source_id'] ?? '')) !== ''
            || trim((string) ($lineage['parent_claim_id'] ?? '')) !== ''
            || (array) ($lineage['parent_source_ids'] ?? []) !== []
            || (array) ($lineage['parent_claim_ids'] ?? []) !== [];
        $derived = $rawOrDerived === 'DERIVED' || $hasParent || ($lineage['derived'] ?? false) === true;
        $origin = trim((string) ($source['canonical_origin_id'] ?? $lineage['canonical_origin_id'] ?? $sourceId));
        $uncertain = !in_array($rawOrDerived, ['RAW', 'DERIVED'], true)
            || !$source['_source_identity_known']
            || ($rawOrDerived === 'DERIVED' && !$hasParent);

        return ['derived' => $derived, 'uncertain' => $uncertain, 'origin_id' => $origin !== '' ? $origin : $sourceId, 'lineage' => $this->safeLineage($lineage)];
    }

    private function diagnostic(array &$diagnostics, array $diagnostic): void
    {
        if (count($diagnostics) >= self::MAX_SOURCE_DIAGNOSTICS) return;
        $safe = [];
        foreach (['code', 'stage', 'source_scope', 'source_id', 'error'] as $field) {
            if (!array_key_exists($field, $diagnostic)) continue;
            $value = $this->safeString($diagnostic[$field], '');
            if ($value !== '') $safe[$field] = $value;
        }
        if (isset($safe['code'])) $diagnostics[] = $safe;
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
        $safe = static fn (mixed $value): string => is_scalar($value) && preg_match('//u', (string) $value) === 1 ? (string) $value : '';
        return ['legacy_only' => array_values(array_filter(array_map($safe, array_diff(array_keys($legacy), array_keys($current))), static fn (string $value): bool => $value !== '')), 'current_also_legacy' => array_values(array_filter(array_map($safe, array_intersect(array_keys($current), array_keys($legacy))), static fn (string $value): bool => $value !== '')), 'current_only' => array_values(array_filter(array_map($safe, array_diff(array_keys($current), array_keys($legacy))), static fn (string $value): bool => $value !== ''))];
    }

    private function rank(string $classification): int { return ['NEW_LEXICAL_CANDIDATE' => 1, 'RESOLVED_EXISTING' => 2, 'ALIAS_TO_EXISTING' => 3, 'EDITORIAL_ONLY' => 4, 'NOISE' => 4, 'SUPPRESSED' => 4, 'AMBIGUOUS' => 5][$classification] ?? 0; }
    private function sourceFingerprint(string $sourceId, string $family, string $text, array $context, string $locale, array $provenance = []): string
    {
        return hash('sha256', $sourceId . "\0" . $family . "\0" . $text . "\0" . (string) json_encode([$context, $locale, $provenance], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** @return array{after:array<string,?string>,active:?array<string,mixed>} */
    private function decodeCursor(?string $cursor, string $scope): array
    {
        if ($cursor === null || $cursor === '') return ['after' => [], 'active' => null];
        $decoded = base64_decode(strtr($cursor, '-_', '+/') . str_repeat('=', (4 - strlen($cursor) % 4) % 4), true);
        $value = is_string($decoded) ? json_decode($decoded, true) : null;
        if (!is_array($value) || ($value['scope'] ?? null) !== $scope || !is_array($value['after'] ?? null)) throw new \InvalidArgumentException('DICTIONARY_SEED_CORPUS_CURSOR_INVALID');
        $active = $value['active'] ?? null;
        if ($active !== null && (!is_array($active) || !is_string($active['source_scope'] ?? null) || !is_string($active['source_id'] ?? null) || !is_string($active['fingerprint'] ?? null) || !preg_match('/^[a-f0-9]{64}$/i', $active['fingerprint']) || !is_int($active['seed_offset'] ?? null) || $active['seed_offset'] < 0)) throw new \InvalidArgumentException('DICTIONARY_SEED_CORPUS_CURSOR_INVALID');
        return ['after' => array_map(static fn (mixed $item): ?string => is_string($item) && $item !== '' ? $item : null, $value['after']), 'active' => $active];
    }
    private function encodeCursor(string $scope, array $after, ?array $active = null): string
    {
        $safeAfter = [];
        foreach ($after as $sourceScope => $cursor) $safeAfter[$this->safeString($sourceScope, '')] = $this->safeString($cursor, '');
        return rtrim(strtr(base64_encode((string) json_encode(['scope' => $scope, 'after' => $safeAfter, 'active' => $active], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
