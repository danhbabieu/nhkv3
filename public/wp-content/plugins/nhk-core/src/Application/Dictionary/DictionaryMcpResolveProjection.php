<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

/**
 * Reconciles the legacy planning envelope with the canonical Entry/Sense
 * resolver for the MCP resolve projection.
 */
final class DictionaryMcpResolveProjection
{
    /** @return array<string,mixed> */
    public function project(array $preview, array $entrySense): array
    {
        $normalized = trim((string) ($entrySense['normalized_term'] ?? ''));
        $term = trim((string) ($entrySense['term'] ?? ''));
        $status = strtoupper(trim((string) ($entrySense['status'] ?? '')));

        if ($status === 'RESOLVED') {
            $row = [
                'term' => $term,
                'normalized_term' => $normalized,
                'concept_id' => $entrySense['sense_id'] ?? null,
                'preferred_label' => $entrySense['preferred_label'] ?? null,
                'destination_type' => $entrySense['destination_type'] ?? null,
                'destination_id' => $entrySense['destination_id'] ?? null,
                'destination_url' => $entrySense['destination_url'] ?? null,
                'origin' => 'ENTRY_SENSE_RESOLVER',
            ];
            $rows = [];
            $replaced = false;
            foreach ((array) ($preview['resolved_terms'] ?? []) as $candidate) {
                if (!is_array($candidate)) continue;
                $candidateNormalized = trim((string) ($candidate['normalized_term'] ?? ''));
                $candidateTerm = trim((string) ($candidate['term'] ?? ''));
                if (($normalized !== '' && $candidateNormalized === $normalized) || ($term !== '' && $candidateTerm === $term)) {
                    $rows[] = $row;
                    $replaced = true;
                    continue;
                }
                $rows[] = $candidate;
            }
            if (!$replaced) $rows[] = $row;
            $preview['resolved_terms'] = $rows;
            $preview['internal_link_candidates'] = $this->replaceLinkCandidate((array) ($preview['internal_link_candidates'] ?? []), $row);
            return $preview;
        }

        if (trim((string) ($entrySense['entry_id'] ?? '')) !== '' && in_array((string) ($entrySense['reason'] ?? ''), ['DICTIONARY_ENTRY_PUBLIC_IDENTITY_MISSING', 'DICTIONARY_SEMANTIC_REFERENCE_INVALID', 'DICTIONARY_SEMANTIC_REFERENCE_UNAVAILABLE', 'DICTIONARY_SEMANTIC_DESTINATION_UNAVAILABLE'], true)) {
            $preview['resolved_terms'] = array_values(array_filter((array) ($preview['resolved_terms'] ?? []), function (mixed $candidate) use ($normalized, $term): bool {
                if (!is_array($candidate)) return false;
                return !(($normalized !== '' && trim((string) ($candidate['normalized_term'] ?? '')) === $normalized) || ($term !== '' && trim((string) ($candidate['term'] ?? '')) === $term));
            }));
            $preview['internal_link_candidates'] = array_values(array_filter((array) ($preview['internal_link_candidates'] ?? []), function (mixed $candidate) use ($normalized, $term): bool {
                if (!is_array($candidate)) return false;
                return !(($normalized !== '' && trim((string) ($candidate['normalized_term'] ?? '')) === $normalized) || ($term !== '' && trim((string) ($candidate['term'] ?? '')) === $term));
            }));
        }

        return $preview;
    }

    /** @param list<array<string,mixed>> $links @return list<array<string,mixed>> */
    private function replaceLinkCandidate(array $links, array $row): array
    {
        $normalized = trim((string) ($row['normalized_term'] ?? ''));
        $term = trim((string) ($row['term'] ?? ''));
        $out = [];
        $replaced = false;
        foreach ($links as $link) {
            if (!is_array($link)) continue;
            $same = ($normalized !== '' && trim((string) ($link['normalized_term'] ?? '')) === $normalized) || ($term !== '' && trim((string) ($link['term'] ?? '')) === $term);
            if ($same) {
                if (!$replaced && trim((string) ($row['destination_url'] ?? '')) !== '') {
                    $out[] = ['term' => $row['term'], 'concept_id' => $row['concept_id'] ?: $row['destination_type'] . ':' . $row['destination_id'], 'url' => $row['destination_url']];
                    $replaced = true;
                }
                continue;
            }
            $out[] = $link;
        }
        if (!$replaced && trim((string) ($row['destination_url'] ?? '')) !== '') $out[] = ['term' => $row['term'], 'concept_id' => $row['concept_id'] ?: $row['destination_type'] . ':' . $row['destination_id'], 'url' => $row['destination_url']];
        return $out;
    }
}
