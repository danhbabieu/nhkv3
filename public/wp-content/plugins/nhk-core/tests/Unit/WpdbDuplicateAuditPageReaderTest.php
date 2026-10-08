<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Audit\WpdbDuplicateAuditPageReader;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class WpdbDuplicateAuditPageReaderTest extends TestCase
{
    public function test_reader_clamps_page_size_and_uses_numeric_stable_cursor(): void
    {
        $database = new DuplicateAuditFakeDatabase();
        for ($id = 1; $id <= 201; $id++) {
            $database->rows[] = [
                '_audit_id' => $id,
                'canonical_uuid' => UuidCodec::toBinary(UuidCodec::newV7()),
                'stable_key' => 'source-' . $id,
                'title' => 'Source ' . $id,
                'source_type' => 'catalog',
                'locator' => 'https://example.test/' . $id,
                'metadata_json' => '{}',
                'state' => 1,
                'revision' => 1,
            ];
        }

        $reader = new WpdbDuplicateAuditPageReader($database, 'Source');
        $page = $reader->page(null, 10000);

        self::assertCount(200, $page['items']);
        self::assertSame('200', $page['next_cursor']);
        self::assertSame(201, $database->lastArgs[1]);

        $reader->setIncludeRetired(false);
        $reader->page('200', 20);
        self::assertStringContainsString('state=1', $database->lastPrepared);
        self::assertStringContainsString('id>%d', $database->lastPrepared);
        self::assertSame(21, $database->lastArgs[1]);
    }

    public function test_reader_projects_lifecycle_and_audit_fields_without_mutating(): void
    {
        $database = new DuplicateAuditFakeDatabase();
        $database->rows = [[
            '_audit_id' => 1,
            'canonical_uuid' => UuidCodec::toBinary(UuidCodec::newV7()),
            'stable_key' => 'source.one',
            'title' => 'Catalogue',
            'source_type' => 'catalog',
            'locator' => 'https://example.test/item',
            'metadata_json' => '{"external_id":"catalog-1","version":"2"}',
            'state' => 0,
            'revision' => 4,
        ]];

        $reader = new WpdbDuplicateAuditPageReader($database, 'Source');
        $reader->setIncludeRetired(true);
        $item = $reader->page(null, 1)['items'][0];

        self::assertSame('RETIRED', $item['state']);
        self::assertSame('catalog-1', $item['external_identity']);
        self::assertSame('2', $item['version']);
        self::assertSame(4, $item['revision']);
        self::assertStringNotContainsString('INSERT', strtoupper($database->lastPrepared));
        self::assertStringNotContainsString('UPDATE', strtoupper($database->lastPrepared));
        self::assertStringNotContainsString('DELETE', strtoupper($database->lastPrepared));
    }

    public function test_unsupported_owner_fails_closed_before_query_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('AUDIT_OWNER_UNSUPPORTED');

        new WpdbDuplicateAuditPageReader(new DuplicateAuditFakeDatabase(), 'UnsupportedOwner');
    }

    public function test_article_projects_canonical_subject_from_graph_and_editorial_intent_from_wp_metadata(): void
    {
        $database = new DuplicateAuditFakeDatabase();
        $database->rows = [[
            '_audit_id' => 1, 'ID' => 1, 'post_status' => 'publish', 'post_title' => 'Clock history',
            'post_modified_gmt' => '2026-10-08 00:00:00', 'editorial_intent' => 'TEXT_ARTICLE', 'article_endpoint_key' => '1:1',
        ]];
        $database->semanticRows = [[
            'edge_uuid' => UuidCodec::toBinary(UuidCodec::newV7()), 'source_id' => '1:1', 'target_type' => 'model', 'target_id' => 'model-1',
            'state' => 1, 'revision' => 3, 'scope_code' => 'variant', 'scope_subject_type' => 'wp_post', 'scope_subject_id' => '1:1',
            'provenance_class' => 'CAPTURE_ARTICLE_SUBJECT_BINDING', 'context_state' => 1, 'context_revision' => 1,
        ]];

        $item = (new WpdbDuplicateAuditPageReader($database, 'Article'))->page(null, 1)['items'][0];

        self::assertSame(['model-1'], $item['subject_ids']);
        self::assertSame('TEXT_ARTICLE', $item['intent']);
        self::assertSame('variant', $item['scope']);
        self::assertSame('MODEL_GAP', $item['identity_classification']);
        self::assertSame(['lineage'], $item['missing_identity_fields']);
        self::assertFalse($item['semantic_identity_available']);
        self::assertStringNotContainsString('INSERT', strtoupper(implode(' ', $database->preparedQueries)));
        self::assertStringNotContainsString('UPDATE', strtoupper(implode(' ', $database->preparedQueries)));
    }

    public function test_article_without_graph_binding_is_legacy_unresolved_and_retired_binding_is_not_active_subject(): void
    {
        $database = new DuplicateAuditFakeDatabase();
        $database->rows = [
            ['_audit_id' => 1, 'ID' => 1, 'post_status' => 'publish', 'post_title' => 'Legacy', 'article_endpoint_key' => '1:1'],
            ['_audit_id' => 2, 'ID' => 2, 'post_status' => 'publish', 'post_title' => 'Retired binding', 'article_endpoint_key' => '1:2'],
        ];
        $database->semanticRows = [[
            'edge_uuid' => UuidCodec::toBinary(UuidCodec::newV7()), 'source_id' => '1:2', 'target_type' => 'model', 'target_id' => 'model-retired',
            'state' => 0, 'revision' => 4, 'scope_code' => 'variant', 'context_state' => 0,
        ]];

        $items = (new WpdbDuplicateAuditPageReader($database, 'Article'))->page(null, 2)['items'];

        self::assertSame('LEGACY_UNRESOLVED', $items[0]['identity_classification']);
        self::assertSame('NO_CANONICAL_SUBJECT_BINDING', $items[0]['identity_reason']);
        self::assertSame([], $items[1]['subject_ids']);
        self::assertSame('LEGACY_UNRESOLVED', $items[1]['identity_classification']);
        self::assertSame('NO_ACTIVE_CANONICAL_SUBJECT', $items[1]['identity_reason']);
    }

    public function test_article_multiple_active_graph_subjects_are_ambiguous_not_merged(): void
    {
        $database = new DuplicateAuditFakeDatabase();
        $database->rows = [[
            '_audit_id' => 1, 'ID' => 1, 'post_status' => 'publish', 'post_title' => 'Ambiguous', 'article_endpoint_key' => '1:1',
        ]];
        $database->semanticRows = [
            ['edge_uuid' => UuidCodec::toBinary(UuidCodec::newV7()), 'source_id' => '1:1', 'target_type' => 'model', 'target_id' => 'model-1', 'state' => 1, 'revision' => 1, 'context_state' => 1],
            ['edge_uuid' => UuidCodec::toBinary(UuidCodec::newV7()), 'source_id' => '1:1', 'target_type' => 'model', 'target_id' => 'model-2', 'state' => 1, 'revision' => 1, 'context_state' => 1],
        ];

        $item = (new WpdbDuplicateAuditPageReader($database, 'Article'))->page(null, 1)['items'][0];

        self::assertSame([], $item['subject_ids']);
        self::assertSame('LEGACY_UNRESOLVED', $item['identity_classification']);
        self::assertSame('AMBIGUOUS_ACTIVE_SUBJECT', $item['identity_reason']);
    }
}

final class DuplicateAuditFakeDatabase
{
    public string $prefix = 'wp_';
    public string $lastPrepared = '';
    /** @var list<mixed> */
    public array $lastArgs = [];
    /** @var list<array<string,mixed>> */
    public array $rows = [];
    /** @var list<array<string,mixed>> */
    public array $semanticRows = [];
    /** @var list<string> */
    public array $preparedQueries = [];

    public function prepare(string $query, mixed ...$args): string
    {
        $this->lastPrepared = $query;
        $this->lastArgs = $args;
        $this->preparedQueries[] = $query;
        return $query;
    }

    public function get_results(string $query, mixed $format): array
    {
        return str_contains($query, 'nhk_graph_edges') ? $this->semanticRows : $this->rows;
    }
}
