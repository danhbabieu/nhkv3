<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\MediaBindingStagingGuard;
use NHK\Core\Application\Media\MediaTargetNormalizer;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};
use NHK\Core\Domain\Graph\EndpointTypeRegistry;
use NHK\Core\Infrastructure\Graph\WpPostEndpointResolver;
use PHPUnit\Framework\TestCase;

final class MediaUsageTargetScopeConvergenceTest extends TestCase
{
    public function test_scope_payload_fingerprint_converges_post_field_and_canonical_forms(): void
    {
        $guard = new MediaBindingStagingGuard(static fn (): string => 'staging', targetNormalizer: $this->normalizer());
        $method = new \ReflectionMethod($guard, 'mediaUsagePayloadFingerprint');
        $scope = ['operation' => 'add', 'capture_id' => 'capture-1', 'capture_fingerprint' => str_repeat('a', 64)];
        $base = ['operation' => 'add', 'media' => ['id' => '01a0d7ee-3e33-7366-88c6-287112b34936'], 'role' => 'featured_primary', 'placement_key' => 'featured_primary', 'idempotency_key' => 'same'];

        $fields = $method->invoke($guard, $base + ['target' => ['type' => 'wp_post', 'blog_id' => 1, 'post_id' => 18]], $scope);
        $canonical = $method->invoke($guard, $base + ['target' => ['type' => 'wp_post', 'id' => '1:18']], $scope);

        self::assertSame($fields, $canonical);
    }

    private function normalizer(): MediaTargetNormalizer
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
        return new MediaTargetNormalizer($endpoints, $types, $authority);
    }
}
