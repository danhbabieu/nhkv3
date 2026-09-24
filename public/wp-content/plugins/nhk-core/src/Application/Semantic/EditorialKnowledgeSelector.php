<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Deterministic adaptive selection of applicable Claims into a transient editorial pack. */
final class EditorialKnowledgeSelector
{
    private const SUPPORTED_PROFILES = ['article', 'video', 'image', 'media', 'generic'];

    public function __construct(
        private ?TopicFulfillment $topicFulfillment = null,
        private ?EditorialSemanticRolePolicy $rolePolicy = null,
        private ?KnowledgeUnitBuilder $unitBuilder = null,
        private ?EditorialCoveragePolicy $coveragePolicy = null,
        private ?EditorialUsageMemory $usageMemory = null,
    ) {
        $this->topicFulfillment ??= new TopicFulfillment();
        $this->rolePolicy ??= new EditorialSemanticRolePolicy();
        $this->unitBuilder ??= new KnowledgeUnitBuilder();
        $this->coveragePolicy ??= new EditorialCoveragePolicy();
        $this->usageMemory ??= new EditorialUsageMemory();
    }

    /** @param array<string,mixed> $retrieval @param array<string,mixed> $primarySubject @param array<string,mixed> $profile @param array<string,mixed> $inputContext */
    public function select(array $retrieval, string $topic, array $primarySubject, array $profile = [], array $inputContext = []): EditorialContextPack
    {
        $profileName = strtolower(trim((string) ($profile['profile'] ?? 'article')));
        $retrievalStatus = (string) ($retrieval['status'] ?? 'unavailable');
        if (!in_array($profileName, self::SUPPORTED_PROFILES, true)) return $this->pack('review', $primarySubject, $topic, $profile, $retrievalStatus, [], [], ['PROFILE_UNSUPPORTED'], ['profile' => $profileName]);
        if ($retrievalStatus === 'unavailable') return $this->pack('unavailable', $primarySubject, $topic, $profile, $retrievalStatus, [], [], (array) ($retrieval['blockers'] ?? []), ['profile' => $profileName]);

        $all = [];
        foreach (array_merge((array) ($retrieval['items'] ?? []), (array) ($retrieval['eligible_claims'] ?? [])) as $candidate) {
            if (!is_array($candidate)) continue;
            $id = (string) ($candidate['claim_id'] ?? $candidate['id'] ?? spl_object_id((object) $candidate));
            $all[$id] ??= $candidate;
        }
        $all = array_values($all);
        $eligible = [];
        $excluded = [];
        foreach ($all as $order => $candidate) {
            $classified = $this->rolePolicy->classify($candidate, $primarySubject, ['profile' => $profileName, 'topic' => $topic, 'input' => $inputContext]);
            if (($classified['eligibility'] ?? '') !== 'eligible' || ($classified['applicability'] ?? '') !== 'applicable') {
                $classified['exclusion_reasons'] = array_values(array_unique(array_merge((array) ($classified['exclusion_reasons'] ?? []), [($classified['applicability'] ?? '') !== 'applicable' ? 'INAPPLICABLE' : 'INELIGIBLE'])));
                $excluded[] = $classified;
                continue;
            }
            $classified['_selection_order'] = $order;
            $eligible[] = $classified;
        }

        $build = $this->unitBuilder->build($eligible, $primarySubject, $topic, ['profile' => $profileName] + $profile, $inputContext);
        $policy = $this->coveragePolicy->for($profileName, $topic, $inputContext);
        $explicitCeiling = array_key_exists('selection_limit', $profile) ? max(1, min(20, (int) $profile['selection_limit'])) : null;
        $units = $build->units;
        $excluded = array_merge($excluded, $build->excluded);
        foreach ($units as $unit) {
            foreach (array_slice($unit->toArray()['supporting_claims'] ?? [], 1) as $duplicate) {
                $duplicate['exclusion_reasons'] = ['REDUNDANT_INFORMATION'];
                $excluded[] = $duplicate;
            }
        }

        $promise = $this->topicFulfillment->promise($topic);
        usort($units, function (KnowledgeUnit $left, KnowledgeUnit $right) use ($topic, $inputContext, $promise, $primarySubject, $profileName): int {
            if (($promise['kind'] ?? 'none') === 'enumeration') {
                $order = ((int) ($left->claim()['_selection_order'] ?? 0)) <=> ((int) ($right->claim()['_selection_order'] ?? 0));
                if ($order !== 0) return $order;
            }
            $directLeft = ($left->claim()['retrieval_origin'] ?? '') === 'direct' ? 1 : 0;
            $directRight = ($right->claim()['retrieval_origin'] ?? '') === 'direct' ? 1 : 0;
            if ($directLeft !== $directRight) return $directRight <=> $directLeft;
            $score = $this->score($left->claim(), $topic, $inputContext) <=> $this->score($right->claim(), $topic, $inputContext);
            if ($score !== 0) return -$score;
            $leftUsage = $this->usageMemory->counts((string) ($left->claim()['claim_id'] ?? ''), (string) ($primarySubject['id'] ?? ''), $profileName);
            $rightUsage = $this->usageMemory->counts((string) ($right->claim()['claim_id'] ?? ''), (string) ($primarySubject['id'] ?? ''), $profileName);
            $usage = ($leftUsage['recent'] + $leftUsage['same_subject']) <=> ($rightUsage['recent'] + $rightUsage['same_subject']);
            if ($usage !== 0) return $usage;
            $order = ((int) ($left->claim()['_selection_order'] ?? 0)) <=> ((int) ($right->claim()['_selection_order'] ?? 0));
            if ($order !== 0) return $order;
            return strcmp((string) ($left->claim()['claim_id'] ?? ''), (string) ($right->claim()['claim_id'] ?? ''));
        });

        $selected = [];
        $covered = [];
        $relaxedCoverage = [];
        $contextualCoverage = [];
        $usedTokens = 0;
        $gains = [];
        $stopReason = 'no_applicable_reader_knowledge';
        foreach ($units as $unit) {
            $data = $unit->toArray();
            $aspects = array_values(array_diff((array) ($data['coverage_aspects'] ?? []), $covered));
            $claim = $unit->claim();
            $coverageKind = strtolower(trim((string) ($claim['coverage_kind'] ?? 'exact')));
            $retrievalOrigin = strtolower(trim((string) ($claim['retrieval_origin'] ?? '')));
            $semanticRole = strtoupper(trim((string) ($claim['semantic_role'] ?? '')));
            $isExactCoverage = $coverageKind === 'exact'
                && (!isset($claim['retrieval_tier']) || strtoupper((string) $claim['retrieval_tier']) === 'EXACT')
                && $retrievalOrigin !== 'neighborhood'
                && $semanticRole !== EditorialSemanticRolePolicy::SUPPORTING_CONTEXT;
            if (!$isExactCoverage) {
                $aspects = ['context:' . (string) ($claim['claim_id'] ?? count($selected))];
            }
            $tokenCost = count($this->tokens((string) ($claim['text'] ?? '')));
            $gain = count($aspects) + (($claim['retrieval_origin'] ?? '') === 'direct' ? 0.75 : 0.5) + min(0.25, $this->score($claim, $topic, $inputContext) / 40.0);
            if ($aspects === [] || $gain < (float) $policy['minimum_gain']) {
                $claim['exclusion_reasons'] = ['LOW_MARGINAL_INFORMATION_GAIN'];
                $excluded[] = $claim;
                $stopReason = 'marginal_gain_low';
                continue;
            }
            if ($usedTokens + $tokenCost > (int) $policy['token_budget']) {
                $claim['exclusion_reasons'] = ['CONTEXT_BUDGET_EXCEEDED'];
                $excluded[] = $claim;
                $stopReason = 'context_budget';
                break;
            }
            $claim['knowledge_unit'] = $data;
            $claim['utility'] = ['information_gain' => $this->novelty($claim, $inputContext), 'reader_value' => round($this->score($claim, $topic, $inputContext), 6), 'semantic_coverage' => $aspects, 'total' => round($this->score($claim, $topic, $inputContext) + count($aspects), 6)];
            $claim['editorial_role'] = $isExactCoverage || $retrievalOrigin === 'neighborhood' ? $this->role($claim, $selected) : 'SUPPORTING_CONTEXT';
            $claim['semantic_context_only'] = !$isExactCoverage;
            $claim['selection_reason'] = $isExactCoverage ? 'applicable KnowledgeUnit adds uncovered reader coverage' : 'applicable KnowledgeUnit provides bounded contextual support';
            $claim['state'] = EditorialSemanticRolePolicy::SELECTED;
            $claim['publicly_composable'] = true;
            $selected[] = $claim;
            $explicitCoverage = array_values(array_filter(
                (array) ($data['coverage_aspects'] ?? []),
                static fn (mixed $aspect): bool => is_string($aspect) && str_starts_with($aspect, 'facet:')
            ));
            if ($isExactCoverage && $explicitCoverage !== []) $covered = array_values(array_unique(array_merge($covered, $explicitCoverage)));
            elseif ($coverageKind === 'contextual') $contextualCoverage[] = (string) ($claim['claim_id'] ?? '');
            else $relaxedCoverage[] = (string) ($claim['claim_id'] ?? '');
            $usedTokens += $tokenCost;
            $gains[] = round((float) $gain, 6);
            if ($explicitCeiling !== null && count($selected) >= $explicitCeiling) {
                $stopReason = 'context_budget';
                break;
            }
            if (count($covered) >= (int) $policy['aspect_target']) {
                $stopReason = 'coverage_sufficient';
                break;
            }
        }
        if ($selected === [] && $units !== []) $stopReason = 'marginal_gain_low';
        if ($selected !== [] && $stopReason === 'no_applicable_reader_knowledge') $stopReason = 'no_more_applicable_units';

        $grounding = array_values($build->grounding);
        $readerFacts = array_values($selected);
        $coverageStatus = $selected === [] ? 'THIN' : (count($covered) >= (int) $policy['aspect_target'] ? 'SUFFICIENT' : 'PARTIAL');
        $status = $selected === [] ? ($eligible === [] ? 'no_eligible_claims' : 'no_useful_claims') : 'available';
        $diagnostics = [
            'profile' => $profileName,
            'candidate_count' => count($all),
            'eligible_count' => count($eligible),
            'knowledge_unit_count' => count($units),
            'selected_unit_count' => count($selected),
            'selected_count' => count($selected),
            'excluded_count' => count($excluded),
            'coverage_achieved' => $covered,
            'relaxed_coverage' => array_values(array_unique($relaxedCoverage)),
            'contextual_coverage' => array_values(array_unique($contextualCoverage)),
            'coverage_status' => $coverageStatus,
            'coverage_kinds' => array_values(array_unique(array_map(static fn (array $claim): string => (string) ($claim['coverage_kind'] ?? 'exact'), $selected))),
            'exact_selected_count' => count(array_filter($selected, static fn (array $claim): bool => strtolower((string) ($claim['coverage_kind'] ?? 'exact')) === 'exact')),
            'non_exact_selected_count' => count(array_filter($selected, static fn (array $claim): bool => strtolower((string) ($claim['coverage_kind'] ?? 'exact')) !== 'exact')),
            'stop_reason' => $stopReason,
            'context_budget' => (int) $policy['token_budget'],
            'context_budget_used' => $usedTokens,
            'marginal_gains' => $gains,
            'policy_version' => 'adaptive-knowledge-selection-v1',
            'information_gain' => array_sum($gains),
            'provenance_dominated' => count($build->grounding) > count($selected) && count($build->grounding) > 0,
            'duplicate_dominated' => count(array_filter($excluded, static fn (array $candidate): bool => in_array('REDUNDANT_INFORMATION', (array) ($candidate['exclusion_reasons'] ?? []), true))) > 0,
        ];
        return $this->pack($status, $primarySubject, $topic, $profile, $retrievalStatus, $selected, $excluded, [], $diagnostics, $this->visualSupport($selected), $inputContext, $grounding, $readerFacts, $units);
    }

