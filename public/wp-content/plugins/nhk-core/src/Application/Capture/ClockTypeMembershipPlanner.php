<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Application\Entity\{EntityProfileResolver, EntityProfileResolution};
use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Domain\Graph\NodeReference;
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Converts an eligible shadow result into a revision-bound Governance input.
 * This class has no semantic writer dependency and never changes canonical data.
 */
final class ClockTypeMembershipPlanner
{
    private const SOURCES = ['model', 'variant', 'specimen', 'product'];
    private const PROVENANCE = ['EXPLICIT_USER_KNOWLEDGE', 'CATALOG_SUPPORTED', 'OBSERVED_FROM_MEDIA', 'EXTERNAL_RESEARCH'];

    public function __construct(
        private AuthorityRepository $authority,
        private GraphService $graph,
        private EntityProfileResolver $profiles = new EntityProfileResolver(),
    ) {}

    /** @param array<string,mixed> $source @param array<string,mixed> $context */
    public function plan(ClockTypeShadowResolution $shadow, array $source, array $context = []): ClockTypeMembershipPlanningResult
    {
        if ($shadow->status === ClockTypeShadowResolution::NONE) return new ClockTypeMembershipPlanningResult(ClockTypeMembershipPlanningResult::NONE, diagnostics: ['NO_CLOCK_TYPE_CANDIDATE']);
        if ($shadow->status === ClockTypeShadowResolution::UNAVAILABLE) return $this->blocked('UNAVAILABLE', 'SHADOW_RESOLUTION_UNAVAILABLE');
        if ($shadow->status === ClockTypeShadowResolution::AMBIGUOUS) return $this->blocked('REVIEW_REQUIRED', 'AMBIGUOUS_CLOCK_TYPE_CANDIDATES');
        if ($shadow->status === ClockTypeShadowResolution::REVIEW_CANDIDATE) return $this->blocked('REVIEW_REQUIRED', 'WEAK_OR_REVIEW_ONLY_CANDIDATE');
        if ($shadow->status === ClockTypeShadowResolution::DATA_COMPATIBILITY_GAP) return $this->blocked('DATA_COMPATIBILITY_GAP', 'LEGACY_CLOCK_TYPE_FAMILY_NOT_WRITEABLE');
        if (!in_array($shadow->status, [ClockTypeShadowResolution::RESOLVED_EXPLICIT, ClockTypeShadowResolution::RESOLVED_CANONICAL], true) || count($shadow->candidates) !== 1) return $this->blocked('REVIEW_REQUIRED', 'SHADOW_STATUS_NOT_WRITE_QUALIFIED');

        $sourceType = strtolower(trim((string) ($source['type'] ?? $source['entity_type'] ?? '')));
        $sourceUuid = trim((string) ($source['id'] ?? $source['canonical_id'] ?? ''));
        if (!in_array($sourceType, self::SOURCES, true)) return $this->blocked('SOURCE_TYPE_NOT_ALLOWED', 'CLASSIFICATION_SCOPE_UNSUPPORTED');
        if (!UuidCodec::isValid($sourceUuid)) return $this->blocked('SOURCE_ID_INVALID', 'CANONICAL_SOURCE_REQUIRED');
        $sourceEntity = $this->authority->findByCanonicalId($sourceUuid);
        if (!$sourceEntity instanceof AuthorityEntity || !$sourceEntity->active() || $sourceEntity->entityType !== $sourceType) return $this->blocked('SOURCE_NOT_ACTIVE_CANONICAL', 'SOURCE_CANONICAL_NOT_AVAILABLE');
        $sourceRevision = (int) ($source['revision'] ?? $sourceEntity->revision);
        if ($sourceRevision < 1 || $sourceRevision !== $sourceEntity->revision) return $this->blocked('SOURCE_REVISION_STALE', 'SOURCE_REVISION_CHANGED');

        $shadowCandidate = $shadow->candidates[0];
        $target = $this->authority->findByCanonicalId($shadowCandidate->classificationUuid);
        if (!$target instanceof AuthorityEntity || !$target->active() || $target->entityType !== 'classification') return $this->blocked('TARGET_NOT_ACTIVE_CANONICAL', 'TARGET_CANONICAL_NOT_AVAILABLE');
        if ($shadowCandidate->revision < 1 || $shadowCandidate->revision !== $target->revision) return $this->blocked('TARGET_REVISION_STALE', 'TARGET_REVISION_CHANGED');
        $profile = $this->profiles->resolveProfile($target);
        if ($profile->status === EntityProfileResolution::COMPATIBILITY_READ) return $this->blocked('DATA_COMPATIBILITY_GAP', 'LEGACY_CLOCK_TYPE_FAMILY_NOT_WRITEABLE');
        if ($profile->status !== EntityProfileResolution::RESOLVED || $profile->profileKey !== 'clock_type' || ($target->payload['family'] ?? null) !== 'clock_type') return $this->blocked('TARGET_FAMILY_INVALID', 'TARGET_FAMILY_NOT_CLOCK_TYPE');

        $existing = $this->graph->findEdge(new NodeReference($sourceType, $sourceUuid), 'classified_as', new NodeReference('classification', $target->canonicalId));
        if ($existing !== null) return $existing->isActive()
            ? new ClockTypeMembershipPlanningResult(ClockTypeMembershipPlanningResult::ALREADY_CANONICAL, diagnostics: ['ACTIVE_CLASSIFIED_AS_ALREADY_EXISTS'])
            : $this->blocked('RELATION_RETIRED_REQUIRES_GOVERNED_REVIEW', 'RETIRED_RELATION_NOT_RESURRECTED');
        if ($shadow->status === ClockTypeShadowResolution::RESOLVED_CANONICAL) return $this->blocked('CANONICAL_MEMBERSHIP_MISMATCH', 'CANONICAL_SHADOW_WITHOUT_ACTIVE_EDGE');

        $provenance = strtoupper(trim((string) ($context['provenance_class'] ?? 'EXPLICIT_USER_KNOWLEDGE')));
        if (!in_array($provenance, self::PROVENANCE, true)) return $this->blocked('PROVENANCE_UNSUPPORTED', 'EVIDENCE_REQUIREMENT_UNSATISFIED');
        $support = trim((string) ($context['support_status'] ?? 'NOT_PROVIDED')) ?: 'NOT_PROVIDED';
        $candidateId = 'clock-type-membership:' . hash('sha256', implode('|', [$sourceType, $sourceUuid, $target->canonicalId]));
        $candidate = new ClockTypeMembershipCandidate(
            $candidateId,
            $sourceType,
            $sourceUuid,
            $sourceRevision,
            $target->canonicalId,
            $target->revision,
            'clock_type',
            $shadowCandidate->resolutionBasis,
            $provenance,
            $support,
            isset($context['capture_id']) ? (string) $context['capture_id'] : null,
            isset($context['capture_revision']) ? (int) $context['capture_revision'] : null,
        );
        return new ClockTypeMembershipPlanningResult(ClockTypeMembershipPlanningResult::QUALIFIED, $candidate);
    }

    private function blocked(string $status, string ...$blockers): ClockTypeMembershipPlanningResult
    {
        return new ClockTypeMembershipPlanningResult($status === 'REVIEW_REQUIRED' ? $status : ClockTypeMembershipPlanningResult::BLOCKED, blockers: $blockers, diagnostics: $status === 'REVIEW_REQUIRED' ? ['REVIEW_REQUIRED'] : [$status]);
    }
}
