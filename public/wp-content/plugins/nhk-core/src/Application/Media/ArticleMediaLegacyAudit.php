<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Contracts\Media\{ArticleMediaBlueprintRepository, ArticleMediaUsageInventory, MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Domain\Media\{Media, MediaUsage, MediaUsageRoleRegistry};

/** Read-only, resumable audit of historical automatic Article media Usage. */
final class ArticleMediaLegacyAudit
{
    public function __construct(
        private ArticleMediaUsageInventory $inventory,
        private MediaRepository $media,
        private MediaAssetRepository $assets,
        private MediaUsageRepository $usages,
        private ArticleMediaBlueprintRepository $blueprints,
        private ArticleMediaCandidateSelector $selector,
        private SemanticSuitabilityPolicy $policy,
    ) {}

    /** @return array{status:string,next_cursor:?string,findings:list<array<string,mixed>>} */
    public function audit(string $cursor = '', int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $page = $this->inventory->page('wp_post', trim($cursor) !== '' ? trim($cursor) : null, $limit);
        $findings = [];
        foreach ((array) ($page['items'] ?? []) as $usage) {
            if (!$usage instanceof MediaUsage || !in_array($usage->role, MediaUsageRoleRegistry::mandatoryArticleRoles(), true)) continue;
            $findings[] = $this->auditUsage($usage);
        }
        return ['status' => 'DRY_RUN', 'next_cursor' => isset($page['next_cursor']) && $page['next_cursor'] !== null ? (string) $page['next_cursor'] : null, 'findings' => $findings];
    }

    /** Re-evaluate one current Usage without creating a repair or Proposal. */
    public function auditUsage(MediaUsage $usage): array
    {
        $media = $this->media->findByCanonicalId($usage->mediaId);
        if ($usage->activeSlot === 'retired') return $this->finding($usage, $media, 'RETIRED_USAGE', 'INFORMATIONAL', null, '', null, 'REVIEW_REQUIRED');
        if (strtoupper(trim($usage->selectionSource)) === 'USER_EXPLICIT' && strtoupper(trim($usage->selectionPolicy)) === 'PINNED') {
            return $this->finding($usage, $media, 'EXPLICIT_PINNED_PROTECTED', 'PROTECTED', null, '', null, 'PROTECT_EXPLICIT');
        }

        $postId = $this->postId($usage->endpointKey);
        $blueprint = $postId > 0 ? $this->blueprints->findByPostAndSlot($postId, $usage->role) : null;
        $subjectContext = $blueprint?->subjectContext ?? [];
        $subjectIds = $this->subjectIds($subjectContext);
        $subjectRevision = trim((string) ($subjectContext['subject_revision'] ?? $subjectContext['canonical_subject_revision'] ?? ''));
        $subjectId = $this->subjectId($subjectContext, $subjectIds);
        $subjectType = trim((string) ($subjectContext['subject_type'] ?? $subjectContext['canonical_subject_type'] ?? $subjectContext['entity_type'] ?? ''));

        if ($subjectIds === []) return $this->finding($usage, $media, 'MISSING_SUBJECT_SCOPE', 'REVIEW_REQUIRED', null, $subjectRevision, null, 'MISSING_SUBJECT_BINDING', $subjectType, $subjectId, $blueprint?->revision);

        $assessment = $media instanceof Media
            ? $this->policy->evaluateMedia($media, $this->assets->listByMediaId($media->canonicalId), ['subject_ids' => $subjectIds, 'subject_revision' => $subjectRevision], 'SYSTEM_AUTO', $usage->role)
            : ['suitability' => SemanticSuitabilityPolicy::INELIGIBLE, 'basis' => 'media_not_found', 'semantic_tier' => 'UNAVAILABLE', 'diagnostic' => 'MEDIA_NOT_FOUND', 'score_components' => []];
        if ($media instanceof Media && ($assessment['valid_for_completeness'] ?? false) === true) {
            return $this->finding($usage, $media, 'VALID_SYSTEM_AUTO', 'VALID', null, $subjectRevision, null, 'VALID', $subjectType, $subjectId, $blueprint?->revision, $assessment, $subjectIds);
        }

        $reason = $media === null
            ? 'MEDIA_NOT_FOUND'
            : (!$media->active ? 'MEDIA_INACTIVE' : ($media->readiness !== 'ready' ? 'MEDIA_NOT_READY' : (($assessment['basis'] ?? '') === 'subject_revision_mismatch' ? 'STALE_SUBJECT_REVISION' : 'SUBJECT_SCOPE_MISMATCH')));
        $selection = $blueprint !== null ? $this->selector->select($blueprint) : ['media' => null, 'candidates' => [], 'diagnostics' => []];
        $replacement = $selection['media'] ?? null;
        $replacementId = $replacement instanceof Media && (!$media instanceof Media || $replacement->canonicalId !== $media->canonicalId) ? $replacement->canonicalId : null;
        $replacementCandidate = null;
        foreach ((array) ($selection['candidates'] ?? []) as $candidate) if (is_array($candidate) && ($candidate['media_id'] ?? '') === $replacementId) { $replacementCandidate = $candidate; break; }
        $disposition = $replacementId !== null ? 'REPLACE_WITH_ELIGIBLE' : 'REMOVE_TO_PLACEHOLDER';
        $repairPlan = $replacementId === null
            ? ['operation' => 'REMOVE_MEDIA_USAGE', 'disposition' => 'NO_SAFE_MEDIA', 'usage_id' => $usage->usageId, 'expected_usage_revision' => $usage->revision, 'expected_subject_revision' => $subjectRevision]
            : ['operation' => 'REPLACE_MEDIA_USAGE', 'usage_id' => $usage->usageId, 'replacement_media_id' => $replacementId, 'expected_usage_revision' => $usage->revision, 'expected_media_revision' => $media?->revision, 'expected_subject_revision' => $subjectRevision];
        return $this->finding($usage, $media, $reason, 'REPAIR_RECOMMENDED', $replacementId, $subjectRevision, $repairPlan, $disposition, $subjectType, $subjectId, $blueprint?->revision, $assessment, $subjectIds, $replacementCandidate);
    }

    /** @param array<string,mixed>|null $repairPlan */
    private function finding(MediaUsage $usage, ?Media $media, string $reason, string $status, ?string $replacementId, ?string $subjectRevision, ?array $repairPlan, string $disposition = 'REVIEW_REQUIRED', string $subjectType = '', string $subjectId = '', ?int $blueprintRevision = null, array $assessment = [], array $subjectIds = [], ?array $replacementCandidate = null): array
    {
        $dependency = [
            'usage_id' => $usage->usageId,
            'usage_revision' => $usage->revision,
            'media_id' => $usage->mediaId,
            'media_revision' => $media?->revision,
            'endpoint_key' => $usage->endpointKey,
            'role' => $usage->role,
            'placement_key' => $usage->placementKey,
            'reason' => $reason,
            'replacement_media_id' => $replacementId,
            'replacement_candidate' => $replacementCandidate,
            'subject_ids' => array_values($subjectIds),
            'subject_id' => $subjectId,
            'subject_revision' => $subjectRevision,
            'blueprint_revision' => $blueprintRevision,
        ];
        $dependencyFingerprint = hash('sha256', json_encode($dependency, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $fingerprint = hash('sha256', json_encode(['dependency' => $dependency, 'disposition' => $disposition], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $finding = [
            'usage_id' => $usage->usageId,
            'media_id' => $usage->mediaId,
            'current_media_id' => $usage->mediaId,
            'endpoint_type' => $usage->endpointType,
            'endpoint_key' => $usage->endpointKey,
            'target' => ['type' => $usage->endpointType, 'id' => $usage->endpointKey],
            'role' => $usage->role,
            'placement_key' => $usage->placementKey,
            'usage_revision' => $usage->revision,
            'expected_usage_revision' => $usage->revision,
            'media_revision' => $media?->revision,
            'canonical_subject_type' => $subjectType !== '' ? $subjectType : null,
            'canonical_subject_id' => $subjectId !== '' ? $subjectId : null,
            'subject_id' => $subjectId,
            'subject_ids' => array_values($subjectIds),
            'subject_revision' => $subjectRevision,
            'semantic_suitability' => $assessment['suitability'] ?? null,
            'semantic_tier' => $assessment['semantic_tier'] ?? null,
            'semantic_score_components' => is_array($assessment['score_components'] ?? null) ? $assessment['score_components'] : [],
            'selection_source' => $usage->selectionSource,
            'selection_policy' => $usage->selectionPolicy,
            'reason' => $reason,
            'status' => $status,
            'disposition' => $disposition,
            'diagnostic_codes' => array_values(array_filter([$reason, $assessment['diagnostic'] ?? null], static fn (mixed $code): bool => is_string($code) && $code !== '')),
            'seo' => ['alt_text' => $usage->altText, 'caption' => $usage->caption, 'title' => $usage->title],
            'dependency_fingerprint' => $dependencyFingerprint,
            'fingerprint' => $fingerprint,
        ];
        if ($replacementId !== null) $finding['replacement_media_id'] = $replacementId;
        if ($replacementCandidate !== null) $finding['replacement_candidate'] = $replacementCandidate;
        if ($repairPlan !== null) $finding['repair_plan'] = $repairPlan;
        return $finding;
    }

    /** @return list<string> */
    private function subjectIds(array $context): array
    {
        $ids = [];
        foreach (['subject_ids', 'canonical_subject_ids'] as $key) foreach ((array) ($context[$key] ?? []) as $id) {
            $id = trim((string) $id);
            if ($id !== '') $ids[$id] = true;
        }
        return array_keys($ids);
    }

    /** @param list<string> $subjectIds */
    private function subjectId(array $context, array $subjectIds): string
    {
        foreach (['subject_id', 'subject_uuid', 'canonical_subject_id', 'canonical_subject_uuid'] as $key) {
            $value = trim((string) ($context[$key] ?? ''));
            if ($value !== '') return $value;
        }
        return (string) ($subjectIds[0] ?? '');
    }

    private function postId(string $endpointKey): int
    {
        $postId = (int) preg_replace('/^.*:/', '', $endpointKey);
        return $postId > 0 ? $postId : 0;
    }
}
