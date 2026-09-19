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
        $warnings = [];
        $missingEnrichments = [];
        $deferredRepairs = [];
        $intent = strtoupper(trim((string) ($evidence['content_intent'] ?? 'TEXT_ARTICLE')));
        if (!in_array($intent, ['TEXT_ARTICLE', 'IMAGE_ARTICLE'], true)) $intent = 'TEXT_ARTICLE';
        if ($draft->status !== 'draft') $blockers[] = 'EDITORIAL_POST_NOT_DRAFT';
        if ($expectedStateToken === '' || !hash_equals($expectedStateToken, $draft->token)) $blockers[] = 'EDITORIAL_CAS_REQUIRED';
        if ($draft->postId < 1 || $draft->endpointKey === '' || $draft->slug === '' || $draft->permalink === '') $blockers[] = 'CANONICAL_PUBLIC_IDENTITY_INVALID';
        $this->requireTrue($evidence, 'research_acceptable', 'RESEARCH_PREFLIGHT_BLOCKED', $blockers);
        $this->requireTrue($evidence, 'subject_resolved', 'SUBJECT_UNRESOLVED', $blockers);
        if (($evidence['subject_persistence_status'] ?? '') === 'unattached_planning_candidate') {
            $this->replaceBlocker($blockers, 'SUBJECT_UNRESOLVED', 'SUBJECT_NOT_PERSISTED');
        }
        $this->requireTrue($evidence, 'duplicate_intent_handled', 'DUPLICATE_INTENT_UNRESOLVED', $blockers);
        $this->requireTrue($evidence, 'category_resolved', 'CATEGORY_UNRESOLVED', $blockers);
        $this->requireTrue($evidence, 'semantic_plan_complete', 'SEMANTIC_PLAN_INCOMPLETE', $blockers);
        $this->requireTrue($evidence, 'semantic_readback_verified', 'SEMANTIC_READBACK_UNVERIFIED', $blockers);
        $mediaSnapshot = is_array($evidence['media_snapshot'] ?? null) ? $evidence['media_snapshot'] : [];
        if ($mediaSnapshot === []) {
            $warnings[] = 'ARTICLE_FEATURED_MEDIA_MISSING';
            $missingEnrichments[] = 'FEATURED_MEDIA';
            if ($intent === 'IMAGE_ARTICLE') $blockers[] = 'IMAGE_ARTICLE_MEDIA_REQUIRED';
        } else {
            $featuredMissing = ($mediaSnapshot['featured_primary']['placeholder'] ?? true) === true;
            $inlineMissing = ($mediaSnapshot['inline_primary']['placeholder'] ?? true) === true;
            if ($featuredMissing) {
                $missingEnrichments[] = 'FEATURED_MEDIA';
                if ($intent === 'IMAGE_ARTICLE') {
                    if (!in_array('MEDIAUSAGE_INCOMPLETE', $blockers, true)) $blockers[] = 'MEDIAUSAGE_INCOMPLETE';
                    $blockers[] = 'ARTICLE_MEDIA_FEATURED_MISSING';
                } else $warnings[] = 'ARTICLE_MEDIA_FEATURED_MISSING';
            } elseif (($evidence['media_usage_complete'] ?? false) !== true) {
                $warnings[] = 'MEDIAUSAGE_INCOMPLETE';
                $missingEnrichments[] = 'MEDIAUSAGE';
            }
            if ($inlineMissing) { $warnings[] = 'ARTICLE_MEDIA_INLINE_MISSING'; $missingEnrichments[] = 'INLINE_MEDIA'; }
        }
        if (($evidence['real_image_requirements_met'] ?? false) !== true) {
            if ($intent === 'IMAGE_ARTICLE' && ($evidence['real_image_requirements_met_status'] ?? '') === 'invalid') $blockers[] = 'REAL_IMAGE_REQUIREMENTS_UNMET';
            else { $warnings[] = 'REAL_IMAGE_INCOMPLETE'; $missingEnrichments[] = 'REAL_IMAGE_SUPPORT'; }
        }
        $this->requireTrue($evidence, 'claim_compliance_acceptable', 'PUBLIC_CLAIM_COMPLIANCE_BLOCKED', $blockers);
        $this->requireTrue($evidence, 'seo_projection_valid', 'SEO_PROJECTION_INVALID', $blockers);
        $this->optionalTrue($evidence, 'internal_links_valid', 'INTERNAL_LINKS_INVALID', 'INTERNAL_LINKS_INCOMPLETE', $blockers, $warnings);
        if (in_array(($evidence['structured_data_status'] ?? ''), ['unavailable', 'incomplete'], true)) $warnings[] = 'STRUCTURED_DATA_INCOMPLETE';
        else $this->requireTrue($evidence, 'structured_data_valid', 'STRUCTURED_DATA_INVALID', $blockers);
        $this->requireTrue($evidence, 'public_route_ready', 'PUBLIC_ROUTE_NOT_READY', $blockers);
        if (($evidence['rendered_public_verification_status'] ?? '') === 'unavailable') $warnings[] = 'RENDERED_PUBLIC_VERIFICATION_UNAVAILABLE';
        else $this->requireTrue($evidence, 'rendered_public_verification', 'RENDERED_PUBLIC_VERIFICATION_UNAVAILABLE', $blockers);
        if (($evidence['media_repair_status'] ?? '') === 'deferred') $deferredRepairs[] = 'MEDIA_ATTACHMENT_BINDING';
        return new ArticlePublicationGateResult($blockers === [], $blockers, $warnings, $missingEnrichments, $deferredRepairs);
    }

    /** @param array<string,mixed> $evidence @param list<string> $blockers */
    private function requireTrue(array $evidence, string $key, string $reason, array &$blockers): void
    {
        if (($evidence[$key] ?? false) !== true) $blockers[] = $reason;
    }

    /** @param list<string> $blockers @param list<string> $warnings */
    private function optionalTrue(array $evidence, string $key, string $hardReason, string $warning, array &$blockers, array &$warnings): void
    {
        if (($evidence[$key] ?? false) === true) return;
        if (($evidence[$key . '_status'] ?? '') === 'invalid') $blockers[] = $hardReason;
        elseif ($warning === 'REAL_IMAGE_INCOMPLETE' && in_array(($evidence[$key . '_status'] ?? ''), ['missing', 'incomplete'], true)) $blockers[] = $warning;
        else $warnings[] = $warning;
    }

    /** @param list<string> $blockers */
    private function replaceBlocker(array &$blockers, string $from, string $to): void
    {
        $index = array_search($from, $blockers, true);
        if ($index !== false) $blockers[$index] = $to;
        else $blockers[] = $to;
    }
}
