<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\{ArticleMediaCandidateSelector, ArticleMediaLegacyAudit, ArticleMediaSubjectReverseReconciliation, SemanticSuitabilityPolicy};
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Graph\GraphReader;
use NHK\Core\Contracts\Media\{ArticleMediaBlueprintCasRepository, ArticleMediaBlueprintRepository, ArticleMediaUsageInventory, MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Domain\Authority\{AuthorityEntity, AuthorityState};
use NHK\Core\Domain\Graph\{EdgeState, GraphEdge, GraphNode, NodeReference};
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaSeoBlueprint, MediaUsage};
use PHPUnit\Framework\TestCase;

final class ArticleMediaSubjectReverseReconciliationTest extends TestCase
{
    public const SUBJECT_ID = '143ee093-5bc0-409f-a4ce-af559d18f16f';

    public function test_one_active_about_edge_produces_deterministic_binding_and_governed_apply_readback(): void
    {
        $repository = $this->blueprints();
        $repository->items['757:featured_primary'] = $this->blueprint(757);
        $service = $this->service($this->edge(), AuthorityState::ACTIVE, $repository);

        $plan = $service->plan('wp_post', '1:757');
        self::assertSame('PLAN_READY', $plan['status']);
        self::assertSame(self::SUBJECT_ID, $plan['subject_binding']['subject_id']);
        self::assertSame('music', $plan['subject_binding']['subject_type']);
        self::assertSame('subject_bind', $plan['governed_operation']['arguments']['operation']);

        $result = $service->apply($this->proposal($plan));
        self::assertSame('1:757', $result->canonicalId);
        self::assertSame(self::SUBJECT_ID, $result->subjectBinding['subject_id']);
        self::assertSame(2, $result->readback[0]['binding_revision']);
        self::assertSame($plan['dependency_fingerprint'], $result->readback[0]['subject_binding']['dependency_fingerprint']);
        self::assertSame('ARTICLE_MEDIA_SUBJECT_BOUND', $result->mutation['mutation_result']);
    }

    public function test_no_active_about_edge_is_missing_without_a_mutation_plan(): void
    {
        $service = $this->service();

        $preview = $service->preview('wp_post', '1:757');

        self::assertSame('MISSING_SUBJECT_BINDING', $preview['status']);
        self::assertFalse($preview['mutated']);
        self::assertArrayNotHasKey('governed_operation', $service->plan('wp_post', '1:757'));
    }

    public function test_multiple_active_about_edges_are_review_required(): void
    {
        $second = $this->edge('22222222-2222-4222-8222-222222222222', '22222222-2222-4222-8222-222222222222');
        $preview = $this->service([$this->edge(), $second])->preview('wp_post', '1:757');

        self::assertSame('REVIEW_REQUIRED', $preview['status']);
        self::assertSame('AMBIGUOUS_ACTIVE_ABOUT_SUBJECT', $preview['reason']);
        self::assertCount(2, $preview['active_about_edges']);
    }

    public function test_inactive_or_unresolvable_authority_target_fails_closed(): void
    {
        $preview = $this->service($this->edge(), AuthorityState::RETIRED)->preview('wp_post', '1:757');

        self::assertSame('BLOCKED', $preview['status']);
        self::assertSame('SUBJECT_INACTIVE_OR_UNRESOLVABLE', $preview['reason']);
        self::assertFalse($preview['mutated']);
    }

    public function test_blueprint_cas_mismatch_rejects_apply_without_overwriting_newer_binding(): void
    {
        $repository = $this->blueprints();
        $repository->items['757:featured_primary'] = $this->blueprint(757);
        $service = $this->service($this->edge(), AuthorityState::ACTIVE, $repository);
        $plan = $service->plan('wp_post', '1:757');
        $repository->items['757:featured_primary'] = $repository->items['757:featured_primary']->withSubjectContext(['subject_ids' => ['newer']]);

        $this->expectExceptionMessage('ARTICLE_MEDIA_BLUEPRINT_REVISION_CONFLICT');
        $service->apply($this->proposal($plan));
    }

