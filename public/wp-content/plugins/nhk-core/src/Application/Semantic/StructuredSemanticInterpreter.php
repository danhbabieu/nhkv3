<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

use NHK\Core\Application\Dictionary\DictionaryTermDetector;

/**
 * Shared bounded interpreter for all linguistic input surfaces.
 *
 * It observes wording and creates planning candidates only. Canonical
 * resolution, Knowledge, Graph and Governance remain downstream owners.
 */
final class StructuredSemanticInterpreter
{
    /** @param callable(string):bool|null $predicateValidator */
    public function __construct(
        private ?DictionaryTermDetector $detector = null,
        private $predicateValidator = null,
    ) {
        $this->detector ??= new DictionaryTermDetector();
    }

    public function interpret(UniversalInputEnvelope|array $input): StructuredInterpretationPacket
    {
        $value = $input instanceof UniversalInputEnvelope ? $input->toArray() : UniversalInputEnvelope::fromArray($input)->toArray();
        $text = trim((string) ($value['raw_text'] ?? $value['body'] ?? ''));
        $metadata = is_array($value['metadata'] ?? null) ? $value['metadata'] : [];
        $approved = $this->strings($metadata['approved_labels'] ?? $metadata['dictionary_labels'] ?? []);
        $hints = is_array($metadata['lexical_hints'] ?? null) ? $metadata['lexical_hints'] : (array) ($value['user_hints'] ?? []);
        $spans = $this->detector->detect($text, $approved, $hints);
        $lexical = array_values(array_map(fn (array $span): array => $this->span($span), $spans));
        $detectorEditorial = $this->detector->editorialSignals($text);
        $detectorNoise = $this->detector->noiseSignals($text);
        foreach (array_merge(array_filter($detectorEditorial, static fn (array $signal): bool => preg_match('/(?:hãy|đừng|chỉ\s+cần|cảm\s+thấy|bắt\s+nguồn|ghi\s+nhận)/iu', (string) ($signal['term'] ?? '')) === 1), $detectorNoise) as $signal) {
            $normalized = (string) ($signal['normalized_term'] ?? '');
            $found = false;
            foreach ($lexical as &$span) if ($span['normalized_term'] === $normalized) { $span['origin'] = (string) ($signal['origin'] ?? $span['origin']); $found = true; break; }
            unset($span);
            if (!$found) $lexical[] = $this->span($signal);
        }
        $declaredEditorial = $this->editorialSignals($value);
        $declaredEditorialTerms = array_fill_keys(array_map(fn (array $signal): string => $this->normalize((string) ($signal['term'] ?? '')), $declaredEditorial), true);
        $lexical = array_values(array_filter($lexical, static fn (array $span): bool => !isset($declaredEditorialTerms[$span['normalized_term']])));
        $proper = array_values(array_filter($lexical, static fn (array $span): bool => $span['origin'] === 'PROPER_NAME_SPAN'));
        $identifiers = array_values(array_filter($lexical, static fn (array $span): bool => in_array($span['origin'], ['IDENTIFIER_SPAN', 'TECHNICAL_PATTERN'], true)));
        $configuration = array_values(array_filter($lexical, static fn (array $span): bool => $span['origin'] === 'STRUCTURAL_CONFIGURATION'));
        $technical = array_values(array_filter($lexical, static fn (array $span): bool => in_array($span['origin'], ['IDENTIFIER_SPAN', 'TECHNICAL_PATTERN', 'STRUCTURAL_CONFIGURATION'], true)));

        $ambiguous = $this->ambiguousTerms($value, $lexical);
        $unresolved = $this->unresolvedTerms($value, $lexical, $ambiguous);
        $resolved = $this->resolvedReferences($value);
        $claims = $this->claimCandidates($value, $text);
        $relations = $this->relationCandidates($value);
        $editorialSignals = array_values(array_filter(array_merge($detectorEditorial, $declaredEditorial), 'is_array'));
        $diagnostics = array_values(array_unique(array_merge(
            (array) ($value['diagnostics'] ?? []),
            $this->diagnostics($value, $lexical, $ambiguous, $relations),
        )));
        $status = $ambiguous !== [] ? 'AMBIGUOUS' : ($text === '' ? 'UNRESOLVED' : ($unresolved !== [] ? 'REVIEW_REQUIRED' : 'INTERPRETED'));

        return StructuredInterpretationPacket::fromArray([
            'status' => $status,
            'source_context' => [
                'source_kind' => strtolower((string) ($value['source_kind'] ?? $value['owner_or_source_type'] ?? 'generic')),
                'source_identity' => (array) ($value['source_identity'] ?? []),
                'source_identifier' => (string) (($value['source_identity']['source_id'] ?? '') ?: ($value['source_identity']['id'] ?? '')),
                'raw_or_derived' => $value['raw_or_derived'] ?? $this->rawOrDerived($value),
                'lineage' => (array) ($value['lineage'] ?? $metadata['lineage'] ?? []),
                'content_intent' => (string) ($value['content_intent'] ?? ''),
                'content_intent_context' => (array) ($value['content_intent_context'] ?? []),
                'canonical_target_hint' => (array) ($value['canonical_target_hint'] ?? []),
                'provenance_context' => (array) ($value['provenance_context'] ?? []),
                'observation_strength' => (string) ($value['observation_strength'] ?? 'NORMAL'),
            ],
            'raw_input_reference' => $value['raw_input_reference'] ?? ($value['source_identity']['raw_input_reference'] ?? null),
            'locale' => (string) ($value['locale'] ?? $metadata['locale'] ?? 'vi-VN'),
            'lexical_spans' => $lexical,
            'proper_name_spans' => $proper,
            'identifier_spans' => $identifiers,
            'configuration_spans' => $configuration,
            'technical_term_spans' => $technical,
            'resolved_references' => $resolved,
            'unresolved_terms' => $unresolved,
            'ambiguous_terms' => $ambiguous,
            'subject_candidates' => $this->subjectCandidates($value),
            'attribute_candidates' => $this->attributeCandidates($value, $lexical),
            'claim_candidates' => $claims,
            'relation_candidates' => $relations,
            'scope_signals' => $this->scopeSignals($value, $claims),
            'provenance_signals' => $this->provenanceSignals($value, $claims),
            'evidence_signals' => $this->evidenceSignals($value),
            'uncertainty_signals' => $this->uncertaintySignals($value, $ambiguous),
            'editorial_signals' => $editorialSignals,
            'reuse_matches' => $this->reuseMatches($value, $claims),
            'dictionary_delta_candidates' => $this->dictionaryCandidates($lexical, $unresolved),
            'knowledge_delta_candidates' => $this->knowledgeCandidates($claims),
            'relation_delta_candidates' => $relations,
            'semantic_query_seeds' => $this->querySeeds($lexical, $value, $ambiguous),
            'diagnostics' => $diagnostics,
            'outcomes' => $this->outcomes($lexical, $claims, $relations, $ambiguous, $unresolved),
        ]);
    }

