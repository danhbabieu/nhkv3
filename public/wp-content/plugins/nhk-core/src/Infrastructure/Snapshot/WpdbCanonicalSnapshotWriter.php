<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Snapshot;

use NHK\Core\Application\Snapshot\{SnapshotCollectionRegistry, SnapshotEnvironment};
use NHK\Core\Contracts\Snapshot\CanonicalSnapshotWriter;
use NHK\Core\Domain\Graph\PredicateRegistry;
use NHK\Core\Shared\Uuid\UuidCodec;

/** Recovery-only transactional persistence for the normalized snapshot port. */
final class WpdbCanonicalSnapshotWriter implements CanonicalSnapshotWriter
{
    private bool $transaction = false;
    private ?string $manifestHash = null;
    private ?WpdbCanonicalSnapshotSource $readModel = null;
    /** @var list<string>|null */
    private ?array $graphPredicateKeys = null;

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
            if ($table === 'nhk_graph_predicates') {
                if (!$this->isAllowlistedPredicateBaseline()) return false;
                continue;
            }
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
            if ($collection === 'graph_predicates') {
                $this->importPredicate($record);
                continue;
            }
            if ($this->isWordPressContextTable($table)) {
                $this->importWordPressContext($table, $record);
                continue;
            }
            $this->insert($table, $record);
        }
    }
    public function commit(string $manifestHash): void
    {
        if (!$this->transaction) throw new \RuntimeException('SNAPSHOT_TRANSACTION_REQUIRED');
        if ($this->database->query('COMMIT') === false) throw new \RuntimeException('SNAPSHOT_TRANSACTION_COMMIT_FAILED');
        $this->transaction = false;
        $this->manifestHash = $manifestHash;
        $this->persistGraphPredicateKeys();
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
        $placeholders = [];
        foreach ($columns as $name => $column) {
            if (!array_key_exists($name, $record)) continue;
            $fields[] = $name;
            if ($record[$name] === null) {
                $placeholders[] = 'NULL';
                continue;
            }
            $placeholders[] = '%s';
            $values[] = $this->storageValue($record[$name], (string) ($column['Type'] ?? ''));
        }
        if ($fields === []) throw new \RuntimeException('SNAPSHOT_RECORD_EMPTY:' . $suffix);
        $query = 'INSERT INTO ' . $source->table($suffix) . ' (`' . implode('`,`', $fields) . '`) VALUES (' . implode(',', $placeholders) . ')';
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
        return $this->readModel ??= new WpdbCanonicalSnapshotSource($this->database, $this->runtime, null, null, null, $this->graphPredicateKeys());
    }

    /** @return list<string> */
    private function graphPredicateKeys(): array
    {
        if ($this->graphPredicateKeys !== null) return $this->graphPredicateKeys;
        $value = function_exists('get_option') ? get_option('nhk_v3_snapshot_graph_predicate_keys', null) : null;
        $this->graphPredicateKeys = is_array($value) ? array_values(array_unique(array_map('strval', $value))) : [];
        return $this->graphPredicateKeys;
    }

    /** @param array<string,mixed> $record */
    private function importPredicate(array $record): void
    {
        $key = trim((string) ($record['predicate_key'] ?? ''));
        if ($key === '') throw new \RuntimeException('SNAPSHOT_PREDICATE_IDENTITY_INVALID');
        $this->graphPredicateKeys ??= [];
        if (!in_array($key, $this->graphPredicateKeys, true)) $this->graphPredicateKeys[] = $key;
        $this->readModel = null;
        $table = $this->source()->table('nhk_graph_predicates');
        $existingRows = $this->database->get_results('SELECT * FROM ' . $table, defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A') ?: [];
        $sourceId = isset($record['id']) ? (int) $record['id'] : null;
        $existing = null;
        foreach ($existingRows as $row) {
            if (($sourceId !== null && (int) ($row['id'] ?? 0) === $sourceId) || (string) ($row['predicate_key'] ?? '') === $key) { $existing = $row; break; }
        }
        if ($existing !== null) {
            if ((string) ($existing['predicate_key'] ?? '') !== $key || ($sourceId !== null && (int) ($existing['id'] ?? 0) !== $sourceId)) throw new \RuntimeException('SNAPSHOT_PREDICATE_IDENTITY_CONFLICT:' . $key);
            if (array_key_exists('created_at', $record) && (string) ($existing['created_at'] ?? '') !== (string) $record['created_at']) {
                $this->database->query($this->database->prepare('UPDATE ' . $table . ' SET `created_at`=%s WHERE `id`=%d', $record['created_at'], (int) $existing['id']));
            }
            return;
        }
        $this->insert('nhk_graph_predicates', $record);
    }

    private function isAllowlistedPredicateBaseline(): bool
    {
        $allowed = array_fill_keys(array_map(static fn (object $definition): string => $definition->key, (new PredicateRegistry())->all()), true);
        $rows = $this->database->get_results('SELECT * FROM ' . $this->source()->table('nhk_graph_predicates'), defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A') ?: [];
        $keys = [];
        foreach ($rows as $row) {
            $key = (string) ($row['predicate_key'] ?? '');
            if ($key === '' || !isset($allowed[$key]) || isset($keys[$key])) return false;
            $keys[$key] = true;
        }
        return true;
    }

    private function isWordPressContextTable(string $table): bool
    {
        return in_array($table, ['posts', 'postmeta', 'terms', 'term_taxonomy', 'termmeta', 'term_relationships'], true);
    }

    /** @param array<string,mixed> $record */
    private function importWordPressContext(string $table, array $record): void
    {
        $columns = $this->source()->columns($table);
        $identity = $this->wordPressIdentity($table, $record, $columns);
        $existing = null;
        foreach ($this->database->get_results('SELECT * FROM ' . $this->source()->table($table), defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A') ?: [] as $row) {
            if ($this->sameWordPressIdentity($row, $identity)) { $existing = $row; break; }
        }
        if ($existing === null) { $this->insert($table, $record); return; }
        $fields = [];
        $values = [];
        foreach ($columns as $name => $column) {
            if (!array_key_exists($name, $record) || array_key_exists($name, $identity)) continue;
            if ($record[$name] === null) {
                $fields[] = '`' . $name . '`=NULL';
                continue;
            }
            $fields[] = '`' . $name . '`=%s';
            $values[] = $this->storageValue($record[$name], (string) ($column['Type'] ?? ''));
        }
        if ($fields === []) return;
        $where = [];
        foreach ($identity as $name => $value) {
            if ($value === null) {
                $where[] = '`' . $name . '` IS NULL';
                continue;
            }
            $where[] = '`' . $name . '`=%s';
            $values[] = $this->storageValue($value, (string) ($columns[$name]['Type'] ?? ''));
        }
        $query = 'UPDATE ' . $this->source()->table($table) . ' SET ' . implode(',', $fields) . ' WHERE ' . implode(' AND ', $where);
        if ($this->database->query($this->database->prepare($query, ...$values)) === false) throw new \RuntimeException('SNAPSHOT_IMPORT_WRITE_FAILED:' . $table);
    }

    /** @param array<string,mixed> $record @param array<string,array<string,mixed>> $columns @return array<string,mixed> */
    private function wordPressIdentity(string $table, array $record, array $columns): array
    {
        if ($table === 'term_relationships') return ['object_id' => $record['object_id'] ?? null, 'term_taxonomy_id' => $record['term_taxonomy_id'] ?? null];
        $primary = array_keys(array_filter($columns, static fn (array $column): bool => (string) ($column['Key'] ?? '') === 'PRI'));
        $field = $primary[0] ?? ($table === 'posts' ? 'ID' : null);
        if ($table === 'posts' && $field !== null && !array_key_exists($field, $record) && array_key_exists('ID', $record)) $field = 'ID';
        if ($field === null || !array_key_exists($field, $record)) throw new \RuntimeException('SNAPSHOT_WORDPRESS_IDENTITY_REQUIRED:' . $table);
        return [$field => $record[$field]];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $identity */
    private function sameWordPressIdentity(array $row, array $identity): bool
    {
        foreach ($identity as $field => $value) if ((string) ($row[$field] ?? '') !== (string) $value) return false;
        return true;
    }

    private function persistGraphPredicateKeys(): void
    {
        $keys = $this->graphPredicateKeys ?? [];
        if (function_exists('update_option')) update_option('nhk_v3_snapshot_graph_predicate_keys', array_values(array_unique($keys)), false);
    }
}
