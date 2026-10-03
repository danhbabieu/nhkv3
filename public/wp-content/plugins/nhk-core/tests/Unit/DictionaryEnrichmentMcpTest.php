<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryRuntime;
use NHK\Core\Application\Mcp\McpDictionaryHandler;
use PHPUnit\Framework\TestCase;

final class DictionaryEnrichmentMcpTest extends TestCase
{
    public function test_unavailable_runtime_fails_closed_for_enrichment_audit(): void
    {
        $runtime = (new \ReflectionClass(DictionaryRuntime::class))->newInstanceWithoutConstructor();
        $handler = new McpDictionaryHandler($runtime);
        self::assertSame(['status' => 'unavailable', 'reason' => 'DICTIONARY_STORAGE_UNAVAILABLE', 'read_only' => true, 'mutated' => false], $handler->enrichmentAudit(['limit' => 10]));
    }

    public function test_apply_requires_exact_plan_fingerprint_and_idempotency_key(): void
    {
        $runtime = (new \ReflectionClass(DictionaryRuntime::class))->newInstanceWithoutConstructor();
        $handler = new McpDictionaryHandler($runtime);
        self::assertSame('unavailable', $handler->enrichmentApply(['plan' => [], 'approved_plan_fingerprint' => str_repeat('a', 64), 'idempotency_key' => 'apply-1'])['status']);
    }
}