    public function test_subject_revision_change_invalidates_stale_plan(): void
    {
        $repository = $this->blueprints();
        $repository->items['757:featured_primary'] = $this->blueprint(757);
        $authority = $this->authority();
        $service = $this->service($this->edge(), AuthorityState::ACTIVE, $repository, $authority);
        $plan = $service->plan('wp_post', '1:757');
        $authority->entity = $this->subject(2);

        $this->expectExceptionMessage('ARTICLE_MEDIA_SUBJECT_BINDING_DEPENDENCY_CHANGED');
        $service->apply($this->proposal($plan));
    }

    public function test_missing_graph_subject_does_not_select_title_matching_media(): void
    {
        $service = $this->service();
        $preview = $service->preview('wp_post', '1:757');

        self::assertSame('MISSING_SUBJECT_BINDING', $preview['reason']);
        self::assertArrayNotHasKey('replacement_media_id', $preview);
        self::assertArrayNotHasKey('media', $preview);
    }

    public function test_757_shaped_fixture_is_missing_before_reconcile_and_audit_reads_sonodo_afterward(): void
    {
        $repository = $this->blueprints();
        $repository->items['757:featured_primary'] = $this->blueprint(757);
        $service = $this->service($this->edge(), AuthorityState::ACTIVE, $repository);
        $usage = new MediaUsage('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'wp_post', '1:757', 'featured_primary');
        self::assertSame('MISSING_SUBJECT_SCOPE', $this->auditAfterReconcile($repository, $usage)->auditUsage($usage)['reason']);

        $plan = $service->plan('wp_post', '1:757');
        $service->apply($this->proposal($plan, 'governance-proposal-757'));
        $blueprint = $repository->items['757:featured_primary'];
        self::assertSame(self::SUBJECT_ID, $blueprint->subjectContext['subject_id']);
        self::assertSame(1, (int) $blueprint->subjectContext['subject_revision']);

        $audit = $this->auditAfterReconcile($repository, $usage);
        $finding = $audit->auditUsage($usage);
        self::assertSame(self::SUBJECT_ID, $finding['canonical_subject_id']);
        self::assertSame('music', $finding['canonical_subject_type']);
    }

    private function service(array|GraphEdge $edges = [], AuthorityState $state = AuthorityState::ACTIVE, ?ArticleMediaBlueprintCasRepository $repository = null, ?object $authority = null): ArticleMediaSubjectReverseReconciliation
    {
        if ($edges instanceof GraphEdge) $edges = [$edges];
        $repository ??= $this->blueprints();
        $authority ??= $this->authority($state);
        $graph = new class($edges) implements GraphReader {
            public function __construct(private array $edges) {}
            public function findOutgoing(NodeReference $source, ?string $predicate = null, int $after = 0, int $limit = 50, bool $includeRetired = false, ?string $targetType = null): array { return ['items' => $this->edges, 'next_cursor' => null]; }
        };
        return new ArticleMediaSubjectReverseReconciliation($graph, $authority, $repository, static fn (NodeReference $reference): ?int => 17);
    }

    private function authority(AuthorityState $state = AuthorityState::ACTIVE): object
    {
        return new class($this->subject(1, $state)) implements AuthorityRepository {
            public function __construct(public ?AuthorityEntity $entity) {}
            public function findByCanonicalId(string $id): ?AuthorityEntity { return $this->entity?->canonicalId === $id ? $this->entity : null; }
            public function findByStableKey(string $type, string $key): ?AuthorityEntity { return null; }
            public function create(AuthorityEntity $entity): AuthorityEntity { return $entity; }
            public function update(AuthorityEntity $entity, int $expectedRevision): AuthorityEntity { return $entity; }
            public function rekey(AuthorityEntity $entity, string $oldStableKey, string $newStableKey, int $expectedRevision): AuthorityEntity { return $entity; }
            public function listByType(string $type, bool $includeRetired = false): array { return []; }
        };
    }

    private function subject(int $revision, AuthorityState $state = AuthorityState::ACTIVE): AuthorityEntity
    {
        return new AuthorityEntity(self::SUBJECT_ID, 'music', 'music.sonodo', 'Sonodo', 1, [], $state, $revision);
    }

    private function edge(string $uuid = '11111111-1111-4111-8111-111111111111', string $targetId = self::SUBJECT_ID): GraphEdge
    {
        return new GraphEdge($uuid, new GraphNode(1, new NodeReference('wp_post', '1:757')), 'about', new GraphNode(2, new NodeReference('music', $targetId)), EdgeState::ACTIVE, 3);
    }

    private function blueprint(int $postId): MediaSeoBlueprint
    {
        return MediaSeoBlueprint::forPost($postId, 'featured_primary', ['subject_context' => ['subject' => 'Post title that is not authority evidence']]);
    }

    private function blueprints(): ArticleMediaBlueprintCasRepository
    {
        return new class implements ArticleMediaBlueprintCasRepository {
            public array $items = [];
            public function findByPostAndSlot(int $postId, string $slot): ?MediaSeoBlueprint { return $this->items[$postId . ':' . $slot] ?? null; }
            public function save(MediaSeoBlueprint $blueprint): MediaSeoBlueprint { return $this->items[$blueprint->postId . ':' . $blueprint->slot] = $blueprint; }
            public function saveExpected(MediaSeoBlueprint $blueprint, int $expectedRevision): MediaSeoBlueprint
            {
                $key = $blueprint->postId . ':' . $blueprint->slot;
                if (($this->items[$key]?->revision ?? 0) !== $expectedRevision) throw new \RuntimeException('ARTICLE_MEDIA_BLUEPRINT_REVISION_CONFLICT');
                return $this->items[$key] = $blueprint;
            }
            public function listByPost(int $postId): array { return array_values(array_filter($this->items, static fn (MediaSeoBlueprint $blueprint): bool => $blueprint->postId === $postId)); }
        };
    }

    private function proposal(array $plan, string $id = 'governance-proposal'): Proposal
    {
        return new Proposal($id, '1:757', 'subject_bind', $plan['governed_operation']['arguments']['payload'], $plan['plan_fingerprint'], $plan['source_revision'], $plan['dependency_fingerprint'], ProposalState::APPROVED, idempotencyKey: $plan['governed_operation']['arguments']['idempotency_key'], entityType: 'wp_post');
    }

    private function auditAfterReconcile(ArticleMediaBlueprintRepository $blueprints, MediaUsage $usage): ArticleMediaLegacyAudit
    {
        $mediaId = $usage->mediaId;
        $media = new class($mediaId) implements MediaRepository {
            public function __construct(private string $id) {}
            public function findByCanonicalId(string $id): ?Media { return $id === $this->id ? new Media($this->id, 'sonodo-media', 'Sonodo image', 'ready', ['subject_id' => ArticleMediaSubjectReverseReconciliationTest::SUBJECT_ID]) : null; }
            public function findByStableKey(string $stableKey): ?Media { return null; }
            public function create(Media $media): Media { return $media; }
            public function update(Media $media, int $expectedRevision): Media { return $media; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $assets = new class($mediaId) implements MediaAssetRepository {
            public function __construct(private string $mediaId) {}
            public function findByAssetId(string $id): ?MediaAsset { return null; }
            public function create(MediaAsset $asset): MediaAsset { return $asset; }
            public function update(MediaAsset $asset, int $expectedRevision = 1): MediaAsset { return $asset; }
            public function listByMediaId(string $id): array { return $id === $this->mediaId ? [new MediaAsset('cccccccc-cccc-4ccc-8ccc-cccccccccccc', $id, 'original', 'sonodo.webp', hash('sha256', 'sonodo'), 'image/webp', 10, 1200, 675, 'PUBLIC')] : []; }
            public function findByChecksum(string $checksum): array { return []; }
        };
        $usages = new class($usage) implements MediaUsageRepository {
            public function __construct(private MediaUsage $usage) {}
            public function create(MediaUsage $usage): MediaUsage { return $usage; }
            public function listByMediaId(string $id, ?string $role = null): array { return $id === $this->usage->mediaId ? [$this->usage] : []; }
            public function listByEndpoint(string $endpointType, string $endpointKey, ?string $role = null): array { return [$this->usage]; }
        };
        $inventory = new class($usage) implements ArticleMediaUsageInventory {
            public function __construct(private MediaUsage $usage) {}
            public function page(string $endpointType, ?string $afterUsageId, int $limit): array { return ['items' => [$this->usage], 'next_cursor' => null]; }
        };
        return new ArticleMediaLegacyAudit($inventory, $media, $assets, $usages, $blueprints, new ArticleMediaCandidateSelector($media, $assets, $usages, new SemanticSuitabilityPolicy()), new SemanticSuitabilityPolicy());
    }
}
