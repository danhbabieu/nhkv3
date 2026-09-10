<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Governance;

use JsonException;
use NHK\Core\Application\Governance\ControlledApplyOperationRegistry;
use NHK\Core\Contracts\Governance\GovernanceQueueQuery;
use NHK\Core\Domain\Authority\CanonicalEntityTypeCatalog;
use NHK\Core\Domain\Governance\ProposalState;
use NHK\Core\Shared\Uuid\UuidCodec;

final class WpdbGovernanceQueueQuery implements GovernanceQueueQuery
{
    private ControlledApplyOperationRegistry $compatibility;
    /** @var array<string,string> */
    private const SORTS = [
        'created' => 'created_at',
        'updated' => 'updated_at',
        'name' => "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(command_json, '$.name')), JSON_UNQUOTE(JSON_EXTRACT(command_json, '$.title')), JSON_UNQUOTE(JSON_EXTRACT(command_json, '$.text')), JSON_UNQUOTE(JSON_EXTRACT(command_json, '$.claim_text')), entity_type)",
        'id' => 'proposal_uuid',
        'status' => 'state',
    ];

    public function __construct(private ?object $database = null)
    {
        $this->compatibility = new ControlledApplyOperationRegistry();
    }

    public static function isAllowedOrderBy(string $value): bool
    {
        return isset(self::SORTS[$value]);
    }

    public static function isAllowedType(string $value): bool
    {
        $authorityTypes = array_map(static fn ($definition): string => $definition->type, CanonicalEntityTypeCatalog::definitions());
        return in_array($value, array_merge($authorityTypes, ['knowledge', 'source', 'evidence', 'media', 'video', 'wp_post', 'relation']), true);
    }

