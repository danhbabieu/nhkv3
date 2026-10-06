<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Contracts\Media\MediaUsageRepository;
use NHK\Core\Domain\Media\MediaUsage;

/** Revalidates one audit finding and emits a zero-mutation governed operation packet. */
final class ArticleMediaLegacyRepairPlan
{
    public function __construct(private ArticleMediaLegacyAudit $audit, private MediaUsageRepository $usages) {}

    /** @return array<string,mixed> */
    public function preview(array $input): array
    {
        $finding = is_array($input['finding'] ?? null) ? $input['finding'] : [];
        $usageId = trim((string) ($finding['usage_id'] ?? ''));
        $endpointType = strtolower(trim((string) ($finding['endpoint_type'] ?? '')));
        $endpointKey = trim((string) ($finding['endpoint_key'] ?? ''));
        $fingerprint = trim((string) ($finding['fingerprint'] ?? ''));
        $dependencyFingerprint = trim((string) ($finding['dependency_fingerprint'] ?? ''));
        $expectedRevision = (int) ($finding['expected_usage_revision'] ?? 0);
        if ($usageId === '' || $endpointType === '' || $endpointKey === '' || $fingerprint === '' || $dependencyFingerprint === '' || $expectedRevision < 1) throw new \InvalidArgumentException('ARTICLE_MEDIA_REPAIR_FINDING_BINDING_REQUIRED');
        $usage = null;
        foreach ($this->usages->listByEndpoint($endpointType, $endpointKey) as $candidate) if ($candidate instanceof MediaUsage && $candidate->usageId === $usageId) { $usage = $candidate; break; }
        if (!$usage instanceof MediaUsage) return $this->review('USAGE_NOT_FOUND');
        if ($usage->revision !== $expectedRevision) return $this->review('USAGE_REVISION_CHANGED');

        $current = $this->audit->auditUsage($usage);
        if (!hash_equals($fingerprint, (string) ($current['fingerprint'] ?? ''))) return $this->review('AUDIT_FINGERPRINT_STALE', $current);
        if (!hash_equals($dependencyFingerprint, (string) ($current['dependency_fingerprint'] ?? ''))) return $this->review('DEPENDENCY_FINGERPRINT_STALE', $current);
        if (($finding['subject_id'] ?? '') !== ($current['subject_id'] ?? '') || ($finding['subject_revision'] ?? '') !== ($current['subject_revision'] ?? '')) return $this->review('SUBJECT_BINDING_CHANGED', $current);
        if (($current['disposition'] ?? '') === 'PROTECT_EXPLICIT') return ['status' => 'PROTECTED', 'read_only' => true, 'mutated' => false, 'reason' => 'EXPLICIT_PINNED_PROTECTED', 'finding' => $current];
        if (!in_array(($current['disposition'] ?? ''), ['REPLACE_WITH_ELIGIBLE', 'REMOVE_TO_PLACEHOLDER'], true)) return $this->review('REPAIR_NOT_APPLICABLE', $current);

        $operation = ($current['disposition'] ?? '') === 'REPLACE_WITH_ELIGIBLE' ? 'replace' : 'remove';
        $mediaId = $operation === 'replace' ? (string) ($current['replacement_media_id'] ?? '') : (string) ($current['current_media_id'] ?? '');
        if ($mediaId === '') return $this->review('NO_SAFE_MEDIA', $current);
        $arguments = [
            'idempotency_key' => 'article-media-legacy-' . substr((string) $current['fingerprint'], 0, 48),
            'operation' => $operation,
            'media' => ['id' => $mediaId],
            'target' => ['type' => $current['endpoint_type'], 'id' => $current['endpoint_key']],
            'usage_id' => $current['usage_id'],
            'expected_usage_revision' => $current['expected_usage_revision'],
            'role' => $current['role'],
            'placement_key' => $current['placement_key'],
            'selection_source' => 'SYSTEM_AUTO',
            'selection_policy' => 'AUTO',
            'seo' => $current['seo'],
        ];
        return [
            'status' => 'PREVIEW',
            'read_only' => true,
            'mutated' => false,
            'operation' => $operation,
            'disposition' => $current['disposition'],
            'finding' => $current,
            'subject_id' => $current['subject_id'],
            'subject_revision' => $current['subject_revision'],
            'dependency_fingerprint' => $current['dependency_fingerprint'],
            'governed_operation' => ['tool' => 'nhk.media.usage', 'arguments' => $arguments],
            'apply_requires' => ['proposal', 'submission', 'approval', 'eligibility', 'controlled_apply', 'canonical_readback'],
        ];
    }

    /** @param array<string,mixed> $finding @return array<string,mixed> */
    private function review(string $reason, array $finding = []): array
    {
        return ['status' => 'REVIEW_REQUIRED', 'read_only' => true, 'mutated' => false, 'reason' => $reason] + ($finding === [] ? [] : ['finding' => $finding]);
    }
}