    private function pack(string $status, array $subject, string $topic, array $profile, string $retrievalStatus, array $selected, array $excluded, array $blockers, array $diagnostics, array $visualSupport = [], array $inputContext = [], array $grounding = [], array $readerFacts = [], array $knowledgeUnits = []): EditorialContextPack
    {
        $coverage = [];
        foreach ($selected as $claim) if (is_array($claim['knowledge_unit'] ?? null)) $coverage = array_values(array_unique(array_merge($coverage, (array) ($claim['knowledge_unit']['coverage_aspects'] ?? []))));
        return new EditorialContextPack($status, $subject, trim($topic), $profile, $retrievalStatus, array_values($selected), array_values($excluded), $inputContext, array_values($visualSupport), array_values($blockers), $diagnostics, 1, array_values($grounding), array_values($readerFacts), [], [], [], array_values($knowledgeUnits), $coverage);
    }

    private function score(array $candidate, string $topic, array $inputContext): float
    {
        $text = $this->tokens((string) ($candidate['text'] ?? ''));
        $topicTokens = $this->tokens($topic);
        $overlap = $topicTokens === [] ? 0.0 : count(array_intersect($text, $topicTokens)) / count($topicTokens);
        $evidence = (($candidate['evidence']['status'] ?? '') === 'eligible') ? 1.0 : 0.0;
        $direct = (($candidate['retrieval_origin'] ?? '') === 'direct') ? 2.0 : 0.0;
        return round(($overlap * 5.0) + $evidence + $direct + (float) ($candidate['score'] ?? 0.0), 6);
    }

