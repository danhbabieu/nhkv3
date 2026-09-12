<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Snapshot;

use NHK\Core\Application\Snapshot\SnapshotEnvironment;

/**
 * Governed restore port. An implementation must provide transactional,
 * UUID-preserving persistence through the canonical application services.
 */
interface CanonicalSnapshotWriter
{
    public function environment(): SnapshotEnvironment;

    public function schemaVersion(): string;

    public function schemaConsistent(): bool;

    /** Null means the isolated namespace has no prior snapshot. */
    public function existingManifestHash(): ?string;

    public function readBackManifestHash(): ?string;

    /** @return array<string,list<array<string,mixed>>> */
    public function readBackCollections(): array;

    public function isEmpty(): bool;

    public function begin(): void;

    /** @param list<array<string,mixed>> $records */
    public function importCollection(string $collection, array $records): void;

    public function commit(string $manifestHash): void;

    public function rollback(): void;
}
