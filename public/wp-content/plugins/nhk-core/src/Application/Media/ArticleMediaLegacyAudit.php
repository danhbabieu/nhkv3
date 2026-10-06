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
        $limit = max(1, min(500, $limit));
        $page = $this->inventory->page('wp_post', trim($cursor) !== '' ? trim($cursor) : null, $limit);
        $findings = [];
        foreach ((array) ($page['items'] ?? []) as $usage) {
            if (!$usage instanceof MediaUsage || !in_array($usage->role, MediaUsageRoleRegistry::mandatoryArticleRoles(), true)) continue;
            if ($usage->activeSlot === 'retired') {
                $findings[] = $this->finding($usage, null, 'RETIRED_USAGE', 'INFORMATIONAL', null, null, null);
                continue;
            }
            if (strtoupper(trim($usage->selectionSource)) === 'USER_EXPLICIT' && strtoupper(trim($usage->selectionPolicy)) === 'PINNED') {
                $findings[] = $this->finding($usage, $this->media->findByCanonicalId($usage->mediaId), 'EXPLICIT_PINNED_PROTECTED', 'PROTECTED', null, null, null);
                continue;
            }

            $media = $this->media->findByCanonicalId($usage->mediaId);
            $postId = $this->postId($usage->endpointKey);
            $blueprint = $postId > 0 ? $this->blueprints->findByPostAndSlot($postId, $usage->role) : null;
            $subjectContext = $blueprint?->subjectContext ?? [];
            $subjectIds = $this->subjectIds($subjectContext);
            $subjectRevision = trim((string) ($subjectContext['subject_revision'] ?? $subjectContext['canonical_subject_revision'] ?? ''));
            if ($media === null || !$media->active || $media->readiness !== 'ready') {
                $reason = $media === null ? 'MEDIA_NOT_FOUND' : (!$media->active ? 'MEDIA_RETIRED' : 'MEDIA_NOT_READY');
                $findings[] = $this->finding($usage, $media, $reason, 'REVIEW_REQUIRED', null, $subjectRevision, null);
                continue;
            }
            if ($subjectIds === []) {
                $findings[] = $this->finding($usage, $media, 'MISSING_SUBJECT_SCOPE', 'REVIEW_REQUIRED', null, $subjectRevision, null);
                continue;
            }

            $assessment = $this->policy->evaluateMedia($media, $this->assets->listByMediaId($media->canonicalId), ['subject_ids' => $subjectIds, 'subject_revision' => $subjectRevision], 'SYSTEM_AUTO', $usage->role);
            if (($assessment['valid_for_completeness'] ?? false) === true) continue;
            $reason = ($assessment['basis'] ?? '') === 'subject_revision_mismatch' ? 'STALE_SUBJECT_REVISION' : 'SUBJECT_SCOPE_MISMATCH';
            $replacement = $blueprint !== null ? $this->selector->select($blueprint)['media'] : null;
            $replacementId = $replacement instanceof Media && $replacement->canonicalId !== $media->canonicalId ? $replacement->canonicalId : null;
            $repairPlan = $replacementId === null
                ? ['disposition' => 'NO_SAFE_MEDIA']
                : ['operation' => 'REPLACE_MEDIA_USAGE', 'usage_id' => $usage->usageId, 'replacement_media_id' => $replacementId, 'expected_usage_revision' => $usage->revision, 'expected_media_revision' => $media->revision, 'expected_subject_revision' => $subjectRevision];
            $findings[] = $this->finding($usage, $media, $reason, 'REPAIR_RECOMMENDED', $replacementId, $subjectRevision, $repairPlan);
        }
        return ['status' => 'DRY_RUN', 'next_cursor' => isset($page['next_cursor']) && $page['next_cursor'] !== null ? (string) $page['next_cursor'] : null, 'findings' => $findings];
    }

    /** @param array<string,mixed>|null $repairPlan */
    private function finding(MediaUsage $usage, ?Media $media, string $reason, string $status, ?string $replacementId, ?string $subjectRevision, ?array $repairPlan): array
    {
        $fingerprint = hash('sha256', json_encode([
            'usage_id' => $usage->usageId,
            'media_id' => $usage->mediaId,
            'endpoint_key' => $usage->endpointKey,
            'role' => $usage->role,
            'reason' => $reason,
            'replacement_media_id' => $replacementId,
            'subject_revision' => $subjectRevision,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $finding = [
            'usage_id' => $usage->usageId,
            'media_id' => $usage->mediaId,
            'endpoint_type' => $usage->endpointType,
            'endpoint_key' => $usage->endpointKey,
            'role' => $usage->role,
            'usage_revision' => $usage->revision,
            'media_revision' => $media?->revision,
            'subject_revision' => $subjectRevision,
            'selection_source' => $usage->selectionSource,
            'selection_policy' => $usage->selectionPolicy,
            'reason' => $reason,
            'status' => $status,
            'fingerprint' => $fingerprint,
        ];
        if ($replacementId !== null) $finding['replacement_media_id'] = $replacementId;
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

    private function postId(string $endpointKey): int
    {
        $postId = (int) preg_replace('/^.*:/', '', $endpointKey);
        return $postId > 0 ? $postId : 0;
    }
}
