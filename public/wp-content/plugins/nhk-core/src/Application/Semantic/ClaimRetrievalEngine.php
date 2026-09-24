<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

use NHK\Core\Domain\Graph\PredicateRegistry;

/** Bounded, deterministic Claim discovery and selection over owner read ports. */
final class ClaimRetrievalEngine
{
    /** @param callable(array<string,mixed>):array $neighborhood @param callable(array<string,mixed>,array<string,mixed>):array $claims */
    public function __construct(private $neighborhood, private $claims, private int $maxHops = 2, private int $limit = 50, private ?PredicateRegistry $predicates = null, private $expansion = null) {}

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function retrieve(array $context): array
    {
        $resolution = is_array($context['subject_resolution'] ?? null) ? $context['subject_resolution'] : [];
        $subjects = is_array($resolution['subjects'] ?? null) ? $resolution['subjects'] : [];
        $intent = strtolower((string) ($context['raw_input'] ?? $context['interpretation']['article_intent'] ?? ''));
        $all = [];
        $blockers = [];
        $diagnostics = ['initial_candidates' => 0, 'rounds' => [], 'expansion_depth' => 0, 'expansion_budget' => 0, 'stop_reason' => 'coverage_sufficient'];
        $gaps = array_values(array_filter((array) ($context['coverage_gaps'] ?? []), static fn (mixed $gap): bool => is_string($gap) && trim($gap) !== ''));
        $maxExpansionRounds = max(0, min($this->maxHops, (int) ($context['max_expansion_rounds'] ?? 0)));
        $expansionBudget = max(0, min(200, (int) ($context['expansion_budget'] ?? 50)));
        $retrievalOrder = 0;
        foreach ($subjects as $subject) {
            if (!is_array($subject)) continue;
            $neighborhood = ($this->neighborhood)($subject);
            if (!is_array($neighborhood) || ($neighborhood['status'] ?? 'available') !== 'available') {
                $blockers[] = 'GRAPH_RESEARCH_UNAVAILABLE';
                continue;
            }
            $rows = ($this->claims)($subject, $neighborhood);
            if (!is_array($rows)) { $blockers[] = 'CLAIM_RETRIEVAL_UNAVAILABLE'; continue; }
            $rowLimit = min($this->limit, max(1, (int) ($context['result_limit'] ?? $this->limit)), 200);
            foreach (array_slice($rows, 0, $rowLimit) as $row) {
                if (!is_array($row)) continue;
                $candidate = $this->candidate($row, $subject, $neighborhood, $intent);
                $candidate['_retrieval_order'] = $retrievalOrder++;
                $key = $candidate['claim_id'] . ':' . $candidate['claim_revision'];
                if (!isset($all[$key]) || $candidate['score'] > $all[$key]['score']) $all[$key] = $candidate;
            }
        }
        $diagnostics['initial_candidates'] = count($all);
        if ($gaps !== [] && $maxExpansionRounds > 0 && is_callable($this->expansion)) {
            for ($round = 1; $round <= $maxExpansionRounds && $expansionBudget > 0; $round++) {
                $reason = (string) ($gaps[0] ?? 'coverage_gap');
                $subject = is_array($subjects[0] ?? null) ? $subjects[0] : [];
                $expanded = ($this->expansion)($subject, ['reason' => $reason, 'depth' => $round, 'budget' => $expansionBudget]);
                if (!is_array($expanded)) $expanded = [];
                $considered = array_slice($expanded, 0, $expansionBudget);
                foreach ($considered as $row) {
                    if (!is_array($row)) continue;
                    $candidate = $this->candidate($row, $subject, ['items' => $considered], $intent);
                    $candidate['_retrieval_order'] = $retrievalOrder++;
                    $key = $candidate['claim_id'] . ':' . $candidate['claim_revision'];
                    if (!isset($all[$key]) || $candidate['score'] > $all[$key]['score']) $all[$key] = $candidate;
                }
                $expansionBudget -= count($considered);
                $diagnostics['expansion_depth'] = $round;
                $diagnostics['expansion_budget'] = $expansionBudget;
                $diagnostics['rounds'][] = ['reason' => $reason, 'depth' => $round, 'budget' => $expansionBudget, 'candidates_considered' => count($considered), 'units_formed' => 0, 'selected_units' => 0];
                if ($considered === []) break;
                $gaps = array_slice($gaps, 1);
            }
            $diagnostics['stop_reason'] = $expansionBudget <= 0 ? 'expansion_budget' : ($diagnostics['rounds'] !== [] ? 'coverage_saturated' : 'coverage_sufficient');
        } elseif ($gaps !== []) {
            $diagnostics['stop_reason'] = 'expansion_not_registered_or_budget_zero';
        }
        $items = array_values($all);
        usort($items, static function (array $left, array $right): int {
            $score = $right['score'] <=> $left['score'];
            if ($score !== 0) return $score;
            $order = ((int) ($left['_retrieval_order'] ?? 0)) <=> ((int) ($right['_retrieval_order'] ?? 0));
            return $order !== 0 ? $order : strcmp($left['claim_id'], $right['claim_id']);
        });
        $items = array_slice($items, 0, min($this->limit, max(1, (int) ($context['result_limit'] ?? $this->limit)), 200));
        $selected = array_values(array_filter($items, static fn (array $item): bool => $item['decision'] === 'include'));
        $diagnostics['candidates_considered'] = count($items);
        $diagnostics['units_formed'] = 0;
        $diagnostics['selected_units'] = count($selected);
        return ['status' => $blockers === [] ? 'available' : 'partial', 'items' => $items, 'selected_claims' => $selected, 'blockers' => array_values(array_unique($blockers)), 'retrieval_diagnostics' => $diagnostics];
    }

