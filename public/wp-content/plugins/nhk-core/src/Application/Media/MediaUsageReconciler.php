<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Domain\Media\MediaUsage;

/**
 * Plans endpoint usage convergence without treating a usage as semantic truth.
 * Mutation remains owned by MediaService/repository capabilities.
 */
final class MediaUsageReconciler
{
    /**
     * @param list<MediaUsage> $current
     * @param list<array<string,mixed>> $desired
     * @return array{status:string,actions:list<array<string,mixed>>}
     */
    public function plan(string $endpointType, string $endpointKey, array $current, array $desired, ?callable $suitability = null): array
    {
        $currentByIdentity = [];
        $currentAssessmentByIdentity = [];
        foreach ($current as $usage) {
            if (!$usage instanceof MediaUsage || $usage->activeSlot === 'retired' || $usage->endpointType !== $endpointType || $usage->endpointKey !== $endpointKey) continue;
            $identity = $usage->role . "\0" . $usage->placementKey;
            if (isset($currentByIdentity[$identity])) return $this->review('DUPLICATE_CURRENT_USAGE', $usage->role);
            $currentByIdentity[$identity] = $usage;
            if ($suitability !== null) {
                $assessment = $suitability($usage->mediaId, $usage->role, [
                    'media_id' => $usage->mediaId,
                    'role' => $usage->role,
                    'placement_key' => $usage->placementKey,
                    'sort_order' => $usage->sortOrder,
                    'persisted' => true,
                ]);
                $currentAssessmentByIdentity[$identity] = is_array($assessment)
                    ? $assessment
                    : ['valid_for_completeness' => $assessment === true];
            }
        }

        $desiredByRole = [];
        foreach ($desired as $spec) {
            if (!is_array($spec)) return $this->review('INVALID_DESIRED_USAGE', '');
            $role = trim((string) ($spec['role'] ?? ''));
            $mediaId = trim((string) ($spec['media_id'] ?? ''));
            if ($role === '' || $mediaId === '') return $this->review('INVALID_DESIRED_USAGE', $role);
            try {
                $desired = new MediaUsage(
                    \NHK\Core\Shared\Uuid\UuidCodec::newV7(),
                    $mediaId,
                    $endpointType,
                    $endpointKey,
                    $role,
                    (int) ($spec['sort_order'] ?? 0),
                    (string) ($spec['alt_text'] ?? ''),
                    (string) ($spec['caption'] ?? ''),
                    is_array($spec['keyword_groups'] ?? null) ? array_values(array_map('strval', $spec['keyword_groups'])) : [],
                    (string) ($spec['title'] ?? ''),
                    1,
                    (string) ($spec['placement_key'] ?? ''),
                );
                $identity = $role . "\0" . $desired->placementKey;
                if (isset($desiredByRole[$identity])) return $this->review('DUPLICATE_DESIRED_USAGE', $role);
                if ($suitability !== null) {
                    $assessment = $suitability($desired->mediaId, $desired->role, $spec);
                    $accepted = is_array($assessment)
                        ? (($assessment['valid_for_completeness'] ?? $assessment['auto_select'] ?? false) === true)
                        : $assessment === true;
                    if (!$accepted) {
                        $code = is_array($assessment) ? (string) ($assessment['diagnostic'] ?? 'MEDIA_USAGE_SEMANTIC_MISMATCH') : 'MEDIA_USAGE_SEMANTIC_MISMATCH';
                        return $this->review($code, $role, ['media_id' => $desired->mediaId], 'REVIEW_REQUIRED');
                    }
                }
                $desiredByRole[$identity] = $desired;
            } catch (\Throwable) {
                return $this->review('INVALID_DESIRED_USAGE', $role);
            }
        }

        $actions = [];
        foreach ($desiredByRole as $identity => $wanted) {
            $existing = $currentByIdentity[$identity] ?? null;
            if (!$existing instanceof MediaUsage) {
                $actions[] = ['action' => 'ADD', 'role' => $wanted->role, 'placement_key' => $wanted->placementKey, 'media_id' => $wanted->mediaId];
                continue;
            }
            $same = $existing->mediaId === $wanted->mediaId
                && $existing->sortOrder === $wanted->sortOrder
                && $existing->altText === $wanted->altText
                && $existing->caption === $wanted->caption
                && $existing->keywordGroups === $wanted->keywordGroups
                && $existing->title === $wanted->title;
            $currentValid = ($currentAssessmentByIdentity[$identity]['valid_for_completeness'] ?? true) === true;
            $actions[] = ['action' => $same && $currentValid ? 'KEEP' : 'UPDATE', 'role' => $wanted->role, 'placement_key' => $wanted->placementKey, 'usage_id' => $existing->usageId, 'media_id' => $wanted->mediaId]
                + ($currentValid ? [] : ['reason' => (string) ($currentAssessmentByIdentity[$identity]['diagnostic'] ?? 'MEDIA_USAGE_SEMANTIC_MISMATCH')]);
        }
        foreach ($currentByIdentity as $identity => $existing) {
            if (!isset($desiredByRole[$identity])) $actions[] = ['action' => 'RETIRE', 'role' => $existing->role, 'placement_key' => $existing->placementKey, 'usage_id' => $existing->usageId, 'media_id' => $existing->mediaId];
        }
        usort($actions, static fn (array $left, array $right): int => (($left['role'] ?? '') <=> ($right['role'] ?? '')) ?: (($left['action'] ?? '') <=> ($right['action'] ?? '')));
        return ['status' => 'PLANNED', 'actions' => $actions];
    }

    /** @return array{status:string,actions:list<array<string,mixed>>} */
    private function review(string $code, string $role, array $extra = [], string $actionName = 'CONFLICT'): array
    {
        $action = ['action' => $actionName, 'code' => $code] + $extra;
        if ($role !== '') $action['role'] = $role;
        return ['status' => 'OWNER_REVIEW_REQUIRED', 'actions' => [$action, ['action' => 'OWNER_REVIEW_REQUIRED', 'code' => $code]]];
    }
}
