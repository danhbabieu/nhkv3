<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Governance\WpdbGovernanceQueueQuery;
use PHPUnit\Framework\TestCase;

final class GovernanceQueueQueryTest extends TestCase
{
    private RecordingProposalDatabase $db;
    private WpdbGovernanceQueueQuery $query;
    private string $proposalId = '0198f8d5-1d55-7a10-8d4e-5f0d9d8d0001';
    private string $targetId = '0198f8d5-1d55-7a11-8d4e-5f0d9d8d0002';

    protected function setUp(): void
    {
        $this->db = new RecordingProposalDatabase([$this->proposalRow()], 1);
        $this->query = new WpdbGovernanceQueueQuery($this->db);
    }

    public function test_exact_uuid_search_is_pushed_into_sql_and_returns_total(): void
    {
        $page = $this->query->page(['search' => $this->proposalId]);

        self::assertSame(1, $page['total_items']);
        self::assertStringContainsString('HEX(proposal_uuid)', $this->db->lastPrepared);
        self::assertSame($this->proposalId, $page['items'][0]['proposal_id']);
        self::assertCount(2, $this->db->preparedStatements);
        self::assertStringContainsString('HEX(proposal_uuid)', $this->db->preparedStatements[0]['template']);
        self::assertStringContainsString('HEX(proposal_uuid)', $this->db->preparedStatements[1]['template']);
        self::assertSame('%' . str_replace('-', '', $this->proposalId) . '%', $this->db->preparedStatements[0]['arguments'][0]);
        self::assertSame('%' . str_replace('-', '', $this->proposalId) . '%', $this->db->preparedStatements[0]['arguments'][1]);
        self::assertSame('%' . $this->proposalId . '%', $this->db->preparedStatements[0]['arguments'][2]);
        self::assertSame($this->db->preparedStatements[0]['arguments'], array_slice($this->db->preparedStatements[1]['arguments'], 0, -2));
    }

    public function test_subject_name_and_status_filters_are_server_side(): void
    {
        $page = $this->query->page(['search' => 'Vertical Brand', 'status' => 'submitted']);

        self::assertSame('submitted', $page['filters']['status']);
        self::assertStringContainsString('command_json', $this->db->lastPrepared);
        self::assertStringContainsString('state', $this->db->lastPrepared);
        self::assertSame('Vertical Brand', $page['items'][0]['name']);
    }

    public function test_uuid_and_status_filters_change_items_and_count_consistently(): void
    {
        $other = $this->proposalRow();
        $other['id'] = 45;
        $other['proposal_uuid'] = hex2bin(str_replace('-', '', '0198f8d5-1d55-7a12-8d4e-5f0d9d8d0003'));
        $other['entity_type'] = 'knowledge';
        $other['state'] = 1;
        $other['command_json'] = json_encode(['name' => 'Other record', 'subject_id' => 'other'], JSON_THROW_ON_ERROR);
        $this->db = new RecordingProposalDatabase([$this->proposalRow(), $other], 2, true);
        $this->query = new WpdbGovernanceQueueQuery($this->db);

        $matching = $this->query->page(['search' => $this->proposalId, 'status' => 'submitted']);
        $targetMatching = $this->query->page(['search' => $this->targetId, 'status' => 'submitted']);
        $nonMatching = $this->query->page(['search' => '0198f8d5-1d55-7a13', 'status' => 'draft']);

        self::assertSame(1, $matching['total_items']);
        self::assertCount(1, $matching['items']);
        self::assertSame($this->proposalId, $matching['items'][0]['proposal_id']);
        self::assertSame(1, $targetMatching['total_items']);
        self::assertSame($this->proposalId, $targetMatching['items'][0]['proposal_id']);
        self::assertSame(0, $nonMatching['total_items']);
        self::assertCount(0, $nonMatching['items']);
    }

    public function test_invalid_sort_falls_back_to_updated_desc_with_internal_id_tiebreaker(): void
    {
        $this->query->page(['order_by' => 'drop_table', 'order' => 'sideways']);

        self::assertSame('updated', $this->db->lastFilters['order_by']);
        self::assertStringContainsString('updated_at DESC', $this->db->lastPrepared);
        self::assertStringContainsString('id DESC', $this->db->lastPrepared);
    }

