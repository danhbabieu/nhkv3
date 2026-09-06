<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Application\Migration\PublicSlugMigrationService;
use NHK\Core\Application\Entity\PublicRouteResolver;
use NHK\Core\Application\Graph\{StructuralContextQuery, GraphService};
use NHK\Core\Application\PublicIdentity\PublicIdentityService;
use NHK\Core\Application\Seo\PublicSeoProjection;
use NHK\Core\Application\Video\{VideoPublicContextSelector, VideoSeoProjection, VideoUrlPolicy};
use NHK\Core\Domain\Authority\{AuthorityEntity, AuthorityState, CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, NodeReference, PredicateRegistry};
use NHK\Core\Domain\Video\Video;
use NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository;
use NHK\Core\Infrastructure\Graph\{CoreEndpointResolverRegistrar, WpdbAuditSink, WpdbGraphRepository};
use NHK\Core\Infrastructure\Migration\PublicIdentityMigration014;
use NHK\Core\Infrastructure\PublicIdentity\{WordPressPublicUrlMaintenanceRuntime, WpdbPublicIdentityRepository};
use NHK\Core\Infrastructure\Video\WpdbVideoRepository;
use NHK\Core\Contracts\PublicIdentity\PublicSlugMigrationSource;
use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

final class PublicSlugMigrationIntegrationTest extends TestCase
{
    /** @var list<string> */
    private array $authorityIds = [];
    private string $videoId = '';
    /** @var list<string> */
    private array $edgeIds = [];

    protected function setUp(): void
    {
        if (getenv('NHK_WP_TEST_PATH') !== 'public' || getenv('NHK_WP_TEST_DB') !== 'nhk_v3_test') {
            self::markTestSkipped('ENVIRONMENT_BLOCKED: exact public/nhk_v3_test runtime is required.');
        }
        require_once rtrim((string) getenv('NHK_WP_TEST_PATH'), '/') . '/wp-load.php';
        TestDatabaseGuard::selectTestDatabase();
        TestDatabaseGuard::requireTestDatabase();
        (new PublicIdentityMigration014())->up();
        require_once ABSPATH . 'wp-content/plugins/nhk-core/nhk-core.php';
    }

