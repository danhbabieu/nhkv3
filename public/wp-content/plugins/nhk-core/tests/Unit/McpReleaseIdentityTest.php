<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\{McpDocumentationRegistry, McpReleaseIdentity};
use PHPUnit\Framework\TestCase;

final class McpReleaseIdentityTest extends TestCase
{
    public function test_release_tuple_contains_every_runtime_surface_identity(): void
    {
        $identity = (new McpDocumentationRegistry())->runtimeIdentity();

        foreach (['runtime_version', 'source_revision', 'documentation_version', 'manifest_hash', 'build_identity', 'catalog_version', 'resource_version', 'release_identity'] as $field) {
            self::assertArrayHasKey($field, $identity, $field);
        }
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $identity['catalog_version']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $identity['resource_version']);
        self::assertSame(McpReleaseIdentity::hash($identity), $identity['release_identity']);
    }

    public function test_catalog_and_resource_versions_change_when_their_runtime_contract_changes(): void
    {
        self::assertNotSame('', McpReleaseIdentity::catalogVersion());
        self::assertNotSame('', McpReleaseIdentity::resourceVersion());
        self::assertNotSame(
            McpReleaseIdentity::hash(['catalog_version' => 'a', 'resource_version' => 'b']),
            McpReleaseIdentity::hash(['catalog_version' => 'a', 'resource_version' => 'c']),
        );
    }
}