    public function test_page_boundaries_and_total_pages_are_database_backed(): void
    {
        $this->db->countResult = 5;

        $page = $this->query->page(['page' => 3, 'per_page' => 2]);

        self::assertSame(3, $page['page']);
        self::assertSame(2, $page['per_page']);
        self::assertSame(3, $page['total_pages']);
        self::assertStringContainsString('LIMIT 2 OFFSET 4', $this->db->lastPrepared);
    }

    /** @dataProvider behavioralSortOrders */
    public function test_behavioral_sorting_orders_rows_and_applies_id_tiebreaker(string $orderBy, string $order, array $expectedIds): void
    {
        $rows = $this->sortableRows();
        $this->db = new RecordingProposalDatabase($rows, count($rows), true);
        $this->query = new WpdbGovernanceQueueQuery($this->db);

        $page = $this->query->page(['order_by' => $orderBy, 'order' => $order, 'per_page' => 20]);

        self::assertSame($expectedIds, array_map(static fn (array $item): int => (int) $item['revision'], $page['items']));
        self::assertSame(count($expectedIds), $page['total_items']);
    }

    /** @return iterable<string,array{string,string,list<int>}> */
    public static function behavioralSortOrders(): iterable
    {
        yield 'created ascending' => ['created', 'asc', [101, 102, 104, 103]];
        yield 'created descending' => ['created', 'desc', [103, 104, 102, 101]];
        yield 'updated ascending' => ['updated', 'asc', [102, 103, 104, 101]];
        yield 'updated descending' => ['updated', 'desc', [101, 104, 103, 102]];
        yield 'name ascending' => ['name', 'asc', [102, 101, 103, 104]];
        yield 'name descending' => ['name', 'desc', [104, 103, 101, 102]];
        yield 'id ascending' => ['id', 'asc', [101, 102, 103, 104]];
        yield 'id descending' => ['id', 'desc', [104, 103, 102, 101]];
        yield 'status ascending' => ['status', 'asc', [102, 103, 101, 104]];
        yield 'status descending' => ['status', 'desc', [104, 101, 103, 102]];
    }

    public function test_behavioral_sorting_is_applied_before_page_slicing_at_tied_boundary(): void
    {
        $rows = $this->sortableRows();
        $this->db = new RecordingProposalDatabase($rows, count($rows), true);
        $this->query = new WpdbGovernanceQueueQuery($this->db);

        $page = $this->query->page(['order_by' => 'name', 'order' => 'asc', 'page' => 2, 'per_page' => 2]);

        self::assertSame([103, 104], array_map(static fn (array $item): int => (int) $item['revision'], $page['items']));
        self::assertSame(2, $page['page']);
        self::assertSame(2, $page['per_page']);
        self::assertSame(2, $page['total_pages']);
    }

    public function test_partial_uuid_search_and_type_filter_are_bounded_server_side(): void
    {
        $page = $this->query->page([
            'search' => '0198F8D5',
            'type' => 'brand',
            'per_page' => 999,
        ]);

        self::assertSame('0198f8d5', $page['filters']['search']);
        self::assertSame('brand', $page['filters']['type']);
        self::assertSame(100, $page['per_page']);
        self::assertStringContainsString('entity_type', $this->db->lastPrepared);
        self::assertStringContainsString('LIMIT 100 OFFSET 0', $this->db->lastPrepared);
    }

    /** @dataProvider canonicalBindingRows */
    public function test_canonical_binding_errors_are_blocked_but_supported_exceptions_remain_actionable(callable $change, bool $actionable, ?string $diagnostic): void
    {
        $this->db = new RecordingProposalDatabase([$change($this->proposalRow())], 1);
        $this->query = new WpdbGovernanceQueueQuery($this->db);

        $page = $this->query->page();

        self::assertSame($actionable, $page['items'][0]['actionable']);
        self::assertSame($diagnostic === null ? [] : [$diagnostic], $page['diagnostics']);
    }

