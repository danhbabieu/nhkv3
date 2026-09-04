<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Video\VideoPublicContextSelector;
use NHK\Core\Application\Video\VideoUrlPolicy;
use NHK\Core\Contracts\PublicIdentity\PublicIdentityRepository;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeDefinition, EntityTypeRegistry};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Core\Domain\Graph\PredicateRegistry;
use NHK\Core\Domain\PublicIdentity\{HistoricPublicRoute, PublicIdentity, PublicIdentityMutationResult};
use NHK\Core\Domain\Video\Video;
use PHPUnit\Framework\TestCase;

final class VideoUrlPolicyTest extends TestCase
{
    private const VIDEO_ID = '01a06815-1e51-7964-b004-1ba79e488ad1';

    public function test_governed_persisted_identity_projects_the_video_canary_shape(): void
    {
        $video = new Video(self::VIDEO_ID, 'youtube', 'P4KaHX3LBOw', 'https://www.youtube.com/watch?v=P4KaHX3LBOw', 'Changed source title', [
            'source_snapshot' => ['platform' => 'youtube', 'external_video_id' => 'P4KaHX3LBOw', 'canonical_source_url' => 'https://www.youtube.com/watch?v=P4KaHX3LBOw', 'availability' => 'available', 'embeddable' => true],
            'editorial' => ['title' => 'NHK editorial title', 'summary' => 'Summary'],
            'hub' => ['primary' => '06'],
            'provenance' => ['kind' => 'YOUTUBE_SOURCE', 'source_url' => 'https://www.youtube.com/watch?v=P4KaHX3LBOw'],
            'semantic_attachments' => [['target_id' => '22222222-2222-4222-8222-222222222222', 'target_type' => 'brand', 'predicate' => 'about', 'evidence_refs' => [['evidence_id' => '33333333-3333-4333-8333-333333333333']], 'approved' => true]],
        ]);

        $result = (new VideoUrlPolicy($this->repository('odo-36-10-gai-carillon'), ...$this->governance()))->project($video, new VideoPublicContextSelector());

        self::assertTrue($result->eligible);
        self::assertSame('/video/odo-36-10-gai-carillon-p4kahx3lbow/', $result->finalPath);
    }

    public function test_source_title_changes_do_not_change_the_persisted_video_url(): void
    {
        $video = new Video(self::VIDEO_ID, 'youtube', 'P4KaHX3LBOw', 'https://www.youtube.com/watch?v=P4KaHX3LBOw', 'New marketing title', [
            'source_snapshot' => ['platform' => 'youtube', 'external_video_id' => 'P4KaHX3LBOw', 'canonical_source_url' => 'https://www.youtube.com/watch?v=P4KaHX3LBOw', 'availability' => 'available', 'embeddable' => true],
            'editorial' => ['title' => 'New NHK title', 'summary' => 'Summary'],
            'hub' => ['primary' => '06'],
            'provenance' => ['kind' => 'YOUTUBE_SOURCE', 'source_url' => 'https://www.youtube.com/watch?v=P4KaHX3LBOw'],
            'semantic_attachments' => [['target_id' => '22222222-2222-4222-8222-222222222222', 'target_type' => 'brand', 'predicate' => 'about', 'evidence_refs' => [['evidence_id' => '33333333-3333-4333-8333-333333333333']], 'approved' => true]],
        ]);

        self::assertSame('/video/odo-36-10-gai-carillon-p4kahx3lbow/', (new VideoUrlPolicy($this->repository('odo-36-10-gai-carillon'), ...$this->governance()))->project($video, new VideoPublicContextSelector())->finalPath);
    }

    public function test_context_selector_uses_governed_context_before_editorial_and_user_hint(): void
    {
        $selector = new VideoPublicContextSelector();

        self::assertSame(['source' => 'variant', 'value' => 'Ô Đô 36/10'], $selector->select(['governed_context' => [
            'variant' => ['name' => 'Ô Đô 36/10'],
            'model' => ['name' => 'Model'],
            'brand' => ['name' => 'Brand'],
            'music' => ['name' => 'Music'],
            'editorial_context' => 'Editorial context',
            'user_hint' => 'User hint',
        ]]));
        self::assertSame(['source' => 'editorial_context', 'value' => 'Editorial context'], $selector->select(['governed_context' => [
            'editorial_context' => 'Editorial context',
            'user_hint' => 'User hint',
        ]]));
        self::assertSame(['source' => 'user_hint', 'value' => 'User hint'], $selector->select(['governed_context' => [
            'user_hint' => 'User hint',
        ]]));
        self::assertNull($selector->select(['variant' => 'Arbitrary override', 'marketing_title' => 'Marketing title']));
    }

