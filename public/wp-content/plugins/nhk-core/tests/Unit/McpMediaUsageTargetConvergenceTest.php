<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\McpToolCatalog;
use NHK\Core\Application\Media\MediaTargetNormalizer;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};
use NHK\Core\Domain\Graph\EndpointTypeRegistry;
use NHK\Core\Infrastructure\Graph\WpPostEndpointResolver;
use PHPUnit\Framework\TestCase;

final class McpMediaUsageTargetConvergenceTest extends TestCase
{
    public function test_media_usage_schema_advertises_both_canonical_wordpress_target_forms(): void
    {
        $tool = array_values(array_filter(McpToolCatalog::tools(), static fn (array $tool): bool => $tool['name'] === 'nhk.media.usage'))[0];
        $target = $tool['inputSchema']['properties']['target']['properties'];

        self::assertSame('string', $target['id']['type']);
        self::assertSame('integer', $target['blog_id']['type']);
        self::assertSame('integer', $target['post_id']['type']);
        self::assertArrayHasKey('stable_key', $target);
        self::assertFalse($tool['inputSchema']['additionalProperties'] ?? true);
    }

    public function test_equivalent_wordpress_forms_have_one_normalized_proposal_target(): void
    {
        $types = new EntityTypeRegistry();
        $authority = new class implements AuthorityRepository {
            public function findByCanonicalId(string $id): ?AuthorityEntity { return null; }
            public function findByStableKey(string $type, string $key): ?AuthorityEntity { return null; }
            public function create(AuthorityEntity $entity): AuthorityEntity { return $entity; }
            public function update(AuthorityEntity $entity, int $expectedRevision): AuthorityEntity { return $entity; }
            public function rekey(AuthorityEntity $entity, string $oldStableKey, string $newStableKey, int $expectedRevision): AuthorityEntity { return $entity; }
            public function listByType(string $type, bool $includeRetired = false): array { return []; }
        };
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('wp_post', new WpPostEndpointResolver(
            static fn (int $id): ?object => $id === 18 ? (object) ['post_status' => 'publish', 'post_modified_gmt' => '2023-11-14 22:13:20'] : null,
            static fn (): int => 1,
        ));
        $normalizer = new MediaTargetNormalizer($endpoints, $types, $authority);
        self::assertSame(
            $normalizer->normalizeRequestTarget(['type' => 'wp_post', 'blog_id' => 1, 'post_id' => 18]),
            $normalizer->normalizeRequestTarget(['type' => 'wp_post', 'id' => '1:18']),
        );
    }
}
