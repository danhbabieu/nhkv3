<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Entity\{EntityMediaProjection, PublicEntityEligibilityPolicy, PublicIdentityContract, PublicRouteResolver, SemanticDossierQuery};
use NHK\Core\Application\Graph\{GraphService, PredicateTraversalPolicy, RelatedSemanticQuery};
use NHK\Core\Application\Knowledge\EntityKnowledgeProjection;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, NodeReference, PredicateRegistry};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsage};
use NHK\Core\Domain\Video\Video;
use NHK\Core\Infrastructure\Graph\InMemoryAuditSink;
use NHK\Tests\Support\{InMemoryAuthorityRepository, InMemoryGraphRepository};
use PHPUnit\Framework\TestCase;

final class VideoSemanticDossierTest extends TestCase
{
    public function test_video_dossier_uses_path_aware_context_and_preserves_external_source_boundary(): void
    {
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new AuthorityService($authorityRepo = new InMemoryAuthorityRepository(), $types);
        $movement = $authority->create('movement', 'movement-a', 'Machine A');
        $music = $authority->create('music', 'music-a', 'Music A');
        $video = new Video($videoId = \NHK\Core\Shared\Uuid\UuidCodec::newV7(), 'youtube', 'dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'Âm thanh hiện vật', [
            'public_identity' => ['current_slug' => 'am-thanh-hien-vat'],
            'source_snapshot' => ['availability' => 'available', 'embeddable' => true, 'thumbnail_urls' => ['https://img.example.test/v.jpg']],
            'editorial' => ['title' => 'Âm thanh hiện vật', 'summary' => 'Ghi nhận âm thanh.'],
            'hub' => ['primary' => 'movement'],
            'provenance' => ['kind' => 'TEST_SOURCE'],
            'semantic_attachments' => [['target_id' => $movement->canonicalId]],
        ]);

        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('video', new FakeEndpointResolver('video', [$videoId]));
        $endpoints->register('movement', new FakeEndpointResolver('movement', [$movement->canonicalId]));
        $endpoints->register('music', new FakeEndpointResolver('music', [$music->canonicalId]));
        $predicates = new PredicateRegistry();
        $graph = new GraphService(new InMemoryGraphRepository(), $endpoints, $predicates, new InMemoryAuditSink());
        $graph->create(new NodeReference('video', $videoId), 'about', new NodeReference('movement', $movement->canonicalId));
        $graph->create(new NodeReference('movement', $movement->canonicalId), 'supports_music', new NodeReference('music', $music->canonicalId));

        $mediaRepo = $this->mediaRepository();
        $routes = new PublicRouteResolver($authorityRepo, $types);
        $query = new SemanticDossierQuery(
            $authorityRepo,
            $types,
            new PublicIdentityContract($types),
            new PublicEntityEligibilityPolicy($authorityRepo, $types, $routes),
            $routes,
            new RelatedSemanticQuery($graph, new PredicateTraversalPolicy($predicates)),
            new EntityKnowledgeProjection($this->knowledgeRepository(), $this->evidenceRepository(), $this->sourceRepository()),
            new EntityMediaProjection($mediaRepo, $this->assetRepository(), $this->usageRepository()),
            $mediaRepo,
            $this->videoRepository([$video]),
        );

        $result = $query->forVideo($video);

        self::assertSame('AVAILABLE', $result['status']);
        self::assertSame('Âm thanh hiện vật', $result['identity']['title']);
        self::assertSame('/video/am-thanh-hien-vat-dqw4w9wgxcq/', $result['identity']['url']);
        self::assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $result['identity']['source_url']);
        self::assertSame('https://img.example.test/v.jpg', $result['identity']['thumbnail_url']);
        self::assertSame('DIRECT', $result['relation_sections']['movements'][0]['origin']['kind'] ?? null);
        self::assertSame('DERIVED', $result['relation_sections']['music'][0]['origin']['kind'] ?? null);
        self::assertSame(2, $result['relation_sections']['music'][0]['origin']['hop_count'] ?? null);
        self::assertStringNotContainsString($videoId, json_encode($result, JSON_THROW_ON_ERROR));
    }

    private function mediaRepository(): MediaRepository { return new class implements MediaRepository { public function findByCanonicalId(string $id): ?Media { return null; } public function findByStableKey(string $key): ?Media { return null; } public function create(Media $media): Media { return $media; } public function update(Media $media, int $expectedRevision): Media { return $media; } public function list(bool $includeRetired = false): array { return []; } }; }
    private function assetRepository(): MediaAssetRepository { return new class implements MediaAssetRepository { public function findByAssetId(string $id): ?MediaAsset { return null; } public function create(MediaAsset $asset): MediaAsset { return $asset; } public function update(MediaAsset $asset, int $expectedRevision = 1): MediaAsset { return $asset; } public function listByMediaId(string $mediaId): array { return []; } public function findByChecksum(string $checksum): array { return []; } }; }
    private function usageRepository(): MediaUsageRepository { return new class implements MediaUsageRepository { public function create(MediaUsage $usage): MediaUsage { return $usage; } public function listByMediaId(string $mediaId, ?string $role = null): array { return []; } public function listByEndpoint(string $endpointType, string $endpointKey, ?string $role = null): array { return []; } }; }
    private function videoRepository(array $items): VideoRepository { return new class($items) implements VideoRepository { public function __construct(private array $items) {} public function findByCanonicalId(string $id): ?Video { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; } public function findByExternalReference(string $platform, string $id): ?Video { return null; } public function create(Video $video): Video { return $video; } public function update(Video $video, int $expectedRevision): Video { return $video; } public function list(bool $includeRetired = false): array { return $this->items; } }; }
    private function knowledgeRepository(): KnowledgeRepository { return new class implements KnowledgeRepository { public function findByCanonicalId(string $id): ?KnowledgeClaim { return null; } public function findByStableKey(string $stableKey): ?KnowledgeClaim { return null; } public function create(KnowledgeClaim $claim): KnowledgeClaim { return $claim; } public function update(KnowledgeClaim $claim, int $expectedRevision): KnowledgeClaim { return $claim; } public function list(bool $includeRetired = false): array { return []; } }; }
    private function evidenceRepository(): EvidenceRepository { return new class implements EvidenceRepository { public function findByCanonicalId(string $id): ?Evidence { return null; } public function create(Evidence $evidence): Evidence { return $evidence; } public function update(Evidence $evidence, int $expectedRevision): Evidence { return $evidence; } public function listByClaim(string $claimId, bool $includeRetired = false): array { return []; } public function listBySource(string $sourceId, bool $includeRetired = false): array { return []; } }; }
    private function sourceRepository(): SourceRepository { return new class implements SourceRepository { public function findByCanonicalId(string $id): ?Source { return null; } public function findByStableKey(string $stableKey): ?Source { return null; } public function create(Source $source): Source { return $source; } public function update(Source $source, int $expectedRevision): Source { return $source; } public function list(bool $includeRetired = false): array { return []; } }; }
}