    /**
     * Retrieve a bounded opportunity for every meaningful SemanticNeed before
     * applying the merged candidate limit.
     *
     * @param array<string,mixed> $context
     * @param list<SemanticNeed|array<string,mixed>> $needs
     * @return array<string,mixed>
     */
    public function retrieveForNeeds(array $context, array $needs): array
    {
        $normalizedNeeds = [];
        foreach ($needs as $need) {
            try {
                $normalizedNeeds[] = $need instanceof SemanticNeed ? $need : SemanticNeed::fromArray($need);
            } catch (\Throwable) {
                continue;
            }
        }
        $limit = min($this->limit, max(1, (int) ($context['result_limit'] ?? $this->limit)), 200);
        $all = [];
        $blockers = [];
        $needDiagnostics = [];
        $retrievalOrder = 0;
        foreach ($normalizedNeeds as $need) {
            $needData = $need->toArray();
            $subject = $need->canonicalSubject();
            $neighborhood = ($this->neighborhood)($subject);
            $needId = $need->needId();
            $needDiagnostics[$needId] = [
                'need_id' => $needId,
                'facet_key' => $need->facetKey(),
                'concept_key' => $need->conceptKey(),
                'opportunity_allocated' => max(1, min(200, (int) ($need->retrievalPolicy()['opportunity_budget'] ?? 1))),
                'candidate_count' => 0,
                'eligible_count' => 0,
                'stop_reason' => 'opportunity_exhausted',
            ];
            if (!is_array($neighborhood) || ($neighborhood['status'] ?? 'available') !== 'available') {
                $blockers[] = 'GRAPH_RESEARCH_UNAVAILABLE';
                $needDiagnostics[$needId]['stop_reason'] = 'graph_unavailable';
                continue;
            }
            $rows = $this->claimsFor($subject, $neighborhood, $needData);
            if (!is_array($rows)) {
                $blockers[] = 'CLAIM_RETRIEVAL_UNAVAILABLE';
                $needDiagnostics[$needId]['stop_reason'] = 'claims_unavailable';
                continue;
            }
            $opportunity = $needDiagnostics[$needId]['opportunity_allocated'];
            $opportunityRows = array_values(array_filter($rows, function (mixed $row) use ($need): bool {
                if (!is_array($row)) return false;
                $rowFacet = strtolower(trim((string) ($row['facet'] ?? $row['knowledge_facet'] ?? '')));
                return $need->facetKey() === '' || $rowFacet === '' || $rowFacet === $need->facetKey();
            }));
            foreach (array_slice($opportunityRows, 0, $opportunity) as $row) {
                if (!is_array($row)) continue;
                $rowFacet = strtolower(trim((string) ($row['facet'] ?? $row['knowledge_facet'] ?? '')));
                if ($need->facetKey() !== '' && $rowFacet !== '' && $rowFacet !== $need->facetKey()) continue;
                $candidate = $this->candidate($row, $subject, $neighborhood, strtolower($need->facetKey() . ' ' . $need->conceptKey()), $needData);
                $candidate['_retrieval_order'] = $retrievalOrder++;
                $candidate['need_id'] = $needId;
                $candidate['need_ids'] = [$needId];
                $candidate['facet'] = $rowFacet !== '' ? $rowFacet : $need->facetKey();
                $candidate['concept'] = strtolower(trim((string) ($row['concept'] ?? $row['concept_key'] ?? $need->conceptKey())));
                $candidate['retrieval_tier'] = 'EXACT';
                $candidate['editorial_treatment'] = 'DIRECT_FACT';
                $candidate['coverage_kind'] = 'exact';
                $key = $candidate['claim_id'] . ':' . $candidate['claim_revision'];
                if (!isset($all[$key])) {
                    $all[$key] = $candidate;
                } else {
                    $all[$key]['need_ids'] = array_values(array_unique(array_merge((array) ($all[$key]['need_ids'] ?? []), [$needId])));
                    if ($candidate['score'] > $all[$key]['score']) $all[$key] = array_replace($all[$key], $candidate, ['need_ids' => $all[$key]['need_ids']]);
                }
                $needDiagnostics[$needId]['candidate_count']++;
                if ($candidate['decision'] === 'include') $needDiagnostics[$needId]['eligible_count']++;
            }
            if ($needDiagnostics[$needId]['eligible_count'] > 0) $needDiagnostics[$needId]['stop_reason'] = 'exact_candidates_available';
        }

        $items = array_values($all);
        usort($items, static function (array $left, array $right): int {
            $score = $right['score'] <=> $left['score'];
            if ($score !== 0) return $score;
            return ((int) ($left['_retrieval_order'] ?? 0)) <=> ((int) ($right['_retrieval_order'] ?? 0));
        });
        $mandatory = [];
        foreach ($normalizedNeeds as $need) {
            $needId = $need->needId();
            foreach ($items as $index => $item) {
                if (in_array($needId, (array) ($item['need_ids'] ?? []), true) && ($item['decision'] ?? '') === 'include') {
                    $mandatory[$index] = $item;
                    break;
                }
            }
        }
        $merged = array_values($mandatory);
        $seen = [];
        foreach ($merged as $item) $seen[$item['claim_id'] . ':' . $item['claim_revision']] = true;
        foreach ($items as $item) {
            $key = $item['claim_id'] . ':' . $item['claim_revision'];
            if (isset($seen[$key])) continue;
            if (count($merged) >= $limit) break;
            $merged[] = $item;
            $seen[$key] = true;
        }
        $selected = array_values(array_filter($merged, static fn (array $item): bool => ($item['decision'] ?? '') === 'include'));
        return [
            'status' => $blockers === [] ? 'available' : 'partial',
            'items' => array_slice($merged, 0, $limit),
            'eligible_claims' => $selected,
            'selected_claims' => $selected,
            'need_diagnostics' => array_values($needDiagnostics),
            'blockers' => array_values(array_unique($blockers)),
            'retrieval_diagnostics' => [
                'need_count' => count($normalizedNeeds),
                'initial_candidates' => count($all),
                'candidates_considered' => count($merged),
                'opportunity_allocated_per_need' => $normalizedNeeds === [] ? 0 : min(array_map(static fn (SemanticNeed $need): int => max(1, (int) ($need->retrievalPolicy()['opportunity_budget'] ?? 1)), $normalizedNeeds)),
                'starvation_prevention' => 'mandatory_first_candidate_per_need',
                'expansion_depth' => 0,
                'rounds' => [],
                'stop_reason' => 'facet_opportunities_merged',
            ],
        ];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $subject @param array<string,mixed> $neighborhood @return array<string,mixed> */
    /** @param array<string,mixed>|null $need */
    private function candidate(array $row, array $subject, array $neighborhood, string $intent, ?array $need = null): array
    {
        $id = trim((string) ($row['id'] ?? $row['claim_id'] ?? ''));
        $revision = max(1, (int) ($row['revision'] ?? $row['claim_revision'] ?? 1));
        $claimSubject = (string) ($row['subject_id'] ?? '');
        $claimSubjectType = strtolower(trim((string) ($row['subject_type'] ?? '')));
        $subjectId = (string) ($subject['id'] ?? '');
        $path = is_array($row['relation_path'] ?? null) ? $row['relation_path'] : (is_array($row['path'] ?? null) ? $row['path'] : (is_array($row['best_path'] ?? null) ? $row['best_path'] : $this->pathForClaim($claimSubject, $neighborhood)));
        $hop = max(0, count($path));
        $scope = (string) ($row['scope'] ?? 'unknown');
        $provenance = (string) ($row['provenance'] ?? '');
        $evidence = (string) ($row['evidence_status'] ?? 'NO_EVIDENCE');
        $text = (string) ($row['text'] ?? $row['claim_text'] ?? '');
        $topicOverlap = $intent !== '' ? $this->overlap($intent, strtolower($text)) : 0.0;
        $score = 0.0;
        $decision = 'include';
        $reason = 'subject, scope, provenance, evidence and registered applicability path passed the bounded retrieval policy';
        $warnings = [];
        if ($id === '' || $text === '') { $decision = 'exclude'; $reason = 'claim identity or text is missing'; }
        elseif (!$this->applicableToSubject($claimSubject, $claimSubjectType, $subject, $path)) { $decision = 'exclude'; $reason = 'Claim has no explainable subject-scoped applicability path'; $warnings[] = 'SEMANTIC_SCOPE_NOT_APPLICABLE'; }
        elseif ($scope === 'specimen-only' && ($subject['type'] ?? '') !== 'specimen') { $decision = 'exclude'; $reason = 'specimen-scoped Claim cannot generalize to this subject'; $warnings[] = 'SPECIMEN_SCOPE_LIMIT'; }
        elseif ($intent !== '' && $topicOverlap <= 0.0) { $decision = 'exclude'; $reason = 'Claim is not relevant to the editorial topic'; $warnings[] = 'TOPIC_IRRELEVANT'; }
        elseif ($evidence !== 'SUPPORTED_WITHIN_SCOPE') { $decision = 'review'; $reason = 'evidence is absent or insufficient for direct prose'; $warnings[] = 'EVIDENCE_SCOPE_REVIEW'; }
        elseif ($provenance === '') { $decision = 'review'; $reason = 'provenance is unavailable'; $warnings[] = 'PROVENANCE_UNAVAILABLE'; }
        if ($decision === 'include') {
            $score = (float) ($row['relevance'] ?? 0.0);
            $score += $claimSubject !== '' && $claimSubject === $subjectId ? 5.0 : 0.0;
            $score += $hop === 0 ? 3.0 : max(0.0, 2.0 - $hop);
            $score += $evidence === 'SUPPORTED_WITHIN_SCOPE' ? 3.0 : -2.0;
            $score += $topicOverlap;
        }
        return [
            'claim_id' => $id, 'claim_revision' => $revision, 'text' => $text, 'subject_id' => $claimSubject, 'subject_type' => $claimSubjectType,
            'scope' => $scope, 'provenance' => $provenance, 'evidence_status' => $evidence,
            'need_id' => (string) ($need['need_id'] ?? ''),
            'facet' => strtolower(trim((string) ($row['facet'] ?? $row['knowledge_facet'] ?? ''))),
            'concept' => strtolower(trim((string) ($row['concept'] ?? $row['concept_key'] ?? ''))),
            'relation_path' => $path, 'hop_count' => $hop, 'score' => round($score, 6),
            'decision' => $decision, 'reason' => $reason, 'warnings' => $warnings,
            'source_ids' => array_values((array) ($row['source_ids'] ?? [])), 'evidence_ids' => array_values((array) ($row['evidence_ids'] ?? [])),
        ];
    }

    /** @param array<string,mixed> $subject @param array<string,mixed> $neighborhood @param array<string,mixed> $need @return array<int,mixed> */
    private function claimsFor(array $subject, array $neighborhood, array $need): array
    {
        $reflection = is_array($this->claims)
            ? new \ReflectionMethod($this->claims[0], $this->claims[1])
            : new \ReflectionFunction($this->claims);
        return $reflection->getNumberOfParameters() >= 3
            ? (array) ($this->claims)($subject, $neighborhood, $need)
            : (array) ($this->claims)($subject, $neighborhood);
    }

    /**
     * Reachability only discovers a candidate. Reuse additionally requires a
     * path whose endpoints preserve the original semantic subject and whose
     * predicates are registered structural relationships. Generic `about`
     * attachment edges and lexical overlap are never applicability evidence.
     */
    private function applicableToSubject(string $claimSubject, string $claimSubjectType, array $subject, array $path): bool
    {
        $subjectId = trim((string) ($subject['id'] ?? ''));
        if ($subjectId === '' || $claimSubject === '') return false;
        if ($claimSubject === $subjectId) return true;
        if ($path === []) return false;
        $first = $path[0] ?? [];
        $last = $path[array_key_last($path)] ?? [];
        if (!is_array($first) || !is_array($last)) return false;
        if ((string) ($first['source'] ?? '') !== (string) ($subject['type'] ?? '') . ':' . $subjectId) return false;
        $lastTarget = (string) ($last['target'] ?? '');
        if ($claimSubjectType !== '' ? $lastTarget !== $claimSubjectType . ':' . $claimSubject : !str_ends_with($lastTarget, ':' . $claimSubject)) return false;
        $predicates = $this->predicates ?? new PredicateRegistry();
        foreach ($path as $step) {
            if (!is_array($step)) return false;
            $predicate = trim((string) ($step['predicate'] ?? ''));
            if ($predicate === '' || $predicate === 'about' || $predicate === 'depicts') return false;
            $sourceType = explode(':', (string) ($step['source'] ?? ''), 2)[0] ?? '';
            $targetType = explode(':', (string) ($step['target'] ?? ''), 2)[0] ?? '';
            try {
                $definition = $predicates->get($predicate);
                if (!$definition->allows($sourceType, $targetType) && !$definition->allows($targetType, $sourceType)) return false;
            } catch (\Throwable) { return false; }
        }
        return true;
    }

    private function overlap(string $left, string $right): float
    {
        $leftWords = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $left) ?: []));
        $rightWords = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $right) ?: []));
        if ($leftWords === [] || $rightWords === []) return 0.0;
        return min(2.0, count(array_intersect(array_unique($leftWords), array_unique($rightWords))) / max(1, count(array_unique($leftWords))));
    }

    /** @param array<string,mixed> $neighborhood @return list<array<string,mixed>> */
    private function pathForClaim(string $claimSubject, array $neighborhood): array
    {
        foreach ((array) ($neighborhood['items'] ?? []) as $item) {
            if (is_array($item) && (string) ($item['target_entity_id'] ?? '') === $claimSubject && is_array($item['best_path'] ?? null)) return $item['best_path'];
        }
        return [];
    }
}
