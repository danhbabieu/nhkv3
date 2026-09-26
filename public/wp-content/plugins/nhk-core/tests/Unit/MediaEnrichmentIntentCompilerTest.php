<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\{MediaBindingService, MediaEnrichmentIntentCompiler, MediaOwnerCapabilityRegistry, MediaTargetNormalizer};
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeDefinition, EntityTypeRegistry};
use NHK\Core\Domain\Graph\EndpointTypeRegistry;
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage};
use NHK\Core\Infrastructure\Graph\{AuthorityEndpointResolver, WpPostEndpointResolver};
use PHPUnit\Framework\TestCase;

final class MediaEnrichmentIntentCompilerTest extends TestCase
{
    private const MEDIA = '01a0d7ee-3e33-7366-88c6-287112b34936';
    private const OLD_MEDIA = '01a0d7ee-3e33-7366-88c6-287112b34937';
    private const USAGE = '01a06e2e-73a1-7550-b0e8-168aafdc6ceb';
    public const MODEL = '01a07614-832d-7f27-959c-74eb0cd63f3e';

    public function test_natural_representative_command_compiles_to_featured_article_operation(): void
    {
        $result = $this->compiler(new class implements MediaUsageRepository {
            public function create(MediaUsage $usage): MediaUsage { return $usage; }
            public function listByEndpoint(string $type, string $key, ?string $role = null): array { return []; }
            public function listByMediaId(string $id, ?string $role = null): array { return []; }
        })->compile([
            'idempotency_key' => 'natural-article-1',
            'text' => 'Dùng ảnh https://demo.1945.vn/anh/bo-suu-tap-dong-ho-co.webp làm đại diện cho https://demo.1945.vn/carillon-la-gi-trong-dong-ho-co-phap-dung-nham-carillon-la-ten-hang/',
        ]);

        self::assertSame('MEDIA_ENRICHMENT', $result['intent']);
        self::assertSame('add', $result['media_operations'][0]['operation']);
        self::assertSame('featured_primary', $result['media_operations'][0]['role']);
        self::assertSame('1:18', $result['media_operations'][0]['target']['id']);
    }

    public function test_semantic_target_url_compiles_to_representative_bind_without_url_identity(): void
    {
        $operation = $this->compiler(
            new class implements MediaUsageRepository {
                public function create(MediaUsage $usage): MediaUsage { return $usage; }
                public function listByEndpoint(string $type, string $key, ?string $role = null): array { return []; }
                public function listByMediaId(string $id, ?string $role = null): array { return []; }
            },
            static fn (string $url): array => ['type' => 'model', 'id' => self::MODEL],
        )->compile([
            'intent' => 'MEDIA_ENRICHMENT', 'idempotency_key' => 'model-url-1',
            'media_operations' => [[
                'operation' => 'representative_bind',
                'media_ref' => ['url' => 'https://demo.1945.vn/anh/bo-suu-tap-dong-ho-co.webp'],
                'target' => ['url' => 'https://demo.1945.vn/hermle/model-test/'],
            ]],
        ])['media_operations'][0];

        self::assertSame('representative_bind', $operation['operation']);
        self::assertSame('representative', $operation['role']);
        self::assertSame('representative', $operation['placement_key']);
        self::assertSame(self::MODEL, $operation['target']['id']);
        self::assertSame('nhk:model:test', $operation['target']['stable_key']);
    }