    /** @param array<string,mixed> $span @return array<string,mixed> */
    private function span(array $span): array
    {
        return [
            'term' => (string) ($span['term'] ?? ''),
            'normalized_term' => (string) ($span['normalized_term'] ?? ''),
            'origin' => (string) ($span['origin'] ?? 'UNKNOWN'),
            'strength' => (string) ($span['strength'] ?? 'NORMAL'),
        ];
    }

    /** @param array<string,mixed> $value @return list<array<string,mixed>> */
    private function subjectCandidates(array $value): array
    {
        $resolution = is_array($value['subject_resolution'] ?? null) ? $value['subject_resolution'] : [];
        $candidates = (array) ($resolution['candidates'] ?? $value['subject_candidates'] ?? []);
        return array_values(array_filter($candidates, 'is_array'));
    }

    /** @param array<string,mixed> $value @return list<array<string,mixed>> */
    private function resolvedReferences(array $value): array
    {
        $resolution = is_array($value['subject_resolution'] ?? null) ? $value['subject_resolution'] : [];
        $primary = is_array($resolution['primary'] ?? null) ? $resolution['primary'] : [];
        return $primary === [] || ($resolution['status'] ?? '') !== 'resolved' ? [] : [$primary];
    }

    /** @param array<string,mixed> $value @param list<array<string,mixed>> $lexical @return list<array<string,mixed>> */
    private function unresolvedTerms(array $value, array $lexical, array $ambiguous): array
    {
        $approved = $this->strings(($value['metadata']['approved_labels'] ?? []));
        $known = array_map(fn (string $term): string => $this->normalize($term), $approved);
        return array_values(array_filter($lexical, static fn (array $span): bool => !in_array($span['normalized_term'], $known, true)));
    }

