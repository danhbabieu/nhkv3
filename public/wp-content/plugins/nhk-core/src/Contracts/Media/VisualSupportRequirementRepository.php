<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Media;

use NHK\Core\Domain\Media\VisualSupportRequirement;

interface VisualSupportRequirementRepository
{
    public function save(VisualSupportRequirement $requirement, int $expectedRevision = 0): VisualSupportRequirement;
    public function findById(string $id): ?VisualSupportRequirement;
    public function findBySemanticFingerprint(string $fingerprint): ?VisualSupportRequirement;
    public function findByIdempotencyFingerprint(string $fingerprint): ?VisualSupportRequirement;
    /** @return list<VisualSupportRequirement> */
    public function findCandidatesForMedia(array $context, int $limit = 100): array;
    /** @return list<VisualSupportRequirement> */
    public function findReplacementCandidatesForMedia(array $context, int $limit = 100): array;
    /** @return list<VisualSupportRequirement> */
    public function listForAdmin(array $filters = [], int $limit = 100): array;
}
