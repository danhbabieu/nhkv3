<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Projection;

use NHK\Core\Domain\Knowledge\KnowledgeClaim;

final readonly class ProjectedClaim
{
    public string $displayText;

    /** @param array<string,int> $evidenceSummary */
    public function __construct(
        public KnowledgeClaim $claim,
        public string $category,
        public ClaimProjectionScope $scope,
        public string $status,
        public ?ProjectionContext $context = null,
        string $displayText = '',
        public array $evidenceSummary = ['source_count' => 0, 'evidence_count' => 0],
        public float $score = 0.0,
    ) {
        if (!ClaimProjectionCategory::isValid($category)) throw new \InvalidArgumentException('Projected claim category is invalid.');
        $this->displayText = $displayText !== '' ? $displayText : $claim->claimText;
    }

    /** @return array<string,mixed> */
    public function toArray(bool $public = true): array
    {
        $data = [
            'claim_uuid' => $this->claim->canonicalId,
            'display_text' => $this->displayText !== '' ? $this->displayText : $this->claim->claimText,
            'canonical_subject_uuid' => $this->scope->canonicalSubjectUuid,
            'scope' => $this->scope->scope,
            'graph_distance' => $this->scope->graphDistance,
            'status' => strtolower($this->status),
            'evidence_summary' => $this->evidenceSummary,
        ];
        if ($this->context !== null && $this->scope->scope === ClaimProjectionScope::RELATED) $data['source_context'] = $this->context->toArray(!$public);
        if (!$public) $data['claim_type'] = $this->claim->claimType;
        return $data;
    }
}
