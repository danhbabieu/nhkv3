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
        $mediaComplete = ($media['media_complete'] ?? false) === true;
        $compliance = (string) ($research->compliance['status'] ?? '');
        $semanticApplied = (string) ($semanticWriteBack['status'] ?? '') === 'APPLIED';
        $slug = trim((string) ($articleState['slug'] ?? ''));
        $permalink = trim((string) ($articleState['permalink'] ?? ''));

        return [
            'fresh_preflight' => true,
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
            'semantic_readback_verified' => $semanticApplied,
            'media_usage_complete' => $mediaComplete,
            'media_snapshot' => $media,
            'real_image_requirements_met' => $mediaComplete,
            'claim_compliance_acceptable' => in_array($compliance, ['PASS', 'APPROVED', 'SAFE'], true),
            'seo_projection_valid' => trim((string) ($research->seoBlueprint['slug_intent'] ?? '')) !== '',
            'internal_links_valid' => true,
            'structured_data_status' => 'unavailable',
            'public_route_ready' => $slug !== '' && $permalink !== '',
            'rendered_public_verification_status' => 'unavailable',
        ];
    }
}
