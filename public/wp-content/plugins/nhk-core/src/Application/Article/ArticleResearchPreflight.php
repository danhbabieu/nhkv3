<?php
declare(strict_types=1);

namespace NHK\Core\Application\Article;

use NHK\Core\Application\Dictionary\DictionaryObservationRegistry;
use NHK\Core\Domain\Article\ArticleResearchResult;

/** Read-only Article research orchestration; injected callbacks are application/repository boundaries. */
final class ArticleResearchPreflight
{
    /** @param callable(array<string,mixed>):array $subjectResolver @param callable(array<string,mixed>):array $inventoryReader @param callable(array<string,mixed>):array $publicEligibility */
    public function __construct(private $subjectResolver, private $inventoryReader, private $publicEligibility, private $dictionaryPlanner = null) {}

    public function research(string $topic, array $subject = [], array $articleContext = []): ArticleResearchResult
    {
        $blockers = [];
        $warnings = [];
        try { $resolution = ($this->subjectResolver)(['topic' => $topic, 'subject' => $subject]); }
        catch (\Throwable $e) { return $this->blocked(['SUBJECT_RESOLUTION_UNAVAILABLE'], ['subject_error' => $e->getMessage()]); }
        $resolution = is_array($resolution) ? $resolution : ['status' => 'unavailable'];
        if (($resolution['status'] ?? '') === 'ambiguous') $blockers[] = 'AMBIGUOUS_SUBJECT';
        elseif (($resolution['status'] ?? '') !== 'resolved' || !is_array($resolution['primary'] ?? null)) $blockers[] = ($resolution['status'] ?? '') === 'unavailable' ? 'SUBJECT_RESOLUTION_UNAVAILABLE' : 'SUBJECT_NOT_FOUND';

        try { $inventory = ($this->inventoryReader)(['topic' => $topic, 'subject_resolution' => $resolution, 'article_context' => $articleContext, 'limit' => 50]); }
        catch (\Throwable $e) { return $this->blocked($blockers === [] ? ['RUNTIME_UNAVAILABLE'] : $blockers, ['status' => 'unavailable', 'reason' => $e->getMessage()]); }
        $inventory = is_array($inventory) ? $inventory : ['status' => 'unavailable'];
        if (($inventory['status'] ?? '') !== 'available') $blockers[] = (string) ($inventory['reason'] ?? 'RUNTIME_UNAVAILABLE');
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
        $overlap = $this->overlap($topic, $primaryId, $posts);
        if (in_array($overlap['classification'], ['LIKELY_DUPLICATE_INTENT', 'EXISTING_CANONICAL_ARTICLE'], true)) $blockers[] = 'EXISTING_ARTICLE_OVERLAP';
        $relations = $this->relations(is_array($inventory['relations'] ?? null) ? $inventory['relations'] : [], $blockers);
        $links = $this->links($relations, is_array($inventory['posts'] ?? null) ? $inventory['posts'] : [], $warnings);
        $media = is_array($inventory['media'] ?? null) ? $inventory['media'] : [];
        $articleMedia = is_array($inventory['article_media'] ?? null) ? $inventory['article_media'] : [];
        $mediaComplete = array_key_exists('media_complete', $articleMedia)
            ? $articleMedia['media_complete'] === true
            : count(array_filter($media, static fn (array $item): bool => ($item['ready'] ?? false) && ($item['public'] ?? false))) > 0;
        if (!$mediaComplete) $warnings[] = 'MEDIA_PLACEHOLDER_OR_UNAVAILABLE';
        $category = $this->categoryPlan(
            array_key_exists('post_id', $articleContext)
                ? (is_array($inventory['current_categories'] ?? null) ? $inventory['current_categories'] : [])
                : (is_array($inventory['categories'] ?? null) ? $inventory['categories'] : [])
        );
        if ($category['status'] === 'CATEGORY_MISSING') $warnings[] = 'CATEGORY_MISSING';
        $this->claimEvidencePolicy(is_array($inventory['knowledge'] ?? null) ? $inventory['knowledge'] : [], $blockers, $warnings);

        $parts = [$topic];
        foreach (['title', 'excerpt', 'body'] as $field) if (is_string($articleContext[$field] ?? null) && trim((string) $articleContext[$field]) !== '') $parts[] = (string) $articleContext[$field];
        $dictionaryContext = ['post_id' => $postId > 0 ? $postId : null, 'subject' => is_array($resolution['primary'] ?? null) ? $resolution['primary'] : null];
        try {
            $planned = is_callable($this->dictionaryPlanner)
                ? ($this->dictionaryPlanner)(implode("\n", array_values(array_unique($parts))), array_merge(['source_kind' => 'ARTICLE'], $dictionaryContext))
                : DictionaryObservationRegistry::preview('ARTICLE', implode("\n", array_values(array_unique($parts))), $dictionaryContext);
            $dictionaryPlan = is_array($planned) ? $planned : ['status' => 'UNAVAILABLE', 'blocking' => false];
        } catch (\Throwable) {
            $dictionaryPlan = ['status' => 'UNAVAILABLE', 'resolved_terms' => [], 'ambiguous_terms' => [], 'candidate_terms' => [], 'internal_link_candidates' => [], 'warnings' => ['DICTIONARY_PLANNING_UNAVAILABLE'], 'blocking' => false];
        }
        foreach ((array) ($dictionaryPlan['warnings'] ?? []) as $warning) if (is_string($warning) && trim($warning) !== '') $warnings[] = $warning;

        $compliance = ['status' => 'HUMAN_REVIEW_REQUIRED', 'warnings' => ['PUBLIC_CLAIMS_REQUIRE_EVIDENCE_SCOPE']];
        $blueprint = ['primary_subject' => $resolution['primary'] ?? null, 'intent' => trim($topic), 'title_intent' => trim($topic), 'h1_intent' => trim($topic), 'slug_intent' => $this->slug($topic), 'meta_description_intent' => trim($topic), 'outline' => [], 'media_complete' => $mediaComplete, 'structured_data_applicable' => true, 'canonical_expectation' => 'PUBLIC_CANONICAL_ROUTE', 'indexability_expectation' => 'INDEXABLE_IF_PUBLISHED'];
        $mediaPlan = ['candidates' => $media, 'media_complete' => $mediaComplete];
        if ($articleMedia !== []) $mediaPlan = array_merge($articleMedia, $mediaPlan, ['media_complete' => $mediaComplete]);
        if (!$mediaComplete && !isset($mediaPlan['diagnostics'])) $mediaPlan['diagnostics'] = [['code' => 'ARTICLE_MEDIA_INLINE_MISSING']];
        return new ArticleResearchResult($resolution, $inventory, $overlap, ['claims' => $inventory['knowledge'] ?? [], 'sources' => $inventory['sources'] ?? [], 'evidence' => $inventory['evidence'] ?? []], $relations, $links, $category, $mediaPlan, ['candidates' => $inventory['videos'] ?? []], $blueprint, $compliance, array_values(array_unique($blockers)), array_values(array_unique($warnings)), $blockers === [], $dictionaryPlan);
    }

