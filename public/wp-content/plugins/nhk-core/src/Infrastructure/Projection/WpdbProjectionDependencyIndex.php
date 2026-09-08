<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Projection;

use NHK\Core\Contracts\Projection\ProjectionDependencyIndex;

final class WpdbProjectionDependencyIndex implements ProjectionDependencyIndex
{
    private string $table;
    private WpdbProjectionSchema $schema;
    public function __construct(private object $database, ?WpdbProjectionSchema $schema = null) { $this->table = $database->prefix . 'nhk_claim_projection_dependencies'; $this->schema = $schema ?? new WpdbProjectionSchema($database); }
    public function status(): array { return $this->schema->status(); }

    public function add(array $dependency): void
    {
        $this->ensureAvailable();
        $now = gmdate('Y-m-d H:i:s.u');
        $sql = "INSERT INTO {$this->table} (dependency_kind,dependency_id,node_uuid,node_type,section_key,scope,graph_distance,dependency_revision,created_at) VALUES (%s,%s,%s,%s,%s,%s,%d,%d,%s) ON DUPLICATE KEY UPDATE node_type=VALUES(node_type),scope=VALUES(scope),graph_distance=VALUES(graph_distance),dependency_revision=VALUES(dependency_revision)";
        $ok = $this->database->query($this->database->prepare($sql, (string) ($dependency['kind'] ?? ''), (string) ($dependency['id'] ?? ''), (string) ($dependency['node_uuid'] ?? ''), (string) ($dependency['node_type'] ?? ''), (string) ($dependency['section_key'] ?? ''), (string) ($dependency['scope'] ?? 'direct'), (int) ($dependency['graph_distance'] ?? 0), (int) ($dependency['dependency_revision'] ?? 1), $now));
        if ($ok === false) throw new \RuntimeException('PROJECTION_DEPENDENCY_WRITE_FAILED');
    }

    public function findByDependency(string $kind, string $id): array
    {
        $this->ensureAvailable();
        $output = defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
        $rows = $this->database->get_results($this->database->prepare("SELECT dependency_kind AS kind,dependency_id AS id,node_uuid,node_type,section_key,scope,graph_distance,dependency_revision FROM {$this->table} WHERE dependency_kind=%s AND dependency_id=%s", $kind, $id), $output);
        return is_array($rows) ? $rows : [];
    }

    public function removeForNode(string $nodeUuid): void { $this->ensureAvailable(); $this->database->query($this->database->prepare("DELETE FROM {$this->table} WHERE node_uuid=%s", $nodeUuid)); }
    private function ensureAvailable(): void { if (($this->schema->status()['status'] ?? 'unavailable') !== 'available') throw new \RuntimeException('PROJECTION_STORAGE_UNAVAILABLE'); }
}