    /** @return iterable<string,array{callable,bool,?string}> */
    public static function canonicalBindingRows(): iterable
    {
        yield 'zero expected revision on rename' => [static fn (array $row): array => array_replace($row, ['expected_revision' => 0]), false, 'PROPOSAL_BINDING_INVALID'];
        yield 'empty operation' => [static fn (array $row): array => array_replace($row, ['operation' => '']), false, 'PROPOSAL_BINDING_INVALID'];
        yield 'empty entity type' => [static fn (array $row): array => array_replace($row, ['entity_type' => '']), false, 'PROPOSAL_BINDING_INVALID'];
        yield 'unsupported authority operation' => [static fn (array $row): array => array_replace($row, ['operation' => 'bogus_operation']), false, 'PROPOSAL_BINDING_INVALID'];
        yield 'unknown entity type' => [static fn (array $row): array => array_replace($row, ['entity_type' => 'unknown_type']), false, 'PROPOSAL_BINDING_INVALID'];
        yield 'relation create normalizes expected revision' => [static fn (array $row): array => array_replace($row, ['entity_type' => 'relation', 'operation' => 'relation_create', 'expected_revision' => 0, 'command_json' => json_encode(['source_uuid' => '0198f8d5-1d55-7a10-8d4e-5f0d9d8d0001'])]), true, null];
        yield 'positive relation create expected revision is invalid' => [static fn (array $row): array => array_replace($row, ['entity_type' => 'relation', 'operation' => 'relation_create', 'expected_revision' => 1, 'command_json' => json_encode(['source_uuid' => '0198f8d5-1d55-7a10-8d4e-5f0d9d8d0001'])]), false, 'PROPOSAL_BINDING_INVALID'];
        yield 'targetless create accepts zero expected revision' => [static fn (array $row): array => array_replace($row, ['operation' => 'create', 'target_uuid' => null, 'expected_revision' => 0]), true, null];
        yield 'targetless create accepts null expected revision' => [static fn (array $row): array => array_replace($row, ['operation' => 'create', 'target_uuid' => null, 'expected_revision' => null]), true, null];
        yield 'targetless create accepts empty expected revision' => [static fn (array $row): array => array_replace($row, ['operation' => 'create', 'target_uuid' => null, 'expected_revision' => '']), true, null];
        yield 'targetless create accepts positive expected revision' => [static fn (array $row): array => array_replace($row, ['operation' => 'create', 'target_uuid' => null, 'expected_revision' => 4]), true, null];
        yield 'targetless ingest accepts null expected revision' => [static fn (array $row): array => array_replace($row, ['operation' => 'ingest', 'target_uuid' => null, 'expected_revision' => null]), true, null];
        yield 'targetless ingest accepts empty expected revision' => [static fn (array $row): array => array_replace($row, ['operation' => 'ingest', 'target_uuid' => null, 'expected_revision' => '']), true, null];
        yield 'targetless ingest accepts zero expected revision' => [static fn (array $row): array => array_replace($row, ['operation' => 'ingest', 'target_uuid' => null, 'expected_revision' => 0]), true, null];
        yield 'targetless ingest accepts positive expected revision' => [static fn (array $row): array => array_replace($row, ['operation' => 'ingest', 'target_uuid' => null, 'expected_revision' => 4]), true, null];
    }

    /** @dataProvider sortableOrders */
    public function test_allowlisted_sorting_is_server_side_and_uses_a_stable_id_tiebreaker(string $orderBy, string $order, string $expected): void
    {
        $this->query->page(['order_by' => $orderBy, 'order' => $order]);

        self::assertSame($orderBy, $this->db->lastFilters['order_by']);
        self::assertStringContainsString($expected, $this->db->lastPrepared);
        self::assertStringContainsString('id ' . strtoupper($order), $this->db->lastPrepared);
    }

    /** @return iterable<string,array{string,string,string}> */
    public static function sortableOrders(): iterable
    {
        yield 'created ascending' => ['created', 'asc', 'created_at ASC'];
        yield 'created descending' => ['created', 'desc', 'created_at DESC'];
        yield 'name ascending' => ['name', 'asc', 'JSON_UNQUOTE(JSON_EXTRACT(command_json'];
        yield 'name descending' => ['name', 'desc', 'JSON_UNQUOTE(JSON_EXTRACT(command_json'];
        yield 'status ascending' => ['status', 'asc', 'state ASC'];
        yield 'status descending' => ['status', 'desc', 'state DESC'];
    }

