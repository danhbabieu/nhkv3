<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Snapshot;

use NHK\Core\Application\Snapshot\{SnapshotCollectionRegistry, SnapshotEnvironment};
use NHK\Core\Contracts\Snapshot\CanonicalSnapshotWriter;
use NHK\Core\Shared\Uuid\UuidCodec;

/** Recovery-only transactional persistence for the normalized snapshot port. */
final class WpdbCanonicalSnapshotWriter implements CanonicalSnapshotWriter
{
    private bool $transaction = false;
    private ?string $manifestHash = null;
    private ?WpdbCanonicalSnapshotSource $readModel = null;

    public function __construct(private object $database, private SnapshotEnvironment $runtime, private array $allowedDatabases = [])
    {
        if (strtolower($runtime->runtimeMode) !== 'recovery' || in_array(strtolower($runtime->name), ['staging', 'production', 'test'], true)) throw new \RuntimeException('SNAPSHOT_WRITER_RECOVERY_ONLY');
        if ($allowedDatabases !== [] && !in_array($runtime->database, $allowedDatabases, true)) throw new \RuntimeException('SNAPSHOT_WRITER_DATABASE_NOT_ALLOWLISTED');
    }

    public function environment(): SnapshotEnvironment { return $this->runtime; }
    public function schemaVersion(): string { return SnapshotCollectionRegistry::SCHEMA_VERSION; }
    public function schemaConsistent(): bool { return $this->source()->schemaConsistent(); }
    public function existingManifestHash(): ?string
    {
        if (function_exists('get_option')) { $value = get_option('nhk_v3_snapshot_manifest_hash', null); if (is_string($value) && $value !== '') return $value; }
        return $this->manifestHash;
    }
    public function readBackManifestHash(): ?string { return $this->existingManifestHash(); }
    public function readBackCollections(): array { return $this->source()->collections(); }
    public function isEmpty(): bool
    {
        foreach (WpdbCanonicalSnapshotSource::physicalTables() as $table) {
            if (in_array($table, ['posts', 'postmeta', 'terms', 'term_taxonomy', 'termmeta', 'term_relationships'], true)) continue;
            if ((int) $this->database->get_var('SELECT COUNT(*) FROM ' . $this->source()->table($table)) > 0) return false;
        }
        return true;
    }
    public function begin(): void
    {
        if ($this->transaction) throw new \RuntimeException('SNAPSHOT_TRANSACTION_ALREADY_OPEN');
        if ($this->database->query('START TRANSACTION') === false) throw new \RuntimeException('SNAPSHOT_TRANSACTION_BEGIN_FAILED');
        $this->transaction = true;
    }
    public function importCollection(string $collection, array $records): void
    {
        if (!$this->transaction) throw new \RuntimeException('SNAPSHOT_TRANSACTION_REQUIRED');
        if (!in_array($collection, SnapshotCollectionRegistry::COLLECTIONS, true)) throw new \RuntimeException('SNAPSHOT_COLLECTION_UNSUPPORTED:' . $collection);
        if (in_array($collection, ['claims', 'completion_state', 'idempotency_state'], true)) return;
        foreach ($records as $record) {
            if (!is_array($record)) throw new \RuntimeException('SNAPSHOT_RECORD_MUST_BE_OBJECT');
            $table = (string) ($record['_physical_table'] ?? (WpdbCanonicalSnapshotSource::TABLES[$collection][0] ?? ''));
            if ($table === '' || !in_array($table, WpdbCanonicalSnapshotSource::physicalTables(), true)) throw new \RuntimeException('SNAPSHOT_TABLE_NOT_ALLOWLISTED');
            unset($record['_physical_table']);
            $this->insert($table, $record);
        }
    }
    public function commit(string $manifestHash): void
    {
        if (!$this->transaction) throw new \RuntimeException('SNAPSHOT_TRANSACTION_REQUIRED');
        if ($this->database->query('COMMIT') === false) throw new \RuntimeException('SNAPSHOT_TRANSACTION_COMMIT_FAILED');
        $this->transaction = false;
        $this->manifestHash = $manifestHash;
        if (function_exists('update_option')) update_option('nhk_v3_snapshot_manifest_hash', $manifestHash, false);
    }
    public function rollback(): void
    {
        if ($this->transaction) $this->database->query('ROLLBACK');
        $this->transaction = false;
    }

    /** @param array<string,mixed> $record */
    private function insert(string $suffix, array $record): void
    {
        $source = $this->source();
        $columns = $source->columns($suffix);
        $values = [];
        $fields = [];
        foreach ($columns as $name => $column) {
            if (!array_key_exists($name, $record)) continue;
            $fields[] = $name;
            $values[] = $this->storageValue($record[$name], (string) ($column['Type'] ?? ''));
        }
        if ($fields === []) throw new \RuntimeException('SNAPSHOT_RECORD_EMPTY:' . $suffix);
        $placeholders = implode(',', array_fill(0, count($values), '%s'));
        $query = 'INSERT INTO ' . $source->table($suffix) . ' (`' . implode('`,`', $fields) . '`) VALUES (' . $placeholders . ')';
        $result = $this->database->query($this->database->prepare($query, ...$values));
        if ($result === false) throw new \RuntimeException('SNAPSHOT_IMPORT_WRITE_FAILED:' . $suffix);
    }

    /** @param mixed $value */
    private function storageValue(mixed $value, string $type): mixed
    {
        if ($value === null) return null;
        $type = strtolower($type);
        if (preg_match('/^binary\(16\)/', $type) === 1) return UuidCodec::toBinary((string) $value);
        if (preg_match('/^binary\(32\)/', $type) === 1) {
            $binary = hex2bin((string) $value);
            if ($binary === false || strlen($binary) !== 32) throw new \RuntimeException('SNAPSHOT_BINARY_VALUE_INVALID');
            return $binary;
        }
        return $value;
    }

    private function source(): WpdbCanonicalSnapshotSource
    {
        return $this->readModel ??= new WpdbCanonicalSnapshotSource($this->database, $this->runtime);
    }
}
