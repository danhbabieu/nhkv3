<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\McpDocumentationRegistry;
use PHPUnit\Framework\TestCase;

final class McpDocumentationRegistryTest extends TestCase
{
    public function test_registry_exposes_required_reading_and_canonical_contract_keys(): void
    {
        $keys = McpDocumentationRegistry::documentKeys();
        self::assertContains('agents', $keys);
        self::assertContains('read-first', $keys);
        self::assertContains('constitution', $keys);
        self::assertContains('documentation-status-index', $keys);
        self::assertContains('authority', $keys);
        self::assertContains('knowledge', $keys);
        self::assertContains('graph', $keys);
        self::assertContains('governance', $keys);
        self::assertContains('media', $keys);
        self::assertContains('mcp', $keys);
    }

    public function test_document_is_read_by_key_with_hash_and_no_absolute_path(): void
    {
        $document = (new McpDocumentationRegistry())->get('read-first');
        self::assertSame('read-first', $document['document_key']);
        self::assertSame('canonical_router', $document['classification']);
        self::assertStringContainsString('Mandatory Read-First Router', $document['content']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $document['document_hash']);
        self::assertArrayNotHasKey('absolute_path', $document);
        self::assertArrayNotHasKey('filesystem_path', $document);
    }

    public function test_arbitrary_and_traversal_keys_fail_closed(): void
    {
        $registry = new McpDocumentationRegistry();
        $this->expectExceptionMessage('DOCUMENT_NOT_ALLOWLISTED');
        $registry->get('../wp-config.php');
    }

    public function test_bootstrap_distinguishes_documentation_contract_from_runtime_status(): void
    {
        $bootstrap = (new McpDocumentationRegistry())->bootstrap();
        self::assertArrayHasKey('documentation_revision', $bootstrap);
        self::assertArrayHasKey('required_reading', $bootstrap);
        self::assertSame(['agents', 'read-first', 'constitution', 'documentation-status-index'], $bootstrap['required_reading']);
        self::assertArrayHasKey('canonical_contract', $bootstrap['truth_model']);
        self::assertArrayHasKey('runtime_status', $bootstrap['truth_model']);
        self::assertSame('registered_not_live_verified', $bootstrap['runtime_status']['status']);
        self::assertContains('nhk.docs.bootstrap', $bootstrap['runtime_status']['registered_tools']);
    }
}