    public function test_policy_blocks_video_without_governed_public_context(): void
    {
        $video = Video::fromUrl('https://youtu.be/P4KaHX3LBOw', 'Marketing title', [
            'source_snapshot' => ['platform' => 'youtube', 'external_video_id' => 'P4KaHX3LBOw', 'canonical_source_url' => 'https://www.youtube.com/watch?v=P4KaHX3LBOw', 'availability' => 'available', 'embeddable' => true],
            'editorial' => ['title' => 'Editorial title', 'summary' => 'Summary'],
            'hub' => ['primary' => '06'],
            'provenance' => ['kind' => 'YOUTUBE_SOURCE', 'source_url' => 'https://www.youtube.com/watch?v=P4KaHX3LBOw'],
            'semantic_attachments' => [['target_id' => '22222222-2222-4222-8222-222222222222', 'target_type' => 'brand', 'predicate' => 'about', 'evidence_refs' => [['evidence_id' => '33333333-3333-4333-8333-333333333333']], 'approved' => true]],
        ], null, self::VIDEO_ID);

        $result = (new VideoUrlPolicy($this->repository('missing'), ...$this->governance()))->project($video, new VideoPublicContextSelector());

        self::assertFalse($result->eligible);
        self::assertContains('PUBLIC_IDENTITY_NOT_FOUND', $result->blockers);
    }

    public function test_arbitrary_metadata_identity_cannot_mint_a_public_url(): void
    {
        $video = Video::fromUrl('https://youtu.be/P4KaHX3LBOw', 'Marketing title', ['public_identity' => ['current_slug' => 'forged'], 'source_snapshot' => ['availability' => 'available', 'embeddable' => true]] , null, self::VIDEO_ID);
        $result = (new VideoUrlPolicy($this->repository('missing'), ...$this->governance()))->project($video, new VideoPublicContextSelector());
        self::assertFalse($result->eligible);
        self::assertContains('PUBLIC_IDENTITY_NOT_FOUND', $result->blockers);
    }

    public function test_incomplete_source_snapshot_cannot_mint_a_public_url(): void
    {
        $video = Video::fromUrl('https://youtu.be/P4KaHX3LBOw', 'Marketing title', [
            'source_snapshot' => ['availability' => 'available', 'embeddable' => true],
            'editorial' => ['title' => 'Editorial title', 'summary' => 'Summary'],
            'hub' => ['primary' => '06'],
            'provenance' => ['kind' => 'YOUTUBE_SOURCE', 'source_url' => 'https://www.youtube.com/watch?v=P4KaHX3LBOw'],
            'semantic_attachments' => [['target_id' => '22222222-2222-4222-8222-222222222222', 'target_type' => 'brand', 'predicate' => 'about', 'evidence_refs' => [['evidence_id' => '33333333-3333-4333-8333-333333333333']], 'approved' => true]],
        ], null, self::VIDEO_ID);

        $result = (new VideoUrlPolicy($this->repository('odo-36-10-gai-carillon'), ...$this->governance()))->project($video, new VideoPublicContextSelector());

        self::assertFalse($result->eligible);
        self::assertContains('SOURCE_SNAPSHOT_INVALID', $result->blockers);
    }

    public function test_unregistered_target_and_unusable_evidence_block_projection(): void
    {
        $video = $this->validVideo(['target_id' => '44444444-4444-4444-8444-444444444444', 'target_type' => 'brand', 'predicate' => 'about', 'evidence_refs' => [['evidence_id' => '55555555-5555-4555-8555-555555555555']], 'approved' => true]);
        $result = (new VideoUrlPolicy($this->repository('odo-36-10-gai-carillon'), ...$this->governance(false)))->project($video, new VideoPublicContextSelector());
        self::assertFalse($result->eligible);
        self::assertContains('SEMANTIC_ATTACHMENT_UNUSABLE', $result->blockers);
    }

