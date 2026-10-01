<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

use NHK\Core\Application\Semantic\StructuredInterpretationPacket;
use NHK\Core\Domain\Dictionary\DictionaryResolution;

/** Read-only Dictionary planning over the shared structured packet. */
final class DictionarySeedPlanner
{
    public function __construct(private DictionaryResolver $resolver) {}

    /** @param StructuredInterpretationPacket|array<string,mixed> $packet @param array<string,mixed> $options @return array<string,mixed> */
    public function plan(StructuredInterpretationPacket|array $packet, array $options = []): array
    {
        $value = $packet instanceof StructuredInterpretationPacket ? $packet->toArray() : $packet;
        $sourceContext = is_array($value['source_context'] ?? null) ? $value['source_context'] : [];
        $sourceFamily = trim((string) ($options['source_family'] ?? $sourceContext['source_identifier'] ?? $sourceContext['source_kind'] ?? 'unknown')) ?: 'unknown';
        $context = is_array($options['context'] ?? null) ? $options['context'] : [];
        $lookupCostPerSeed = max(1, min(16, (int) ($options['lookup_cost_per_seed'] ?? 1)));
        $maxSeeds = isset($options['max_lookup_cost'])
            ? max(1, min(128, intdiv(max(1, (int) $options['max_lookup_cost']), $lookupCostPerSeed)))
            : (isset($options['max_seeds']) ? max(1, min(128, (int) $options['max_seeds'])) : null);
        $items = [];
        $bounded = false;
        $truncatedSeeds = 0;

        foreach ((array) ($value['semantic_query_seeds'] ?? []) as $seed) {
            if (!is_array($seed)) continue;
            $normalized = trim((string) ($seed['normalized_form'] ?? ''));
            if ($normalized === '') continue;
            if ($maxSeeds !== null && !isset($items[$normalized]) && count($items) >= $maxSeeds) {
                $bounded = true;
                $truncatedSeeds++;
                continue;
            }
            $category = strtoupper(trim((string) ($seed['category'] ?? 'LEXICAL_OBSERVATION')));
            if (!isset($items[$normalized])) {
                $items[$normalized] = [
                    'normalized_form' => $normalized,
                    'category' => $category,
                    'locale' => (string) ($seed['locale'] ?? $value['locale'] ?? 'vi-VN'),
                    'raw_forms' => [],
                    'source_families' => [],
                    'occurrences' => 0,
                    'classification' => null,
                    'resolution' => [],
                    'resolution_status' => 'UNRESOLVED',
                    'resolved_destination_type' => null,
                    'resolved_destination_id' => null,
                    'resolved_dictionary_concept_id' => null,
                    'ambiguity_count' => 0,
                    'suggested_action' => null,
                    'diagnostics' => [],
                ];
                $items[$normalized]['resolution'] = ['status' => 'UNRESOLVED', 'concept_id' => null, 'destination_ids' => []];
            }
            $raw = trim((string) ($seed['raw_span'] ?? $normalized));
            if ($raw !== '' && !in_array($raw, $items[$normalized]['raw_forms'], true)) $items[$normalized]['raw_forms'][] = $raw;
            if (!in_array($sourceFamily, $items[$normalized]['source_families'], true)) $items[$normalized]['source_families'][] = $sourceFamily;
            $items[$normalized]['occurrences']++;
            if ((array) ($seed['diagnostics'] ?? []) !== []) $items[$normalized]['diagnostics'] = array_values(array_unique(array_merge($items[$normalized]['diagnostics'], array_map('strval', (array) $seed['diagnostics']))));
        }

        foreach ($items as &$item) {
            if ($item['category'] === 'EDITORIAL_SIGNAL') {
                $item['classification'] = 'EDITORIAL_ONLY';
                $item['suggested_action'] = 'SUPPRESS_EDITORIAL';
                continue;
            }
            if ($item['category'] === 'NOISE') {
                $item['classification'] = 'NOISE';
                $item['suggested_action'] = 'SUPPRESS_NOISE';
                continue;
            }
            $resolution = $this->resolver->resolve((string) ($item['raw_forms'][0] ?? $item['normalized_form']), $context + ['locale' => $item['locale']]);
            $item['resolution'] = [
                'status' => $resolution->status,
                'concept_id' => $resolution->status === DictionaryResolution::RESOLVED ? $resolution->conceptId : null,
                'preferred_label' => $resolution->status === DictionaryResolution::RESOLVED ? $resolution->preferredLabel : null,
                'destination_type' => $resolution->status === DictionaryResolution::RESOLVED ? $resolution->destinationType : null,
                'destination_id' => $resolution->status === DictionaryResolution::RESOLVED ? $resolution->destinationId : null,
                'destination_ids' => $resolution->status === DictionaryResolution::RESOLVED && $resolution->destinationId !== null ? [$resolution->destinationId] : [],
            ];
            $item['resolution_status'] = $resolution->status;
            $item['resolved_destination_type'] = $resolution->destinationType;
            $item['resolved_destination_id'] = $resolution->destinationId;
            $item['resolved_dictionary_concept_id'] = $resolution->conceptId;
            $item['ambiguity_count'] = count($resolution->candidates);
            $item['classification'] = match ($resolution->status) {
                DictionaryResolution::RESOLVED => $this->normalize((string) ($resolution->preferredLabel ?? '')) !== '' && $this->normalize((string) ($resolution->preferredLabel ?? '')) !== $item['normalized_form'] ? 'ALIAS_TO_EXISTING' : 'RESOLVED_EXISTING',
                DictionaryResolution::AMBIGUOUS => 'AMBIGUOUS',
                DictionaryResolution::SUPPRESSED => 'SUPPRESSED',
                default => 'NEW_LEXICAL_CANDIDATE',
            };
            $item['suggested_action'] = match ($item['classification']) {
                'RESOLVED_EXISTING' => 'REUSE_EXISTING',
                'ALIAS_TO_EXISTING' => 'ADD_ALIAS_CANDIDATE',
                'AMBIGUOUS' => 'REVIEW_AMBIGUITY',
                'SUPPRESSED' => 'SUPPRESS_NOISE',
                default => 'NEW_CONCEPT_CANDIDATE',
            };
            if ($resolution->status === DictionaryResolution::AMBIGUOUS) $item['diagnostics'][] = 'AMBIGUOUS_CANONICAL_OWNER';
            $item['diagnostics'] = array_values(array_unique($item['diagnostics']));
        }
        unset($item);

        $rows = array_values($items);
        $aggregate = ['total' => count($rows), 'counts_by_classification' => []];
        foreach ($rows as $row) $aggregate['counts_by_classification'][$row['classification']] = ($aggregate['counts_by_classification'][$row['classification']] ?? 0) + 1;
        ksort($aggregate['counts_by_classification']);
        $diagnostics = ['deduplication' => 'normalized_form', 'source_family' => $sourceFamily];
        if ($bounded) {
            $diagnostics['bounded_seed_limit'] = $maxSeeds;
            $diagnostics['truncated_seed_count'] = $truncatedSeeds;
            if (isset($options['max_lookup_cost'])) $diagnostics['bounded_lookup_cost'] = $maxSeeds * $lookupCostPerSeed;
        }
        return ['status' => 'READ_ONLY_PLAN', 'read_only' => true, 'mutated' => false, 'items' => $rows, 'aggregate' => $aggregate, 'diagnostics' => $diagnostics];
    }

    private function normalize(string $value): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }
}
