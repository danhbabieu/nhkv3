<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Projection;

use NHK\Core\Domain\Projection\ProjectionRevision;

interface ProjectionRevisionStore extends ProjectionStorageStatus
{
    public function saveCandidate(ProjectionRevision $revision): ProjectionRevision;
    public function findLatest(string $nodeUuid): ?ProjectionRevision;
    public function findPublished(string $nodeUuid): ?ProjectionRevision;
    public function findCandidate(string $nodeUuid): ?ProjectionRevision;
    public function markReady(string $nodeUuid, int $revision): ProjectionRevision;
    public function publish(string $nodeUuid, int $revision): ProjectionRevision;
    public function discard(string $nodeUuid, int $revision): void;
    public function markFailed(string $nodeUuid, int $revision): ProjectionRevision;
    /** @param list<string> $sections */
    public function markDirty(string $nodeUuid, array $sections): void;
}
