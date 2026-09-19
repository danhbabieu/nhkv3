<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/**
 * Separates a valid canonical claim from a claim that is suitable for public
 * editorial prose.  Knowledge validity alone never grants prose eligibility.
 */
final class EditorialProjectionEligibility
{
    /** @param array<string,mixed> $claim @return array{eligible:bool,reason:string} */
    public function evaluate(array $claim, array $context = []): array
    {
        if (($claim['editorial_eligible'] ?? true) !== true) return ['eligible' => false, 'reason' => 'EDITORIAL_PROJECTION_INELIGIBLE'];
        if (($claim['subject_applicable'] ?? true) !== true) return ['eligible' => false, 'reason' => 'SUBJECT_NOT_APPLICABLE'];
        if (($claim['editorial_relevance'] ?? true) !== true) return ['eligible' => false, 'reason' => 'EDITORIAL_RELEVANCE_MISSING'];

        $class = strtoupper(trim((string) ($claim['projection_class'] ?? $claim['claim_class'] ?? '')));
        if (in_array($class, ['EVIDENCE_PROVENANCE', 'INTERNAL_DIAGNOSTIC', 'NON_SEMANTIC_CONTEXT'], true)) return ['eligible' => false, 'reason' => 'EDITORIAL_PROJECTION_INELIGIBLE'];
        if ($class === 'EDITORIAL_CANDIDATE' && ($claim['editorial_relevance'] ?? true) !== true) return ['eligible' => false, 'reason' => 'EDITORIAL_RELEVANCE_MISSING'];

        $claimType = strtolower(trim((string) ($claim['claim_type'] ?? '')));
        if (in_array($claimType, ['provenance', 'evidence', 'source_context'], true) && ($claim['editorial_relevance'] ?? false) !== true) return ['eligible' => false, 'reason' => 'PROVENANCE_NOT_EDITORIAL_PROSE'];

        $evidenceStatus = strtoupper(trim((string) ($claim['evidence_status'] ?? '')));
        if ($evidenceStatus !== '' && !in_array($evidenceStatus, ['SUPPORTED_WITHIN_SCOPE', 'VERIFIED', 'APPROVED'], true)) return ['eligible' => false, 'reason' => 'EVIDENCE_STATUS_NOT_PUBLIC'];
        if (($claim['public_prose_suitable'] ?? true) !== true) return ['eligible' => false, 'reason' => 'PUBLIC_PROSE_UNSUITABLE'];
        return ['eligible' => true, 'reason' => 'SUPPORTED_EDITORIAL_CANDIDATE'];
    }
}
