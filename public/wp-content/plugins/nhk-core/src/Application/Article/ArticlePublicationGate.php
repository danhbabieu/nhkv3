<?php
declare(strict_types=1);

namespace NHK\Core\Application\Article;

use NHK\Core\Domain\Article\{ArticlePublicationGateResult, EditorialPostState};

/**
 * Operation-level publication boundary. It consumes verified evidence and
 * does not reimplement research, Governance, SEO or public-route policies.
 *
 * @param array<string,mixed> $evidence
 */
final class ArticlePublicationGate
{
    public function check(EditorialPostState $draft, array $evidence, string $expectedStateToken = ''): ArticlePublicationGateResult
    {
        $blockers = [];
        if ($draft->status !== 'draft') $blockers[] = 'EDITORIAL_POST_NOT_DRAFT';
        if ($expectedStateToken === '' || !hash_equals($expectedStateToken, $draft->token)) $blockers[] = 'EDITORIAL_CAS_REQUIRED';
        if ($draft->postId < 1 || $draft->endpointKey === '' || $draft->slug === '' || $draft->permalink === '') $blockers[] = 'CANONICAL_PUBLIC_IDENTITY_INVALID';
        $this->requireTrue($evidence, 'research_acceptable', 'RESEARCH_PREFLIGHT_BLOCKED', $blockers);
        $this->requireTrue($evidence, 'subject_resolved', 'SUBJECT_UNRESOLVED', $blockers);
        $this->requireTrue($evidence, 'duplicate_intent_handled', 'DUPLICATE_INTENT_UNRESOLVED', $blockers);
        $this->requireTrue($evidence, 'category_resolved', 'CATEGORY_UNRESOLVED', $blockers);
        $this->requireTrue($evidence, 'semantic_plan_complete', 'SEMANTIC_PLAN_INCOMPLETE', $blockers);
        $this->requireTrue($evidence, 'semantic_readback_verified', 'SEMANTIC_READBACK_UNVERIFIED', $blockers);
        $this->requireTrue($evidence, 'media_usage_complete', 'MEDIAUSAGE_INCOMPLETE', $blockers);
        $this->requireTrue($evidence, 'real_image_requirements_met', 'REAL_IMAGE_REQUIREMENTS_UNMET', $blockers);
        $this->requireTrue($evidence, 'claim_compliance_acceptable', 'PUBLIC_CLAIM_COMPLIANCE_BLOCKED', $blockers);
        $this->requireTrue($evidence, 'seo_projection_valid', 'SEO_PROJECTION_INVALID', $blockers);
        $this->requireTrue($evidence, 'internal_links_valid', 'INTERNAL_LINKS_INVALID', $blockers);
        $this->requireTrue($evidence, 'structured_data_valid', 'STRUCTURED_DATA_INVALID', $blockers);
        $this->requireTrue($evidence, 'public_route_ready', 'PUBLIC_ROUTE_NOT_READY', $blockers);
        return new ArticlePublicationGateResult($blockers === [], $blockers);
    }

    /** @param array<string,mixed> $evidence @param list<string> $blockers */
    private function requireTrue(array $evidence, string $key, string $reason, array &$blockers): void
    {
        if (($evidence[$key] ?? false) !== true) $blockers[] = $reason;
    }
}
