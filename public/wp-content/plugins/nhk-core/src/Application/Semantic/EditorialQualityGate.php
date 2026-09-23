<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

use NHK\Core\Application\Compliance\{PublicClaimCopyPolicy, PublicEditorialCopyGuard};
use NHK\Core\Domain\Seo\SeoReadinessResult;

/** One deterministic quality gate shared by all transient editorial projections. */
final class EditorialQualityGate
{
    private const DIMENSIONS = [
        'factual_grounding', 'scope', 'evidence', 'knowledge_utilization', 'information_gain',
        'reader_journey', 'topic_centrality', 'redundancy', 'template_boilerplate', 'public_language',
        'visual_support', 'seo_readiness', 'internal_link_quality', 'public_claim_compliance',
        'traceability', 'profile_fit', 'public_readiness',
    ];

    public function evaluate(EditorialContextPack $pack, EditorialPlan $plan, EditorialDraft $draft, SemanticSeoPlan $seo, bool $allowDeferredSeoIdentity = false, int $round = 0, ?string $packageFingerprint = null, string $attemptId = '', int $attemptNo = 0): EditorialQualityReport
    {
        $profile = $this->profile($pack, $plan, $draft, $seo);
        $dimensions = [];
        $blockers = [];
        $warnings = [];
        $informational = [];
        $add = function (string $dimension, string $severity, string $reason) use (&$dimensions, &$blockers, &$warnings, &$informational): void {
            $dimensions[$dimension] ??= ['status' => 'READY', 'severity' => 'INFO', 'reasons' => []];
            $dimensions[$dimension]['reasons'][] = $reason;
            if ($severity === 'BLOCK') { $dimensions[$dimension]['status'] = 'BLOCKED'; $dimensions[$dimension]['severity'] = 'BLOCK'; $blockers[] = $reason; }
            elseif ($severity === 'WARN') { if ($dimensions[$dimension]['severity'] !== 'BLOCK') { $dimensions[$dimension]['status'] = 'INCOMPLETE'; $dimensions[$dimension]['severity'] = 'WARN'; } $warnings[] = $reason; }
            else { $informational[] = $reason; }
        };
        foreach (self::DIMENSIONS as $dimension) $dimensions[$dimension] = ['status' => 'READY', 'severity' => 'INFO', 'reasons' => []];

        $selectionDiagnostics = $pack->diagnostics;
        if (in_array((string) ($selectionDiagnostics['coverage_status'] ?? ''), ['THIN', 'PARTIAL'], true)) $add('knowledge_utilization', 'WARN', 'INSUFFICIENT_READER_COVERAGE');
        if (($selectionDiagnostics['stop_reason'] ?? '') === 'marginal_gain_low') $add('information_gain', 'WARN', 'LOW_MARGINAL_INFORMATION_GAIN');
        if (($selectionDiagnostics['stop_reason'] ?? '') === 'context_budget') $add('information_gain', 'WARN', 'CONTEXT_BUDGET_EXCEEDED');
        if (($selectionDiagnostics['provenance_dominated'] ?? false) === true) $add('knowledge_utilization', 'WARN', 'PROVENANCE_DOMINATED_SELECTION');
        if (($selectionDiagnostics['duplicate_dominated'] ?? false) === true) $add('redundancy', 'WARN', 'DUPLICATE_KNOWLEDGE_DOMINATION');
        if ((int) ($selectionDiagnostics['knowledge_unit_count'] ?? 0) > (int) ($selectionDiagnostics['selected_unit_count'] ?? 0) && (int) ($selectionDiagnostics['selected_unit_count'] ?? 0) > 0) $add('knowledge_utilization', 'INFO', 'SELECTED_KNOWLEDGE_DISCARDED');

        $selected = [];
        foreach ($this->publicClaims($pack) as $claim) {
            $id = trim((string) ($claim['claim_id'] ?? ''));
            if ($id !== '') $selected[$id] = $claim;
            if (($claim['eligibility'] ?? '') !== 'eligible') $add('evidence', 'BLOCK', 'INELIGIBLE_SELECTED_CLAIM');
            if (($claim['evidence']['status'] ?? 'eligible') !== 'eligible' && ($claim['evidence']['status'] ?? 'eligible') !== 'not_required') $add('evidence', 'BLOCK', 'CLAIM_EVIDENCE_NOT_ELIGIBLE');
            if (in_array(strtoupper((string) ($claim['semantic_role'] ?? '')), ['GROUNDING', 'PROVENANCE_ONLY', 'CONTROL_ONLY'], true)) $add('knowledge_utilization', 'BLOCK', 'PUBLIC_ROLE_VIOLATION');
            if (($claim['retrieval_origin'] ?? '') === 'neighborhood' && ($claim['applicability'] ?? 'applicable') !== 'applicable') $add('scope', 'BLOCK', 'INAPPLICABLE_NEIGHBOR_SELECTED');
        }

        $traceIds = [];
        foreach ($draft->claimTrace as $trace) {
            $id = trim((string) ($trace['claim_id'] ?? ''));
            if ($id === '' || !isset($selected[$id]) || ($selected[$id]['eligibility'] ?? '') !== 'eligible') { $add('traceability', 'BLOCK', 'INELIGIBLE_CLAIM_USED'); continue; }
            $traceIds[$id] = true;
            if ((int) ($trace['claim_revision'] ?? 0) !== (int) ($selected[$id]['claim_revision'] ?? 0)) $add('traceability', 'BLOCK', 'STALE_CLAIM_REVISION');
            $traceSubject = $trace['original_subject']['id'] ?? null;
            $claimSubject = $selected[$id]['original_subject']['id'] ?? null;
            if ($traceSubject !== null && $claimSubject !== null && $traceSubject !== $claimSubject) $add('scope', 'BLOCK', 'EDITORIAL_SCOPE_WIDENED');
        }
        $requiresTrace = array_filter($selected, static fn (array $claim): bool => in_array((string) ($claim['editorial_role'] ?? ''), ['CORE', 'IDENTIFICATION', 'EXPLANATION'], true));
        if ($requiresTrace !== [] && $traceIds === []) $add('traceability', 'BLOCK', 'MISSING_CLAIM_TRACE');
        $this->assertFactualGrounding($pack, $draft, $selected, $traceIds, $add);
        foreach ($pack->grounding as $claim) {
            if (($claim['publicly_composable'] ?? true) === false && strtoupper((string) ($claim['semantic_role'] ?? '')) === 'PROVENANCE_ONLY') {
                $claimText = trim((string) ($claim['text'] ?? $claim['claim_text'] ?? ''));
                if ($claimText !== '' && $this->supportedBy($claimText, [$draft->title, $draft->summary, $draft->body])) {
                    $add('knowledge_utilization', 'WARN', 'PROVENANCE_DOMINATED_PUBLIC_CONTENT');
                    break;
                }
            }
        }
        $usedCore = false;
        foreach ($selected as $id => $claim) if (in_array((string) ($claim['editorial_role'] ?? ''), ['CORE', 'IDENTIFICATION'], true) && isset($traceIds[$id])) $usedCore = true;
        if ($selected === []) $add('knowledge_utilization', 'INFO', 'SPARSE_KNOWLEDGE_INPUT');
        elseif (!$usedCore) $add('knowledge_utilization', 'WARN', 'UNDERUTILIZED_RELEVANT_KNOWLEDGE');
        if ($this->informationGain($pack, $draft, $selected, $traceIds) < 0.15) $add('information_gain', 'WARN', 'LOW_INFORMATION_GAIN');
        if ($draft->status === 'sparse_input') $add('knowledge_utilization', 'INFO', 'SPARSE_KNOWLEDGE_INPUT');

        $body = trim($draft->title . ' ' . $draft->summary . ' ' . $draft->body);
        $topic = trim($pack->topic);
        if ($topic !== '' && !$this->containsTopic($body, $topic)) $add('topic_centrality', 'WARN', 'TOPIC_CENTRALITY_WEAK');
        $fulfillment = (new TopicFulfillment())->evaluate($topic, array_values($selected), $draft->body);
        if (!$fulfillment['fulfilled'] && $fulfillment['diagnostic'] !== null) $add('topic_centrality', 'WARN', (string) $fulfillment['diagnostic']);
        if ($topic !== '' && $draft->title !== '' && !$this->containsTopic($draft->title, $topic)) $add('topic_centrality', 'WARN', 'TITLE_BODY_COVERAGE_GAP');
        $knownClaimText = implode(' ', array_map(static fn (array $claim): string => (string) ($claim['text'] ?? ''), array_values($selected)));
        foreach ($this->sentences($draft->body) as $sentence) {
            if (preg_match('/\b(?:sản xuất|ra đời|phát hành)\b.{0,80}\b(?:19|20)\d{2}\b/iu', $sentence) === 1 && !$this->containsTopic($knownClaimText, $sentence)) $add('factual_grounding', 'BLOCK', 'UNTRACEABLE_FACTUAL_ASSERTION');
            elseif (preg_match('/\b\d+(?:[.,]\d+)?\s*(?:mm|cm|m|kg|%)\b/iu', $sentence) === 1 && !$this->containsTopic($knownClaimText, $sentence)) $add('scope', 'WARN', 'TOPIC_DRIFT');
        }
        $coreIndex = null;
        foreach ($plan->sections as $index => $section) if (in_array((string) ($section['id'] ?? ''), ['core', 'identification'], true)) { $coreIndex = $index; break; }
        if ($coreIndex !== null && $coreIndex > 2) $add('reader_journey', 'WARN', 'CORE_TOPIC_BURIED');
        if ($this->hasRedundancy($draft->body)) $add('redundancy', 'WARN', 'EXCESSIVE_REDUNDANCY');
        if ($this->hasBoilerplate($draft->body)) $add('template_boilerplate', 'WARN', 'TEMPLATE_BOILERPLATE_EXPOSED');
        if ($this->hasPlanningLabels($draft->body)) $add('template_boilerplate', 'WARN', 'VISIBLE_PLANNING_LABEL');
        if ($this->hasMalformedJoin($draft->body)) $add('template_boilerplate', 'WARN', 'MALFORMED_SENTENCE_JOIN');
        if ($this->hasGenericFiller($draft->body)) $add('template_boilerplate', 'WARN', 'GENERIC_EDITORIAL_FILLER');

        $qualityFindings = [];
        $guard = new PublicEditorialCopyGuard();
        $guardFindings = $guard->findings(['title' => $draft->title, 'summary' => $draft->summary, 'body' => $draft->body, 'seo_title' => $seo->title, 'seo_description' => $seo->metaDescription]);
        foreach ($guardFindings as $finding) {
            $qualityFindings[] = EditorialQualityFinding::normalize($finding, ['round' => $round, 'package_fingerprint' => $packageFingerprint ?? $this->packageFingerprint($draft, $seo), 'attempt_id' => $attemptId, 'attempt_no' => $attemptNo]);
            if (($finding['severity'] ?? '') === 'HARD_BLOCK') $add('public_language', 'BLOCK', 'PUBLIC_INTERNAL_JARGON_LEAK');
        }
        foreach ($this->internalLanguageFindings([
            'title' => $draft->title,
            'summary' => $draft->summary,
            'body' => $draft->body,
            'seo.description' => $seo->metaDescription,
        ]) as $finding) {
            $origin = $this->originFor((string) ($finding['offending_span'] ?? ''), $pack, $draft, $selected);
            $qualityFindings[] = EditorialQualityFinding::normalize($finding, ['round' => $round, 'package_fingerprint' => $packageFingerprint ?? $this->packageFingerprint($draft, $seo), 'attempt_id' => $attemptId, 'attempt_no' => $attemptNo] + $origin);
        }
        // Keep the historical concatenated boolean as the compatibility
        // decision. Field findings above are diagnostic evidence only.
        if ($this->hasInternalLanguage($body . ' ' . $seo->metaDescription)) $add('public_language', 'BLOCK', 'PUBLIC_INTERNAL_JARGON_LEAK');
        if ((new PublicClaimCopyPolicy())->containsUnsupportedSuperiority($body)) $add('public_claim_compliance', 'BLOCK', 'UNSUPPORTED_PROMOTIONAL_CLAIM');

        $visual = array_merge($pack->visualSupport, $plan->visualSupport, (array) ($draft->diagnostics['visual_support'] ?? []));
        foreach ($visual as $item) if (is_array($item) && (strtoupper((string) ($item['status'] ?? '')) === 'UNRESOLVED' || (strtoupper((string) ($item['status'] ?? '')) === 'UNAVAILABLE' && ($item['required'] ?? true) === true) || strtolower((string) ($item['support'] ?? '')) === 'representative')) { $add('visual_support', 'BLOCK', 'VISUAL_SUPPORT_UNRESOLVED'); break; }

        $deferredVideoIdentity = $allowDeferredSeoIdentity
            && $profile === 'video'
            && $seo->canonicalUrl === null
            && $seo->blockers !== []
            && array_diff($seo->blockers, ['MISSING_PUBLIC_IDENTITY', 'AMBIGUOUS_CANONICAL_SUBJECT']) === [];
        if (!in_array($seo->readiness, [SeoReadinessResult::READY, SeoReadinessResult::NOT_APPLICABLE], true)) {
            if ($deferredVideoIdentity) $add('seo_readiness', 'INFO', 'VIDEO_PUBLIC_IDENTITY_DEFERRED_UNTIL_OWNER_CREATION');
            else $add('seo_readiness', 'BLOCK', 'SEO_NOT_READY');
        }
        if ($topic !== '' && !$this->containsTopic($seo->title . ' ' . $seo->h1 . ' ' . $seo->topicFocus, $topic)) $add('seo_readiness', 'WARN', 'SEO_TOPIC_MISMATCH');
        foreach ($seo->internalLinks as $link) {
            $url = trim((string) ($link['url'] ?? ''));
            if ($url === '' || !str_starts_with($url, '/') || preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/i', $url) === 1) $add('internal_link_quality', 'BLOCK', 'INVALID_PUBLIC_INTERNAL_LINK');
            elseif (!$this->containsAnyTopicTerm((string) ($link['title'] ?? '') . ' ' . $url, $topic)) $add('internal_link_quality', 'WARN', 'IRRELEVANT_INTERNAL_LINK');
        }
        if (($seo->diagnostics['cannibalization']['status'] ?? '') === 'review') $add('internal_link_quality', 'WARN', 'DUPLICATE_INTENT_REVIEW');
        $selectedIds = array_keys($selected);
        foreach ($seo->claimTrace as $trace) if (($trace['claim_id'] ?? '') !== '' && !in_array((string) $trace['claim_id'], $selectedIds, true)) $add('seo_readiness', 'BLOCK', 'SEO_NON_PUBLIC_KNOWLEDGE');
        if (!in_array($profile, ['article', 'video', 'image', 'media'], true)) $add('profile_fit', 'BLOCK', 'UNKNOWN_EDITORIAL_PROFILE');
        if ($profile === 'video' && (!$this->containsTopic($body, $topic) || !$this->hasCoreSpine($body, $selected))) $add('profile_fit', 'WARN', 'VIDEO_TOPIC_SPINE_WEAK');
        if (in_array($profile, ['image', 'media'], true) && !preg_match('/\b(?:hình ảnh|ảnh)\b/iu', $body)) $add('profile_fit', 'WARN', 'IMAGE_PROFILE_MARKER_MISSING');

        $blockers = array_values(array_unique(array_slice($blockers, 0, 30)));
        $warnings = array_values(array_unique(array_slice($warnings, 0, 30)));
        $informational = array_values(array_unique(array_slice($informational, 0, 30)));
        $readiness = $blockers !== [] ? 'BLOCKED' : ($warnings !== [] ? 'INCOMPLETE' : 'READY');
        $dimensions['public_readiness'] = ['status' => $readiness, 'severity' => $blockers !== [] ? 'BLOCK' : ($warnings !== [] ? 'WARN' : 'INFO'), 'reasons' => array_merge($blockers, $warnings)];
        foreach ($dimensions as &$dimension) $dimension['reasons'] = array_values(array_unique(array_slice($dimension['reasons'], 0, 10)));
        unset($dimension);
        return new EditorialQualityReport($readiness, $profile, $dimensions, $blockers, $warnings, $informational, ['gate' => 'shared_editorial_quality', 'opaque_score' => false, 'quality_findings' => $qualityFindings, 'evaluation' => ['round' => $round, 'package_fingerprint' => $packageFingerprint ?? $this->packageFingerprint($draft, $seo), 'attempt_id' => $attemptId, 'attempt_no' => $attemptNo]]);
    }

    private function profile(EditorialContextPack $pack, EditorialPlan $plan, EditorialDraft $draft, SemanticSeoPlan $seo): string
    {
        foreach ([$draft->profile, $plan->profile, $seo->profile, (string) ($pack->profile['profile'] ?? '')] as $profile) if ($profile !== '') return $profile;
        return 'article';
    }

    /** @return list<array<string,mixed>> */
    private function publicClaims(EditorialContextPack $pack): array
    {
        $bucketed = array_merge($pack->readerFacts, $pack->supportingContext, $pack->specimenContext);
        $claims = $pack->selectedClaims !== [] ? $pack->selectedClaims : $bucketed;
        $result = [];
        foreach ($claims as $claim) {
            if (!is_array($claim) || (($claim['publicly_composable'] ?? true) !== true) || (($claim['eligibility'] ?? 'eligible') !== 'eligible')) continue;
            $id = trim((string) ($claim['claim_id'] ?? ''));
            if ($id !== '') $result[$id] = $claim;
        }
        return array_values($result);
    }

    private function containsTopic(string $copy, string $topic): bool
    {
        $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($topic)) ?: [], static fn (string $word): bool => mb_strlen($word) >= 3));
        if ($words === []) return true;
        $copy = mb_strtolower($copy); $hits = 0;
        foreach ($words as $word) if (str_contains($copy, $word)) $hits++;
        return $hits >= min(2, count($words));
    }

    private function containsAnyTopicTerm(string $copy, string $topic): bool
    {
        $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($topic)) ?: [], static fn (string $word): bool => mb_strlen($word) >= 3));
        $copy = mb_strtolower($copy);
        foreach ($words as $word) if (str_contains($copy, $word)) return true;
        return false;
    }

    /** @param array<string,array<string,mixed>> $selected */
    private function hasCoreSpine(string $body, array $selected): bool
    {
        $bodyTokens = $this->tokens($body);
        foreach ($selected as $claim) {
            if (!in_array((string) ($claim['editorial_role'] ?? ''), ['CORE', 'IDENTIFICATION'], true)) continue;
            $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) ($claim['text'] ?? ''))) ?: [], static fn (string $word): bool => mb_strlen($word) >= 2));
            $needle = array_slice($words, 0, 4);
            if ($needle !== [] && $this->containsTokenSequence($bodyTokens, $needle)) return true;
        }
        return $selected === [];
    }

    /** @param list<string> $haystack @param list<string> $needle */
    private function containsTokenSequence(array $haystack, array $needle): bool
    {
        if ($needle === [] || count($needle) > count($haystack)) return false;
        foreach (array_keys($haystack) as $start) {
            if (array_slice($haystack, (int) $start, count($needle)) === $needle) return true;
        }
        return false;
    }

    private function hasRedundancy(string $body): bool
    {
        $paragraphs = array_values(array_filter(array_map(static fn (string $part): string => preg_replace('/\s+/u', ' ', trim($part)) ?? trim($part), preg_split('/\R{2,}/u', $body) ?: [])));
        return count($paragraphs) >= 3 && count($paragraphs) !== count(array_unique($paragraphs));
    }

    private function hasBoilerplate(string $body): bool
    {
        foreach (['Nội dung dưới đây tập trung', 'Video là điểm bắt đầu', 'Hình ảnh là điểm bắt đầu'] as $phrase) if (substr_count(mb_strtolower($body), mb_strtolower($phrase)) > 1) return true;
        return false;
    }

    private function hasPlanningLabels(string $body): bool
    {
        return preg_match('/(?:Điểm chính của chủ đề|Bối cảnh hữu ích|Điều cần hiểu thêm|Cách nhận biết|Những khác biệt đáng chú ý|Bước tìm hiểu tiếp theo)\s*:/iu', $body) === 1;
    }

    private function hasMalformedJoin(string $body): bool
    {
        return preg_match('/(?:\.\.\.|\b(?:đồng hồ|Odo\s+\d+)\b)[ \t]+(?:Video|Hình ảnh|Nội dung)\s+là/iu', $body) === 1
            || preg_match('/[.!?][ \t]+[a-zà-ỹ]/u', $body) === 1;
    }

    private function hasGenericFiller(string $body): bool
    {
        return preg_match('/(?:Video|Hình ảnh)\s+là điểm bắt đầu để (?:theo dõi|quan sát)/iu', $body) === 1;
    }

    /** @param array<string,array<string,mixed>> $selected @param array<string,bool> $traceIds @param callable(string,string,string):void $add */
    private function assertFactualGrounding(EditorialContextPack $pack, EditorialDraft $draft, array $selected, array $traceIds, callable $add): void
    {
        $support = [(string) ($pack->inputContext['raw_input'] ?? $pack->inputContext['text'] ?? ''), (string) ($draft->diagnostics['source_input'] ?? '')];
        foreach ($selected as $id => $claim) if (isset($traceIds[$id])) $support[] = (string) ($claim['text'] ?? $claim['claim_text'] ?? '');
        foreach ($this->sentences($draft->body) as $sentence) {
            $sourceInput = trim((string) ($draft->diagnostics['source_input'] ?? ''));
            if ($sourceInput !== '' && str_contains($sentence, $sourceInput)) continue;
            $factualSentence = (string) (preg_replace('/^(?:Điểm chính của chủ đề|Bối cảnh hữu ích|Điều cần hiểu thêm|Cách nhận biết|Những khác biệt đáng chú ý|Bước tìm hiểu tiếp theo)\s*:\s*/iu', '', $sentence) ?? $sentence);
            if (!$this->looksFactual($factualSentence) || $this->supportedBy($factualSentence, $support)) continue;
            $add('factual_grounding', 'BLOCK', 'UNTRACEABLE_FACTUAL_ASSERTION');
            return;
        }
    }

    /** @param array<string,array<string,mixed>> $selected @param array<string,bool> $traceIds */
    private function informationGain(EditorialContextPack $pack, EditorialDraft $draft, array $selected, array $traceIds): float
    {
        $inputTokens = array_unique($this->tokens((string) ($pack->inputContext['raw_input'] ?? $pack->inputContext['text'] ?? '')));
        $bodyTokens = array_unique($this->tokens($draft->body));
        if ($bodyTokens === []) return 0.0;
        $novel = array_diff($bodyTokens, $inputTokens);
        $claimContribution = 0;
        foreach ($selected as $id => $claim) if (isset($traceIds[$id]) && $this->supportedBy((string) ($claim['text'] ?? ''), [$draft->body])) $claimContribution++;
        $calculated = count($novel) / max(1, count($bodyTokens)) + ($claimContribution > 0 ? 0.35 : 0.0);
        $sentences = $this->sentences($draft->body);
        if ($sentences !== []) $calculated *= count(array_unique($sentences)) / count($sentences);
        $declared = $draft->diagnostics['information_gain'] ?? null;
        if (is_numeric($declared)) $calculated = min($calculated, (float) $declared);
        return min(1.0, $calculated);
    }

    private function hasInternalLanguage(string $copy): bool
    {
        return preg_match('/(?:semantic\s+graph|claim\s+hiện\s+có|claim\s+revision|graph\s+path|evidence\s+eligibility|semantic\s+owner|governance|\bmcp\b|reconciliation\s+diagnostics?|trong\s+bối\s+cảnh\s+tri\s+thức\s+nhk|nguồn\s+tham\s+chiếu\s+cụ\s+thể|không\s+biến\s+cách\s+diễn\s+đạt\s+marketing\s+thành\s+kết\s+luận\s+phổ\s+quát)/iu', $copy) === 1;
    }

    /** @param array<string,string> $fields @return list<array<string,mixed>> */
    private function internalLanguageFindings(array $fields): array
    {
        $pattern = '/(?:semantic\s+graph|claim\s+hiện\s+có|claim\s+revision|graph\s+path|evidence\s+eligibility|semantic\s+owner|governance|\bmcp\b|reconciliation\s+diagnostics?|trong\s+bối\s+cảnh\s+tri\s+thức\s+nhk|nguồn\s+tham\s+chiếu\s+cụ\s+thể|không\s+biến\s+cách\s+diễn\s+đạt\s+marketing\s+thành\s+kết\s+luận\s+phổ\s+quát)/iu';
        $findings = [];
        foreach ($fields as $field => $copy) {
            if (preg_match_all($pattern, $copy, $matches) === false) continue;
            foreach (array_values(array_unique($matches[0] ?? [])) as $span) $findings[] = ['code' => 'PUBLIC_INTERNAL_JARGON_LEAK', 'severity' => 'BLOCK', 'repairability' => 'UNKNOWN', 'rule_id' => 'editorial_quality.internal_language', 'surface' => str_starts_with($field, 'seo.') ? 'SEO' : 'VIDEO_PUBLIC_COPY', 'field' => $field, 'match_kind' => 'INTERNAL_LANGUAGE', 'offending_span' => $span, 'offending_fingerprint' => hash('sha256', $span), 'message' => 'Internal workflow language was detected in public editorial copy.', 'finding_source' => 'EDITORIAL_QUALITY_GATE', 'origin_kind' => 'UNKNOWN', 'origin_component' => self::class, 'origin_role' => 'NONE'];
        }
        return $findings;
    }

    private function packageFingerprint(EditorialDraft $draft, SemanticSeoPlan $seo): string
    {
        return hash('sha256', (string) json_encode(['title' => $draft->title, 'summary' => $draft->summary, 'body' => $draft->body, 'seo_title' => $seo->title, 'seo_description' => $seo->metaDescription], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string,array<string,mixed>> $selected @return array<string,string> */
    private function originFor(string $span, EditorialContextPack $pack, EditorialDraft $draft, array $selected): array
    {
        $contains = static function (string $haystack, string $needle): bool {
            if ($haystack === '' || $needle === '') return false;
            return function_exists('mb_stripos') ? mb_stripos($haystack, $needle) !== false : stripos($haystack, $needle) !== false;
        };
        if ($contains((string) ($pack->inputContext['raw_input'] ?? ''), $span) || $contains((string) ($draft->diagnostics['source_input'] ?? ''), $span)) return ['origin_kind' => 'USER_INPUT', 'origin_component' => 'VideoEditorialScopeNormalizer', 'origin_role' => 'NONE'];
        foreach ($selected as $claim) {
            if (!is_array($claim) || !$contains((string) ($claim['text'] ?? $claim['claim_text'] ?? ''), $span)) continue;
            return ['origin_kind' => 'CANONICAL_KNOWLEDGE', 'origin_component' => 'EditorialKnowledgeSelector', 'origin_role' => trim((string) ($claim['editorial_role'] ?? 'NONE')) ?: 'NONE'];
        }
        return ['origin_kind' => 'UNKNOWN', 'origin_component' => '', 'origin_role' => 'NONE'];
    }

    /** @return list<string> */
    private function tokens(string $value): array
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $value) ?: [];
        return array_values(array_filter($tokens, static fn (string $token): bool => mb_strlen($token) >= 2));
    }

    private function looksFactual(string $sentence): bool
    {
        return preg_match('/(?:\b(?:là|có|được|thuộc|sản xuất|ra đời|nằm|gồm|giúp|dùng|sử dụng)\b|\b\d{2,4}\b|\b(?:mm|cm|năm|phiên bản|cấu hình)\b)/iu', $sentence) === 1;
    }

    /** @param list<string> $sources */
    private function supportedBy(string $sentence, array $sources): bool
    {
        $sentenceTokens = array_values(array_unique($this->tokens($sentence)));
        if ($sentenceTokens === []) return true;
        foreach ($sources as $source) {
            $sourceTokens = array_values(array_unique($this->tokens($source)));
            if ($sourceTokens !== [] && count(array_intersect($sentenceTokens, $sourceTokens)) / count($sentenceTokens) >= 0.45) return true;
        }
        return false;
    }

    /** @return list<string> */
    private function sentences(string $body): array
    {
        return array_values(array_filter(array_map(static fn (string $sentence): string => trim($sentence), preg_split('/(?<=[.!?。！？])\s+/u', $body) ?: [])));
    }
}
