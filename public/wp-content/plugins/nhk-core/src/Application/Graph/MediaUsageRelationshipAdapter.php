<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

use NHK\Core\Contracts\Media\MediaUsageRepository;
use NHK\Core\Domain\Media\MediaUsage;

/** Read-only projection over canonical MediaUsage. Writes remain MediaBindingService-owned. */
final class MediaUsageRelationshipAdapter implements RelationshipOwnerAdapter
{
    private const OPERATIONS = ['ADD', 'REPLACE', 'REMOVE', 'REPRESENTATIVE_BIND'];
    public function __construct(private ?MediaUsageRepository $repository = null) {}
    public function kind(): string { return 'media_usage'; }
    public function registry(): array { return ['relationship_kind' => $this->kind(), 'canonical_owner' => 'MediaUsage', 'read_only' => true, 'operations' => self::OPERATIONS, 'cas' => ['replace' => ['usage_id', 'expected_usage_revision'], 'remove' => ['usage_id', 'expected_usage_revision']]]; }
    public function list(array $filters, int $limit = 50, ?string $after = null): array
    {
        $type = trim((string) ($filters['endpoint_type'] ?? '')); $key = trim((string) ($filters['endpoint_key'] ?? ''));
        if ($this->repository === null || $type === '' || $key === '') return ['status' => 'available', 'relationship_kind' => $this->kind(), 'canonical_owner' => 'MediaUsage', 'owner' => 'MediaUsage', 'items' => [], 'next_cursor' => null, 'diagnostics' => ['code' => 'ENDPOINT_SCOPE_REQUIRED', 'read_only' => true]];
        $items = array_map($this->row(...), $this->repository->listByEndpoint($type, $key, isset($filters['role']) ? (string) $filters['role'] : null)); usort($items, static fn (array $a, array $b): int => $a['usage_id'] <=> $b['usage_id']);
        if ($after !== null && $after !== '') $items = array_values(array_filter($items, static fn (array $item): bool => strcmp($item['usage_id'], $after) > 0));
        $page = array_slice($items, 0, min(200, max(1, $limit))); return ['status' => 'available', 'relationship_kind' => $this->kind(), 'canonical_owner' => 'MediaUsage', 'owner' => 'MediaUsage', 'items' => $page, 'next_cursor' => count($items) > count($page) ? $page[array_key_last($page)]['usage_id'] : null];
    }
    public function get(string $id, array $context = []): array { foreach (($this->list($context, 200)['items'] ?? []) as $item) if ($item['usage_id'] === $id) return ['status' => 'available', 'relationship_kind' => $this->kind(), 'canonical_owner' => 'MediaUsage', 'relationship' => $item]; return ['status' => 'not_found', 'relationship_kind' => $this->kind(), 'reason' => 'MEDIA_USAGE_NOT_FOUND']; }
    public function preview(array $input): array
    {
        $operation = strtoupper(trim((string) ($input['operation'] ?? ''))); $base = ['relationship_kind' => $this->kind(), 'canonical_owner' => 'MediaUsage', 'operation' => $operation, 'current_state' => 'UNKNOWN', 'current_relationships' => [], 'current_record' => null, 'revision_state' => ['status' => 'NOT_EVALUATED'], 'dependency_state' => ['status' => 'NOT_EVALUATED'], 'blockers' => [], 'warnings' => [], 'planned_transition' => [], 'safe_to_apply' => false];
        if (!in_array($operation, self::OPERATIONS, true)) return $this->blocked($base, 'INVALID_OPERATION');
        if ($operation !== 'ADD' && (trim((string) ($input['usage_id'] ?? $input['current_relation_id'] ?? '')) === '' || (int) ($input['expected_usage_revision'] ?? $input['expected_revision'] ?? 0) < 1)) return $this->blocked($base, 'USAGE_REVISION_BINDING_REQUIRED');
        $base['revision_state'] = ['status' => 'PASS', 'expected_usage_revision' => $input['expected_usage_revision'] ?? $input['expected_revision'] ?? null]; $base['dependency_state'] = ['status' => 'PASS', 'closure' => []]; $base['planned_transition'] = [['action' => match ($operation) { 'ADD' => 'USAGE_CREATE', 'REPLACE' => 'USAGE_UPDATE', 'REMOVE' => 'USAGE_RETIRE', 'REPRESENTATIVE_BIND' => 'USAGE_REPRESENTATIVE_BIND' }]]; $base['safe_to_apply'] = true; $base['preview_fingerprint'] = hash('sha256', json_encode([$this->kind(), $operation, $input], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); return $base;
    }
    private function row(MediaUsage $usage): array { return ['usage_id' => $usage->usageId, 'media_id' => $usage->mediaId, 'endpoint' => ['type' => $usage->endpointType, 'id' => $usage->endpointKey], 'role' => $usage->role, 'placement_key' => $usage->placementKey, 'active_slot' => $usage->activeSlot, 'revision' => $usage->revision, 'state' => $usage->activeSlot === 'retired' ? 'RETIRED' : 'ACTIVE']; }
    private function blocked(array $base, string $code): array { $base['blockers'][] = $code; return $base; }
}
