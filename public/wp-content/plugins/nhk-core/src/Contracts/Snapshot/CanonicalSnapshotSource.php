<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Snapshot;

use NHK\Core\Application\Snapshot\SnapshotEnvironment;

/**
 * Read-only, normalized source for a governed V3 semantic snapshot.
 *
 * Adapters own the repository reads. The snapshot application layer never
 * discovers tables and never writes to the source runtime.
 */
interface CanonicalSnapshotSource
{
    public function environment(): SnapshotEnvironment;

    public function schemaVersion(): string;

    public function documentationVersion(): string;

    public function buildIdentity(): string;

    /** @return array{current:int,target:int} */
    public function migrationLevel(): array;

    public function schemaConsistent(): bool;

    public function isReadOnly(): bool;

    /** @return array<string,array<string,mixed>> */
    public function repositoryInventory(): array;

    /** @return array<string,list<array<string,mixed>>> */
    public function collections(): array;
}