    /** @param array<string,mixed> $value @param list<array<string,mixed>> $lexical @return list<array<string,mixed>> */
    private function ambiguousTerms(array $value, array $lexical): array
    {
        $resolution = is_array($value['subject_resolution'] ?? null) ? $value['subject_resolution'] : [];
        $ambiguous = strtoupper((string) ($resolution['status'] ?? '')) === 'AMBIGUOUS' || count((array) ($resolution['candidates'] ?? [])) > 1;
        return $ambiguous ? $lexical : [];
    }

    /** @param array<string,mixed> $value @return list<array<string,mixed>> */
    private function claimCandidates(array $value, string $text): array
    {
        $claims = [];
        foreach ((array) ($value['observations'] ?? []) as $observation) {
            if (!is_array($observation)) continue;
            $candidate = trim((string) ($observation['text'] ?? $observation['value'] ?? ''));
            if ($candidate !== '') $claims[] = ['text' => $candidate, 'candidate_kind' => 'observation', 'provenance' => strtoupper((string) ($observation['origin'] ?? 'SYSTEM_INFERENCE')), 'scope' => (string) ($observation['scope'] ?? 'UNRESOLVED')];
        }
        if ($text !== '' && $claims === []) $claims[] = ['text' => $text, 'candidate_kind' => 'derived_candidate', 'provenance' => (string) ($value['provenance']['source_class'] ?? 'UNRESOLVED'), 'scope' => 'UNRESOLVED'];
        return $claims;
    }

    /** @param array<string,mixed> $value @return list<array<string,mixed>> */
    private function relationCandidates(array $value): array
    {
        $result = [];
        foreach ((array) ($value['relations'] ?? []) as $relation) {
            if (!is_array($relation)) continue;
            $predicate = trim((string) ($relation['predicate'] ?? ''));
            if ($predicate === '' || !is_callable($this->predicateValidator) || !($this->predicateValidator)($predicate)) continue;
            if (trim((string) ($relation['source_id'] ?? '')) === '' || trim((string) ($relation['target_id'] ?? '')) === '') continue;
            $result[] = ['source_id' => (string) $relation['source_id'], 'target_id' => (string) $relation['target_id'], 'predicate' => $predicate, 'provenance' => (string) ($relation['provenance'] ?? 'UNRESOLVED'), 'scope' => (string) ($relation['scope'] ?? 'UNRESOLVED')];
        }
        return $result;
    }

    /** @param array<string,mixed> $value @param list<array<string,mixed>> $lexical @param list<array<string,mixed>> $ambiguous @param list<array<string,mixed>> $relations @return list<string> */
    private function diagnostics(array $value, array $lexical, array $ambiguous, array $relations): array
    {
        $result = [];
        if ($lexical === [] && trim((string) ($value['raw_text'] ?? '')) !== '') $result[] = 'NO_LEXICAL_SPANS';
        if ($ambiguous !== []) $result[] = 'AMBIGUOUS_CANONICAL_OWNER';
        if ((array) ($value['relations'] ?? []) !== [] && $relations === []) $result[] = 'RELATION_PREDICATE_UNSUPPORTED';
        if (trim((string) ($value['raw_text'] ?? '')) === '') $result[] = 'INPUT_CONTENT_UNAVAILABLE';
        return $result;
    }

