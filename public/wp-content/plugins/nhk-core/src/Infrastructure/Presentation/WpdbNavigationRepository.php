<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Presentation;

use NHK\Core\Contracts\PresentationNavigation\NavigationRepository;
use NHK\Core\Domain\PresentationNavigation\NavigationNode;
use NHK\Core\Infrastructure\Migration\PresentationNavigationMigration023;
use NHK\Core\Shared\Uuid\UuidCodec;

final class WpdbNavigationRepository implements NavigationRepository
{
    private string $table;

    /** @param callable(NavigationNode):bool|null $targetValidator */
    public function __construct(private object $database, private $targetValidator = null)
    {
        $this->table = $database->prefix . 'nhk_presentation_navigation';
    }

    public function list(string $navigationKey): array
    {
        $this->ensureAvailable();
        $rows = $this->database->get_results($this->database->prepare("SELECT * FROM {$this->table} WHERE navigation_key=%s ORDER BY sort_order,id", $navigationKey), $this->outputMode()) ?: [];
        return array_values(array_filter(array_map(fn (mixed $row): ?NavigationNode => is_array($row) ? $this->hydrate($row) : null, $rows)));
    }

    public function save(NavigationNode $node, int $expectedRevision): NavigationNode
    {
        $this->ensureAvailable();
        $this->validate($node);
        $now = gmdate('Y-m-d H:i:s.u');
        $canonical = UuidCodec::toBinary($node->canonicalUuid);
        $parent = $node->parentId !== null && ctype_digit($node->parentId) ? (int) $node->parentId : null;
        if ($node->id === '' || $node->id === '0') {
            $parentSql = $parent === null ? 'NULL' : '%d';
            $args = [$node->navigationKey, $node->canonicalType, $canonical];
            if ($parent !== null) $args[] = $parent;
            array_push($args, $node->sortOrder, $node->enabled ? 1 : 0, $node->showInTypeIndex ? 1 : 0, $node->showInHeaderMenu ? 1 : 0, $node->showInMobileMenu ? 1 : 0, $node->showInSidebar ? 1 : 0, $node->featured ? 1 : 0, $now, $now);
            $ok = $this->database->query($this->database->prepare("INSERT INTO {$this->table} (navigation_key,canonical_type,canonical_uuid,parent_id,sort_order,enabled,show_in_type_index,show_in_header_menu,show_in_mobile_menu,show_in_sidebar,featured,revision,created_at,updated_at) VALUES (%s,%s,%s,{$parentSql},%d,%d,%d,%d,%d,%d,%d,1,%s,%s)", ...$args));
            if ($ok !== 1) throw new \RuntimeException('NAVIGATION_INSERT_FAILED');
            $id = (string) $this->database->insert_id;
            return $this->findById($id) ?? throw new \RuntimeException('NAVIGATION_INSERT_READBACK_FAILED');
        }
        $parentSql = $parent === null ? 'NULL' : '%d';
        $args = [];
        if ($parent !== null) $args[] = $parent;
        array_push($args, $node->sortOrder, $node->enabled ? 1 : 0, $node->showInTypeIndex ? 1 : 0, $node->showInHeaderMenu ? 1 : 0, $node->showInMobileMenu ? 1 : 0, $node->showInSidebar ? 1 : 0, $node->featured ? 1 : 0, $now, (int) $node->id, $node->navigationKey, $expectedRevision);
        $ok = $this->database->query($this->database->prepare("UPDATE {$this->table} SET parent_id={$parentSql},sort_order=%d,enabled=%d,show_in_type_index=%d,show_in_header_menu=%d,show_in_mobile_menu=%d,show_in_sidebar=%d,featured=%d,revision=revision+1,updated_at=%s WHERE id=%d AND navigation_key=%s AND revision=%d", ...$args));
        if ($ok !== 1) throw new \RuntimeException('NAVIGATION_REVISION_CONFLICT');
        return $this->findById($node->id) ?? throw new \RuntimeException('NAVIGATION_UPDATE_READBACK_FAILED');
    }

    private function findById(string $id): ?NavigationNode
    {
        $row = $this->database->get_row($this->database->prepare("SELECT * FROM {$this->table} WHERE id=%d LIMIT 1", (int) $id), $this->outputMode());
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): NavigationNode
    {
        $uuid = (string) ($row['canonical_uuid'] ?? '');
        try { $uuid = UuidCodec::fromBinary($uuid); } catch (\Throwable) {}
        $parentId = $row['parent_id'] ?? null;
        if ($parentId === null || (string) $parentId === '' || (int) $parentId === 0) $parentId = null;
        return NavigationNode::fromArray([...$row, 'id' => (string) ($row['id'] ?? ''), 'canonical_uuid' => $uuid, 'parent_id' => $parentId]);
    }

    private function validate(NavigationNode $node): void
    {
        if ($node->navigationKey === '' || $node->canonicalType === '' || !UuidCodec::isValid($node->canonicalUuid)) throw new \InvalidArgumentException('NAVIGATION_NODE_INVALID');
        if ($this->targetValidator !== null && !($this->targetValidator)($node)) throw new \RuntimeException('NAVIGATION_CANONICAL_TARGET_INVALID');
        if ($node->parentId !== null && (!ctype_digit($node->parentId) || $node->parentId === $node->id)) throw new \InvalidArgumentException('NAVIGATION_PARENT_INVALID');
    }

    private function ensureAvailable(): void
    {
        if (!PresentationNavigationMigration023::schemaReady($this->database)) throw new \RuntimeException('PRESENTATION_NAVIGATION_STORAGE_UNAVAILABLE');
    }

    private function outputMode(): mixed { return defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A'; }
}
