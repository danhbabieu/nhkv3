<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Deterministic selection of eligible Claims into a transient editorial pack. */
final class EditorialKnowledgeSelector
{
    private const SUPPORTED_PROFILES = ['article', 'video', 'image', 'media'];

    /** @param array<string,mixed> $retrieval @param array<string,mixed> $primarySubject @param array<string,mixed> $profile @param array<string,mixed> $inputContext */
    public function select(array $retrieval, string $topic, array $primarySubject, array $profile = [], array $inputContext = []): EditorialContextPack
    {
        $profileName = strtolower(trim((string) ($profile['profile'] ?? 'article')));
        $retrievalStatus = (string) ($retrieval['status'] ?? 'unavailable');
        if (!in_array($profileName, self::SUPPORTED_PROFILES, true)) {
            return $this->pack('review', $primarySubject, $topic, $profile, $retrievalStatus, [], [], ['PROFILE_UNSUPPORTED'], ['profile' => $profileName]);
        }
        if ($retrievalStatus === 'unavailable') {
            return $this->pack('unavailable', $primarySubject, $topic, $profile, $retrievalStatus, [], [], (array) ($retrieval['blockers'] ?? []), ['profile' => $profileName]);
        }

        $limit = max(1, min(20, (int) ($profile['selection_limit'] ?? ['article' => 8, 'video' => 6, 'image' => 6, 'media' => 6][$profileName])));
        $all = array_values(array_filter((array) ($retrieval['items'] ?? []), 'is_array'));
        $eligible = array_values(array_filter((array) ($retrieval['eligible_claims'] ?? []), static fn (mixed $candidate): bool => is_array($candidate) && ($candidate['eligibility'] ?? '') === 'eligible'));
        $inputTokens = $this->tokens((string) ($inputContext['raw_input'] ?? ''));
        $topicTokens = $this->tokens($topic);
        $ranked = [];
        foreach ($eligible as $order => $candidate) {
            $utility = $this->utility($candidate, $topicTokens, $inputTokens, $profileName);
            $candidate['utility'] = $utility;
            $candidate['editorial_role'] = 'CONTEXT';
            $candidate['_selection_order'] = $order;
            $ranked[] = $candidate;
        }
        usort($ranked, static function (array $left, array $right): int {
            $utility = $right['utility']['total'] <=> $left['utility']['total'];
            return $utility !== 0 ? $utility : (($left['_selection_order'] ?? 0) <=> ($right['_selection_order'] ?? 0));
        });

        $selected = [];
        $excluded = [];
        foreach ($all as $candidate) {
            if (($candidate['eligibility'] ?? '') !== 'eligible') {
                unset($candidate['_selection_order']);
                $excluded[] = $candidate;
            }
        }
        foreach ($ranked as $candidate) {
            if (count($selected) >= $limit) {
                $candidate['exclusion_reasons'] = ['SELECTION_BUDGET_EXHAUSTED'];
                unset($candidate['_selection_order']);
                $excluded[] = $candidate;
                continue;
            }
            $duplicate = false;
            foreach ($selected as $prior) {
                if ($this->redundant((string) ($candidate['text'] ?? ''), (string) ($prior['text'] ?? ''))) {
                    $duplicate = true;
                    break;
                }
            }
            if ($duplicate) {
                $candidate['exclusion_reasons'] = ['REDUNDANT_INFORMATION'];
                $excluded[] = $candidate;
                continue;
            }
            $candidate['editorial_role'] = $this->role($candidate, $selected);
            $candidate['selection_reason'] = $this->selectionReason($candidate);
            unset($candidate['_selection_order']);
            $selected[] = $candidate;
        }

        $visualSupport = $this->visualSupport($selected);
        $status = $selected !== [] ? 'available' : ($eligible === [] ? 'no_eligible_claims' : 'no_useful_claims');
        return $this->pack($status, $primarySubject, $topic, $profile, $retrievalStatus, $selected, $excluded, [], [
            'profile' => $profileName,
            'selection_limit' => $limit,
            'eligible_count' => count($eligible),
            'selected_count' => count($selected),
            'excluded_count' => count($excluded),
            'policy_version' => 'editorial-selection-v1',
            'information_gain' => array_sum(array_map(static fn (array $item): float => (float) ($item['utility']['information_gain'] ?? 0.0), $selected)),
        ], $visualSupport, $inputContext);
    }

