<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\MediaTargetNormalizer;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeDefinition, EntityTypeRegistry};
use NHK\Core\Domain\Graph\EndpointTypeRegistry;
use NHK\Core\Domain\Media\MediaException;
use NHK\Core\Infrastructure\Graph\{AuthorityEndpointResolver, WpPostEndpointResolver};
use PHPUnit\Framework\TestCase;

final class MediaTargetNormalizerTest extends TestCase
{
    public const AUTHORITY_ID = '01a07614-832d-7f27-959c-74eb0cd63f3e';

    public function test_authority_uuid_and_stable_key_converge_to_one_reference(): void
    {
        $normalizer = $this->normalizer();

        $byId = $normalizer->normalize(['type' => 'model', 'id' => self::AUTHORITY_ID]);
        $byStableKey = $normalizer->normalize(['type' => 'model', 'stable_key' => 'nhk:model:clock']);

        self::assertSame('model', $byId->endpointType);
        self::assertSame(self::AUTHORITY_ID, $byId->endpointKey);
        self::assertSame(self::AUTHORITY_ID, $byStableKey->canonicalUuid);
        self::assertSame($byId->endpointKey, $byStableKey->endpointKey);
        self::assertSame('nhk:model:clock', $byStableKey->stableKey);
        self::assertSame(7, $byStableKey->revision);
    }

    public function test_wp_post_field_form_and_canonical_id_converge(): void
    {
        $normalizer = $this->normalizer();

        $fields = $normalizer->normalize(['type' => 'wp_post', 'blog_id' => 1, 'post_id' => 18]);
        $canonical = $normalizer->normalize(['type' => 'wp_post', 'id' => '1:18']);

        self::assertSame('wp_post', $fields->endpointType);
        self::assertSame('1:18', $fields->endpointKey);
        self::assertSame($fields->endpointKey, $canonical->endpointKey);
        self::assertNull($fields->canonicalUuid);
        self::assertSame(1700000000, $fields->revision);
        self::assertSame(['type' => 'wp_post', 'id' => '1:18', 'revision' => 1700000000], $normalizer->normalizeRequestTarget(['type' => 'wp_post', 'blog_id' => 1, 'post_id' => 18]));
    }

    public function test_unknown_type_has_distinct_invalid_type_diagnostic(): void
    {
        $this->expectExceptionMessage('MEDIA_TARGET_TYPE_INVALID');
        $this->normalizer()->normalize(['type' => 'arbitrary_target', 'id' => self::AUTHORITY_ID]);
    }

    public function test_malformed_reference_has_distinct_malformed_diagnostic(): void
    {
        $this->expectExceptionMessage('MEDIA_TARGET_REFERENCE_MALFORMED');
        $this->normalizer()->normalize(['type' => 'wp_post', 'id' => '18']);
    }

    public function test_missing_and_trashed_posts_are_not_valid_targets(): void
    {
        try {
            $this->normalizer()->normalize(['type' => 'wp_post', 'id' => '1:999']);
            self::fail('Missing post must be rejected.');
        } catch (MediaException $error) {
            self::assertSame('MEDIA_TARGET_NOT_FOUND', $error->getMessage());
        }

        try {
            $this->normalizer()->normalize(['type' => 'wp_post', 'id' => '1:19']);
            self::fail('Trashed post must be rejected.');
        } catch (MediaException $error) {
            self::assertSame('MEDIA_TARGET_NOT_FOUND', $error->getMessage());
        }
    }

    private function normalizer(): MediaTargetNormalizer
    {
        $types = new EntityTypeRegistry();
        $types->register(new EntityTypeDefinition('model', 1, true));
        $authority = new class implements \NHK\Core\Contracts\Authority\AuthorityRepository {
            public function findByCanonicalId(string $id): ?AuthorityEntity { return $id === MediaTargetNormalizerTest::AUTHORITY_ID ? new AuthorityEntity(MediaTargetNormalizerTest::AUTHORITY_ID, 'model', 'nhk:model:clock', 'Clock', 1, [], \NHK\Core\Domain\Authority\AuthorityState::ACTIVE, 7) : null; }
            public function findByStableKey(string $type, string $key): ?AuthorityEntity { return $type === 'model' && $key === 'nhk:model:clock' ? $this->findByCanonicalId(MediaTargetNormalizerTest::AUTHORITY_ID) : null; }
            public function create(AuthorityEntity $entity): AuthorityEntity { return $entity; }
            public function update(AuthorityEntity $entity, int $expectedRevision): AuthorityEntity { return $entity; }
            public function rekey(AuthorityEntity $entity, string $oldStableKey, string $newStableKey, int $expectedRevision): AuthorityEntity { return $entity; }
            public function listByType(string $type, bool $includeRetired = false): array { return []; }
        };
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('model', new AuthorityEndpointResolver($types, $authority));
        $endpoints->register('wp_post', new WpPostEndpointResolver(
            static fn (int $id): ?object => match ($id) {
                18 => (object) ['post_status' => 'publish', 'post_modified_gmt' => '2023-11-14 22:13:20'],
                19 => (object) ['post_status' => 'trash', 'post_modified_gmt' => '2023-11-14 22:13:20'],
                default => null,
            },
            static fn (): int => 1,
        ));

        return new MediaTargetNormalizer($endpoints, $types, $authority);
    }
}