    public function test_url_intent_resolves_media_and_article_and_snapshots_replace_cas(): void
    {
        $usages = new class implements MediaUsageRepository {
            public function __construct() { $this->items = [new MediaUsage('01a06e2e-73a1-7550-b0e8-168aafdc6ceb', '01a0d7ee-3e33-7366-88c6-287112b34937', 'wp_post', '1:18', 'featured_primary', 0, '', '', [], '', 1, 'featured_primary', 'USER_EXPLICIT', 'PINNED')]; }
            private array $items;
            public function create(MediaUsage $usage): MediaUsage { $this->items[] = $usage; return $usage; }
            public function listByEndpoint(string $type, string $key, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $u): bool => $u->endpointType === $type && $u->endpointKey === $key && ($role === null || $u->role === $role))); }
            public function listByMediaId(string $id, ?string $role = null): array { return []; }
        };
        $compiler = $this->compiler($usages);
        $result = $compiler->compile([
            'intent' => 'MEDIA_ENRICHMENT',
            'idempotency_key' => 'url-intent-1',
            'media_operations' => [[
                'operation' => 'set_featured',
                'media_ref' => ['url' => 'https://demo.1945.vn/anh/bo-suu-tap-dong-ho-co.webp'],
                'target' => ['type' => 'wp_post', 'url' => 'https://demo.1945.vn/carillon-la-gi-trong-dong-ho-co-phap-dung-nham-carillon-la-ten-hang/'],
                'role' => 'featured_primary',
            ]],
        ]);

        $operation = $result['media_operations'][0];
        self::assertSame('replace', $operation['operation']);
        self::assertSame(self::MEDIA, $operation['media']['id']);
        self::assertSame('1:18', $operation['target']['id']);
        self::assertSame(self::USAGE, $operation['usage_id']);
        self::assertSame(1, $operation['expected_usage_revision']);
        self::assertTrue($result['_nhk_exact_media_operations']);
    }

    public function test_legacy_empty_featured_placement_is_reused_for_replace_cas(): void
    {
        $usages = new class implements MediaUsageRepository {
            public function create(MediaUsage $usage): MediaUsage { return $usage; }
            public function listByEndpoint(string $type, string $key, ?string $role = null): array {
                return [new MediaUsage(
                    '01a06e2e-73a1-7550-b0e8-168aafdc6ceb',
                    '01a0d7ee-3e33-7366-88c6-287112b34937',
                    'wp_post',
                    '1:18',
                    'featured_primary',
                    0,
                    '',
                    '',
                    [],
                    '',
                    1,
                    '',
                    'USER_EXPLICIT',
                    'PINNED',
                )];
            }
            public function listByMediaId(string $id, ?string $role = null): array { return []; }
        };

        $operation = $this->compiler($usages)->compile([
            'intent' => 'MEDIA_ENRICHMENT',
            'idempotency_key' => 'legacy-slot-1',
            'media_operations' => [[
                'operation' => 'set_featured',
                'media_ref' => ['url' => 'https://demo.1945.vn/anh/bo-suu-tap-dong-ho-co.webp'],
                'target' => ['type' => 'wp_post', 'url' => 'https://demo.1945.vn/carillon-la-gi-trong-dong-ho-co-phap-dung-nham-carillon-la-ten-hang/'],
            ]],
        ])['media_operations'][0];

        self::assertSame('replace', $operation['operation']);
        self::assertSame(self::USAGE, $operation['usage_id']);
        self::assertSame(1, $operation['expected_usage_revision']);
        self::assertSame('featured_primary', $operation['placement_key']);
    }

    public function test_replay_of_same_featured_media_compiles_to_keep_without_new_cas_write(): void
    {
        $usages = new class implements MediaUsageRepository {
            public function create(MediaUsage $usage): MediaUsage { return $usage; }
            public function listByEndpoint(string $type, string $key, ?string $role = null): array { return [new MediaUsage('01a06e2e-73a1-7550-b0e8-168aafdc6ceb', '01a0d7ee-3e33-7366-88c6-287112b34936', 'wp_post', '1:18', 'featured_primary', 0, '', '', [], '', 1, 'featured_primary', 'USER_EXPLICIT', 'PINNED')]; }
            public function listByMediaId(string $id, ?string $role = null): array { return []; }
        };
        $operation = $this->compiler($usages)->compile([
            'intent' => 'MEDIA_ENRICHMENT', 'idempotency_key' => 'replay-1',
            'media_operations' => [[
                'operation' => 'set_featured', 'media_ref' => ['url' => 'https://demo.1945.vn/anh/bo-suu-tap-dong-ho-co.webp'],
                'target' => ['type' => 'wp_post', 'url' => 'https://demo.1945.vn/carillon-la-gi-trong-dong-ho-co-phap-dung-nham-carillon-la-ten-hang/'],
            ]],
        ])['media_operations'][0];
        self::assertSame('keep', $operation['operation']);
        self::assertSame(self::USAGE, $operation['usage_id']);
        self::assertSame(1, $operation['expected_usage_revision']);
    }

    public function test_missing_featured_slot_compiles_to_add_without_client_cas_fields(): void
    {
        $usages = new class implements MediaUsageRepository {
            public function create(MediaUsage $usage): MediaUsage { return $usage; }
            public function listByEndpoint(string $type, string $key, ?string $role = null): array { return []; }
            public function listByMediaId(string $id, ?string $role = null): array { return []; }
        };
        $operation = $this->compiler($usages)->compile([
            'intent' => 'MEDIA_ENRICHMENT', 'idempotency_key' => 'add-1',
            'media_operations' => [[
                'operation' => 'set_featured', 'media_ref' => ['url' => 'https://demo.1945.vn/anh/bo-suu-tap-dong-ho-co.webp'],
                'target' => ['type' => 'wp_post', 'url' => 'https://demo.1945.vn/carillon-la-gi-trong-dong-ho-co-phap-dung-nham-carillon-la-ten-hang/'],
            ]],
        ])['media_operations'][0];
        self::assertSame('add', $operation['operation']);
        self::assertArrayNotHasKey('usage_id', $operation);
        self::assertArrayNotHasKey('expected_usage_revision', $operation);
    }

    private function compiler(MediaUsageRepository $usages, ?callable $targetResolver = null): MediaEnrichmentIntentCompiler
    {
        $media = new class implements MediaRepository {
            public function findByCanonicalId(string $id): ?Media { return in_array($id, [MediaEnrichmentIntentCompilerTest::MEDIA, MediaEnrichmentIntentCompilerTest::OLD_MEDIA], true) ? new Media($id, 'key:' . $id, 'media', 'ready') : null; }
            public function findByStableKey(string $key): ?Media { return null; }
            public function create(Media $media): Media { return $media; }
            public function update(Media $media, int $expectedRevision): Media { return $media; }
            public function list(bool $includeRetired = false): array { return [new Media('01a0d7ee-3e33-7366-88c6-287112b34936', 'key:media-a', 'media', 'ready'), new Media('01a0d7ee-3e33-7366-88c6-287112b34937', 'key:media-b', 'media', 'ready')]; }
        };
        $assets = new class implements MediaAssetRepository {
            public function findByAssetId(string $id): ?MediaAsset { return null; }
            public function create(MediaAsset $asset): MediaAsset { return $asset; }
            public function update(MediaAsset $asset, int $expectedRevision = 1): MediaAsset { return $asset; }
            public function listByMediaId(string $id): array {
                if ($id !== '01a0d7ee-3e33-7366-88c6-287112b34936') return [];
                return [new MediaAsset('01a0d7ee-3e33-7366-88c6-287112b34940', $id, 'derivative', 'public/clock.webp', str_repeat('a', 64), 'image/webp', 10, 100, 100, 'PUBLIC', ['public_url_path' => '/anh/bo-suu-tap-dong-ho-co.webp'])];
            }
            public function findByChecksum(string $checksum): array { return []; }
        };
        $authority = new class implements AuthorityRepository {
            private function model(): AuthorityEntity { return new AuthorityEntity(MediaEnrichmentIntentCompilerTest::MODEL, 'model', 'nhk:model:test', 'Model test', 1, []); }
            public function findByCanonicalId(string $id): ?AuthorityEntity { return $id === MediaEnrichmentIntentCompilerTest::MODEL ? $this->model() : null; }
            public function findByStableKey(string $type, string $key): ?AuthorityEntity { return $type === 'model' && $key === 'nhk:model:test' ? $this->model() : null; }
            public function create(AuthorityEntity $entity): AuthorityEntity { return $entity; }
            public function update(AuthorityEntity $entity, int $expectedRevision): AuthorityEntity { return $entity; }
            public function rekey(AuthorityEntity $entity, string $oldStableKey, string $newStableKey, int $expectedRevision): AuthorityEntity { return $entity; }
            public function listByType(string $type, bool $includeRetired = false): array { return $type === 'model' ? [$this->model()] : []; }
        };
        $types = new EntityTypeRegistry();
        $types->register(new EntityTypeDefinition('model', 1, true));
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('model', new AuthorityEndpointResolver($types, $authority));
        $endpoints->register('wp_post', new WpPostEndpointResolver(static fn (int $id): ?object => $id === 18 ? (object) ['post_status' => 'publish', 'post_modified_gmt' => '2023-11-14 22:13:20'] : null, static fn (): int => 1));
        $normalizer = new MediaTargetNormalizer($endpoints, $types, $authority);
        $binding = new MediaBindingService($media, $assets, $usages, $authority, $types, targetNormalizer: $normalizer, capabilities: MediaOwnerCapabilityRegistry::fromEndpointRegistry($endpoints));
        return new MediaEnrichmentIntentCompiler($binding, $usages, $normalizer, $targetResolver ?? static fn (string $url): array => ['type' => 'wp_post', 'id' => '1:18']);
    }
}