    private function blocked(array $blockers, array $inventory): ArticleResearchResult { return new ArticleResearchResult([], $inventory, ['classification' => 'UNCERTAIN'], ['claims' => [], 'sources' => [], 'evidence' => []], [], [], ['status' => 'UNKNOWN'], ['candidates' => [], 'media_complete' => false], ['candidates' => []], [], ['status' => 'UNAVAILABLE'], array_values(array_unique($blockers)), [], false, ['status' => 'UNAVAILABLE', 'resolved_terms' => [], 'ambiguous_terms' => [], 'candidate_terms' => [], 'internal_link_candidates' => [], 'warnings' => ['DICTIONARY_PLANNING_UNAVAILABLE'], 'blocking' => false]); }
    private function overlap(string $topic, string $subjectId, array $posts): array { foreach ($posts as $post) if (in_array($subjectId, (array) ($post['subject_ids'] ?? []), true) && strcasecmp(trim((string) ($post['title'] ?? '')), trim($topic)) === 0) return ['classification' => 'EXISTING_CANONICAL_ARTICLE', 'post' => $post]; foreach ($posts as $post) if (in_array($subjectId, (array) ($post['subject_ids'] ?? []), true)) return ['classification' => 'SUBSTANTIAL_OVERLAP', 'post' => $post]; return ['classification' => $posts === [] ? 'NO_OVERLAP' : 'COMPLEMENTARY_CONTENT', 'post' => null]; }
    private function relations(array $items, array &$blockers): array { $out = []; foreach ($items as $item) { $class = (string) ($item['class'] ?? 'UNSUPPORTED'); if (!in_array($class, ['DIRECT', 'DERIVED', 'PROPOSED_DIRECT', 'EDITORIAL_RELATED', 'AMBIGUOUS', 'UNSUPPORTED'], true)) $class = 'UNSUPPORTED'; if ($class === 'DERIVED' && count((array) ($item['path'] ?? [])) > 2) $class = 'UNSUPPORTED'; if (in_array($class, ['AMBIGUOUS', 'UNSUPPORTED'], true)) $blockers[] = $class . '_RELATION'; $item['classification'] = ['DIRECT' => 'EXISTING_DIRECT', 'DERIVED' => 'EXISTING_DERIVED', 'PROPOSED_DIRECT' => 'PROPOSED_DIRECT', 'EDITORIAL_RELATED' => 'EDITORIAL_RELATED', 'AMBIGUOUS' => 'AMBIGUOUS', 'UNSUPPORTED' => 'UNSUPPORTED'][$class]; $out[] = $item; } return $out; }
    private function links(array $relations, array $posts, array &$warnings): array { $links = []; foreach ($relations as $relation) if (in_array($relation['classification'], ['EXISTING_DIRECT', 'EXISTING_DERIVED'], true)) { try { $eligible = ($this->publicEligibility)($relation); } catch (\Throwable) { $eligible = ['eligible' => false, 'status' => 'unavailable']; } if (($eligible['status'] ?? '') === 'unavailable') { $warnings[] = 'PUBLIC_ROUTE_ELIGIBILITY_UNAVAILABLE'; continue; } if (($eligible['eligible'] ?? false) && trim((string) ($eligible['route'] ?? '')) !== '') $links[] = ['route' => $eligible['route'], 'relation_class' => $relation['classification'], 'reason' => $relation['reason'] ?? 'registered semantic context', 'source' => 'graph', 'path' => $relation['path'] ?? []]; } return $links; }
    private function categoryPlan(array $categories): array { foreach ($categories as $category) if (isset($category['slug'])) return ['status' => 'EXISTING', 'category' => $category, 'current_category' => $category, 'recommendation' => null]; return ['status' => 'CATEGORY_MISSING', 'category' => null, 'current_category' => null, 'recommendation' => 'CREATE_CATEGORY_BEFORE_DRAFT']; }
    private function claimEvidencePolicy(array $claims, array &$blockers, array &$warnings): void { foreach ($claims as $claim) { $status = (string) ($claim['evidence_status'] ?? 'NO_EVIDENCE'); $isNewOrModified = ($claim['new_or_modified'] ?? false) === true || ($claim['legacy'] ?? true) === false; if ($isNewOrModified && $status !== 'SUPPORTED_WITHIN_SCOPE') $blockers[] = 'PUBLIC_CLAIM_EVIDENCE_REQUIRED'; elseif (($claim['legacy'] ?? false) === true && $status !== 'SUPPORTED_WITHIN_SCOPE') $warnings[] = 'LEGACY_EVIDENCE_DEBT'; } }
    private function slug(string $value): string { $value = function_exists('remove_accents') ? remove_accents($value) : $value; return trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower($value)), '-') ?: 'article'; }
}