    /** @param array<string,mixed> $value @param list<array<string,mixed>> $claims @return list<array<string,mixed>> */
    private function scopeSignals(array $value, array $claims): array { return array_values(array_filter(array_merge((array) ($value['scope_signals'] ?? []), array_map(static fn (array $claim): array => ['scope' => $claim['scope'] ?? 'UNRESOLVED'], $claims)), 'is_array')); }
    /** @param array<string,mixed> $value @param list<array<string,mixed>> $claims @return list<array<string,mixed>> */
    private function provenanceSignals(array $value, array $claims): array { return array_values(array_filter(array_merge((array) ($value['provenance_signals'] ?? []), array_map(static fn (array $claim): array => ['provenance' => $claim['provenance'] ?? 'UNRESOLVED'], $claims)), 'is_array')); }
    /** @param array<string,mixed> $value @return list<array<string,mixed>> */
    private function evidenceSignals(array $value): array { return array_values(array_filter((array) ($value['evidence_signals'] ?? []), 'is_array')); }
    /** @param array<string,mixed> $value @param list<array<string,mixed>> $ambiguous @return list<string> */
    private function uncertaintySignals(array $value, array $ambiguous): array { return array_values(array_unique(array_merge($this->strings($value['uncertainty_signals'] ?? []), $ambiguous !== [] ? ['AMBIGUOUS'] : []))); }
    /** @param array<string,mixed> $value @return list<array<string,mixed>> */
    private function editorialSignals(array $value): array { return array_values(array_filter((array) ($value['editorial_signals'] ?? $value['metadata']['editorial_signals'] ?? $value['metadata']['editorial_only'] ?? []), 'is_array')); }
    /** @return list<array<string,mixed>> */
    private function detectorEditorialSignals(string $text): array { return $this->detector?->editorialSignals($text) ?? []; }
    /** @param array<string,mixed> $value @param list<array<string,mixed>> $claims @return list<array<string,mixed>> */
    private function reuseMatches(array $value, array $claims): array { return array_values(array_filter((array) ($value['existing_knowledge'] ?? []), static fn (mixed $item): bool => is_array($item) && trim((string) ($item['claim_id'] ?? $item['id'] ?? '')) !== '')); }
    /** @param list<array<string,mixed>> $lexical @param list<array<string,mixed>> $unresolved @return list<array<string,mixed>> */
    private function dictionaryCandidates(array $lexical, array $unresolved): array { return array_values(array_map(static fn (array $item): array => ['term' => $item['term'], 'normalized_term' => $item['normalized_term'], 'status' => 'NEEDS_REVIEW'], $unresolved !== [] ? $unresolved : $lexical)); }
    /** @param list<array<string,mixed>> $claims @return list<array<string,mixed>> */
    private function knowledgeCandidates(array $claims): array { return array_values(array_map(static fn (array $claim): array => $claim + ['status' => 'REVIEW_REQUIRED'], $claims)); }
    /** @param list<array<string,mixed>> $lexical @param list<array<string,mixed>> $claims @param list<array<string,mixed>> $relations @param list<array<string,mixed>> $ambiguous @param list<array<string,mixed>> $unresolved @return list<string> */
    private function outcomes(array $lexical, array $claims, array $relations, array $ambiguous, array $unresolved): array { $outcomes = []; if ($lexical === [] && $claims === []) $outcomes[] = 'NO_CHANGE'; if ($unresolved !== []) $outcomes[] = 'NEW_LEXICAL_CANDIDATE'; if ($claims !== []) $outcomes[] = 'NEW_KNOWLEDGE_CANDIDATE'; if ($relations !== []) $outcomes[] = 'RELATION_CANDIDATE'; if ($ambiguous !== []) $outcomes[] = 'AMBIGUOUS'; return array_values(array_unique($outcomes)); }
    private function rawOrDerived(array $value): string { return (is_array($value['lineage'] ?? null) && ($value['lineage']['derived'] ?? false) === true) || in_array(strtoupper((string) ($value['owner_or_source_type'] ?? '')), ['ARTICLE_SUMMARY', 'SEO', 'GENERATED_ARTICLE'], true) ? 'DERIVED' : 'RAW'; }
    /** @param mixed $value @return list<string> */
    private function strings(mixed $value): array { return array_values(array_filter(array_map(static fn (mixed $item): string => trim((string) $item), (array) $value), static fn (string $item): bool => $item !== '')); }
    private function normalize(string $value): string { return function_exists('mb_strtolower') ? mb_strtolower(trim($value)) : strtolower(trim($value)); }
    /** @param array<string,mixed> $value @param list<array<string,mixed>> $claims @return list<array<string,mixed>> */
    private function attributeCandidates(array $value, array $lexical): array { return array_values(array_filter($lexical, static fn (array $span): bool => in_array($span['origin'], ['DOMAIN_PHRASE', 'STRUCTURAL_CONFIGURATION'], true))); }