    private function validVideo(array $attachment): Video
    {
        return new Video(self::VIDEO_ID, 'youtube', 'P4KaHX3LBOw', 'https://www.youtube.com/watch?v=P4KaHX3LBOw', 'Title', [
            'source_snapshot' => ['platform' => 'youtube', 'external_video_id' => 'P4KaHX3LBOw', 'canonical_source_url' => 'https://www.youtube.com/watch?v=P4KaHX3LBOw', 'availability' => 'available', 'embeddable' => true],
            'editorial' => ['title' => 'Editorial', 'summary' => 'Summary'], 'hub' => ['primary' => '06'], 'provenance' => ['kind' => 'YOUTUBE_SOURCE', 'source_url' => 'https://www.youtube.com/watch?v=P4KaHX3LBOw'], 'semantic_attachments' => [$attachment],
        ]);
    }

    /** @return array{?AuthorityRepository,?EntityTypeRegistry,?EvidenceRepository,?SourceRepository,PredicateRegistry} */
    private function governance(bool $valid = true): array
    {
        $types = new EntityTypeRegistry(); $types->register(new EntityTypeDefinition('brand', 1, true, []));
        $authority = new class($valid) implements AuthorityRepository { public function __construct(private bool $valid) {} public function findByCanonicalId(string $id): ?AuthorityEntity { return $this->valid ? new AuthorityEntity($id, 'brand', 'brand:target', 'Target', 1, []) : null; } public function findByStableKey(string $type, string $key): ?AuthorityEntity { return null; } public function create(AuthorityEntity $entity): AuthorityEntity { return $entity; } public function update(AuthorityEntity $entity, int $expectedRevision): AuthorityEntity { return $entity; } public function rekey(AuthorityEntity $entity, string $oldStableKey, string $newStableKey, int $expectedRevision): AuthorityEntity { return $entity; } public function listByType(string $type, bool $includeRetired = false): array { return []; } };
        $evidence = new class implements EvidenceRepository { public function findByCanonicalId(string $id): ?Evidence { return new Evidence($id, '66666666-6666-4666-8666-666666666666', '77777777-7777-4777-8777-777777777777', 'supports', 'excerpt', null, true, 1, ['visibility' => 'PUBLIC']); } public function create(Evidence $evidence): Evidence { return $evidence; } public function update(Evidence $evidence, int $expectedRevision): Evidence { return $evidence; } public function listByClaim(string $claimId, bool $includeRetired = false): array { return []; } public function listBySource(string $sourceId, bool $includeRetired = false): array { return []; } };
        $sources = new class implements SourceRepository { public function findByCanonicalId(string $id): ?Source { return new Source($id, 'source:test', 'Source', 'website', 'https://example.test', ['visibility' => 'PUBLIC']); } public function findByStableKey(string $key): ?Source { return null; } public function create(Source $source): Source { return $source; } public function update(Source $source, int $expectedRevision): Source { return $source; } public function list(bool $includeRetired = false): array { return []; } };
        return [$authority, $types, $evidence, $sources, new PredicateRegistry()];
    }

    private function repository(string $slug): PublicIdentityRepository
    {
        return new class($slug, self::VIDEO_ID) implements PublicIdentityRepository {
            public function __construct(private string $slug, private string $ownerId) {}
            public function findByOwner(string $ownerKind, string $ownerId): ?PublicIdentity { return $ownerKind === 'video' && $ownerId === $this->ownerId && $this->slug === 'odo-36-10-gai-carillon' ? new PublicIdentity('identity-001', 'video', $ownerId, 'video', $this->slug, 'video', 'public-route-v1', 3) : null; }
            public function findByRoute(string $routeType, string $collisionScope, string $slug): ?PublicIdentity { return null; }
            public function create(PublicIdentity $identity): PublicIdentityMutationResult { return PublicIdentityMutationResult::accepted($identity); }
            public function update(PublicIdentity $identity, int $expectedRevision): PublicIdentityMutationResult { return PublicIdentityMutationResult::accepted($identity); }
            public function appendHistoricRoute(HistoricPublicRoute $historicRoute): PublicIdentityMutationResult { return PublicIdentityMutationResult::accepted(); }
        };
    }
}
