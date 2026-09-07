<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Application\Knowledge\KnowledgeService;
use NHK\Core\Contracts\Governance\ProposalRepository;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Governance\{CommandCanonicalizer, Proposal, ProposalState};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Core\Shared\Uuid\UuidCodec;

/** Reconciles pre-evidence Video relation proposals inside an existing governed transaction. */
final class HistoricalVideoRelationEvidenceReconciliation
{
    public function __construct(
        private KnowledgeService $knowledge,
        private KnowledgeRepository $claims,
        private SourceRepository $sources,
        private EvidenceRepository $evidence,
        private ProposalRepository $proposals,
    ) {}

    /** @param list<Proposal> $relations @return list<array<string,mixed>> */
    public function reconcile(string $videoId, string $videoFingerprint, array $source, array $relations = []): array
    {
        if (!UuidCodec::isValid($videoId) || $videoFingerprint === '') throw new \InvalidArgumentException('VIDEO_RECONCILIATION_BINDING_REQUIRED');
        $sourceRecord = $this->source($videoId, $source);
        $results = [];
        foreach ($relations as $relation) {
            if (!$relation instanceof Proposal || $relation->state !== ProposalState::APPROVED || $relation->operation !== 'relation_create') continue;
            $payload = $relation->payload;
            if (($payload['source_type'] ?? '') !== 'video' || ($payload['source_uuid'] ?? '') !== $videoId) continue;
            $declaredFingerprint = trim((string) ($payload['source_fingerprint'] ?? ''));
            if ($declaredFingerprint !== '' && !hash_equals($videoFingerprint, $declaredFingerprint)) throw new \RuntimeException('FINGERPRINT_MISMATCH');
            $refs = $this->references($payload['evidence_refs'] ?? [], $sourceRecord->canonicalId);
            $claim = $this->claimForRelation($relation, $videoId);
            if ($refs !== []) {
                foreach ($refs as $ref) {
                    $item = $this->evidence->findByCanonicalId($ref['evidence_id']);
                    if ($item === null || $item->claimId !== $claim->canonicalId || ($item->metadata['origin'] ?? '') !== 'VIDEO_CANONICAL_PROVENANCE' || ($item->metadata['video_uuid'] ?? '') !== $videoId || strtoupper((string) ($item->metadata['visibility'] ?? '')) !== 'PRIVATE') throw new \RuntimeException('WRONG_EVIDENCE_PROVENANCE');
                }
            }
            if ($refs === []) $refs = [$this->evidenceForRelation($relation, $videoId, $sourceRecord, $source, $claim)];
            if ($this->proposals->findByIdempotencyKey($this->idempotencyKey($relation, $refs)) !== null) {
                $existing = $this->proposals->findByIdempotencyKey($this->idempotencyKey($relation, $refs));
                $results[] = ['status' => 'replay', 'replacement_id' => $existing?->id, 'source_id' => $sourceRecord->canonicalId, 'evidence_refs' => $refs];
                continue;
            }
            $replacementPayload = $payload;
            $replacementPayload['evidence_refs'] = $refs;
            $replacementPayload['source_fingerprint'] = $videoFingerprint;
            $contentFingerprint = hash('sha256', CommandCanonicalizer::canonicalize($replacementPayload));
            $dependencyFingerprint = hash('sha256', CommandCanonicalizer::canonicalize(array_map(fn (array $ref): string => (string) $ref['evidence_id'], $refs)));
            $replacement = new Proposal(
                UuidCodec::newV7(), 'relation', 'relation_create', $replacementPayload,
                $contentFingerprint, null, $dependencyFingerprint, ProposalState::DRAFT,
                actor: 'historical-evidence-reconciliation', idempotencyKey: $this->idempotencyKey($relation, $refs), entityType: 'relation'
            );
            $governance = new GovernanceService($this->proposals);
            $replacement = $governance->create($replacement);
            $replacement = $governance->approve($replacement->id, $replacement->contentFingerprint, $replacement->dependencyFingerprint, 'historical-evidence-reconciliation');
            $governance->supersede($relation->id, $replacement->id, 'historical-evidence-reconciliation');
            $results[] = ['status' => 'rebound', 'replacement_id' => $replacement->id, 'source_id' => $sourceRecord->canonicalId, 'evidence_refs' => $refs];
        }
        return $results;
    }

    private function source(string $videoId, array $source): Source
    {
        $url = trim((string) ($source['canonical_source_url'] ?? ''));
        $platform = strtolower(trim((string) ($source['platform'] ?? '')));
        $externalId = trim((string) ($source['external_video_id'] ?? ''));
        if ($platform !== 'youtube' || $url === '' || filter_var($url, FILTER_VALIDATE_URL) === false || $externalId === '') throw new \RuntimeException('CANONICAL_VIDEO_PROVENANCE_REQUIRED');
        $stableKey = 'nhk:video-source:' . hash('sha256', CommandCanonicalizer::canonicalize([$platform, $externalId, $url]));
        $existing = $this->sources->findByStableKey($stableKey);
        if ($existing !== null) {
            $metadata = $existing->metadata;
            if (($metadata['origin'] ?? '') !== 'VIDEO_CANONICAL_PROVENANCE' || ($metadata['video_uuid'] ?? '') !== $videoId || ($metadata['platform'] ?? '') !== $platform || ($metadata['external_video_id'] ?? '') !== $externalId || $existing->locator !== $url || !$existing->active) throw new \RuntimeException('WRONG_SOURCE_PROVENANCE');
            return $existing;
        }
        return $this->knowledge->createSource($stableKey, trim((string) ($source['source_title'] ?? 'YouTube video source')) ?: 'YouTube video source', 'website', $url, ['visibility' => 'PRIVATE', 'origin' => 'VIDEO_CANONICAL_PROVENANCE', 'video_uuid' => $videoId, 'platform' => $platform, 'external_video_id' => $externalId]);
    }

