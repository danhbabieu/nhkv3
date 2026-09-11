<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Contracts\Media\VisualSupportRequirementRepository;
use NHK\Core\Domain\Media\VisualSupportRequirement;

/** Application owner for the durable requirement ledger; not a semantic writer. */
final class VisualSupportRequirementService
{
    public function __construct(private VisualSupportRequirementRepository $requirements) {}

    /** @param array<string,mixed> $context */
    public function require(string $subjectId, string $scope, string $facet, string $featureKey, string $visualIntent, array $context = [], string $subjectType = 'entity'): VisualSupportRequirement
    {
        $candidate = VisualSupportRequirement::create($subjectId, $scope, $facet, $featureKey, $visualIntent, $context, $subjectType);
        return $this->requirements->findBySemanticFingerprint($candidate->semanticFingerprint)
            ?? $this->requirements->findByIdempotencyFingerprint($candidate->idempotencyFingerprint)
            ?? $this->requirements->save($candidate);
    }

    public function get(string $id): ?VisualSupportRequirement
    {
        return $this->requirements->findById($id);
    }
}