    public function test_empty_result_is_an_available_empty_page(): void
    {
        $this->db = new RecordingProposalDatabase([], 0);
        $this->query = new WpdbGovernanceQueueQuery($this->db);

        $page = $this->query->page();

        self::assertSame('available', $page['availability']);
        self::assertSame([], $page['items']);
        self::assertSame(0, $page['total_items']);
        self::assertSame(0, $page['total_pages']);
        self::assertCount(1, $this->db->preparedStatements);
        self::assertStringContainsString('LIMIT', $this->db->preparedStatements[0]['template']);
    }

    public function test_count_failure_is_an_unavailable_queue_not_an_empty_success(): void
    {
        $this->db->countResult = null;

        $page = $this->query->page(['status' => 'submitted']);

        self::assertSame('unavailable', $page['availability']);
        self::assertSame(['PROPOSAL_QUEUE_STORAGE_UNAVAILABLE'], $page['diagnostics']);
        self::assertSame([], $page['items']);
        self::assertCount(0, $this->db->itemQueries);
    }

    public function test_item_failure_is_an_unavailable_queue_not_an_empty_success(): void
    {
        $this->db->resultsResult = false;

        $page = $this->query->page(['status' => 'submitted']);

        self::assertSame('unavailable', $page['availability']);
        self::assertSame(['PROPOSAL_QUEUE_STORAGE_UNAVAILABLE'], $page['diagnostics']);
        self::assertSame(1, $page['total_items']);
        self::assertSame([], $page['items']);
    }

    public function test_database_error_indicator_blocks_a_zero_count_result(): void
    {
        $this->db->countResult = 0;
        $this->db->countError = 'Table wp_nhk_proposals does not exist';

        $page = $this->query->page(['status' => 'submitted']);

        self::assertSame('unavailable', $page['availability']);
        self::assertSame(['PROPOSAL_QUEUE_STORAGE_UNAVAILABLE'], $page['diagnostics']);
        self::assertSame(0, $page['total_items']);
    }

    public function test_malformed_payload_returns_a_blocked_diagnostic_instead_of_false_success(): void
    {
        $row = $this->proposalRow();
        $row['command_json'] = '{not-json';
        $this->db = new RecordingProposalDatabase([$row], 1);
        $this->query = new WpdbGovernanceQueueQuery($this->db);

        $page = $this->query->page();

        self::assertSame('blocked', $page['availability']);
        self::assertSame(['PROPOSAL_PAYLOAD_MALFORMED'], $page['diagnostics']);
        self::assertSame(1, $page['total_items']);
    }

    /** @dataProvider invalidPersistedRows */
    public function test_invalid_persisted_proposal_rows_are_blocked_and_not_actionable(callable $change, string $diagnostic): void
    {
        $this->db = new RecordingProposalDatabase([$change($this->proposalRow())], 1);
        $this->query = new WpdbGovernanceQueueQuery($this->db);

        $page = $this->query->page(['status' => 'submitted']);

        self::assertSame('blocked', $page['availability']);
        self::assertSame([$diagnostic], $page['diagnostics']);
        self::assertSame('blocked', $page['items'][0]['status']);
        self::assertFalse($page['items'][0]['actionable']);
    }

    /** @return iterable<string,array{callable,string}> */
    public static function invalidPersistedRows(): iterable
    {
        yield 'state below enum range' => [static fn (array $row): array => array_replace($row, ['state' => 0]), 'PROPOSAL_STATE_INVALID'];
        yield 'state above enum range' => [static fn (array $row): array => array_replace($row, ['state' => 8]), 'PROPOSAL_STATE_INVALID'];
        yield 'nil proposal uuid' => [static fn (array $row): array => array_replace($row, ['proposal_uuid' => null]), 'PROPOSAL_IDENTITY_INVALID'];
        yield 'malformed proposal uuid' => [static fn (array $row): array => array_replace($row, ['proposal_uuid' => 'invalid']), 'PROPOSAL_IDENTITY_INVALID'];
        yield 'malformed target uuid' => [static fn (array $row): array => array_replace($row, ['target_uuid' => str_repeat("\xff", 16)]), 'PROPOSAL_TARGET_INVALID'];
        yield 'zero revision' => [static fn (array $row): array => array_replace($row, ['revision' => 0]), 'PROPOSAL_REVISION_INVALID'];
        yield 'short content fingerprint' => [static fn (array $row): array => array_replace($row, ['fingerprint' => str_repeat('a', 31)]), 'PROPOSAL_FINGERPRINT_INVALID'];
        yield 'short dependency fingerprint' => [static fn (array $row): array => array_replace($row, ['dependency_fingerprint' => str_repeat('b', 31)]), 'PROPOSAL_FINGERPRINT_INVALID'];
    }

