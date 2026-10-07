<?php
declare(strict_types=1);

namespace NHK\Core\Application\Article;

use NHK\Core\Application\Compliance\PublicClaimCopyPolicy;
use NHK\Core\Application\Dictionary\DictionaryObservationRegistry;
use NHK\Core\Application\Semantic\StructuredSemanticInterpreter;
use NHK\Core\Application\Seo\PublicSeoProjection;
use NHK\Core\Domain\Article\ArticleResearchResult;
use NHK\Core\Domain\Capture\SubjectResolutionPacket;

/** Read-only Article research orchestration; injected callbacks are application/repository boundaries. */
final class ArticleResearchPreflight
{
    /** @param callable(array<string,mixed>):array $subjectResolver @param callable(array<string,mixed>):array $inventoryReader @param callable(array<string,mixed>):array $publicEligibility */
    public function __construct(private $subjectResolver, private $inventoryReader, private $publicEligibility, private $dictionaryPlanner = null, private ?StructuredSemanticInterpreter $interpreter = null) {}

    public function research(string $topic, array $subject = [], array $articleContext = []): ArticleResearchResult
    {
        $started = microtime(true);
        $timings = [];
        $blockers = [];
        $warnings = [];
        $packet = SubjectResolutionPacket::fromArray((array) ($articleContext['subject_resolution_packet'] ?? []));
        try { $resolution = $packet !== null ? $packet->toResolution() : ($this->subjectResolver)(['topic' => $topic, 'subject' => $subject]); }
        catch (\Throwable $e) { return $this->blocked(['SUBJECT_RESOLUTION_UNAVAILABLE'], ['subject_error' => $e->getMessage()]); }
        $timings['subject_resolution_ms'] = $this->elapsed($started);
        $resolution = is_array($resolution) ? $resolution : ['status' => 'unavailable'];
        if (($resolution['status'] ?? '') === 'ambiguous') $blockers[] = 'AMBIGUOUS_SUBJECT';
        elseif (($resolution['status'] ?? '') !== 'resolved' || !is_array($resolution['primary'] ?? null)) $blockers[] = ($resolution['status'] ?? '') === 'unavailable' ? 'SUBJECT_RESOLUTION_UNAVAILABLE' : 'SUBJECT_NOT_FOUND';

        $stage = microtime(true);
        try { $inventory = ($this->inventoryReader)(['topic' => $topic, 'subject_resolution' => $resolution, 'article_context' => $articleContext, 'limit' => 100]); }
        catch (\Throwable $e) { return $this->blocked($blockers === [] ? ['RUNTIME_UNAVAILABLE'] : $blockers, ['status' => 'unavailable', 'reason' => $e->getMessage()]); }
        $timings['inventory_ms'] = $this->elapsed($stage);
        $inventory = is_array($inventory) ? $inventory : ['status' => 'unavailable'];
        if (($inventory['status'] ?? '') !== 'available') $blockers[] = (string) ($inventory['reason'] ?? 'RUNTIME_UNAVAILABLE');
        $subjectIds = [];
        foreach ((array) ($resolution['subjects'] ?? []) as $resolvedSubject) if (is_array($resolvedSubject) && trim((string) ($resolvedSubject['id'] ?? '')) !== '') $subjectIds[] = trim((string) $resolvedSubject['id']);
        if ($subjectIds === [] && is_array($resolution['primary'] ?? null) && trim((string) ($resolution['primary']['id'] ?? '')) !== '') $subjectIds[] = trim((string) $resolution['primary']['id']);
        foreach (['knowledge', 'media', 'videos'] as $branchKey) {
            if (is_array($inventory[$branchKey] ?? null)) $inventory[$branchKey] = $this->branchItems($inventory[$branchKey], $subjectIds);
        }
        $posts = is_array($inventory['posts'] ?? null) ? $inventory['posts'] : [];
        $primaryId = (string) (($resolution['primary']['id'] ?? ''));
        $postId = (int) ($articleContext['post_id'] ?? 0);
        $articlePost = null;
        foreach ($posts as $post) {
            $candidatePostId = (int) preg_replace('/^.*:/', '', (string) ($post['id'] ?? ''));
            if ($postId > 0 && $candidatePostId === $postId) { $articlePost = $post; break; }
        }
        $resolution['persistence'] = [
            'status' => $articlePost !== null && in_array($primaryId, (array) ($articlePost['subject_ids'] ?? []), true) ? 'attached' : 'unattached_planning_candidate',
            'post_id' => $postId > 0 ? $postId : null,
            'subject_id' => $primaryId,
        ];
        $overlap = $this->overlap($topic, $primaryId, $posts, $postId, $articleContext);
        if (($overlap['substantial'] ?? false) === true) $blockers[] = 'EXISTING_ARTICLE_OVERLAP';
        $relations = $this->relations(is_array($inventory['relations'] ?? null) ? $inventory['relations'] : [], $blockers);
        $links = $this->links($relations, is_array($inventory['posts'] ?? null) ? $inventory['posts'] : [], $warnings);
        $media = is_array($inventory['media'] ?? null) ? $inventory['media'] : [];
        $articleMedia = is_array($inventory['article_media'] ?? null) ? $inventory['article_media'] : [];
        $mediaComplete = array_key_exists('media_complete', $articleMedia)
            ? $articleMedia['media_complete'] === true
            : count(array_filter($media, static fn (array $item): bool => ($item['ready'] ?? false) && ($item['public'] ?? false))) > 0;
        if (!$mediaComplete) $warnings[] = 'MEDIA_PLACEHOLDER_OR_UNAVAILABLE';
        $category = $this->categoryPlan(
            array_key_exists('post_id', $articleContext) ? (is_array($inventory['current_categories'] ?? null) ? $inventory['current_categories'] : []) : [],
            is_array($inventory['categories'] ?? null) ? $inventory['categories'] : [],
        );
        if ($category['status'] === 'CATEGORY_MISSING') $warnings[] = 'CATEGORY_MISSING';
        $publicationClaims = $this->publicationClaims(is_array($inventory['knowledge'] ?? null) ? $inventory['knowledge'] : [], $articleContext);
        $this->claimEvidencePolicy($publicationClaims, $blockers, $warnings);

        $parts = [$topic];
        foreach (['title', 'excerpt', 'body'] as $field) if (is_string($articleContext[$field] ?? null) && trim((string) $articleContext[$field]) !== '') $parts[] = (string) $articleContext[$field];
        $structuredPacket = ($this->interpreter ?? new StructuredSemanticInterpreter())->interpret([
            'input_type' => 'ARTICLE',
            'source_identity' => ['source_id' => $postId > 0 ? 'post:' . $postId : 'article:preview'],
            'text' => implode("\n", array_values(array_unique($parts))),
            'subject_resolution' => $resolution,
            'content_intent' => $articleContext['content_intent'] ?? 'TEXT_ARTICLE',
            'metadata' => ['lineage' => $articleContext['lineage'] ?? []],
            'lineage' => is_array($articleContext['lineage'] ?? null) ? $articleContext['lineage'] : [],
        ])->toArray();
        $dictionaryContext = ['post_id' => $postId > 0 ? $postId : null, 'subject' => is_array($resolution['primary'] ?? null) ? $resolution['primary'] : null];
        try {
            $planned = is_callable($this->dictionaryPlanner)
                ? ($this->dictionaryPlanner)(implode("\n", array_values(array_unique($parts))), array_merge(['source_kind' => 'ARTICLE'], $dictionaryContext))
                : DictionaryObservationRegistry::preview('ARTICLE', implode("\n", array_values(array_unique($parts))), $dictionaryContext);
            $dictionaryPlan = is_array($planned) ? $planned : ['status' => 'UNAVAILABLE', 'blocking' => false];
        } catch (\Throwable) {
            $dictionaryPlan = ['status' => 'UNAVAILABLE', 'resolved_terms' => [], 'ambiguous_terms' => [], 'candidate_terms' => [], 'internal_link_candidates' => [], 'warnings' => ['DICTIONARY_PLANNING_UNAVAILABLE'], 'blocking' => false];
        }
        $dictionaryPlan['structured_interpretation'] = $structuredPacket;
        foreach ((array) ($dictionaryPlan['warnings'] ?? []) as $warning) if (is_string($warning) && trim($warning) !== '') $warnings[] = $warning;
        $timings['planning_ms'] = $this->elapsed($started);

        $claimDiagnostics = $this->claimComplianceDiagnostics($publicationClaims, is_array($resolution['primary'] ?? null) ? $resolution['primary'] : []);
        $compliance = $claimDiagnostics === []
            ? ['status' => 'PASS', 'code' => null, 'warnings' => [], 'diagnostics' => [], 'review_required' => false]
            : ['status' => 'HUMAN_REVIEW_REQUIRED', 'code' => 'PUBLIC_CLAIM_COMPLIANCE_BLOCKED', 'warnings' => ['PUBLIC_CLAIMS_REQUIRE_EVIDENCE_SCOPE'], 'diagnostics' => $claimDiagnostics, 'review_required' => true];
        $plannedTitle = trim((string) ($articleContext['planned_title'] ?? $articleContext['title'] ?? $topic));
        $blueprint = ['primary_subject' => $resolution['primary'] ?? null, 'intent' => trim($topic), 'title_intent' => $plannedTitle, 'h1_intent' => $plannedTitle, 'slug_intent' => $this->slug($plannedTitle), 'meta_description_intent' => $plannedTitle, 'outline' => [], 'media_complete' => $mediaComplete, 'structured_data_applicable' => true, 'canonical_expectation' => 'PUBLIC_CANONICAL_ROUTE', 'indexability_expectation' => 'INDEXABLE_IF_PUBLISHED'];
        $mediaPlan = ['candidates' => $media, 'media_complete' => $mediaComplete];
        if ($articleMedia !== []) $mediaPlan = array_merge($articleMedia, $mediaPlan, ['media_complete' => $mediaComplete]);
        if (!isset($mediaPlan['diagnostics'])) {
            $mediaPlan['diagnostics'] = [];
            if (($articleMedia['featured_primary']['placeholder'] ?? false) === true) $mediaPlan['diagnostics'][] = ['code' => 'ARTICLE_MEDIA_FEATURED_MISSING'];
            if (($articleMedia['inline_primary']['placeholder'] ?? false) === true) $mediaPlan['diagnostics'][] = ['code' => 'ARTICLE_MEDIA_INLINE_MISSING'];
        }
        if (!isset($mediaPlan['guidance']) || !is_array($mediaPlan['guidance'])) $mediaPlan['guidance'] = $this->mediaGuidance($articleMedia, $resolution, $mediaComplete, $articleContext);
        $timings['total_ms'] = $this->elapsed($started);
        return new ArticleResearchResult($resolution, $inventory, $overlap, ['claims' => $inventory['knowledge'] ?? [], 'sources' => $inventory['sources'] ?? [], 'evidence' => $inventory['evidence'] ?? []], $relations, $links, $category, $mediaPlan, ['candidates' => $inventory['videos'] ?? []], $blueprint, $compliance, array_values(array_unique($blockers)), array_values(array_unique($warnings)), $blockers === [], $dictionaryPlan, ['timings_ms' => $timings, 'bounds' => ['inventory_limit' => 100, 'relation_limit' => 50, 'evidence_per_claim_limit' => 5]]);
    }

