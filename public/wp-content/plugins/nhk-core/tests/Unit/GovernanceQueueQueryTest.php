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
    }

    public function test_subject_name_and_status_filters_are_server_side(): void
    {
        $page = $this->query->page(['search' => 'Vertical Brand', 'status' => 'submitted']);

        self::assertSame('submitted', $page['filters']['status']);
        self::assertStringContainsString('command_json', $this->db->lastPrepared);
        self::assertStringContainsString('state', $this->db->lastPrepared);
        self::assertSame('Vertical Brand', $page['items'][0]['name']);
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
        $this->db->count = 5;

        $page = $this->query->page(['page' => 3, 'per_page' => 2]);

        self::assertSame(3, $page['page']);
        self::assertSame(2, $page['per_page']);
        self::assertSame(3, $page['total_pages']);
        self::assertStringContainsString('LIMIT 2 OFFSET 4', $this->db->lastPrepared);
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

    /** @return array<string,mixed> */
    private function proposalRow(): array
    {
        return [
            'id' => 44,
            'proposal_uuid' => hex2bin(str_replace('-', '', $this->proposalId)),
            'entity_type' => 'brand',
            'operation' => 'rename',
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
}

final class RecordingProposalDatabase
{
    public string $prefix = 'wp_';
    public string $lastPrepared = '';
    /** @var array<string,string> */
    public array $lastFilters = [];
    /** @var list<array<string,mixed>> */
    public array $rows;

    /** @param list<array<string,mixed>> $rows */
    public function __construct(array $rows, public int $count)
    {
        $this->rows = $rows;
    }

    public function prepare(string $query, mixed ...$args): string
    {
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
        $this->lastFilters = [
            'order_by' => str_contains($prepared, 'ORDER BY updated_at') ? 'updated'
                : (str_contains($prepared, 'ORDER BY created_at') ? 'created'
                    : (str_contains($prepared, 'ORDER BY state') ? 'status'
                        : (str_contains($prepared, 'ORDER BY proposal_uuid') ? 'id' : 'name'))),
        ];

        return $prepared;
    }

    /** @return list<array<string,mixed>> */
    public function get_results(string $query, mixed $output): array
    {
        return $this->rows;
    }

    public function get_var(string $query): int
    {
        return $this->count;
    }
}
