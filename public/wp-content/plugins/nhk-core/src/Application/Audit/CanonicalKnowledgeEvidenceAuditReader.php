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
            $subjectId = $this->canonicalString($metadata['subject_id'] ?? null);
            $subjectType = $this->canonicalString($metadata['subject_type'] ?? null);
            $scope = $this->canonicalString($metadata['scope'] ?? null);
            if ($subjectId !== $sourceUuid || $subjectType !== $sourceType) continue;

            $targetId = $this->targetId($claim, $metadata);
            $provenance = $this->canonicalString($metadata['provenance_class'] ?? null);
            $tier = strtoupper($this->canonicalString($metadata['audit_tier'] ?? null));
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
                    'evidence_revisions' => $support['evidence_revisions'],
                    'source_ids' => $support['source_ids'],
                    'source_revisions' => $support['source_revisions'],
                    'source_state' => $support['source_state'],
                    'visibility' => $support['visibility'],
                    'claim_visibility' => $claim->isPublic() ? 'PUBLIC' : 'PRIVATE',
                ],
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['claim_uuid'], (string) $b['claim_uuid']));
        return $rows;
    }

    /** @return array{status:string,evidence_ids:list<string>,evidence_revisions:array<string,int>,source_ids:list<string>,source_revisions:array<string,int>,source_state:string,visibility:list<string>} */
    private function support(KnowledgeClaim $claim): array
    {
        $evidenceIds = []; $evidenceRevisions = []; $sourceIds = []; $sourceRevisions = []; $visibility = []; $activeSupport = false; $sourceState = 'UNAVAILABLE';
        foreach ($this->evidence->listByClaim($claim->canonicalId, true) as $item) {
            if (!$item instanceof Evidence) continue;
            if ($item->claimId !== $claim->canonicalId) continue;
            $evidenceIds[] = $item->canonicalId;
            $evidenceRevisions[$item->canonicalId] = $item->revision;
            $source = $this->sources->findByCanonicalId($item->sourceId);
            if ($source instanceof Source) { $sourceIds[] = $source->canonicalId; $sourceRevisions[$source->canonicalId] = $source->revision; }
            $visibility[] = $this->visibility($item->metadata, $item->isPublic());
            if (!$item->active || $item->relation !== 'supports') continue;
            if (!$source instanceof Source) { $sourceState = 'MISSING'; continue; }
            if (!$source->active) { $sourceState = 'INACTIVE'; continue; }
            $sourceState = $source->isPublic() ? 'PUBLIC' : 'PRIVATE';
            $activeSupport = true;
        }
        $metadata = is_array($claim->provenance['metadata'] ?? null) ? $claim->provenance['metadata'] : [];
        $claimStatus = strtoupper((string) ($metadata['knowledge_status'] ?? ''));
        if (!$claim->active || in_array($claimStatus, ['PRIVATE', 'HIDDEN', 'DRAFT', 'NEEDS_CONFIRMATION'], true)) $activeSupport = false;
        return ['status' => $activeSupport ? 'SUPPORTED' : ($evidenceIds === [] ? 'NO_EVIDENCE' : 'UNSUPPORTED'), 'evidence_ids' => array_values(array_unique($evidenceIds)), 'evidence_revisions' => $evidenceRevisions, 'source_ids' => array_values(array_unique($sourceIds)), 'source_revisions' => $sourceRevisions, 'source_state' => $sourceState, 'visibility' => array_values(array_unique($visibility))];
    }

    /** @param array<string,mixed> $metadata */
    private function targetId(KnowledgeClaim $claim, array $metadata): string
    {
        $targetType = $this->canonicalString($metadata['target_type'] ?? null);
        if ($targetType !== '' && $targetType !== 'classification') return '';
        $value = $this->canonicalString($metadata['target_uuid'] ?? null);
        return UuidCodec::isValid($value) ? $value : '';
    }

    /** @param list<mixed> $values */
    private function canonicalString(mixed $value): string { return is_string($value) ? trim($value) : ''; }

    private function visibility(array $metadata, bool $public): string
    {
        $value = strtoupper($this->canonicalString($metadata['visibility'] ?? null));
        return in_array($value, ['PUBLIC', 'PRIVATE', 'HIDDEN'], true) ? $value : ($public ? 'PUBLIC' : 'PRIVATE');
    }
}