    /** @return array<string,mixed> */
    private function proposalRow(): array
    {
        return [
            'id' => 44,
            'proposal_uuid' => hex2bin(str_replace('-', '', $this->proposalId)),
            'entity_type' => 'brand',
            'operation' => 'rename',
            'expected_revision' => 3,
            'target_uuid' => hex2bin(str_replace('-', '', $this->targetId)),
            'command_json' => json_encode([
                'name' => 'Vertical Brand',
                'text' => 'Đổi tên thương hiệu theo hồ sơ đối chiếu.',
                'provenance' => ['kind' => 'CATALOG_SUPPORTED'],
                'source' => ['title' => 'Sổ tay kỹ thuật'],
                'subject_id' => $this->targetId,
                'private_blob' => 'must not be exposed',
            ], JSON_THROW_ON_ERROR),
            'state' => 2,
            'created_at' => '2026-09-10 01:02:03.000000',
            'updated_at' => '2026-09-10 04:05:06.000000',
            'revision' => 3,
            'fingerprint' => hex2bin(str_repeat('a', 64)),
            'dependency_fingerprint' => hex2bin(str_repeat('b', 64)),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function sortableRows(): array
    {
        $rows = [];
        foreach ([
            [10, 101, '2026-09-10 01:00:00', '2026-09-10 04:00:00', 'Bravo', 2],
            [20, 102, '2026-09-10 01:00:00', '2026-09-10 01:00:00', 'Alpha', 1],
            [30, 103, '2026-09-10 03:00:00', '2026-09-10 02:00:00', 'Delta', 1],
            [40, 104, '2026-09-10 02:00:00', '2026-09-10 03:00:00', 'Delta', 2],
        ] as [$id, $revision, $created, $updated, $name, $state]) {
            $row = $this->proposalRow();
            $row['id'] = $id;
            $row['revision'] = $revision;
            $row['created_at'] = $created;
            $row['updated_at'] = $updated;
            $row['command_json'] = json_encode(['name' => $name], JSON_THROW_ON_ERROR);
            $row['state'] = $state;
            $rows[] = $row;
        }
        return $rows;
    }
}

final class RecordingProposalDatabase
{
    public string $prefix = 'wp_';
    public string $lastPrepared = '';
    /** @var array<string,string> */
    public array $lastFilters = [];
    /** @var list<array{template:string,arguments:list<mixed>,sql:string}> */
    public array $preparedStatements = [];
    /** @var list<string> */
    public array $countQueries = [];
    /** @var list<string> */
    public array $itemQueries = [];
    public string $last_error = '';
    public ?string $countError = null;
    public ?string $itemsError = null;
    public mixed $countResult;
    public mixed $resultsResult;
    /** @var list<array<string,mixed>> */
    public array $rows;

    /** @param list<array<string,mixed>> $rows */
    public function __construct(array $rows, public int $count, private bool $behavioral = false)
    {
        $this->rows = $rows;
        $this->countResult = $count;
        $this->resultsResult = $rows;
    }

    public function prepare(string $query, mixed ...$args): string
    {
        if (preg_match('/%[dfs]/', $query) !== 1) {
            throw new \LogicException('wpdb::prepare requires a placeholder.');
        }
        $argumentIndex = 0;
        $prepared = (string) preg_replace_callback('/%[dfs]/', static function (array $match) use (&$argumentIndex, $args): string {
            $argument = $args[$argumentIndex++] ?? null;
            return match ($match[0]) {
                '%d' => (string) (int) $argument,
                '%f' => (string) (float) $argument,
                default => "'" . addslashes((string) $argument) . "'",
            };
        }, $query);

        $this->lastPrepared = $prepared;
        $this->preparedStatements[] = ['template' => $query, 'arguments' => $args, 'sql' => $prepared];
        $this->lastFilters = [
            'order_by' => str_contains($prepared, 'ORDER BY updated_at') ? 'updated'
                : (str_contains($prepared, 'ORDER BY created_at') ? 'created'
                    : (str_contains($prepared, 'ORDER BY state') ? 'status'
                        : (str_contains($prepared, 'ORDER BY proposal_uuid') ? 'id' : 'name'))),
        ];

        return $prepared;
    }

    public function get_results(string $query, mixed $output): mixed
    {
        $this->itemQueries[] = $query;
        $this->last_error = $this->itemsError ?? '';
        if (!$this->behavioral || !is_array($this->resultsResult)) return $this->resultsResult;
        $rows = $this->matchingRows($query);
        preg_match('/LIMIT (\d+) OFFSET (\d+)/', $query, $paging);
        return array_slice($rows, (int) ($paging[2] ?? 0), (int) ($paging[1] ?? count($rows)));
    }

    public function get_var(string $query): mixed
    {
        $this->countQueries[] = $query;
        $this->last_error = $this->countError ?? '';
        return $this->behavioral ? count($this->matchingRows($query)) : $this->countResult;
    }

    /** @return list<array<string,mixed>> */
    private function matchingRows(string $query): array
    {
        $rows = $this->rows;
        preg_match_all("/LIKE '([^']*)'/", $query, $likes);
        if (count($likes[1]) >= 4) {
            $search = strtolower($likes[1][0]);
            $rows = array_values(array_filter($rows, static function (array $row) use ($search): bool {
                $json = strtolower((string) ($row['command_json'] ?? ''));
                $proposal = strtolower(bin2hex((string) ($row['proposal_uuid'] ?? '')));
                $target = strtolower(bin2hex((string) ($row['target_uuid'] ?? '')));
                return str_contains($proposal, trim($search, '%')) || str_contains($target, trim($search, '%'))
                    || str_contains(strtolower((string) ($row['entity_type'] ?? '')), trim($search, '%'))
                    || str_contains($json, trim($search, '%'));
            }));
        }
        if (preg_match('/state = (\d+)/', $query, $state)) $rows = array_values(array_filter($rows, static fn (array $row): bool => (int) $row['state'] === (int) $state[1]));
        if (preg_match("/entity_type = '([^']*)'/", $query, $type)) $rows = array_values(array_filter($rows, static fn (array $row): bool => (string) $row['entity_type'] === $type[1]));
        if (preg_match('/ORDER BY (.+?) (ASC|DESC), id (ASC|DESC)/', $query, $order)) {
            $expression = $order[1];
            $direction = $order[2] === 'ASC' ? 1 : -1;
            usort($rows, static function (array $left, array $right) use ($expression, $direction): int {
                $leftValue = match (true) {
                    str_contains($expression, 'created_at') => (string) $left['created_at'],
                    str_contains($expression, 'updated_at') => (string) $left['updated_at'],
                    str_contains($expression, 'proposal_uuid') => bin2hex((string) $left['proposal_uuid']),
                    str_contains($expression, 'state') => (int) $left['state'],
                    default => self::payloadSortValue($left),
                };
                $rightValue = match (true) {
                    str_contains($expression, 'created_at') => (string) $right['created_at'],
                    str_contains($expression, 'updated_at') => (string) $right['updated_at'],
                    str_contains($expression, 'proposal_uuid') => bin2hex((string) $right['proposal_uuid']),
                    str_contains($expression, 'state') => (int) $right['state'],
                    default => self::payloadSortValue($right),
                };
                $comparison = $leftValue <=> $rightValue;
                return $comparison !== 0 ? $comparison * $direction : ((int) $left['id'] <=> (int) $right['id']) * $direction;
            });
        }
        return $rows;
    }

    /** @param array<string,mixed> $row */
    private static function payloadSortValue(array $row): string
    {
        $payload = json_decode((string) ($row['command_json'] ?? ''), true);
        if (!is_array($payload)) return (string) ($row['entity_type'] ?? '');
        foreach (['name', 'title', 'text', 'claim_text'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key])) return $payload[$key];
        }
        return (string) ($row['entity_type'] ?? '');
    }
}
