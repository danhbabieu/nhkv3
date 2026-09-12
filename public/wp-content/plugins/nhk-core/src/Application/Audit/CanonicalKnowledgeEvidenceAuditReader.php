<?php
declare(strict_types=1);

namespace NHK\Core\Application\Audit;

use NHK\Core\Contracts\Audit\ClockTypeAuditEvidenceReader;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Production read adapter over the existing Knowledge/Source/Evidence owners.
 * It emits safe references and never returns claim text, excerpts or payloads.
 */
final class CanonicalKnowledgeEvidenceAuditReader implements ClockTypeAuditEvidenceReader
{
    public function __construct(
        private KnowledgeRepository $claims,
        private SourceRepository $sources,
        private EvidenceRepository $evidence,
    ) {}

    /** @return list<array<string,mixed>> */
    public function findForSubject(string $sourceType, string $sourceUuid): array
    {
        $sourceType = trim($sourceType); $sourceUuid = trim($sourceUuid);
        if ($sourceType === '' || !UuidCodec::isValid($sourceUuid)) return [];
        $rows = [];
        foreach ($this->claims->list(true) as $claim) {
            if (!$claim instanceof KnowledgeClaim) continue;
            $metadata = is_array($claim->provenance['metadata'] ?? null) ? $claim->provenance['metadata'] : [];
            $subjectId = $this->firstString([$metadata['subject_uuid'] ?? null, $metadata['subject_id'] ?? null, $claim->provenance['subject_uuid'] ?? null, $claim->provenance['subject_id'] ?? null]);
            $subjectType = $this->firstString([$metadata['subject_type'] ?? null, $claim->provenance['subject_type'] ?? null]);
            $scope = $this->firstString([$metadata['scope'] ?? null, $claim->provenance['scope'] ?? null]);
            if ($subjectId !== $sourceUuid || $subjectType !== $sourceType) continue;

            $targetId = $this->targetId($claim, $metadata);
            $provenance = $this->firstString([$metadata['provenance_class'] ?? null, $metadata['provenance'] ?? null, $claim->provenance['provenance_class'] ?? null, $claim->provenance['provenance'] ?? null, $metadata['origin'] ?? null]);
            $tier = strtoupper($this->firstString([$metadata['audit_tier'] ?? null, $metadata['evidence_tier'] ?? null]));
            $support = $this->support($claim);
            $rows[] = [
                'tier' => in_array($tier, ['B', 'C', 'D'], true) ? $tier : '',
                'source_type' => $sourceType,
                'source_uuid' => $sourceUuid,
                'scope_source_uuid' => $sourceUuid,
                'scope' => $scope,
                'target_uuid' => $targetId,
                'claim_uuid' => $claim->canonicalId,
                'claim_revision' => $claim->revision,
                'evidence_status' => $scope === $sourceType ? $support['status'] : 'UNSUPPORTED',
                'provenance_class' => $provenance,
                'basis' => $scope === $sourceType && $targetId !== '' ? 'EXACT_CANONICAL_KNOWLEDGE_EVIDENCE' : ($scope !== $sourceType ? 'SCOPE_UNRESOLVED_KNOWLEDGE_REFERENCE' : 'UNRESOLVED_KNOWLEDGE_REFERENCE'),
                'supporting_canonical_ids' => array_values(array_filter([$claim->canonicalId, ...$support['evidence_ids'], ...$support['source_ids']], static fn (string $id): bool => UuidCodec::isValid($id))),
                'support_summary' => [
                    'claim_uuid' => $claim->canonicalId,
                    'claim_revision' => $claim->revision,
                    'claim_active' => $claim->active,
                    'evidence_ids' => $support['evidence_ids'],
                    'evidence_state' => $support['status'],
                    'source_ids' => $support['source_ids'],
                    'source_state' => $support['source_state'],
                    'visibility' => $support['visibility'],
                    'claim_visibility' => $claim->isPublic() ? 'PUBLIC' : 'PRIVATE',
                ],
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['claim_uuid'], (string) $b['claim_uuid']));
        return $rows;
    }

    /** @return array{status:string,evidence_ids:list<string>,source_ids:list<string>,source_state:string,visibility:list<string>} */
    private function support(KnowledgeClaim $claim): array
    {
        $evidenceIds = []; $sourceIds = []; $visibility = []; $activeSupport = false; $sourceState = 'UNAVAILABLE';
        foreach ($this->evidence->listByClaim($claim->canonicalId, true) as $item) {
            if (!$item instanceof Evidence) continue;
            $evidenceIds[] = $item->canonicalId;
            $source = $this->sources->findByCanonicalId($item->sourceId);
            if ($source instanceof Source) $sourceIds[] = $source->canonicalId;
            $visibility[] = $item->isPublic() ? 'PUBLIC' : 'PRIVATE';
            if (!$item->active || $item->relation !== 'supports') continue;
            if (!$source instanceof Source) { $sourceState = 'MISSING'; continue; }
            if (!$source->active) { $sourceState = 'INACTIVE'; continue; }
            $sourceState = $source->isPublic() ? 'PUBLIC' : 'PRIVATE';
            $activeSupport = true;
        }
        $metadata = is_array($claim->provenance['metadata'] ?? null) ? $claim->provenance['metadata'] : [];
        $claimStatus = strtoupper((string) ($metadata['knowledge_status'] ?? ''));
        if (!$claim->active || in_array($claimStatus, ['PRIVATE', 'HIDDEN', 'DRAFT', 'NEEDS_CONFIRMATION'], true)) $activeSupport = false;
        return ['status' => $activeSupport ? 'SUPPORTED' : ($evidenceIds === [] ? 'NO_EVIDENCE' : 'UNSUPPORTED'), 'evidence_ids' => array_values(array_unique($evidenceIds)), 'source_ids' => array_values(array_unique($sourceIds)), 'source_state' => $sourceState, 'visibility' => array_values(array_unique($visibility))];
    }

    /** @param array<string,mixed> $metadata */
    private function targetId(KnowledgeClaim $claim, array $metadata): string
    {
        $targetType = $this->firstString([$metadata['target_type'] ?? null, $claim->provenance['target_type'] ?? null]);
        if ($targetType !== '' && !in_array($targetType, ['classification', 'clock_type'], true)) return '';
        $value = $this->firstString([$metadata['clock_type_uuid'] ?? null, $metadata['classification_uuid'] ?? null, $metadata['target_uuid'] ?? null, $claim->provenance['clock_type_uuid'] ?? null, $claim->provenance['classification_uuid'] ?? null, $claim->provenance['target_uuid'] ?? null]);
        return UuidCodec::isValid($value) ? $value : '';
    }

    /** @param list<mixed> $values */
    private function firstString(array $values): string { foreach ($values as $value) if (is_string($value) && trim($value) !== '') return trim($value); return ''; }
}