    private function pack(string $status, array $subject, string $topic, array $profile, string $retrievalStatus, array $selected, array $excluded, array $blockers, array $diagnostics, array $visualSupport = [], array $inputContext = []): EditorialContextPack
    {
        return new EditorialContextPack($status, $subject, trim($topic), $profile, $retrievalStatus, array_values($selected), array_values($excluded), $inputContext, array_values($visualSupport), array_values($blockers), $diagnostics);
    }

    private function utility(array $candidate, array $topicTokens, array $inputTokens, string $profile): array
    {
        $claimTokens = $this->tokens((string) ($candidate['text'] ?? ''));
        $topicRelevance = $this->overlap($topicTokens, $claimTokens);
        $informationGain = $inputTokens === [] ? 1.0 : count(array_diff($claimTokens, $inputTokens)) / max(1, count($claimTokens));
        $direct = ($candidate['retrieval_origin'] ?? '') === 'direct' ? 2.0 : 0.0;
        $evidence = (($candidate['evidence']['status'] ?? '') === 'eligible') ? 1.0 : 0.0;
        $profileBias = $profile === 'image' || $profile === 'media' ? (($candidate['retrieval_origin'] ?? '') === 'direct' ? 0.5 : 0.0) : 0.0;
        return ['topic_relevance' => round($topicRelevance, 6), 'information_gain' => round($informationGain, 6), 'reader_value' => round($informationGain + $direct, 6), 'semantic_coverage' => round($topicRelevance + $informationGain, 6), 'total' => round(($topicRelevance * 10.0) + ($informationGain * 4.0) + $direct + $evidence + $profileBias, 6)];
    }

    private function role(array $candidate, array $selected): string
    {
        if ($selected === []) return 'CORE';
        return ($candidate['retrieval_origin'] ?? '') === 'neighborhood' ? 'EXPLANATION' : 'CONTEXT';
    }

    private function selectionReason(array $candidate): string
    {
        return ($candidate['retrieval_origin'] ?? '') === 'direct'
            ? 'direct eligible Claim adds topic coverage and reader context'
            : 'bounded eligible neighbor adds explainable contextual information';
    }

    private function redundant(string $left, string $right): bool
    {
        $a = $this->tokens($left); $b = $this->tokens($right);
        if ($a === [] || $b === []) return false;
        return count(array_intersect($a, $b)) / max(1, min(count($a), count($b))) >= 0.8;
    }

    /** @return list<array<string,mixed>> */
    private function visualSupport(array $selected): array
    {
        $requirements = [];
        foreach ($selected as $candidate) {
            if (is_array($candidate['visual_support'] ?? null)) {
                $requirements[] = $candidate['visual_support'] + ['claim_id' => $candidate['claim_id']];
            } elseif (($candidate['visual_support_required'] ?? false) === true) {
                $requirements[] = ['claim_id' => $candidate['claim_id'], 'status' => 'UNRESOLVED', 'reason' => 'FEATURE_SUPPORT_REQUIRED', 'representative_media' => $candidate['representative_media'] ?? null];
            }
        }
        return $requirements;
    }

    /** @return list<string> */
    private function tokens(string $value): array
    {
        return array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value)) ?: [])));
    }

    private function overlap(array $left, array $right): float
    {
        return $left === [] || $right === [] ? 0.0 : count(array_intersect($left, $right)) / max(1, count($left));
    }
}