    private function novelty(array $candidate, array $inputContext): float
    {
        $claim = $this->tokens((string) ($candidate['text'] ?? ''));
        $input = $this->tokens((string) ($inputContext['raw_input'] ?? $inputContext['text'] ?? ''));
        return round($input === [] ? 1.0 : count(array_diff($claim, $input)) / max(1, count($claim)), 6);
    }

    private function role(array $candidate, array $selected): string { return $selected === [] ? 'CORE' : (($candidate['retrieval_origin'] ?? '') === 'neighborhood' ? 'EXPLANATION' : 'CONTEXT'); }

    /** @return list<array<string,mixed>> */
    private function visualSupport(array $selected): array
    {
        $requirements = [];
        foreach ($selected as $candidate) {
            if (is_array($candidate['visual_support'] ?? null)) $requirements[] = $candidate['visual_support'] + ['claim_id' => $candidate['claim_id']];
            elseif (($candidate['visual_support_required'] ?? false) === true) $requirements[] = ['claim_id' => $candidate['claim_id'], 'status' => 'UNRESOLVED', 'reason' => 'FEATURE_SUPPORT_REQUIRED', 'representative_media' => $candidate['representative_media'] ?? null];
        }
        return $requirements;
    }

    /** @return list<string> */
    private function tokens(string $value): array
    {
        $lower = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        return array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $lower) ?: [])));
    }
}
