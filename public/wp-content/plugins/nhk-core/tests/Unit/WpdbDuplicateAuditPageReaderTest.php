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
}

final class DuplicateAuditFakeDatabase
{
    public string $prefix = 'wp_';
    public string $lastPrepared = '';
    /** @var list<mixed> */
    public array $lastArgs = [];
    /** @var list<array<string,mixed>> */
    public array $rows = [];

    public function prepare(string $query, mixed ...$args): string
    {
        $this->lastPrepared = $query;
        $this->lastArgs = $args;
        return $query;
    }

    public function get_results(string $query, mixed $format): array
    {
        return $this->rows;
    }
}
