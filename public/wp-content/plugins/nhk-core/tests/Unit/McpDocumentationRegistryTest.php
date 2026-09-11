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
        self::assertContains('collector-profile', $keys);
        self::assertContains('graph', $keys);
        self::assertContains('governance', $keys);
        self::assertContains('media', $keys);
        self::assertContains('visual-support-requirement', $keys);
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

    public function test_visual_support_contract_is_active_and_readable(): void
    {
        $document = (new McpDocumentationRegistry())->get('visual-support-requirement');
        self::assertSame('ACTIVE', $document['status']);
        self::assertSame('media', $document['domain']);
        self::assertStringContainsString('VisualSupportRequirement', $document['content']);
        self::assertStringContainsString('MISSING', $document['content']);
        self::assertStringContainsString('Evidence', $document['content']);
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
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $bootstrap['build_identity']);
        self::assertArrayHasKey('required_reading', $bootstrap);
        self::assertSame(['agents', 'read-first', 'constitution', 'documentation-status-index'], $bootstrap['required_reading']);
        self::assertArrayHasKey('canonical_contract', $bootstrap['truth_model']);
        self::assertArrayHasKey('runtime_status', $bootstrap['truth_model']);
        self::assertSame('registered_not_live_verified', $bootstrap['runtime_status']['status']);
        self::assertContains('nhk.docs.bootstrap', $bootstrap['runtime_status']['registered_tools']);
    }

    public function test_manifest_list_get_and_bootstrap_are_deterministic_and_paginated(): void
    {
        $directory = sys_get_temp_dir() . '/nhk-docs-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($directory, 0755, true));
        $first = McpDocumentationRegistry::buildSnapshot(dirname(__DIR__, 6), $directory . '/one', 'test-runtime', '2026-09-09T00:00:00+00:00');
        $second = McpDocumentationRegistry::buildSnapshot(dirname(__DIR__, 6), $directory . '/two', 'test-runtime', '2026-09-09T23:59:59+00:00');
        $rebuilt = McpDocumentationRegistry::buildSnapshot(dirname(__DIR__, 6), $directory . '/one', 'test-runtime', '2026-09-10T00:00:00+00:00');

        self::assertSame($first['documentation_version'], $second['documentation_version']);
        self::assertSame($first['manifest_hash'], $second['manifest_hash']);
        self::assertSame($first['documentation_version'], $rebuilt['documentation_version']);
        self::assertSame($first['manifest_hash'], $rebuilt['manifest_hash']);
        self::assertSame(array_column($first['files'], 'path'), array_values(array_map(static fn (array $entry): string => $entry['path'], $first['files'])));

        $registry = new McpDocumentationRegistry($directory . '/one', 'test-runtime');
        $listed = $registry->list('ACTIVE', 'mcp', 'docs/mcp/');
        self::assertNotEmpty($listed['files']);
        $page = $registry->get('docs/constitution/READ_FIRST.md', 1, 2);
        self::assertSame(1, $page['start_line']);
        self::assertSame(2, $page['end_line']);
        self::assertTrue($page['has_more']);
        self::assertSame($first['manifest_hash'], $page['manifest_hash']);
        self::assertSame(hash_file('sha256', $directory . '/one/docs/constitution/READ_FIRST.md'), $page['sha256']);
        $bootstrap = $registry->bootstrap();
        self::assertStringContainsString('Mandatory Read-First Router', $bootstrap['read_first']);
        self::assertStringContainsString('Current Documentation Status Index', $bootstrap['documentation_status_index']);
        self::assertStringContainsString('NHK V3 Execution State', $bootstrap['execution_state_content']);
        self::assertContains('docs/architecture/VISUAL_SUPPORT_REQUIREMENT_CONTRACT.md', array_column($bootstrap['active_documents'], 'path'));
    }

    public function test_manifest_runtime_mismatch_and_checkpoint_fail_closed(): void
    {
        $directory = sys_get_temp_dir() . '/nhk-docs-mismatch-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($directory, 0755, true));
        $manifest = McpDocumentationRegistry::buildSnapshot(dirname(__DIR__, 6), $directory, 'runtime-a', '2026-09-09T00:00:00+00:00');
        $registry = new McpDocumentationRegistry($directory, 'runtime-a');
        $registry->assertCheckpoint(['manifest_hash' => $manifest['manifest_hash'], 'documentation_version' => $manifest['documentation_version']]);
        try {
            $registry->assertCheckpoint(['manifest_hash' => str_repeat('0', 64), 'documentation_version' => $manifest['documentation_version']]);
            self::fail('Expected stale checkpoint.');
        } catch (\RuntimeException $error) { self::assertSame('DOCUMENTATION_CHECKPOINT_STALE', $error->getMessage()); }
        try {
            (new McpDocumentationRegistry($directory, 'runtime-b'))->bootstrap();
            self::fail('Expected runtime mismatch.');
        } catch (\RuntimeException $error) { self::assertSame('DOC_RUNTIME_MISMATCH', $error->getMessage()); }
    }

    public function test_path_security_rejects_traversal_absolute_encoded_and_symlink_escape(): void
    {
        $registry = new McpDocumentationRegistry();
        foreach (['../wp-config.php', '/etc/hosts', '%2e%2e/wp-config.php', "docs/constitution/READ_FIRST.md\0.txt"] as $path) {
            try { $registry->get($path); self::fail('Expected path rejection for ' . $path); }
            catch (\RuntimeException $error) { self::assertSame('DOC_PATH_TRAVERSAL_BLOCKED', $error->reasonCode); }
        }

        $directory = sys_get_temp_dir() . '/nhk-docs-symlink-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($directory, 0755, true));
        McpDocumentationRegistry::buildSnapshot(dirname(__DIR__, 6), $directory, 'runtime-a', '2026-09-09T00:00:00+00:00');
        $target = $directory . '/docs/constitution/READ_FIRST.md';
        unlink($target);
        self::assertTrue(symlink('/etc/hosts', $target));
        try { (new McpDocumentationRegistry($directory, 'runtime-a'))->bootstrap(); self::fail('Expected symlink rejection.'); }
        catch (\RuntimeException $error) { self::assertSame('DOC_MANIFEST_INVALID', $error->getMessage()); }
    }
}
