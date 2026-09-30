<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

use NHK\Core\Application\Dictionary\DictionarySeedPlanner;
use NHK\Core\Application\Semantic\StructuredSemanticInterpreter;

/** MCP adapter for bounded, privacy-safe Dictionary Seed v1 planning. */
final class DictionarySeedAuditHandler
{
    private const CLASSIFICATIONS = ['RESOLVED_EXISTING', 'ALIAS_TO_EXISTING', 'PROPER_NAME', 'IDENTIFIER', 'CONFIGURATION', 'TECHNICAL_TERM', 'COLLOQUIAL_TERM', 'PHONETIC_ASR_FORM', 'AMBIGUOUS', 'NEW_LEXICAL_CANDIDATE', 'EDITORIAL_ONLY', 'NOISE', 'SUPPRESSED'];

    public function __construct(private DictionarySeedPlanner $planner, private ?StructuredSemanticInterpreter $interpreter = null)
    {
        $this->interpreter ??= new StructuredSemanticInterpreter();
    }

    /** @return array<string,mixed> */
    public function audit(array $input): array
    {
        $text = trim((string) ($input['text'] ?? ''));
        if ($text === '' || strlen($text) > 12000) throw new \InvalidArgumentException('DICTIONARY_SEED_AUDIT_TEXT_INVALID');
        $limit = $input['limit'] ?? 50;
        if (!is_int($limit) || $limit < 1 || $limit > 100) throw new \InvalidArgumentException('DICTIONARY_SEED_AUDIT_LIMIT_INVALID');
        $offset = $input['offset'] ?? 0;
        if (!is_int($offset) || $offset < 0 || $offset > 10000) throw new \InvalidArgumentException('DICTIONARY_SEED_AUDIT_OFFSET_INVALID');
        $filters = $input['classification_filters'] ?? [];
        if (!is_array($filters) || count($filters) > 10 || array_diff($filters, self::CLASSIFICATIONS) !== []) throw new \InvalidArgumentException('DICTIONARY_SEED_AUDIT_FILTER_INVALID');

        try {
            $packet = $this->interpreter->interpret([
                'text' => $text,
                'source_kind' => (string) ($input['source_kind'] ?? 'generic'),
                'source_identifier' => (string) ($input['source_id'] ?? ''),
                'locale' => (string) ($input['locale'] ?? 'vi-VN'),
                'hints' => is_array($input['hints'] ?? null) ? $input['hints'] : [],
                'metadata' => is_array($input['context'] ?? null) ? $input['context'] : [],
            ]);
            $plan = $this->planner->plan($packet, ['source_family' => (string) ($input['source_family'] ?? $input['source_id'] ?? $input['source_kind'] ?? 'unknown'), 'context' => is_array($input['context'] ?? null) ? $input['context'] : []]);
        } catch (\Throwable) {
            return ['status' => 'UNAVAILABLE', 'read_only' => true, 'mutated' => false, 'total' => 0, 'items' => [], 'aggregate' => ['total' => 0, 'counts_by_classification' => []], 'next_offset' => null, 'has_more' => false, 'diagnostics' => ['DICTIONARY_SEED_AUDIT_UNAVAILABLE']];
        }

        $items = array_values(array_filter($plan['items'], static fn (array $item): bool => $filters === [] || in_array($item['classification'] ?? '', $filters, true)));
        $total = count($items);
        $page = array_slice($items, $offset, $limit);
        $safe = array_map(static fn (array $item): array => [
            'raw_form' => null,
            'normalized_form' => $item['normalized_form'],
            'category' => $item['category'],
            'classification' => $item['classification'],
            'resolved_destination_type' => $item['resolved_destination_type'] ?? null,
            'resolved_destination_id' => $item['resolved_destination_id'] ?? null,
            'resolved_dictionary_concept_id' => $item['resolved_dictionary_concept_id'] ?? null,
            'resolution_status' => $item['resolution_status'] ?? 'UNRESOLVED',
            'ambiguity_count' => $item['ambiguity_count'] ?? 0,
            'locale' => $item['locale'],
            'occurrences' => $item['occurrences'],
            'raw_form_count' => count($item['raw_forms']),
            'source_family_count' => count($item['source_families']),
            'suggested_action' => $item['suggested_action'] ?? null,
            'diagnostics' => $item['diagnostics'],
        ], $page);
        $counts = [];
        foreach ($items as $item) $counts[$item['classification']] = ($counts[$item['classification']] ?? 0) + 1;
        ksort($counts);
        return ['status' => 'AVAILABLE', 'read_only' => true, 'mutated' => false, 'total' => $total, 'items' => $safe, 'aggregate' => ['total' => $total, 'counts_by_classification' => $counts], 'next_offset' => $offset + count($page) < $total ? $offset + count($page) : null, 'has_more' => $offset + count($page) < $total, 'diagnostics' => ['bounded_limit' => $limit, 'offset' => $offset, 'source_kind' => (string) ($input['source_kind'] ?? 'generic')]];
    }
}