    /** @return array<string,mixed> */
    public function page(array $filters = []): array
    {
        $filters = $this->normalize($filters);
        [$where, $parameters] = $this->where($filters);
        $db = $this->db();
        $table = $this->table();
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $countSql = 'SELECT COUNT(*) FROM ' . $table . $whereSql;
        $countResult = $db->get_var($parameters === [] ? $countSql : $db->prepare($countSql, ...$parameters));
        $totalItems = $this->count($countResult);
        if ($totalItems === null || $this->hasDatabaseError($db)) {
            return $this->unavailable($filters);
        }
        $offset = ($filters['page'] - 1) * $filters['per_page'];
        $orderExpression = self::SORTS[$filters['order_by']];
        $direction = strtoupper($filters['order']);
        $itemsSql = sprintf(
            'SELECT id, proposal_uuid, entity_type, operation, target_uuid, expected_revision, command_json, state, created_at, updated_at, revision, fingerprint, dependency_fingerprint FROM %s%s ORDER BY %s %s, id %s LIMIT %%d OFFSET %%d',
            $table,
            $whereSql,
            $orderExpression,
            $direction,
            $direction,
        );
        $rows = $db->get_results($db->prepare($itemsSql, ...[...$parameters, $filters['per_page'], $offset]), 'ARRAY_A');
        if (!is_array($rows) || $this->hasDatabaseError($db)) {
            return $this->unavailable($filters, $totalItems);
        }

        $items = [];
        $diagnostics = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $items[] = $this->blockedItem([], 'PROPOSAL_ROW_INVALID');
                $diagnostics[] = 'PROPOSAL_ROW_INVALID';
                continue;
            }
            $item = $this->item($row);
            $items[] = $item;
            if (isset($item['diagnostic'])) {
                $diagnostics[] = $item['diagnostic'];
            }
        }

        return [
            'availability' => $diagnostics === [] ? 'available' : 'blocked',
            'diagnostics' => array_values(array_unique($diagnostics)),
            'items' => $items,
            'total_items' => $totalItems,
            'total_pages' => $totalItems === 0 ? 0 : (int) ceil($totalItems / $filters['per_page']),
            'page' => $filters['page'],
            'per_page' => $filters['per_page'],
            'filters' => $filters,
        ];
    }

    /** @param array{search:string,status:string,type:string,order_by:string,order:string,page:int,per_page:int} $filters @return array<string,mixed> */
    private function unavailable(array $filters, int $totalItems = 0): array
    {
        return [
            'availability' => 'unavailable',
            'diagnostics' => ['PROPOSAL_QUEUE_STORAGE_UNAVAILABLE'],
            'items' => [],
            'total_items' => $totalItems,
            'total_pages' => $totalItems === 0 ? 0 : (int) ceil($totalItems / $filters['per_page']),
            'page' => $filters['page'],
            'per_page' => $filters['per_page'],
            'filters' => $filters,
        ];
    }

    private function db(): object
    {
        global $wpdb;
        return $this->database ?? $wpdb;
    }

    private function table(): string
    {
        return $this->db()->prefix . 'nhk_proposals';
    }

    private function count(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/', $value) !== 1) {
            return null;
        }
        return (int) $value;
    }

    private function hasDatabaseError(object $database): bool
    {
        return property_exists($database, 'last_error') && trim((string) $database->last_error) !== '';
    }

    /** @param array<string,mixed> $filters @return array{search:string,status:string,type:string,order_by:string,order:string,page:int,per_page:int} */
    private function normalize(array $filters): array
    {
        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        $states = array_map(static fn (ProposalState $state): string => $state->value, ProposalState::cases());
        if (!in_array($status, $states, true)) {
            $status = '';
        }

        $type = strtolower(trim((string) ($filters['type'] ?? '')));
        if (!self::isAllowedType($type)) {
            $type = '';
        }

        $orderBy = strtolower(trim((string) ($filters['order_by'] ?? 'updated')));
        if (!self::isAllowedOrderBy($orderBy)) {
            $orderBy = 'updated';
        }

        $order = strtolower(trim((string) ($filters['order'] ?? 'desc')));
        if (!in_array($order, ['asc', 'desc'], true)) {
            $order = 'desc';
        }

        return [
            'search' => substr(strtolower(trim((string) ($filters['search'] ?? ''))), 0, 191),
            'status' => $status,
            'type' => $type,
            'order_by' => $orderBy,
            'order' => $order,
            'page' => max(1, (int) ($filters['page'] ?? 1)),
            'per_page' => min(100, max(1, (int) ($filters['per_page'] ?? 20))),
        ];
    }

    /** @return list<string> */
    private function types(): array
    {
        $authorityTypes = array_map(static fn ($definition): string => $definition->type, CanonicalEntityTypeCatalog::definitions());
        return array_values(array_unique(array_merge($authorityTypes, ['knowledge', 'source', 'evidence', 'media', 'video', 'wp_post', 'relation'])));
    }

    /** @param array{search:string,status:string,type:string,order_by:string,order:string,page:int,per_page:int} $filters @return array{list<string>,list<mixed>} */
    private function where(array $filters): array
    {
        $where = [];
        $parameters = [];
        if ($filters['search'] !== '') {
            $like = '%' . $this->escapeLike($filters['search']) . '%';
            $uuidLike = '%' . $this->escapeLike(str_replace('-', '', $filters['search'])) . '%';
            $where[] = '(LOWER(HEX(proposal_uuid)) LIKE %s OR LOWER(HEX(target_uuid)) LIKE %s OR LOWER(entity_type) LIKE %s OR LOWER(command_json) LIKE %s)';
            array_push($parameters, $uuidLike, $uuidLike, $like, $like);
        }
        if ($filters['status'] !== '') {
            $where[] = 'state = %d';
            $parameters[] = array_search($filters['status'], array_map(static fn (ProposalState $state): string => $state->value, ProposalState::cases()), true) + 1;
        }
        if ($filters['type'] !== '') {
            $where[] = 'entity_type = %s';
            $parameters[] = $filters['type'];
        }

        return [$where, $parameters];
    }

    private function escapeLike(string $value): string
    {
        if (method_exists($this->db(), 'esc_like')) {
            return $this->db()->esc_like($value);
        }
        return addcslashes($value, '_%\\');
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function item(array $row): array
    {
        $diagnostic = $this->rowDiagnostic($row);
        if ($diagnostic !== null) {
            return $this->blockedItem($row, $diagnostic);
        }

        $status = $this->state($row['state']);
        $proposalId = $this->uuid($row['proposal_id'] ?? $row['proposal_uuid']);
        $targetUuid = $this->targetUuid($row['target_uuid'] ?? null);
        $base = [
            'proposal_id' => $proposalId,
            'entity_type' => (string) ($row['entity_type'] ?? ''),
            'operation' => (string) ($row['operation'] ?? ''),
            'subject_id' => '',
            'target_uuid' => $targetUuid,
            'name' => '',
            'summary' => '',
            'status' => $status->value,
            'status_label' => $this->statusLabel($status),
            'provenance_summary' => '',
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'revision' => (int) ($row['revision'] ?? 0),
            'content_fingerprint' => $this->fingerprint($row['content_fingerprint'] ?? $row['fingerprint'] ?? null),
            'dependency_fingerprint' => $this->fingerprint($row['dependency_fingerprint'] ?? null),
            'actionable' => in_array($status->value, [ProposalState::DRAFT->value, ProposalState::SUBMITTED->value, ProposalState::APPROVED->value], true),
        ];

        try {
            $payload = json_decode((string) ($row['command_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return array_replace($base, [
                'name' => 'Dữ liệu đề xuất không thể đọc',
                'summary' => 'JSON đề xuất không hợp lệ.',
                'diagnostic' => 'PROPOSAL_PAYLOAD_MALFORMED',
                'actionable' => false,
            ]);
        }
        if (!is_array($payload)) {
            return array_replace($base, [
                'name' => 'Dữ liệu đề xuất không thể đọc',
                'summary' => 'JSON đề xuất không hợp lệ.',
                'diagnostic' => 'PROPOSAL_PAYLOAD_MALFORMED',
                'actionable' => false,
            ]);
        }

        $subjectId = $this->string($payload['subject_id'] ?? null);
        $name = $this->firstString($payload, ['name', 'title']);
        $summary = $this->firstString($payload, ['summary', 'text', 'claim_text', 'description']);
        return array_replace($base, [
            'subject_id' => $subjectId !== '' ? $subjectId : ($base['target_uuid'] ?: $base['entity_type']),
            'name' => $name !== '' ? $name : $base['entity_type'],
            'summary' => $summary,
            'provenance_summary' => $this->provenanceSummary($payload),
        ]);
    }

    /** @param array<string,mixed> $row */
    private function rowDiagnostic(array $row): ?string
    {
        if ($this->uuid($row['proposal_id'] ?? $row['proposal_uuid'] ?? null) === null) {
            return 'PROPOSAL_IDENTITY_INVALID';
        }
        if ($this->state($row['state'] ?? null) === null) {
            return 'PROPOSAL_STATE_INVALID';
        }
        if (!$this->targetIsValid($row['target_uuid'] ?? null)) {
            return 'PROPOSAL_TARGET_INVALID';
        }
        if (!$this->revisionIsValid($row['revision'] ?? null)) {
            return 'PROPOSAL_REVISION_INVALID';
        }
        if (!$this->bindingIsValid($row)) {
            return 'PROPOSAL_BINDING_INVALID';
        }
        if (!$this->fingerprintIsValid($row['fingerprint'] ?? $row['content_fingerprint'] ?? null)
            || !$this->fingerprintIsValid($row['dependency_fingerprint'] ?? null)) {
            return 'PROPOSAL_FINGERPRINT_INVALID';
        }
        return null;
    }

    /** @param array<string,mixed> $row */
    private function bindingIsValid(array $row): bool
    {
        $entityType = trim((string) ($row['entity_type'] ?? ''));
        $operation = trim((string) ($row['operation'] ?? ''));
        if ($entityType === '' || $operation === '' || !in_array($entityType, $this->types(), true) || !$this->compatibility->supports($entityType, $operation)) return false;

        $expected = $row['expected_revision'] ?? null;
        $isTargetlessCreate = in_array($operation, ['create', 'ingest'], true) && $this->targetUuid($row['target_uuid'] ?? null) === null;
        if ($operation === 'relation_create') return $expected === null || $expected === '' || (string) $expected === '0';
        if ($isTargetlessCreate && ($expected === null || $expected === '' || (string) $expected === '0')) return true;
        return $this->revisionIsValid($expected);
    }

    private function state(mixed $value): ?ProposalState
    {
        if (is_int($value)) {
            $ordinal = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $ordinal = (int) $value;
        } else {
            return null;
        }
        $states = ProposalState::cases();
        return $ordinal >= 1 && $ordinal <= count($states) ? $states[$ordinal - 1] : null;
    }

    private function uuid(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            if (strlen($value) === 16) {
                $uuid = UuidCodec::fromBinary($value);
                return UuidCodec::isValid($uuid) ? $uuid : null;
            }
            $hex = strtolower(str_replace('-', '', $value));
            if (preg_match('/^[a-f0-9]{32}$/', $hex) !== 1) {
                return null;
            }
            $uuid = UuidCodec::fromBinary(hex2bin($hex));
            return UuidCodec::isValid($uuid) ? $uuid : null;
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function targetUuid(mixed $value): ?string
    {
        if ($value === null || $value === '' || (is_string($value) && strlen($value) === 16 && bin2hex($value) === str_repeat('0', 32))) {
            return null;
        }
        return $this->uuid($value);
    }

    private function targetIsValid(mixed $value): bool
    {
        return $this->targetUuid($value) !== null || $value === null || $value === '' || (is_string($value) && strlen($value) === 16 && bin2hex($value) === str_repeat('0', 32));
    }

    private function revisionIsValid(mixed $value): bool
    {
        return (is_int($value) && $value > 0) || (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1);
    }

    private function fingerprintIsValid(mixed $value): bool
    {
        return is_string($value) && strlen($value) === 32;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function blockedItem(array $row, string $diagnostic): array
    {
        return [
            'proposal_id' => $this->uuid($row['proposal_id'] ?? $row['proposal_uuid'] ?? null),
            'entity_type' => (string) ($row['entity_type'] ?? ''),
            'operation' => (string) ($row['operation'] ?? ''),
            'subject_id' => '',
            'target_uuid' => $this->targetUuid($row['target_uuid'] ?? null),
            'name' => 'Dữ liệu đề xuất không hợp lệ',
            'summary' => 'Bản ghi Proposal không đạt điều kiện đọc an toàn.',
            'status' => 'blocked',
            'status_label' => 'Dữ liệu bị chặn',
            'provenance_summary' => '',
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'revision' => 0,
            'content_fingerprint' => '',
            'dependency_fingerprint' => '',
            'actionable' => false,
            'diagnostic' => $diagnostic,
        ];
    }

    private function fingerprint(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        return strlen($value) === 32 ? bin2hex($value) : strtolower($value);
    }

    /** @param array<string,mixed> $payload @param list<string> $keys */
    private function firstString(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $this->string($payload[$key] ?? null);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? trim(substr($value, 0, 500)) : '';
    }

    /** @param array<string,mixed> $payload */
    private function provenanceSummary(array $payload): string
    {
        $parts = [];
        foreach (['provenance', 'source'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $parts[] = $this->string($value);
                continue;
            }
            if (is_array($value)) {
                $summary = $this->firstString($value, ['kind', 'source_class', 'type', 'title', 'name']);
                if ($summary !== '') {
                    $parts[] = $summary;
                }
            }
        }
        return implode(' · ', array_values(array_unique($parts)));
    }

    private function statusLabel(ProposalState $state): string
    {
        return match ($state) {
            ProposalState::DRAFT => 'Chờ kiểm tra',
            ProposalState::SUBMITTED => 'Chờ phê duyệt',
            ProposalState::APPROVED => 'Đã phê duyệt',
            ProposalState::REJECTED => 'Đã từ chối',
            ProposalState::CANCELLED => 'Đã hủy',
            ProposalState::SUPERSEDED => 'Đã thay thế',
            ProposalState::APPLIED => 'Đã áp dụng',
        };
    }
}
