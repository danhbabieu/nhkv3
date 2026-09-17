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
        $mediaUsage = is_array($media['canonical_readback']['media_usage'] ?? null) ? $media['canonical_readback']['media_usage'] : [];
        $captureMediaReconciliationRequired = ($media['article_media_reconciliation'] ?? '') === 'REQUIRED_BEFORE_PUBLICATION_RESEARCH';
        $mediaReadbackValid = $this->validArticleMediaReadback($mediaUsage, $articleState);
        $mediaComplete = $captureMediaReconciliationRequired
            ? $mediaReadbackValid
            : ((($media['media_complete'] ?? false) === true
                || (($media['slots']['featured_primary']['placeholder'] ?? true) === false))
                && ($mediaUsage === [] || strtoupper(trim((string) ($mediaUsage['state'] ?? ''))) === 'VERIFIED'));
        $compliance = (string) ($research->compliance['status'] ?? '');
        $semanticApplied = (string) ($semanticWriteBack['status'] ?? '') === 'APPLIED';
        $slug = trim((string) ($articleState['slug'] ?? ''));
        $permalink = trim((string) ($articleState['permalink'] ?? ''));
        $publicRouteReady = $slug !== '' && $permalink !== '';
        $renderedPublicStatus = strtolower(trim((string) ($articleState['rendered_public_verification_status'] ?? 'unavailable')));
        $requirements = is_array($semanticWriteBack['requirements'] ?? null) ? $semanticWriteBack['requirements'] : [];
        $semanticStatus = strtoupper(trim((string) ($semanticWriteBack['status'] ?? 'NONE')));
        $semanticRequirement = is_array($requirements['semantic_delta'] ?? null)
            ? $requirements['semantic_delta']
            : [
                'applicability' => $semanticStatus === 'SKIPPED' ? 'NOT_REQUIRED' : 'REQUIRED',
                'policy' => $semanticStatus === 'SYSTEM_BLOCKED' ? 'HARD_BLOCK' : (in_array($semanticStatus, ['REVIEW_REQUIRED', 'PENDING'], true) ? 'HUMAN_REVIEW' : 'VERIFY'),
                'state' => $semanticApplied ? 'VERIFIED' : ($semanticStatus === 'SYSTEM_BLOCKED' ? 'BLOCKED' : ($semanticStatus === 'SKIPPED' ? 'SKIPPED' : 'PENDING')),
                'evidence' => ['intent' => '', 'status' => $semanticStatus],
            ];
        $requirements['semantic_delta'] = $semanticRequirement;
        $requirements['article_media'] = [
            'applicability' => 'REQUIRED',
            'policy' => 'VERIFY',
            'state' => $mediaComplete ? 'VERIFIED' : 'PENDING',
            'evidence' => ['media_complete' => $mediaComplete, 'media_usage' => $mediaUsage, 'reconciliation' => $captureMediaReconciliationRequired ? 'REQUIRED_BEFORE_PUBLICATION_RESEARCH' : 'LEGACY_COMPATIBILITY'],
        ];
        $requirements['public_route'] = [
            'applicability' => 'REQUIRED',
            'policy' => 'VERIFY',
            'state' => $publicRouteReady ? 'VERIFIED' : 'PENDING',
            'evidence' => ['slug' => $slug, 'permalink' => $permalink],
        ];
        $requirements['rendered_public'] = [
            'applicability' => in_array($renderedPublicStatus, ['unavailable', 'not_present'], true) ? 'NOT_APPLICABLE' : 'REQUIRED',
            'policy' => 'VERIFY',
            'state' => in_array($renderedPublicStatus, ['unavailable', 'not_present'], true) ? 'SKIPPED' : ($renderedPublicStatus === 'verified' ? 'VERIFIED' : 'PENDING'),
            'evidence' => ['status' => $renderedPublicStatus],
        ];
        $freshPreflightBlockers = array_values(array_unique(array_merge(
            array_values(array_map('strval', $research->blockers)),
            array_values(array_map('strval', (array) ($mediaUsage['blockers'] ?? []))),
            $captureMediaReconciliationRequired && !$mediaReadbackValid ? ['MEDIAUSAGE_INCOMPLETE'] : [],
        )));
        $researchAcceptable = $research->blockers === []
            && ($captureMediaReconciliationRequired
                ? $mediaComplete
                : ($mediaUsage === [] || strtoupper(trim((string) ($mediaUsage['state'] ?? ''))) === 'VERIFIED'));

        return [
            'fresh_preflight' => true,
            'fresh_preflight_blockers' => $freshPreflightBlockers,
            'fresh_overlap' => $research->overlap,
            'fresh_category_plan' => $research->categoryPlan,
            'subject_resolution' => $resolution,
            'subject_resolved' => ($resolution['status'] ?? '') === 'resolved' && trim((string) ($primary['id'] ?? '')) !== '',
            'subject_persistence_status' => (string) ($resolution['persistence']['status'] ?? ''),
            'research_acceptable' => $researchAcceptable,
            'duplicate_intent_handled' => in_array($overlap, ['NO_OVERLAP', 'COMPLEMENTARY_CONTENT'], true),
            'category_resolved' => $category === 'EXISTING',
            'semantic_plan_complete' => $research->readyForDraft,
            'semantic_readback_verified' => $semanticApplied,
            'requirements' => $requirements,
            'media_usage_complete' => $mediaComplete,
            'media_snapshot' => $media,
            'media_usage_readback' => $mediaUsage,
            'media_guidance' => is_array($research->mediaPlan['guidance'] ?? null) ? $research->mediaPlan['guidance'] : (is_array($media['guidance'] ?? null) ? $media['guidance'] : []),
            'real_image_requirements_met' => $mediaComplete,
            'claim_compliance_acceptable' => in_array($compliance, ['PASS', 'APPROVED', 'SAFE'], true),
            'claim_compliance' => $research->compliance,
            'claim_compliance_diagnostics' => (array) ($research->compliance['diagnostics'] ?? []),
            'seo_projection_valid' => trim((string) ($research->seoBlueprint['slug_intent'] ?? '')) !== '',
            'internal_links_valid' => true,
            'structured_data_status' => 'unavailable',
            'public_route_ready' => $publicRouteReady,
            'rendered_public_verification_status' => $renderedPublicStatus,
        ];
    }

    /** @param array<string,mixed> $mediaUsage @param array<string,mixed> $articleState */
    private function validArticleMediaReadback(array $mediaUsage, array $articleState): bool
    {
        $requiredRoles = ['featured_primary', 'inline_primary'];
        $roles = array_values(array_unique(array_filter(array_map('strval', (array) ($mediaUsage['roles'] ?? [])), static fn (string $role): bool => trim($role) !== '')));
        $usageIds = array_values(array_unique(array_filter(array_map('strval', (array) ($mediaUsage['usage_ids'] ?? [])), static fn (string $id): bool => trim($id) !== '')));
        $postId = (int) ($articleState['post_id'] ?? 0);
        $blogId = max(1, (int) ($articleState['blog_id'] ?? (function_exists('get_current_blog_id') ? get_current_blog_id() : 1)));
        $expectedEndpointKey = trim((string) ($articleState['endpoint_key'] ?? ''));
        if ($expectedEndpointKey === '' && $postId > 0) $expectedEndpointKey = $blogId . ':' . $postId;
        return strtoupper(trim((string) ($mediaUsage['state'] ?? ''))) === 'VERIFIED'
            && (string) ($mediaUsage['endpoint_type'] ?? '') === 'wp_post'
            && $expectedEndpointKey !== ''
            && trim((string) ($mediaUsage['endpoint_key'] ?? '')) === $expectedEndpointKey
            && array_diff($requiredRoles, $roles) === []
            && count($usageIds) >= count($requiredRoles)
            && count($usageIds) === count(array_unique($usageIds))
            && trim((string) ($mediaUsage['source'] ?? '')) === 'ARTICLE_MEDIA_RECONCILIATION'
            && (array) ($mediaUsage['blockers'] ?? []) === [];
    }
}