    private function blocked(array $blockers, array $inventory): ArticleResearchResult { return new ArticleResearchResult([], $inventory, ['classification' => 'UNCERTAIN'], ['claims' => [], 'sources' => [], 'evidence' => []], [], [], ['status' => 'UNKNOWN'], ['candidates' => [], 'media_complete' => false], ['candidates' => []], [], ['status' => 'UNAVAILABLE'], array_values(array_unique($blockers)), [], false, ['status' => 'UNAVAILABLE', 'resolved_terms' => [], 'ambiguous_terms' => [], 'candidate_terms' => [], 'internal_link_candidates' => [], 'warnings' => ['DICTIONARY_PLANNING_UNAVAILABLE'], 'blocking' => false], ['timings_ms' => []]); }

    private function elapsed(float $started): int { return (int) round((microtime(true) - $started) * 1000); }
    private function overlap(string $topic, string $subjectId, array $posts, int $currentPostId = 0, array $articleContext = []): array
    {
        $posts = array_values(array_filter($posts, static function (mixed $post) use ($currentPostId): bool {
            if (!is_array($post) || $currentPostId < 1) return is_array($post);
            return (int) preg_replace('/^.*:/', '', (string) ($post['id'] ?? '')) !== $currentPostId;
        }));
        $candidates = [];
        $intent = trim((string) ($articleContext['editorial_intent'] ?? $articleContext['intent'] ?? ''));
        foreach ($posts as $post) {
            if (!is_array($post) || !in_array($subjectId, (array) ($post['subject_ids'] ?? []), true)) continue;
            $candidateIntent = trim((string) ($post['editorial_intent'] ?? $post['intent'] ?? ''));
            $titleScore = $this->tokenSimilarity($topic, (string) ($post['title'] ?? ''));
            $intentMatch = $intent !== '' && $candidateIntent !== '' && $this->phraseKey($intent) === $this->phraseKey($candidateIntent);
            $sameTitle = $this->phraseKey($topic) !== '' && $this->phraseKey($topic) === $this->phraseKey((string) ($post['title'] ?? ''));
            $classification = $sameTitle ? 'EXACT_DUPLICATE' : ($intentMatch ? 'SAME_INTENT' : ($titleScore >= 0.45 ? 'PARTIAL_OVERLAP' : 'SUBJECT_ONLY_OVERLAP'));
            $substantial = in_array($classification, ['EXACT_DUPLICATE', 'SAME_INTENT'], true);
            $matched = ['primary_subject'];
            if ($sameTitle) $matched[] = 'exact_title'; elseif ($titleScore >= 0.45) $matched[] = 'title_similarity';
            if ($intentMatch) $matched[] = 'editorial_intent';
            $id = (int) preg_replace('/^.*:/', '', (string) ($post['id'] ?? ''));
            $candidates[] = [
                'post_id' => $id, 'article_id' => $id, 'canonical_identity' => ['type' => 'wp_post', 'id' => $id],
                'title' => (string) ($post['title'] ?? ''), 'slug' => (string) ($post['slug'] ?? ''), 'route' => (string) ($post['route'] ?? ''),
                'status' => (string) ($post['status'] ?? (($post['published'] ?? false) ? 'publish' : 'draft')),
                'primary_subject' => $subjectId, 'relevant_subjects' => array_values(array_map('strval', (array) ($post['subject_ids'] ?? []))),
                'editorial_intent' => $candidateIntent !== '' ? $candidateIntent : null,
                'overlap_score' => round(max($sameTitle ? 1.0 : 0.0, $intentMatch ? 1.0 : 0.0, $titleScore), 6),
                'matched_dimensions' => $matched, 'classification' => $classification,
                'reason' => $this->overlapReason($classification), 'substantial' => $substantial,
            ];
        }
        $substantialCandidates = array_values(array_filter($candidates, static fn (array $candidate): bool => ($candidate['substantial'] ?? false) === true));
        if ($substantialCandidates !== []) {
            $winnerId = (int) ($substantialCandidates[0]['post_id'] ?? 0);
            $winner = array_values(array_filter($posts, static fn (array $post): bool => (int) preg_replace('/^.*:/', '', (string) ($post['id'] ?? '')) === $winnerId))[0] ?? null;
            return ['classification' => 'SUBSTANTIAL_OVERLAP', 'substantial' => true, 'post' => $winner, 'candidates' => $candidates, 'candidate_articles' => $substantialCandidates, 'reason' => 'A candidate matches the same editorial intent or exact title.'];
        }
        return ['classification' => $candidates === [] ? ($posts === [] ? 'NO_OVERLAP' : 'COMPLEMENTARY_CONTENT') : 'COMPLEMENTARY_CONTENT', 'substantial' => false, 'post' => null, 'candidates' => $candidates, 'candidate_articles' => [], 'reason' => $candidates === [] ? 'No current Article is bound to the primary subject.' : 'Subject overlap without proof of the same editorial intent is not substantial.'];
    }
    private function phraseKey(string $value): string { $value = function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value)); $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value; return trim(preg_replace('/\s+/u', ' ', $value) ?? $value); }
    private function tokenSimilarity(string $left, string $right): float { $a = array_values(array_unique(array_filter(explode(' ', $this->phraseKey($left))))); $b = array_values(array_unique(array_filter(explode(' ', $this->phraseKey($right))))); if ($a === [] || $b === []) return 0.0; return count(array_intersect($a, $b)) / max(1, min(count($a), count($b))); }
    private function overlapReason(string $classification): string { return match ($classification) { 'EXACT_DUPLICATE' => 'Same canonical subject and exact editorial title.', 'SAME_INTENT' => 'Same canonical subject and persisted editorial intent.', 'PARTIAL_OVERLAP' => 'Same canonical subject with partial title/topic overlap; intent is not proven identical.', default => 'Same canonical subject only; no same-intent signal was persisted.' }; }
    private function relations(array $items, array &$blockers): array { $out = []; foreach ($items as $item) { $class = (string) ($item['class'] ?? 'UNSUPPORTED'); if (!in_array($class, ['DIRECT', 'DERIVED', 'PROPOSED_DIRECT', 'EDITORIAL_RELATED', 'AMBIGUOUS', 'UNSUPPORTED'], true)) $class = 'UNSUPPORTED'; if ($class === 'DERIVED' && count((array) ($item['path'] ?? [])) > 2) $class = 'UNSUPPORTED'; if (in_array($class, ['AMBIGUOUS', 'UNSUPPORTED'], true)) $blockers[] = $class . '_RELATION'; $item['classification'] = ['DIRECT' => 'EXISTING_DIRECT', 'DERIVED' => 'EXISTING_DERIVED', 'PROPOSED_DIRECT' => 'PROPOSED_DIRECT', 'EDITORIAL_RELATED' => 'EDITORIAL_RELATED', 'AMBIGUOUS' => 'AMBIGUOUS', 'UNSUPPORTED' => 'UNSUPPORTED'][$class]; $out[] = $item; } return $out; }
    private function links(array $relations, array $posts, array &$warnings): array { $links = []; foreach ($relations as $relation) if (in_array($relation['classification'], ['EXISTING_DIRECT', 'EXISTING_DERIVED'], true)) { try { $eligible = ($this->publicEligibility)($relation); } catch (\Throwable) { $eligible = ['eligible' => false, 'status' => 'unavailable']; } if (($eligible['status'] ?? '') === 'unavailable') { $warnings[] = 'PUBLIC_ROUTE_ELIGIBILITY_UNAVAILABLE'; continue; } if (($eligible['eligible'] ?? false) && trim((string) ($eligible['route'] ?? '')) !== '') $links[] = ['route' => $eligible['route'], 'relation_class' => $relation['classification'], 'reason' => $relation['reason'] ?? 'registered semantic context', 'source' => 'graph', 'path' => $relation['path'] ?? []]; } return $links; }
    private function categoryPlan(array $currentCategories, array $availableCategories = []): array
    {
        $current = array_values(array_filter($currentCategories, static fn (mixed $category): bool => is_array($category) && trim((string) ($category['slug'] ?? '')) !== ''));
        $available = array_values(array_filter($availableCategories, static fn (mixed $category): bool => is_array($category) && trim((string) ($category['slug'] ?? '')) !== ''));
        $valid = [];
        foreach (array_merge($current, $available) as $category) {
            $key = trim((string) ($category['id'] ?? $category['slug'] ?? $category['name'] ?? ''));
            if ($key !== '') $valid[$key] = $category;
        }
        $valid = array_values($valid);
        if ($valid === []) return ['status' => 'CATEGORY_MISSING', 'category' => null, 'current_category' => null, 'persisted_native_categories' => $current, 'desired_category' => null, 'recommendation' => 'CREATE_CATEGORY_BEFORE_DRAFT'];
        $currentUsable = array_values(array_filter($current, fn (array $category): bool => !$this->isDefaultCategory($category)));
        foreach ($currentUsable as $category) {
            if ($this->isPreferredCategory($category)) return ['status' => 'EXISTING', 'category' => $category, 'current_category' => $category, 'persisted_native_categories' => $current, 'desired_category' => $category, 'recommendation' => null];
        }
        foreach ($currentUsable as $category) return ['status' => 'EXISTING', 'category' => $category, 'current_category' => $category, 'persisted_native_categories' => $current, 'desired_category' => $category, 'recommendation' => null];
        foreach ($valid as $category) {
            $slug = strtolower(trim((string) ($category['slug'] ?? '')));
            $name = trim((string) ($category['name'] ?? ''));
            if ($this->isPreferredCategory($category)) return ['status' => 'EXISTING', 'category' => $category, 'current_category' => $current[0] ?? null, 'persisted_native_categories' => $current, 'desired_category' => $category, 'previous_category' => $current[0] ?? null, 'recommendation' => null];
        }
        foreach ($valid as $category) if (!$this->isDefaultCategory($category)) return ['status' => 'EXISTING', 'category' => $category, 'current_category' => $current[0] ?? null, 'persisted_native_categories' => $current, 'desired_category' => $category, 'previous_category' => $current[0] ?? null, 'recommendation' => null];
        return ['status' => 'EXISTING', 'category' => $valid[0], 'current_category' => $current[0] ?? null, 'persisted_native_categories' => $current, 'desired_category' => $valid[0], 'recommendation' => null];
    }
    private function isPreferredCategory(array $category): bool { return in_array(strtolower(trim((string) ($category['slug'] ?? ''))), ['tri-thuc-dong-ho', 'tri-thuc'], true) || in_array(trim((string) ($category['name'] ?? '')), ['Tri thức đồng hồ', 'Tri thức'], true); }
    private function isDefaultCategory(array $category): bool { return ($category['is_default'] ?? false) === true || in_array(strtolower(trim((string) ($category['slug'] ?? ''))), ['uncategorized', 'chua-phan-loai'], true); }
    /** @param list<array<string,mixed>> $claims @param list<string> $blockers @param list<string> $warnings */
    private function claimEvidencePolicy(array $claims, array &$blockers, array &$warnings): void
    {
        foreach ($claims as $claim) {
            $status = (string) ($claim['evidence_status'] ?? 'NO_EVIDENCE');
            $isNewOrModified = ($claim['new_or_modified'] ?? false) === true || ($claim['legacy'] ?? true) === false;
            if ($isNewOrModified && $status !== 'SUPPORTED_WITHIN_SCOPE') $blockers[] = 'PUBLIC_CLAIM_EVIDENCE_REQUIRED';
            elseif (($claim['legacy'] ?? false) === true && $status !== 'SUPPORTED_WITHIN_SCOPE') $warnings[] = 'LEGACY_EVIDENCE_DEBT';
        }
    }

    /** @return list<array<string,mixed>> */
    private function publicationClaims(array $claims, array $articleContext): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', (array) ($articleContext['selected_claim_ids'] ?? [])), static fn (string $id): bool => trim($id) !== '')));
        foreach ((array) ($articleContext['claim_trace'] ?? []) as $trace) if (is_array($trace)) {
            $id = trim((string) ($trace['claim_id'] ?? $trace['id'] ?? ''));
            if ($id !== '') $ids[] = $id;
        }
        $ids = array_fill_keys(array_values(array_unique($ids)), true);
        $copy = implode("\n", array_filter(array_map(static fn (string $field): string => trim((string) ($articleContext[$field] ?? '')), ['title', 'excerpt', 'body'])));
        return array_values(array_filter($claims, static function (array $claim) use ($ids, $copy): bool {
            $id = (string) ($claim['claim_id'] ?? $claim['id'] ?? '');
            if (isset($ids[$id])) return true;
            $text = trim((string) ($claim['claim_text'] ?? $claim['text'] ?? ''));
            return $text !== '' && $copy !== '' && (function_exists('mb_stripos') ? mb_stripos($copy, $text) : stripos($copy, $text)) !== false;
        }));
    }

    /** @param list<array<string,mixed>> $claims @param array<string,mixed> $subject @return list<array<string,mixed>> */
    private function claimComplianceDiagnostics(array $claims, array $subject): array
    {
        $copy = new PublicClaimCopyPolicy();
        $subjectName = trim((string) ($subject['name'] ?? $subject['canonical_name'] ?? ''));
        $subjectScope = trim((string) ($subject['type'] ?? 'entity')) ?: 'entity';
        $diagnostics = [];
        foreach ($claims as $claim) {
            if (!is_array($claim)) continue;
            $text = trim((string) ($claim['claim_text'] ?? $claim['text'] ?? ''));
            if ($text === '') continue;
            $class = trim((string) ($claim['claim_class'] ?? ''));
            if ($class === '') $class = $copy->containsUnsupportedSuperiority($text) ? 'superiority/uniqueness/absolute' : (trim((string) ($claim['claim_type'] ?? '')) ?: 'descriptive/editorial');
            $scope = trim((string) ($claim['scope'] ?? $claim['semantic_scope'] ?? $subjectScope)) ?: $subjectScope;
            $evidenceStatus = trim((string) ($claim['evidence_status'] ?? 'NO_EVIDENCE')) ?: 'NO_EVIDENCE';
            $evidenceExists = $evidenceStatus === 'SUPPORTED_WITHIN_SCOPE' || (array) ($claim['evidence'] ?? $claim['evidence_refs'] ?? []) !== [];
            $requiresReview = !$evidenceExists || str_contains($class, 'superiority') || str_contains($class, 'uniqueness') || str_contains($class, 'absolute');
            $narrowable = $copy->containsUnsupportedSuperiority($text) && $subjectName !== '';
            $diagnostics[] = [
                'claim_id' => $claim['claim_id'] ?? $claim['id'] ?? null,
                'claim_text' => $text,
                'claim_class' => $class,
                'scope' => $scope,
                'semantic_subject' => ['id' => $subject['id'] ?? null, 'type' => $subjectScope, 'name' => $subjectName !== '' ? $subjectName : null],
                'reason' => $evidenceExists ? 'Claim requires scope review before public publication.' : 'No canonical Evidence is available for this claim within its semantic scope.',
                'evidence_status' => $evidenceStatus,
                'canonical_evidence_exists' => $evidenceExists,
                'can_safely_narrow' => $narrowable,
                'suggested_rewrite' => $narrowable ? 'Theo nguồn tham chiếu, bản ghi này mô tả ' . $subjectName . ' với những đặc điểm được ghi nhận riêng trong nguồn; không suy rộng thành kết luận chung.' : null,
                'review_required' => $requiresReview,
            ];
        }
        return $diagnostics;
    }

    /** @param array<string,mixed> $articleMedia @param array<string,mixed> $resolution @return array<string,mixed> */
    private function mediaGuidance(array $articleMedia, array $resolution, bool $complete, array $articleContext = []): array
    {
        $slots = is_array($articleMedia['slots'] ?? null) ? $articleMedia['slots'] : [];
        $featuredMissing = ($slots['featured_primary']['placeholder'] ?? !$complete) === true;
        $inlineMissing = ($slots['inline_primary']['placeholder'] ?? !$complete) === true;
        $primary = is_array($resolution['primary'] ?? null) ? $resolution['primary'] : [];
        $intent = is_array($articleContext['content_intent'] ?? null) ? (string) ($articleContext['content_intent']['intent'] ?? 'TEXT_ARTICLE') : (string) ($articleContext['content_intent'] ?? 'TEXT_ARTICLE');
        $required = strtoupper(trim($intent)) === 'IMAGE_ARTICLE' && $featuredMissing;
        return [
            'user_message' => $featuredMissing ? 'Bài đã đủ nội dung nhưng còn thiếu ảnh đại diện. Bạn có muốn tải ảnh đại diện cho bài này không?' : ($inlineMissing ? 'Bài còn thiếu ảnh minh họa trong nội dung. Bạn có muốn tải ảnh cho bài này không?' : 'Hình ảnh của bài đã sẵn sàng.'),
            'featured_image_missing' => $featuredMissing,
            'inline_image_missing' => $inlineMissing,
            'expected_subject' => $primary['name'] ?? null,
            'preferred_view' => null,
            'preferred_aspect' => '16:9',
            'video_thumbnail_fallback' => null,
            'user_upload_preferred' => $featuredMissing,
            'user_upload_required' => $required,
            'upload_required' => $required,
        ];
    }
    /** @param list<array<string,mixed>> $items @param list<string> $subjectIds @return list<array<string,mixed>> */
    private function branchItems(array $items, array $subjectIds): array
    {
        $scoped = false;
        foreach ($items as $item) if (is_array($item) && (array_key_exists('subject_id', $item) || array_key_exists('subject_ids', $item))) { $scoped = true; break; }
        if (!$scoped || $subjectIds === []) return $items;
        return array_values(array_filter($items, static function (mixed $item) use ($subjectIds): bool {
            if (!is_array($item)) return false;
            $ids = array_key_exists('subject_ids', $item) ? (array) $item['subject_ids'] : [(string) ($item['subject_id'] ?? '')];
            return array_intersect(array_map('strval', $ids), $subjectIds) !== [];
        }));
    }
    private function slug(string $value): string
    {
        if (function_exists('remove_accents')) $value = remove_accents($value);
        elseif (function_exists('transliterator_transliterate')) $value = (string) transliterator_transliterate('Any-Latin; Latin-ASCII', $value);
        elseif (function_exists('iconv')) $value = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower($value)), '-') ?: 'article';
    }
}
