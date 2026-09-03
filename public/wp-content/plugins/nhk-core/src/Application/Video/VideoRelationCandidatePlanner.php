<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Domain\Graph\PredicateRegistry;
use NHK\Core\Domain\Video\VideoRelationCandidate;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Shared\Uuid\UuidCodec;

final class VideoRelationCandidatePlanner
{
    public function __construct(private PredicateRegistry $predicates, private EvidenceRepository $evidence, private KnowledgeRepository $claims, private SourceRepository $sources)
    {
    }

    /** @param list<array<string,mixed>> $relations @return list<VideoRelationCandidate> */
    public function plan(string $videoId, array $relations): array
    {
        if (!UuidCodec::isValid($videoId)) throw new \InvalidArgumentException('Video relation source identity is invalid.');
        $seen = [];
        $result = [];
        foreach ($relations as $relation) {
            $targetId = trim((string) ($relation['target_id'] ?? ''));
            $targetType = trim((string) ($relation['target_type'] ?? ''));
            $predicate = trim((string) ($relation['predicate'] ?? 'about'));
            $origin = trim((string) ($relation['origin'] ?? 'INFERRED_RELATION'));
            $evidence = is_array($relation['evidence_refs'] ?? null) ? array_values($relation['evidence_refs']) : [];
            if (!UuidCodec::isValid($targetId)) throw new \InvalidArgumentException('Video relation target must be a canonical UUID.');
            if (!in_array($origin, ['EXPLICIT_USER_RELATION', 'INFERRED_RELATION'], true)) throw new \InvalidArgumentException('Video relation origin is invalid.');
            if ($evidence === []) throw new \InvalidArgumentException('Video relation requires evidence.');
            foreach ($evidence as $reference) {
                if (!is_array($reference) || array_keys($reference) !== ['evidence_id']) throw new \InvalidArgumentException('Video relation evidence reference is invalid.');
                $evidenceId = trim((string) $reference['evidence_id']);
                $record = UuidCodec::isValid($evidenceId) ? $this->evidence->findByCanonicalId($evidenceId) : null;
                $claim = $record === null ? null : $this->claims->findByCanonicalId($record->claimId);
                $source = $record === null ? null : $this->sources->findByCanonicalId($record->sourceId);
                if ($record === null || !$record->active || !$record->isPublic() || $claim === null || !$claim->active || !$claim->isPublic() || $source === null || !$source->active || !$source->isPublic()) throw new \InvalidArgumentException('Video relation evidence reference is invalid.');
            }
            $definition = $this->predicates->get($predicate);
            if (!$definition->allows('video', $targetType)) throw new \InvalidArgumentException('Predicate is not allowed for a Video target.');
            $key = $predicate . ':' . $targetType . ':' . $targetId;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $result[] = new VideoRelationCandidate('video', $videoId, $targetId, $targetType, $predicate, $origin, $evidence, trim((string) ($relation['reason'] ?? '')), max(0.0, min(1.0, (float) ($relation['confidence'] ?? 0.0))));
        }
        return $result;
    }
}