    /** @param list<array<string,mixed>> $lexical @param list<array<string,mixed>> $ambiguous @return list<array<string,mixed>> */
    private function querySeeds(array $lexical, array $value, array $ambiguous): array
    {
        $ambiguousTerms = array_fill_keys(array_column($ambiguous, 'normalized_term'), true);
        $locale = (string) ($value['locale'] ?? 'vi-VN');
        $context = is_array($value['semantic_context'] ?? null) ? $value['semantic_context'] : [];
        $facet = (string) ($context['facet'] ?? '');
        $seeds = [];
        usort($lexical, static function (array $left, array $right) use ($value): int {
            $text = (string) ($value['raw_text'] ?? $value['body'] ?? '');
            $leftPosition = mb_stripos($text, (string) ($left['term'] ?? ''));
            $rightPosition = mb_stripos($text, (string) ($right['term'] ?? ''));
            return ($leftPosition === false ? PHP_INT_MAX : $leftPosition) <=> ($rightPosition === false ? PHP_INT_MAX : $rightPosition);
        });
        foreach ($lexical as $span) {
            $normalized = (string) ($span['normalized_term'] ?? '');
            if ($normalized === '') continue;
            $seeds[] = [
                'raw_span' => (string) ($span['term'] ?? ''),
                'normalized_form' => $normalized,
                'category' => $this->queryCategory((string) ($span['origin'] ?? 'UNKNOWN')),
                'locale' => $locale,
                'context' => $context,
                'canonical_reference' => null,
                'facet_hint' => $facet !== '' ? $facet : null,
                'ambiguity' => isset($ambiguousTerms[$normalized]) ? 'AMBIGUOUS' : 'UNRESOLVED',
                'diagnostics' => isset($ambiguousTerms[$normalized]) ? ['AMBIGUOUS_CANONICAL_OWNER'] : [],
            ];
        }
        return $seeds;
    }

    private function queryCategory(string $origin): string
    {
        return match ($origin) {
            'PROPER_NAME_SPAN' => 'PROPER_NAME',
            'IDENTIFIER_SPAN', 'TECHNICAL_PATTERN' => 'IDENTIFIER',
            'STRUCTURAL_CONFIGURATION' => 'CONFIGURATION',
            'EDITORIAL_SIGNAL' => 'EDITORIAL_SIGNAL',
            'NOISE' => 'NOISE',
            'DOMAIN_PHRASE', 'QUOTED_PHRASE', 'MUSIC_NAME' => 'LEXICAL_TERM',
            default => 'LEXICAL_OBSERVATION',
        };
    }
}
