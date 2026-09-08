<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Projection;

use NHK\Core\Contracts\Projection\ProjectionRevisionStore;
use NHK\Core\Domain\Projection\{ProjectionRevision, ProjectionStatus};

final class InMemoryProjectionRevisionStore implements ProjectionRevisionStore
{
    /** @var array<string,list<ProjectionRevision>> */
    private array $items = [];

    public function status(): array { return ['status' => 'available', 'reason' => null, 'missing_tables' => []]; }

    public function saveCandidate(ProjectionRevision $revision): ProjectionRevision
    {
        $items = $this->items[$revision->nodeUuid] ?? [];
        foreach ($items as $item) if ($item->inputHash === $revision->inputHash) return $item;
        $next = 1; foreach ($items as $item) $next = max($next, $item->revision + 1);
        $saved = new ProjectionRevision($revision->nodeUuid, $next, ProjectionStatus::CANDIDATE, $revision->inputHash, $revision->claimSetHash, $revision->graphHash, $revision->policyRevision, $revision->templateRevision, $revision->payload, $revision->dirtySections, $revision->generatedAt ?: gmdate('c'), $revision->publishedAt);
        $this->items[$revision->nodeUuid][] = $saved;
        return $saved;
    }

    public function findLatest(string $nodeUuid): ?ProjectionRevision
    {
        $items = $this->items[$nodeUuid] ?? [];
        return $items === [] ? null : $items[array_key_last($items)];
    }

    public function findPublished(string $nodeUuid): ?ProjectionRevision
    {
        $items = array_values(array_filter($this->items[$nodeUuid] ?? [], static fn (ProjectionRevision $item): bool => $item->status === ProjectionStatus::PUBLISHED));
        usort($items, static fn (ProjectionRevision $a, ProjectionRevision $b): int => $b->revision <=> $a->revision);
        return $items[0] ?? null;
    }

    public function findCandidate(string $nodeUuid): ?ProjectionRevision
    {
        $items = array_values(array_filter($this->items[$nodeUuid] ?? [], static fn (ProjectionRevision $item): bool => in_array($item->status, [ProjectionStatus::CANDIDATE, ProjectionStatus::VALIDATING, ProjectionStatus::READY], true)));
        usort($items, static fn (ProjectionRevision $a, ProjectionRevision $b): int => $b->revision <=> $a->revision);
        return $items[0] ?? null;
    }

    public function publish(string $nodeUuid, int $revision): ProjectionRevision
    {
        $candidate = null;
        foreach ($this->items[$nodeUuid] ?? [] as $item) if ($item->revision === $revision) $candidate = $item;
        if ($candidate === null || $candidate->status !== ProjectionStatus::READY) throw new \RuntimeException('PROJECTION_NOT_READY');
        $updated = [];
        foreach ($this->items[$nodeUuid] as $item) {
            if ($item->status === ProjectionStatus::PUBLISHED) $item = new ProjectionRevision($item->nodeUuid, $item->revision, ProjectionStatus::SUPERSEDED, $item->inputHash, $item->claimSetHash, $item->graphHash, $item->policyRevision, $item->templateRevision, $item->payload, $item->dirtySections, $item->generatedAt, $item->publishedAt);
            if ($item->revision === $revision) $item = new ProjectionRevision($item->nodeUuid, $item->revision, ProjectionStatus::PUBLISHED, $item->inputHash, $item->claimSetHash, $item->graphHash, $item->policyRevision, $item->templateRevision, $item->payload, [], $item->generatedAt, gmdate('c'));
            $updated[] = $item;
        }
        $this->items[$nodeUuid] = $updated;
        return $this->findPublished($nodeUuid);
    }

    public function discard(string $nodeUuid, int $revision): void
    {
        $this->items[$nodeUuid] = array_values(array_filter($this->items[$nodeUuid] ?? [], static fn (ProjectionRevision $item): bool => $item->revision !== $revision));
    }

    public function markDirty(string $nodeUuid, array $sections): void
    {
        $candidate = $this->findCandidate($nodeUuid);
        if ($candidate === null) return;
        $dirty = array_values(array_unique(array_merge($candidate->dirtySections, $sections)));
        foreach ($this->items[$nodeUuid] as $i => $item) if ($item->revision === $candidate->revision) $this->items[$nodeUuid][$i] = new ProjectionRevision($item->nodeUuid, $item->revision, ProjectionStatus::CANDIDATE, $item->inputHash, $item->claimSetHash, $item->graphHash, $item->policyRevision, $item->templateRevision, $item->payload, $dirty, $item->generatedAt, null);
    }

    public function markReady(string $nodeUuid, int $revision): ProjectionRevision
    {
        foreach ($this->items[$nodeUuid] ?? [] as $i => $item) if ($item->revision === $revision && $item->status === ProjectionStatus::CANDIDATE) {
            $ready = new ProjectionRevision($item->nodeUuid, $item->revision, ProjectionStatus::READY, $item->inputHash, $item->claimSetHash, $item->graphHash, $item->policyRevision, $item->templateRevision, $item->payload, $item->dirtySections, $item->generatedAt, null);
            return $this->items[$nodeUuid][$i] = $ready;
        }
        throw new \RuntimeException('PROJECTION_CANDIDATE_NOT_FOUND');
    }
}
