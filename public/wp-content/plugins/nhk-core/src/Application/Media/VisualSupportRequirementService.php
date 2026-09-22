<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Contracts\Media\VisualSupportRequirementRepository;
use NHK\Core\Domain\Knowledge\KnowledgeFacetProfile;
use NHK\Core\Domain\Media\VisualSupportRequirement;
use NHK\Core\Domain\Media\{MediaDetailTypeRegistry, VisualSupportIntentRegistry};
use NHK\Core\Shared\Uuid\UuidCodec;

/** Application owner for the durable requirement ledger; not a semantic writer. */
final class VisualSupportRequirementService
{
    public function __construct(private VisualSupportRequirementRepository $requirements) {}

    /** @param array<string,mixed> $context */
    public function require(string $subjectId, string $scope, string $facet, string $featureKey, string $visualIntent, array $context = [], string $subjectType = 'entity'): VisualSupportRequirement
    {
        $candidate = VisualSupportRequirement::create($subjectId, $scope, $facet, $featureKey, $visualIntent, $context, $subjectType);
        $existing = $this->requirements->findBySemanticFingerprint($candidate->semanticFingerprint)
            ?? $this->requirements->findByIdempotencyFingerprint($candidate->idempotencyFingerprint)
            ?? null;
        if ($existing === null) return $this->requirements->save($candidate);
        $merged = $this->mergeContext($existing->context, $context);
        if ($merged === $existing->context) return $existing;
        $updated = new VisualSupportRequirement($existing->canonicalId, $existing->subjectType, $existing->subjectId, $existing->scope, $existing->facet, $existing->featureKey, $existing->visualIntent, $existing->state, $existing->mediaId, $existing->mediaRevision, $merged, $existing->provenance, $existing->unresolvedReason, $existing->semanticFingerprint, $existing->idempotencyFingerprint, $existing->revision);
        return $this->requirements->save($updated, $existing->revision);
    }

    public function get(string $id): ?VisualSupportRequirement
    {
        return $this->requirements->findById($id);
    }

    /** @param array<string,mixed> $context @return array{status:string,requirement:?VisualSupportRequirement,diagnostic:?string} */
    public function tryRequire(string $subjectId, string $scope, string $facet, string $featureKey, string $visualIntent, array $context = [], string $subjectType = 'entity'): array
    {
        $subjectId = trim($subjectId);
        $subjectType = trim($subjectType);
        if (!UuidCodec::isValid($subjectId)) return ['status' => 'REVIEW_REQUIRED', 'requirement' => null, 'diagnostic' => 'VISUAL_SUBJECT_REQUIRED'];
        if ($subjectType === '' || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $subjectType) !== 1) return ['status' => 'REVIEW_REQUIRED', 'requirement' => null, 'diagnostic' => 'VISUAL_SUBJECT_TYPE_INVALID'];
        if (!in_array($scope, KnowledgeFacetProfile::SCOPES, true) || !in_array($facet, KnowledgeFacetProfile::FACETS, true)) return ['status' => 'REVIEW_REQUIRED', 'requirement' => null, 'diagnostic' => 'VISUAL_SCOPE_OR_FACET_INVALID'];
        if (!in_array($featureKey, MediaDetailTypeRegistry::all(), true)) return ['status' => 'REVIEW_REQUIRED', 'requirement' => null, 'diagnostic' => 'VISUAL_FEATURE_KEY_INVALID'];
        if (!in_array($visualIntent, VisualSupportIntentRegistry::all(), true)) return ['status' => 'REVIEW_REQUIRED', 'requirement' => null, 'diagnostic' => 'VISUAL_INTENT_INVALID'];
        try {
            return ['status' => 'PERSISTED', 'requirement' => $this->require($subjectId, $scope, $facet, $featureKey, $visualIntent, $context, $subjectType), 'diagnostic' => null];
        } catch (\InvalidArgumentException $error) {
            $diagnostic = strtoupper(trim($error->getMessage()));
            $diagnostic = preg_replace('/_+$/', '', preg_replace('/[^A-Z0-9_]+/', '_', $diagnostic) ?: 'VISUAL_REQUIREMENT_INVALID') ?: 'VISUAL_REQUIREMENT_INVALID';
            return ['status' => 'REVIEW_REQUIRED', 'requirement' => null, 'diagnostic' => $diagnostic];
        }
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $incoming @return array<string,mixed> */
    private function mergeContext(array $current, array $incoming): array
    {
        $merged = $current;
        $consumers = [];
        foreach ([$current['consumer'] ?? null, ...((array) ($current['consumers'] ?? [])), $incoming['consumer'] ?? null, ...((array) ($incoming['consumers'] ?? []))] as $consumer) {
            if (!is_array($consumer)) continue;
            $key = trim((string) ($consumer['endpoint_type'] ?? $consumer['type'] ?? '')) . ':' . trim((string) ($consumer['endpoint_key'] ?? $consumer['key'] ?? ''));
            if ($key !== ':') $consumers[$key] = $consumer;
        }
        if ($consumers !== []) {
            $merged['consumers'] = array_values($consumers);
            unset($merged['consumer']);
        }
        foreach ($incoming as $key => $value) if ($key !== 'consumer' && $key !== 'consumers' && !array_key_exists($key, $merged)) $merged[$key] = $value;
        return $merged;
    }
}