    protected function tearDown(): void
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) return;
        foreach ($this->edgeIds as $edgeId) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_graph_edges WHERE edge_uuid=UNHEX(%s)", str_replace('-', '', $edgeId)));
        }
        foreach ($this->authorityIds as $id) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_public_identity_history WHERE identity_uuid IN (SELECT identity_uuid FROM {$wpdb->prefix}nhk_public_identities WHERE owner_uuid=%s)", \NHK\Core\Shared\Uuid\UuidCodec::toBinary($id)));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_public_identities WHERE owner_uuid=%s", \NHK\Core\Shared\Uuid\UuidCodec::toBinary($id)));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_graph_nodes WHERE endpoint_key=%s", $id));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_entities WHERE canonical_uuid=%s", \NHK\Core\Shared\Uuid\UuidCodec::toBinary($id)));
        }
        if ($this->videoId !== '') {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_public_identity_history WHERE identity_uuid IN (SELECT identity_uuid FROM {$wpdb->prefix}nhk_public_identities WHERE owner_uuid=%s)", \NHK\Core\Shared\Uuid\UuidCodec::toBinary($this->videoId)));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_public_identities WHERE owner_uuid=%s", \NHK\Core\Shared\Uuid\UuidCodec::toBinary($this->videoId)));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_graph_nodes WHERE endpoint_key=%s", $this->videoId));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_videos WHERE canonical_uuid=%s", \NHK\Core\Shared\Uuid\UuidCodec::toBinary($this->videoId)));
        }
    }

    public function test_persisted_apply_is_idempotent_and_projection_consistent(): void
    {
        global $wpdb;
        $authority = new WpdbAuthorityRepository($wpdb);
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $identityRepository = new WpdbPublicIdentityRepository($wpdb);
        $identityService = new PublicIdentityService($identityRepository, static fn (string $slug): bool => false);

        $normal = $this->createAuthority($authority, 'Tuổi Được', ['country' => 'Việt Nam']);
        $noop = $this->createAuthority($authority, 'Đã Đúng', []);
        $autoA = $this->createAuthority($authority, 'Sưu Thương', ['country' => 'Việt Nam']);
        $autoB = $this->createAuthority($authority, 'Sưu Thương', ['country' => 'Nhật Bản']);
        $manualA = $this->createAuthority($authority, 'Người', []);
        $manualB = $this->createAuthority($authority, 'Người', []);
        $identityService->allocate('authority', $normal->canonicalId, 'brand', 'root', 'old-tuoi', 'fixture:' . $normal->canonicalId);
        $identityService->allocate('authority', $noop->canonicalId, 'brand', 'root', 'da-dung', 'fixture:' . $noop->canonicalId);

        $video = Video::fromUrl('https://youtu.be/AbCdEfGhI_1', 'Được Video', [
            'source_snapshot' => ['availability' => 'available', 'embeddable' => true, 'thumbnail_urls' => ['https://img.youtube.com/vi/AbCdEfGhI_1/hqdefault.jpg']],
            'editorial' => ['title' => 'Được Video', 'summary' => 'Video summary'],
            'hub' => ['primary' => ['key' => 'history', 'label' => 'Lịch sử']],
            'provenance' => ['kind' => 'source'],
            'semantic_attachments' => [['target_id' => $normal->canonicalId, 'target_type' => 'brand']],
        ]);
        $this->videoId = $video->canonicalId;
        (new WpdbVideoRepository($wpdb))->create($video);
        $identityService->allocate('video', $video->canonicalId, 'video', 'root', 'video-old', 'fixture:' . $video->canonicalId);

        $graph = $this->graph($wpdb, $types, $authority, $video);
        $edge = $graph->create(new NodeReference('video', $video->canonicalId), 'about', new NodeReference('brand', $normal->canonicalId));
        $this->edgeIds[] = $edge->edge_uuid;
        $beforeRelation = $graph->findOutgoing(new NodeReference('video', $video->canonicalId), 'about')['items'];

        $source = $this->source($authority, $video, $identityRepository, [$normal, $noop, $autoA, $autoB, $manualA, $manualB]);
        $writes = 0;
        $service = new PublicSlugMigrationService($source, function (array $row) use (&$writes, $identityService, $identityRepository): array {
            $writes++;
            $identity = $identityRepository->findCurrentByOwner($row['resource_type'] === 'video' ? 'video' : 'authority', $row['resource_id'], $row['resource_type']);
            $result = $identity === null
                ? $identityService->allocate($row['resource_type'] === 'video' ? 'video' : 'authority', $row['resource_id'], $row['resource_type'], (string) $row['scope'], (string) $row['proposed_slug'], (string) $row['idempotency_key'])
                : $identityService->changeSlug((string) $identity['identity_id'], (string) $row['proposed_slug'], (int) $row['expected_revision'], (string) $row['idempotency_key']);
            return ['status' => 'CHANGED', 'persisted' => $result];
        });
        $dry = $service->dryRun();
        self::assertSame(7, $dry['candidate_count']);
        self::assertSame(4, $dry['changed']);
        self::assertSame(1, $dry['no_op']);
        self::assertSame(2, $dry['manual_review']);
        self::assertSame(0, $writes);
        self::assertSame('old-tuoi', $identityRepository->findCurrentByOwner('authority', $normal->canonicalId, 'brand')['current_slug']);

        $first = $service->apply($dry, 'approved', $dry['fingerprint']);
        self::assertSame('APPLIED', $first['status']);
        self::assertSame(4, $first['changed']);
        self::assertSame(4, $writes);
        self::assertSame('tuoi-duoc', $identityRepository->findCurrentByOwner('authority', $normal->canonicalId, 'brand')['current_slug']);
        self::assertSame('da-dung', $identityRepository->findCurrentByOwner('authority', $noop->canonicalId, 'brand')['current_slug']);
        self::assertSame('duoc-video', $identityRepository->findCurrentByOwner('video', $video->canonicalId, 'video')['current_slug']);
        self::assertSame('FOUND', $identityRepository->resolveHistoric('/old-tuoi/')['status']);
        self::assertSame($normal->canonicalId, $authority->findByCanonicalId($normal->canonicalId)?->canonicalId);
        self::assertSame($normal->stableKey, $authority->findByCanonicalId($normal->canonicalId)?->stableKey);
        self::assertCount(1, $graph->findOutgoing(new NodeReference('video', $video->canonicalId), 'about')['items']);
        self::assertSame($beforeRelation[0]->edge_uuid, $graph->findOutgoing(new NodeReference('video', $video->canonicalId), 'about')['items'][0]->edge_uuid);

        $secondDry = $service->dryRun();
        self::assertSame(0, $secondDry['changed']);
        self::assertSame(5, $secondDry['no_op']);
        self::assertSame(2, $secondDry['manual_review']);
        $second = $service->apply($secondDry, 'approved', $secondDry['fingerprint']);
        self::assertSame('APPLIED', $second['status']);
        self::assertSame(0, $second['changed']);
        self::assertSame(4, $writes);

        $route = new PublicRouteResolver($authority, $types, null, null, $identityRepository);
        $seo = new PublicSeoProjection();
        $path = $route->path($authority->findByCanonicalId($normal->canonicalId));
        self::assertSame('/tuoi-duoc/', $path);
        self::assertSame($path, $seo->project(['path' => $path, 'eligible' => true], ['type' => 'Entity'])['canonical']);
        $videoRead = (new WpdbVideoRepository($wpdb))->findByCanonicalId($video->canonicalId);
        self::assertSame($video->externalVideoId, $videoRead?->externalVideoId);
        $videoUrl = (new VideoUrlPolicy($identityRepository))->project($videoRead, new VideoPublicContextSelector());
        self::assertSame('/video/duoc-video/', $videoUrl['path']);
        self::assertStringNotContainsString($video->externalVideoId, $videoUrl['path']);
        self::assertSame('/video/duoc-video/', (new VideoSeoProjection())->project(['source' => ['external_video_id' => $video->externalVideoId], 'editorial' => ['title' => 'Được Video', 'summary' => 'Video summary']], $videoUrl)['canonical']);
        self::assertSame($manualA->stableKey, $authority->findByCanonicalId($manualA->canonicalId)?->stableKey);
        self::assertSame($manualB->stableKey, $authority->findByCanonicalId($manualB->canonicalId)?->stableKey);
    }

    public function test_manual_collision_is_reported_without_persisted_mutation(): void
    {
        global $wpdb;
        $authority = new WpdbAuthorityRepository($wpdb);
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $identityRepository = new WpdbPublicIdentityRepository($wpdb);
        $left = $this->createAuthority($authority, 'Người', []);
        $right = $this->createAuthority($authority, 'Người', []);
        $writes = 0;
        $service = new PublicSlugMigrationService($this->source($authority, null, $identityRepository, [$left, $right]), static function () use (&$writes): array { $writes++; return ['status' => 'CHANGED']; });
        $dry = $service->dryRun();
        self::assertSame(2, $dry['manual_review']);
        self::assertSame(0, $dry['changed']);
        self::assertSame(0, $writes);
        self::assertSame([], $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}nhk_public_identities WHERE owner_uuid IN (%s,%s)", \NHK\Core\Shared\Uuid\UuidCodec::toBinary($left->canonicalId), \NHK\Core\Shared\Uuid\UuidCodec::toBinary($right->canonicalId)), ARRAY_A));
    }

    private function createAuthority(WpdbAuthorityRepository $repository, string $name, array $payload): AuthorityEntity
    {
        $id = \NHK\Core\Shared\Uuid\UuidCodec::newV7();
        $entity = new AuthorityEntity($id, 'brand', 'nhk:test:slug:' . bin2hex(random_bytes(5)), $name, 1, $payload, AuthorityState::ACTIVE);
        $this->authorityIds[] = $id;
        return $repository->create($entity);
    }

    private function source(WpdbAuthorityRepository $authority, ?Video $video, WpdbPublicIdentityRepository $identities, array $entities): PublicSlugMigrationSource
    {
        return new class($authority, $video, $identities, $entities) implements PublicSlugMigrationSource {
            public function __construct(private WpdbAuthorityRepository $authority, private ?Video $video, private WpdbPublicIdentityRepository $identities, private array $entities) {}
            public function candidates(): array
            {
                $rows = [];
                foreach ($this->entities as $entity) {
                    $identity = $this->identities->findCurrentByOwner('authority', $entity->canonicalId, $entity->entityType);
                    $rows[] = ['type' => $entity->entityType, 'id' => $entity->canonicalId, 'title' => $entity->canonicalName, 'current_slug' => $identity['current_slug'] ?? '', 'current_url' => $identity['current_path'] ?? '', 'scope' => 'root', 'revision' => $identity['revision'] ?? 0, 'fingerprint' => hash('sha256', $entity->stableKey), 'meaningful_context' => $entity->payload, 'route_owner' => 'semantic'];
                }
                if ($this->video !== null) {
                    $identity = $this->identities->findCurrentByOwner('video', $this->video->canonicalId, 'video');
                    $metadata = $this->video->metadata;
                    $editorial = is_array($metadata['editorial'] ?? null) ? $metadata['editorial'] : [];
                    $rows[] = ['type' => 'video', 'id' => $this->video->canonicalId, 'title' => (string) ($editorial['title'] ?? $this->video->title), 'current_slug' => $identity['current_slug'] ?? '', 'current_url' => $identity['current_path'] ?? '', 'scope' => 'root', 'revision' => $identity['revision'] ?? 0, 'fingerprint' => hash('sha256', $this->video->externalVideoId), 'meaningful_context' => [], 'route_owner' => 'semantic'];
                }
                return $rows;
            }
        };
    }

    private function graph(object $wpdb, EntityTypeRegistry $types, WpdbAuthorityRepository $authority, Video $video): GraphService
    {
        $endpoints = new EndpointTypeRegistry();
        CoreEndpointResolverRegistrar::register($endpoints, $types, $authority, new \NHK\Core\Infrastructure\Media\WpdbMediaRepository($wpdb), new WpdbVideoRepository($wpdb));
        return new GraphService(new WpdbGraphRepository($wpdb), $endpoints, new PredicateRegistry(), new WpdbAuditSink());
    }
}
