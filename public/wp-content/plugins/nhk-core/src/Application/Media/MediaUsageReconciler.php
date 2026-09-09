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
    public function plan(string $endpointType, string $endpointKey, array $current, array $desired): array
    {
        $currentByRole = [];
        foreach ($current as $usage) {
            if (!$usage instanceof MediaUsage || $usage->endpointType !== $endpointType || $usage->endpointKey !== $endpointKey) continue;
            if (isset($currentByRole[$usage->role])) return $this->review('DUPLICATE_CURRENT_USAGE', $usage->role);
            $currentByRole[$usage->role] = $usage;
        }

        $desiredByRole = [];
        foreach ($desired as $spec) {
            if (!is_array($spec)) return $this->review('INVALID_DESIRED_USAGE', '');
            $role = trim((string) ($spec['role'] ?? ''));
            $mediaId = trim((string) ($spec['media_id'] ?? ''));
            if ($role === '' || $mediaId === '') return $this->review('INVALID_DESIRED_USAGE', $role);
            if (isset($desiredByRole[$role])) return $this->review('DUPLICATE_DESIRED_USAGE', $role);
            try {
                $desiredByRole[$role] = new MediaUsage(
                    \NHK\Core\Shared\Uuid\UuidCodec::newV7(),
                    $mediaId,
                    $endpointType,
                    $endpointKey,
                    $role,
                    (int) ($spec['sort_order'] ?? 0),
                    (string) ($spec['alt_text'] ?? ''),
                    (string) ($spec['caption'] ?? ''),
                    is_array($spec['keyword_groups'] ?? null) ? array_values(array_map('strval', $spec['keyword_groups'])) : [],
                );
            } catch (\Throwable) {
                return $this->review('INVALID_DESIRED_USAGE', $role);
            }
        }

        $actions = [];
        foreach ($desiredByRole as $role => $wanted) {
            $existing = $currentByRole[$role] ?? null;
            if (!$existing instanceof MediaUsage) {
                $actions[] = ['action' => 'ADD', 'role' => $role, 'media_id' => $wanted->mediaId];
                continue;
            }
            $same = $existing->mediaId === $wanted->mediaId
                && $existing->sortOrder === $wanted->sortOrder
                && $existing->altText === $wanted->altText
                && $existing->caption === $wanted->caption
                && $existing->keywordGroups === $wanted->keywordGroups;
            $actions[] = ['action' => $same ? 'KEEP' : 'UPDATE', 'role' => $role, 'usage_id' => $existing->usageId, 'media_id' => $wanted->mediaId];
        }
        foreach ($currentByRole as $role => $existing) {
            if (!isset($desiredByRole[$role])) $actions[] = ['action' => 'RETIRE', 'role' => $role, 'usage_id' => $existing->usageId, 'media_id' => $existing->mediaId];
        }
        usort($actions, static fn (array $left, array $right): int => (($left['role'] ?? '') <=> ($right['role'] ?? '')) ?: (($left['action'] ?? '') <=> ($right['action'] ?? '')));
        return ['status' => 'PLANNED', 'actions' => $actions];
    }

    /** @return array{status:string,actions:list<array<string,mixed>>} */
    private function review(string $code, string $role): array
    {
        $action = ['action' => 'CONFLICT', 'code' => $code];
        if ($role !== '') $action['role'] = $role;
        return ['status' => 'OWNER_REVIEW_REQUIRED', 'actions' => [$action, ['action' => 'OWNER_REVIEW_REQUIRED', 'code' => $code]]];
    }
}
