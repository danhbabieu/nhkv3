<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

/**
 * The narrow Admin vocabulary for attaching a canonical Video to Authority.
 * It is deliberately a validation/payload boundary; Governance remains the writer.
 */
final class VideoRelationAdminContract
{
    private const TARGET_TYPES = ['brand', 'model', 'variant', 'movement', 'music', 'component', 'classification', 'specimen', 'product'];

    public function sourceType(): string { return 'video'; }
    public function predicate(): string { return 'about'; }
    public function targetTypes(): array { return self::TARGET_TYPES; }
    public function evidenceOrigin(): string { return 'EXPLICIT_USER_RELATION'; }
    public function evidenceVisibility(): string { return 'PRIVATE'; }

    /** @param list<array{evidence_id:string}> $evidenceRefs @return array<string,mixed> */
    public function payload(string $videoId, string $targetType, string $targetId, array $evidenceRefs, string $videoFingerprint): array
    {
        if ($videoId === '' || $targetId === '' || !in_array($targetType, self::TARGET_TYPES, true)) throw new \InvalidArgumentException('CANONICAL_RELATION_ENDPOINT_REQUIRED');
        if ($videoFingerprint === '') throw new \InvalidArgumentException('VIDEO_FINGERPRINT_REQUIRED');
        if ($evidenceRefs === []) throw new \InvalidArgumentException('EVIDENCE_REFS_REQUIRED');
        foreach ($evidenceRefs as $ref) {
            if (!is_array($ref) || count($ref) !== 1 || !isset($ref['evidence_id']) || trim((string) $ref['evidence_id']) === '') throw new \InvalidArgumentException('CANONICAL_EVIDENCE_REQUIRED');
        }
        return [
            'source_type' => 'video', 'source_uuid' => $videoId,
            'target_type' => $targetType, 'target_uuid' => $targetId,
            'predicate' => 'about', 'origin' => 'EXPLICIT_USER_RELATION',
            'evidence_refs' => array_values($evidenceRefs), 'source_fingerprint' => $videoFingerprint,
        ];
    }
}