    /** @return list<array{evidence_id:string}> */
    private function references(mixed $raw, string $sourceId): array
    {
        if (!is_array($raw)) return [];
        $refs = [];
        foreach ($raw as $ref) {
            if (!is_array($ref) || count($ref) !== 1 || !isset($ref['evidence_id'])) throw new \RuntimeException('CANONICAL_EVIDENCE_REQUIRED');
            $id = trim((string) $ref['evidence_id']);
            $item = UuidCodec::isValid($id) ? $this->evidence->findByCanonicalId($id) : null;
            if ($item === null || !$item->active) throw new \RuntimeException('CANONICAL_EVIDENCE_REQUIRED');
            if ($item->sourceId !== $sourceId) throw new \RuntimeException('WRONG_SOURCE_EVIDENCE');
            $refs[] = ['evidence_id' => $item->canonicalId];
        }
        return $refs;
    }

    /** @return list<array{evidence_id:string}> */
    private function claimForRelation(Proposal $relation, string $videoId): KnowledgeClaim
    {
        $payload = $relation->payload;
        $targetType = trim((string) ($payload['target_type'] ?? ''));
        $targetId = trim((string) ($payload['target_uuid'] ?? $payload['target_key'] ?? ''));
        $predicate = trim((string) ($payload['predicate'] ?? ''));
        if ($targetType === '' || $targetId === '' || $predicate === '') throw new \RuntimeException('RELATION_BINDING_REQUIRED');
        $key = 'nhk:video-relation-claim:' . hash('sha256', CommandCanonicalizer::canonicalize([$videoId, $targetType, $targetId, $predicate]));
        $claim = $this->claims->findByStableKey($key);
        if ($claim === null) $claim = $this->knowledge->createClaim($key, 'Video ' . $videoId . ' has a registered ' . $predicate . ' relation to ' . $targetType . ' ' . $targetId . '.', 'provenance', ['metadata' => ['origin' => 'VIDEO_RELATION_RECONCILIATION'], 'video_uuid' => $videoId, 'target_type' => $targetType, 'target_uuid' => $targetId, 'predicate' => $predicate]);
        else {
            $claimProvenance = $claim->provenance;
            $claimMetadata = is_array($claimProvenance['metadata'] ?? null) ? $claimProvenance['metadata'] : [];
            if (($claimMetadata['origin'] ?? '') !== 'VIDEO_RELATION_RECONCILIATION' || ($claimProvenance['video_uuid'] ?? '') !== $videoId || ($claimProvenance['target_type'] ?? '') !== $targetType || ($claimProvenance['target_uuid'] ?? '') !== $targetId || ($claimProvenance['predicate'] ?? '') !== $predicate || !$claim->active) throw new \RuntimeException('WRONG_CLAIM_PROVENANCE');
        }
        return $claim;
    }

    /** @return list<array{evidence_id:string}> */
    private function evidenceForRelation(Proposal $relation, string $videoId, Source $source, array $provenance, KnowledgeClaim $claim): array
    {
        $payload = $relation->payload;
        $targetType = trim((string) ($payload['target_type'] ?? ''));
        $targetId = trim((string) ($payload['target_uuid'] ?? $payload['target_key'] ?? ''));
        $predicate = trim((string) ($payload['predicate'] ?? ''));
        $key = 'nhk:video-relation-claim:' . hash('sha256', CommandCanonicalizer::canonicalize([$videoId, $targetType, $targetId, $predicate]));
        $fingerprint = hash('sha256', CommandCanonicalizer::canonicalize([$key, $source->stableKey, $videoId, $targetType, $targetId, $predicate]));
        $evidenceId = UuidCodec::v5('nhk:video-relation-evidence:' . $fingerprint);
        $existing = $this->evidence->findByCanonicalId($evidenceId);
        if ($existing !== null) {
            if (!$existing->active || $existing->claimId !== $claim->canonicalId || $existing->sourceId !== $source->canonicalId || ($existing->metadata['reconciliation_fingerprint'] ?? '') !== $fingerprint || ($existing->metadata['origin'] ?? '') !== 'VIDEO_CANONICAL_PROVENANCE' || ($existing->metadata['video_uuid'] ?? '') !== $videoId || strtoupper((string) ($existing->metadata['visibility'] ?? '')) !== 'PRIVATE') throw new \RuntimeException('WRONG_EVIDENCE_PROVENANCE');
            return [['evidence_id' => $existing->canonicalId]];
        }
        $excerpt = trim((string) ($provenance['source_description'] ?? $provenance['source_title'] ?? ''));
        if ($excerpt === '') $excerpt = 'Canonical YouTube source: ' . $source->locator;
        $item = $this->knowledge->citeWithId($evidenceId, $claim->canonicalId, $source->canonicalId, $excerpt, 'supports', $source->locator, ['visibility' => 'PRIVATE', 'origin' => 'VIDEO_CANONICAL_PROVENANCE', 'video_uuid' => $videoId, 'reconciliation_fingerprint' => $fingerprint]);
        return [['evidence_id' => $item->canonicalId]];
    }

    /** @param list<array{evidence_id:string}> $refs */
    private function idempotencyKey(Proposal $relation, array $refs): string { return 'nhk:video-relation-evidence-reconcile:' . $relation->id . ':' . hash('sha256', CommandCanonicalizer::canonicalize($refs)); }
}
