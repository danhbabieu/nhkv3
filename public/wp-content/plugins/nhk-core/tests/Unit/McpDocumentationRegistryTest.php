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
        self::assertContains('entity-profile-clock-type', $keys);
        self::assertContains('knowledge', $keys);
        self::assertContains('collector-profile', $keys);
        self::assertContains('graph', $keys);
        self::assertContains('governance', $keys);
        self::assertContains('media', $keys);
        self::assertContains('visual-support-requirement', $keys);
        self::assertContains('mcp', $keys);
    }

    public function test_every_read_first_document_reference_is_allowlisted_and_retrievable(): void
    {
        $readFirst = file_get_contents(dirname(__DIR__, 6) . '/docs/constitution/READ_FIRST.md');
        self::assertIsString($readFirst);
        preg_match_all('/docs\/[A-Za-z0-9_.\/-]+\.md/', $readFirst, $matches);
        $paths = array_values(array_unique($matches[0] ?? []));
        $registry = new McpDocumentationRegistry();
        $allowlist = McpDocumentationRegistry::documentPaths();

        foreach ($paths as $path) {
            self::assertContains($path, $allowlist, 'READ_FIRST reference is outside the MCP documentation allowlist: ' . $path);
            self::assertSame($path, $registry->get($path)['path']);
        }
    }

    public function test_public_entity_identity_route_and_seo_contracts_are_retrievable(): void
    {
        $registry = new McpDocumentationRegistry();
        $paths = [
            'docs/architecture/PUBLIC_ENTITY_DOSSIER_PROJECTION_CONTRACT.md',
            'docs/architecture/V3_PUBLIC_ENTITY_IDENTITY_MATRIX.md',
            'docs/architecture/V3_PUBLIC_ROUTE_AUDIT.md',
            'docs/seo/ENTITY_SEO_PROJECTION_CONTRACT.md',
            'docs/seo/MEDIA_IMAGE_SEO_PROJECTION_CONTRACT.md',
            'docs/seo/NHK_V3_SEO_CORE_CONTRACT.md',
            'docs/seo/PUBLIC_URL_SLUG_CONTRACT.md',
            'docs/seo/SITEMAP_INDEXABILITY_CONTRACT.md',
        ];

        foreach ($paths as $path) {
            $document = $registry->get($path);
            self::assertSame('ACTIVE', $document['status'], $path);
            self::assertNotSame('', trim((string) $document['content']), $path);
        }
    }

    public function test_clock_type_entity_profile_contract_is_active_and_readable(): void
    {
        $document = (new McpDocumentationRegistry())->get('entity-profile-clock-type');
        self::assertSame('ACTIVE', $document['status']);
        self::assertSame('authority', $document['domain']);
        self::assertStringContainsString('family=clock_type', str_replace('`', '', $document['content']));
        self::assertStringContainsString('classified_as', $document['content']);
        self::assertStringContainsString('Odo vai bò', $document['content']);
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

    public function test_current_execution_state_fits_the_canonical_document_limit(): void
    {
        $executionState = dirname(__DIR__, 6) . '/docs/architecture/V3_EXECUTION_STATE.md';
        $size = filesize($executionState);

        self::assertIsInt($size);
        self::assertGreaterThan($size, McpDocumentationRegistry::MAX_DOCUMENT_BYTES);
    }

    public function test_build_identity_normalizes_manifest_generation_timestamp(): void
    {
        $directory = sys_get_temp_dir() . '/nhk-build-identity-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($directory, 0755, true));
        $first = $directory . '/first-manifest.json';
        $second = $directory . '/second-manifest.json';
        $manifest = ['schema_version' => 1, 'documentation_version' => 'docs', 'generated_at' => '2026-09-21T05:56:41+00:00', 'files' => []];
        try {
            self::assertNotFalse(file_put_contents($first, json_encode($manifest, JSON_THROW_ON_ERROR)));
            $manifest['generated_at'] = '2026-09-21T05:56:49+00:00';
            self::assertNotFalse(file_put_contents($second, json_encode($manifest, JSON_THROW_ON_ERROR)));
            $method = new \ReflectionMethod(McpDocumentationRegistry::class, 'buildInputHash');
            $method->setAccessible(true);
            $registry = new McpDocumentationRegistry();
            self::assertSame(
                $method->invoke($registry, 'resources/canonical-docs/manifest.json', $first),
                $method->invoke($registry, 'resources/canonical-docs/manifest.json', $second),
            );
        } finally {
            @unlink($first);
            @unlink($second);
            $this->removeDirectory($directory);
        }
    }

    public function test_deployed_plugin_snapshot_precedes_stale_repository_docs(): void
    {
        $method = new \ReflectionMethod(McpDocumentationRegistry::class, 'roots');
        $method->setAccessible(true);
        $roots = $method->invoke(new McpDocumentationRegistry(null, '0.1.0'));

        self::assertStringEndsWith('/resources/canonical-docs', $roots[0]);
    }

    public function test_snapshot_remains_authoritative_when_runtime_version_is_not_yet_defined(): void
    {
        $method = new \ReflectionMethod(McpDocumentationRegistry::class, 'roots');
        $method->setAccessible(true);
        $roots = $method->invoke(new McpDocumentationRegistry());

        self::assertStringEndsWith('/resources/canonical-docs', $roots[0]);
        self::assertNotSame(dirname(__DIR__, 6), $roots[0]);
    }

    public function test_bootstrap_get_and_list_project_one_snapshot_identity(): void
    {
        $directory = sys_get_temp_dir() . '/nhk-docs-parity-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($directory, 0755, true));
        try {
            $registry = new McpDocumentationRegistry($directory, 'runtime-parity');
            $manifest = McpDocumentationRegistry::buildSnapshot(dirname(__DIR__, 6), $directory, 'runtime-parity', '2026-09-09T00:00:00+00:00');
            $bootstrap = $registry->bootstrap();
            $listed = $registry->list();
            $document = $registry->get('AGENTS.md', 1, 1);

            foreach ([$listed, $document] as $projection) {
                self::assertSame($bootstrap['documentation_version'], $projection['documentation_version']);
                self::assertSame($bootstrap['manifest_hash'], $projection['manifest_hash']);
            }
            self::assertSame($bootstrap['manifest_hash'], $bootstrap['manifest']['manifest_hash']);
            self::assertSame($manifest['source_revision'], $bootstrap['source_revision']);
            self::assertSame($manifest['source_revision'], json_decode((string) file_get_contents($directory . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR)['source_revision']);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function test_generator_boundary_records_exact_checkout_head_and_bootstrap_projects_it(): void
    {
        $directory = sys_get_temp_dir() . '/nhk-docs-exact-head-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($directory, 0755, true));
        try {
            $expected = trim((string) shell_exec('git -C ' . escapeshellarg(dirname(__DIR__, 6)) . ' rev-parse --verify HEAD 2>/dev/null'));
            self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $expected);
            $manifest = McpDocumentationRegistry::buildSnapshot(dirname(__DIR__, 6), $directory, 'runtime-a', '2026-09-09T00:00:00+00:00', $expected);
            self::assertSame($expected, $manifest['source_revision']);
            $persisted = json_decode((string) file_get_contents($directory . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($expected, $persisted['source_revision']);
            self::assertSame($expected, (new McpDocumentationRegistry($directory, 'runtime-a'))->bootstrap()['source_revision']);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function test_snapshot_generation_fails_closed_without_a_git_source_revision(): void
    {
        $source = sys_get_temp_dir() . '/nhk-docs-no-git-' . bin2hex(random_bytes(5));
        $destination = $source . '-destination';
        self::assertTrue(mkdir($source, 0755, true));
        try {
            copy(dirname(__DIR__, 6) . '/AGENTS.md', $source . '/AGENTS.md');
            $this->copyDirectory(dirname(__DIR__, 6) . '/docs', $source . '/docs');
            $this->expectExceptionMessage('DOC_SOURCE_REVISION_UNAVAILABLE');
            McpDocumentationRegistry::buildSnapshot($source, $destination, 'runtime-a');
        } finally {
            $this->removeDirectory($source);
            $this->removeDirectory($destination);
        }
    }

    public function test_snapshot_generation_rejects_a_revision_that_is_not_checkout_head(): void
    {
        $directory = sys_get_temp_dir() . '/nhk-docs-source-mismatch-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($directory, 0755, true));
        try {
            $this->expectExceptionMessage('DOC_SOURCE_REVISION_MISMATCH');
            McpDocumentationRegistry::buildSnapshot(dirname(__DIR__, 6), $directory, 'runtime-a', null, str_repeat('0', 40));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function test_registry_cannot_cache_across_a_replaced_release_snapshot(): void
    {
        $directory = sys_get_temp_dir() . '/nhk-docs-release-cache-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($directory, 0755, true));
        try {
            McpDocumentationRegistry::buildSnapshot(dirname(__DIR__, 6), $directory, 'runtime-a', '2026-09-09T00:00:00+00:00');
            $registry = new McpDocumentationRegistry($directory, 'runtime-a');
            $registry->bootstrap();

            McpDocumentationRegistry::buildSnapshot(dirname(__DIR__, 6), $directory, 'runtime-b', '2026-09-09T00:00:00+00:00');

            $this->expectExceptionMessage('DOC_RUNTIME_MISMATCH');
            $registry->list();
        } finally {
            $this->removeDirectory($directory);
        }
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

    public function test_manifest_invariants_fail_closed_with_specific_diagnostics(): void
    {
        $scenarios = [
            'missing-file' => static function (array $manifest, string $directory): array {
                unlink($directory . '/AGENTS.md');
                return $manifest;
            },
            'duplicate-path' => static function (array $manifest): array {
                $manifest['files'][] = $manifest['files'][0];
                return $manifest;
            },
            'duplicate-key' => static function (array $manifest): array {
                $manifest['files'][1]['document_key'] = $manifest['files'][0]['document_key'];
                return $manifest;
            },
            'hash-mismatch' => static function (array $manifest): array {
                $manifest['files'][0]['sha256'] = str_repeat('0', 64);
                return $manifest;
            },
            'version-mismatch' => static function (array $manifest): array {
                $manifest['documentation_version'] = str_repeat('f', 64);
                return $manifest;
            },
            'missing-source-revision' => static function (array $manifest): array {
                $manifest['source_revision'] = null;
                return $manifest;
            },
        ];

        $expectedDiagnostics = [
            'missing-file' => 'document_file_missing_or_unsafe',
            'duplicate-path' => 'duplicate_document_path',
            'duplicate-key' => 'duplicate_document_key',
            'hash-mismatch' => 'document_hash_mismatch',
            'version-mismatch' => 'documentation_version_mismatch',
            'missing-source-revision' => 'source_revision_invalid',
        ];

        foreach ($scenarios as $name => $mutator) {
            $directory = sys_get_temp_dir() . '/nhk-docs-invariant-' . $name . '-' . bin2hex(random_bytes(5));
            self::assertTrue(mkdir($directory, 0755, true));
            $manifest = McpDocumentationRegistry::buildSnapshot(dirname(__DIR__, 6), $directory, 'runtime-a', '2026-09-09T00:00:00+00:00');
            $updated = $mutator($manifest, $directory);
            $this->writeManifest($directory . '/manifest.json', $updated);

            try {
                (new McpDocumentationRegistry($directory, 'runtime-a'))->bootstrap();
                self::fail('Expected manifest rejection for ' . $name);
            } catch (\RuntimeException $error) {
                self::assertSame('DOC_MANIFEST_INVALID', $error->getMessage(), $name);
                self::assertSame($expectedDiagnostics[$name], $error->details['diagnostic'] ?? null, $name);
            }
        }
    }

    public function test_source_revision_mismatch_is_rejected_when_manifest_is_read_from_repository_root(): void
    {
        $directory = sys_get_temp_dir() . '/nhk-docs-source-revision-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($directory, 0755, true));
        $manifest = McpDocumentationRegistry::buildSnapshot(dirname(__DIR__, 6), $directory, 'runtime-a', '2026-09-09T00:00:00+00:00');
        $manifest['source_revision'] = str_repeat('0', 40);
        $this->writeManifest($directory . '/manifest.json', $manifest);
        self::assertTrue(symlink(dirname(__DIR__, 6) . '/.git', $directory . '/.git'));

        $registry = new McpDocumentationRegistry($directory, 'runtime-a');
        try {
            $registry->bootstrap();
            self::fail('Expected source revision rejection.');
        } catch (\RuntimeException $error) {
            self::assertSame('DOC_MANIFEST_INVALID', $error->getMessage());
            self::assertSame('source_revision_mismatch', $error->details['diagnostic'] ?? null);
        }
    }

    /** @param array<string,mixed> $manifest */
    private function writeManifest(string $path, array $manifest): void
    {
        chmod($path, 0644);
        $manifest['manifest_hash'] = null;
        $hashInput = $manifest;
        unset($hashInput['manifest_hash'], $hashInput['generated_at']);
        $manifest['manifest_hash'] = hash('sha256', json_encode($hashInput, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        self::assertNotFalse(file_put_contents($path, json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n"));
        chmod($path, 0444);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) return;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        rmdir($directory);
    }

    private function copyDirectory(string $source, string $destination): void
    {
        self::assertTrue(mkdir($destination, 0755, true));
        $iterator = new \FilesystemIterator($source, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $item) {
            $target = $destination . '/' . $item->getBasename();
            if ($item->isDir()) $this->copyDirectory($item->getPathname(), $target);
            else self::assertTrue(copy($item->getPathname(), $target));
        }
    }
}
