<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Domain\Article\ArticleResearchResult;

/** Converts a fresh Article owner read into publication-gate evidence. */
final class CaptureArticlePreflightHandoff
{
    /** @return array<string,mixed> */
    public function build(ArticleResearchResult $research, array $media, array $semanticWriteBack, array $articleState = []): array
    {
        $resolution = $research->subjectResolution;
        $primary = is_array($resolution['primary'] ?? null) ? $resolution['primary'] : [];
        $overlap = (string) ($research->overlap['classification'] ?? 'UNCERTAIN');
        $category = (string) ($research->categoryPlan['status'] ?? 'UNKNOWN');
        // The coordinator's post-reconciliation/read-back snapshot is the
        // current publication-unit truth. Research inventory is historical
        // planning evidence and must not override a current Capture Media
        // handoff with empty or stale candidates.
        $currentArticleMedia = is_array($media) && $media !== []
            ? $media
            : (is_array($research->inventory['article_media'] ?? null) ? $research->inventory['article_media'] : []);
        $mediaComplete = ($currentArticleMedia['media_complete'] ?? false) === true
            || (($currentArticleMedia['slots']['featured_primary']['placeholder'] ?? $currentArticleMedia['featured_primary']['placeholder'] ?? true) === false);
        $compliance = (string) ($research->compliance['status'] ?? '');
        $semanticStatus = strtoupper(trim((string) ($semanticWriteBack['status'] ?? '')));
        $semanticApplied = in_array($semanticStatus, ['APPLIED', 'REUSED_VERIFIED'], true);
        $semanticNotRequired = in_array($semanticStatus, ['SKIPPED', 'NOT_REQUIRED', 'SKIPPED_UNCHANGED'], true)
            && (array) ($semanticWriteBack['blockers'] ?? []) === []
            && (string) ($resolution['persistence']['status'] ?? '') === 'attached';
        $slug = trim((string) ($articleState['slug'] ?? ''));
        $permalink = trim((string) ($articleState['permalink'] ?? ''));
        $contentIntent = strtoupper(trim((string) ($articleState['content_intent'] ?? 'TEXT_ARTICLE')));

        return [
            'fresh_preflight' => true,
            'content_intent' => $contentIntent,
            'fresh_preflight_blockers' => array_values(array_map('strval', $research->blockers)),
            'fresh_overlap' => $research->overlap,
            'fresh_category_plan' => $research->categoryPlan,
            'subject_resolution' => $resolution,
            'subject_resolved' => ($resolution['status'] ?? '') === 'resolved' && trim((string) ($primary['id'] ?? '')) !== '',
            'subject_persistence_status' => (string) ($resolution['persistence']['status'] ?? ''),
            'research_acceptable' => $research->blockers === [],
            'duplicate_intent_handled' => in_array($overlap, ['NO_OVERLAP', 'COMPLEMENTARY_CONTENT'], true),
            'category_resolved' => $category === 'EXISTING',
            'semantic_plan_complete' => $research->readyForDraft,
            'semantic_readback_verified' => $semanticApplied || $semanticNotRequired,
            'media_usage_complete' => $mediaComplete,
            'media_snapshot' => $currentArticleMedia,
            'media_guidance' => is_array($research->mediaPlan['guidance'] ?? null) ? $research->mediaPlan['guidance'] : (is_array($currentArticleMedia['guidance'] ?? null) ? $currentArticleMedia['guidance'] : []),
            'real_image_requirements_met' => $mediaComplete,
            'claim_compliance_acceptable' => in_array($compliance, ['PASS', 'APPROVED', 'SAFE'], true),
            'claim_compliance' => $research->compliance,
            'claim_compliance_diagnostics' => (array) ($research->compliance['diagnostics'] ?? []),
            'seo_projection_valid' => trim((string) ($research->seoBlueprint['slug_intent'] ?? '')) !== '',
            'internal_links_valid' => true,
            'structured_data_status' => 'unavailable',
            'public_route_ready' => $slug !== '' && $permalink !== '',
            'rendered_public_verification_status' => 'unavailable',
        ];
    }
}
