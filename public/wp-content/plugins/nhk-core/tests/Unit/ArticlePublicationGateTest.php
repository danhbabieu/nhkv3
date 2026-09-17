<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Article\{ArticlePublicationGate, ArticleResearchPreflight};
use NHK\Core\Application\Capture\CaptureArticlePreflightHandoff;
use NHK\Core\Application\Media\{ArticleMediaCoordinator, MediaService};
use NHK\Core\Contracts\Media\{ArticleMediaBlueprintRepository, MediaAssetRepository, MediaRepository, MediaUsageUpdater, MutableMediaUsageRepository};
use NHK\Core\Domain\Article\EditorialPostState;
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaSeoBlueprint, MediaUsage};
use PHPUnit\Framework\TestCase;

final class ArticlePublicationGateTest extends TestCase
{
    public function test_gate_requires_all_verified_boundaries_and_matching_draft_token(): void
    {
        $draft = $this->draft();
        $evidence = $this->evidence();
        $result = (new ArticlePublicationGate())->check($draft, $evidence, $draft->token);
        self::assertTrue($result->eligible);
        self::assertSame([], $result->blockers);
    }

    public function test_gate_reports_explicit_blockers_and_never_returns_generic_failure(): void
    {
        $draft = $this->draft();
        $result = (new ArticlePublicationGate())->check($draft, ['claim_compliance_acceptable' => false], str_repeat('0', 64));
        self::assertFalse($result->eligible);
        self::assertContains('EDITORIAL_CAS_REQUIRED', $result->blockers);
        self::assertContains('RESEARCH_PREFLIGHT_BLOCKED', $result->blockers);
        self::assertContains('PUBLIC_CLAIM_COMPLIANCE_BLOCKED', $result->blockers);
        self::assertArrayHasKey('blockers', $result->toArray());
        self::assertArrayNotHasKey('ok', $result->toArray());
    }

    public function test_gate_rejects_published_or_identity_incomplete_state(): void
    {
        $draft = new EditorialPostState(1, '1:1', 'post', 'publish', 'Title', 'Body', '', '', '', 1, 1);
        $result = (new ArticlePublicationGate())->check($draft, $this->evidence(), $draft->token);
        self::assertFalse($result->eligible);
        self::assertContains('EDITORIAL_POST_NOT_DRAFT', $result->blockers);
        self::assertContains('CANONICAL_PUBLIC_IDENTITY_INVALID', $result->blockers);
    }

    public function test_soft_incomplete_media_links_optional_data_and_rendered_unavailability_do_not_block(): void
    {
        $evidence = $this->evidence();
        $evidence['real_image_requirements_met'] = false;
        $evidence['internal_links_valid'] = false;
        $evidence['structured_data_valid'] = false;
        $evidence['structured_data_status'] = 'incomplete';
        $evidence['rendered_public_verification'] = false;
        $evidence['rendered_public_verification_status'] = 'unavailable';

        $result = (new ArticlePublicationGate())->check($this->draft(), $evidence, $this->draft()->token);

        self::assertTrue($result->eligible);
        self::assertSame([], $result->blockers);
        self::assertContains('REAL_IMAGE_INCOMPLETE', $result->warnings);
        self::assertContains('RENDERED_PUBLIC_VERIFICATION_UNAVAILABLE', $result->warnings);
    }

    public function test_gate_does_not_treat_planning_subject_or_media_candidates_as_persisted_state(): void
    {
        $evidence = $this->evidence();
        $evidence['subject_resolved'] = true;
        $evidence['subject_persistence_status'] = 'unattached_planning_candidate';
        $evidence['media_usage_complete'] = true;
        $evidence['media_snapshot'] = [
            'featured_primary' => ['placeholder' => false],
            'inline_primary' => ['placeholder' => true],
        ];

        $result = (new ArticlePublicationGate())->check($this->draft(), $evidence, $this->draft()->token);

        self::assertContains('SUBJECT_NOT_PERSISTED', $result->blockers);
        self::assertNotContains('MEDIAUSAGE_INCOMPLETE', $result->blockers);
        self::assertNotContains('ARTICLE_MEDIA_INLINE_MISSING', $result->blockers);
        self::assertContains('ARTICLE_MEDIA_INLINE_MISSING', $result->warnings);
    }

    public function test_missing_optional_inline_media_is_a_warning_when_featured_media_is_verified(): void
    {
        $evidence = $this->evidence();
        $evidence['media_usage_complete'] = false;
        $evidence['media_snapshot'] = [
            'featured_primary' => ['placeholder' => false],
            'inline_primary' => ['placeholder' => true],
        ];

        $result = (new ArticlePublicationGate())->check($this->draft(), $evidence, $this->draft()->token);

        self::assertTrue($result->eligible);
        self::assertNotContains('MEDIAUSAGE_INCOMPLETE', $result->blockers);
        self::assertNotContains('ARTICLE_MEDIA_INLINE_MISSING', $result->blockers);
        self::assertContains('MEDIAUSAGE_INCOMPLETE', $result->warnings);
        self::assertContains('ARTICLE_MEDIA_INLINE_MISSING', $result->warnings);
    }

    public function test_missing_featured_media_remains_a_publication_blocker(): void
    {
        $evidence = $this->evidence();
        $evidence['media_usage_complete'] = false;
        $evidence['media_snapshot'] = [
            'featured_primary' => ['placeholder' => true],
            'inline_primary' => ['placeholder' => true],
        ];

        $result = (new ArticlePublicationGate())->check($this->draft(), $evidence, $this->draft()->token);

        self::assertFalse($result->eligible);
        self::assertContains('MEDIAUSAGE_INCOMPLETE', $result->blockers);
        self::assertContains('ARTICLE_MEDIA_FEATURED_MISSING', $result->blockers);
        self::assertContains('ARTICLE_MEDIA_INLINE_MISSING', $result->warnings);
    }

    public function test_representative_only_usage_cannot_satisfy_article_media_readiness(): void
    {
        $evidence = $this->evidence();
        $evidence['media_usage_complete'] = false;
        $evidence['requirements'] = $this->requirements('article_media');
        $evidence['media_snapshot'] = [
            'representative_usages' => [
                ['endpoint_type' => 'model', 'endpoint_key' => 'model-111', 'role' => 'representative', 'usage_id' => 'model-usage'],
                ['endpoint_type' => 'classification', 'endpoint_key' => 'classification-cuckoo', 'role' => 'representative', 'usage_id' => 'classification-usage'],
            ],
            'article_usage_ids' => [],
        ];

        $result = (new ArticlePublicationGate())->check($this->draft(), $evidence, $this->draft()->token);

        self::assertFalse($result->eligible);
        self::assertContains('MEDIAUSAGE_INCOMPLETE', $result->blockers);
        self::assertContains('ARTICLE_MEDIA_FEATURED_MISSING', $result->blockers);
        self::assertNotContains('model-usage', $evidence['media_snapshot']['article_usage_ids']);
        self::assertNotContains('classification-usage', $evidence['media_snapshot']['article_usage_ids']);
    }

    public function test_verified_article_media_handoff_from_real_reconciliation_preserves_representatives_and_passes_gate(): void
    {
        [$media, $assets, $usages, $blueprints, $service] = $this->mediaStores();
        $clock = $service->create('media-clock', 'Clock article image', 'ready', [
            'metadata' => ['subject_id' => 'subject-vedette-37'],
        ]);
        $service->addAsset($clock->canonicalId, 'original', 'uploads/clock.webp', hash('sha256', 'clock'), 'image/webp', 10, 1200, 800, 'PUBLIC');
        $service->addUsage($clock->canonicalId, 'model', 'model-111', 'representative');
        $service->addUsage($clock->canonicalId, 'classification', 'classification-cuckoo', 'representative');
        $service->addUsage($clock->canonicalId, 'dictionary_concept', 'dictionary-clock', 'representative');
        $representativesBefore = $this->usageTuples($usages->listByMediaId($clock->canonicalId));

        $mediaEvidence = (new ArticleMediaCoordinator($service, $media, $assets, $usages, $blueprints, 1))
            ->ensureForPost(573, [
                'content_intent' => 'IMAGE_ARTICLE',
                'capture_owned_media_ids' => [$clock->canonicalId],
                'single_real_image_exception' => true,
                'subject_ids' => ['subject-vedette-37'],
            ])
            ->toArray();
        $mediaEvidence['featured_primary'] = $mediaEvidence['slots']['featured_primary'];
        $mediaEvidence['inline_primary'] = $mediaEvidence['slots']['inline_primary'];
        $mediaEvidence['article_media_reconciliation'] = 'REQUIRED_BEFORE_PUBLICATION_RESEARCH';
        $mediaUsage = $mediaEvidence['canonical_readback']['media_usage'];

        self::assertSame('VERIFIED', $mediaUsage['state']);
        self::assertSame('wp_post', $mediaUsage['endpoint_type']);
        self::assertSame('1:573', $mediaUsage['endpoint_key']);
        self::assertSame(['featured_primary', 'inline_primary'], $mediaUsage['roles']);
        self::assertNotEmpty($mediaUsage['usage_ids']);
        self::assertCount(2, $mediaUsage['usage_ids']);
        self::assertSame('ARTICLE_MEDIA_RECONCILIATION', $mediaUsage['source']);
        self::assertSame([], $mediaUsage['blockers']);

        $research = (new ArticleResearchPreflight(
            static fn (array $subject): array => ['status' => 'resolved', 'primary' => ['id' => 'subject-vedette-37', 'type' => 'variant', 'name' => 'Vedette 37']],
            static fn (array $context): array => [
                'status' => 'available',
                'posts' => [['id' => '1:573', 'subject_ids' => ['subject-vedette-37'], 'title' => 'Bài khác']],
                'categories' => [['name' => 'Tri thức đồng hồ', 'slug' => 'tri-thuc-dong-ho']],
                'knowledge' => [], 'sources' => [], 'evidence' => [], 'media' => [], 'videos' => [], 'relations' => [],
                'article_media' => $mediaEvidence,
            ],
            static fn (array $candidate): array => ['eligible' => false],
        ))->research('Vedette 37', ['type' => 'variant', 'name' => 'Vedette 37'], ['post_id' => 573]);
        $handoff = (new CaptureArticlePreflightHandoff())->build(
            $research,
            $mediaEvidence,
            ['status' => 'SKIPPED', 'requirements' => ['semantic_delta' => ['applicability' => 'NOT_REQUIRED', 'policy' => 'VERIFY', 'state' => 'SKIPPED']]],
            ['post_id' => 573, 'slug' => 'vedette-37', 'permalink' => '/vedette-37/'],
        );
        $handoff['claim_compliance_acceptable'] = true;

        $representativesAfter = $this->usageTuples($usages->listByMediaId($clock->canonicalId));
        self::assertSame($representativesBefore, $representativesAfter);

        $draft = new EditorialPostState(573, '1:573', 'post', 'draft', 'Vedette 37', 'Body', '', 'vedette-37', '/vedette-37/', 1, 1);
        $result = (new ArticlePublicationGate())->check($draft, array_replace($this->evidence(), $handoff), $draft->token);

        self::assertTrue($result->eligible, 'Unexpected blockers: ' . implode(', ', $result->blockers));
        self::assertSame('PASS', $result->toArray()['outcome']);
        self::assertSame($mediaUsage['usage_ids'], $handoff['media_snapshot']['canonical_readback']['media_usage']['usage_ids']);
        self::assertSame(['model-111', 'classification-cuckoo', 'dictionary-clock'], array_column($representativesAfter, 'endpoint_key'));
    }

    public function test_gate_skips_non_applicable_semantic_owner_but_evaluates_required_article_owners(): void
    {
        $evidence = $this->evidence();
        $evidence['semantic_readback_verified'] = false;
        $evidence['requirements'] = $this->requirements();

        $draft = $this->draft();
        $result = (new ArticlePublicationGate())->check($draft, $evidence, $draft->token);

        self::assertTrue($result->eligible);
        self::assertNotContains('SEMANTIC_READBACK_UNVERIFIED', $result->blockers);
        self::assertNotContains('MEDIAUSAGE_INCOMPLETE', $result->blockers);
        self::assertNotContains('PUBLIC_ROUTE_NOT_READY', $result->blockers);
    }

    public function test_gate_does_not_skip_a_hard_blocked_semantic_requirement_marked_not_required(): void
    {
        $evidence = $this->evidence();
        $evidence['semantic_readback_verified'] = false;
        $evidence['requirements'] = $this->requirements();
        $evidence['requirements']['semantic_delta'] = [
            'applicability' => 'NOT_REQUIRED',
            'policy' => 'HARD_BLOCK',
            'state' => 'BLOCKED',
        ];

        $draft = $this->draft();
        $result = (new ArticlePublicationGate())->check($draft, $evidence, $draft->token);

        self::assertFalse($result->eligible);
        self::assertContains('SEMANTIC_READBACK_UNVERIFIED', $result->blockers);
    }

    public function test_gate_does_not_skip_a_hard_blocked_semantic_requirement_marked_not_applicable(): void
    {
        $evidence = $this->evidence();
        $evidence['semantic_readback_verified'] = false;
        $evidence['requirements'] = $this->requirements();
        $evidence['requirements']['semantic_delta'] = [
            'applicability' => 'NOT_APPLICABLE',
            'policy' => 'HARD_BLOCK',
            'state' => 'BLOCKED',
        ];

        $draft = $this->draft();
        $result = (new ArticlePublicationGate())->check($draft, $evidence, $draft->token);

        self::assertFalse($result->eligible);
        self::assertContains('SEMANTIC_READBACK_UNVERIFIED', $result->blockers);
    }

    public function test_gate_does_not_skip_pending_semantic_requirements_under_non_required_applicability(): void
    {
        foreach (['NOT_APPLICABLE', 'NOT_REQUIRED'] as $applicability) {
            $evidence = $this->evidence();
            $evidence['semantic_readback_verified'] = false;
            $evidence['requirements'] = $this->requirements();
            $evidence['requirements']['semantic_delta'] = [
                'applicability' => $applicability,
                'policy' => 'HUMAN_REVIEW',
                'state' => 'PENDING',
            ];

            $draft = $this->draft();
            $result = (new ArticlePublicationGate())->check($draft, $evidence, $draft->token);

            self::assertFalse($result->eligible);
            self::assertContains('SEMANTIC_READBACK_UNVERIFIED', $result->blockers);
        }
    }

    public function test_unavailable_rendered_public_verification_is_a_warning_when_not_applicable(): void
    {
        $evidence = $this->evidence();
        $evidence['rendered_public_verification'] = false;
        $evidence['rendered_public_verification_status'] = 'unavailable';
        $evidence['requirements'] = $this->requirements();
        $evidence['requirements']['rendered_public'] = [
            'applicability' => 'NOT_APPLICABLE',
            'policy' => 'VERIFY',
            'state' => 'SKIPPED',
            'evidence' => ['status' => 'unavailable'],
        ];

        $draft = $this->draft();
        $result = (new ArticlePublicationGate())->check($draft, $evidence, $draft->token);

        self::assertTrue($result->eligible);
        self::assertNotContains('RENDERED_PUBLIC_VERIFICATION_UNAVAILABLE', $result->blockers);
        self::assertContains('RENDERED_PUBLIC_VERIFICATION_UNAVAILABLE', $result->warnings);
    }

    public function test_missing_or_empty_rendered_public_verification_status_fails_closed(): void
    {
        foreach ([null, ''] as $status) {
            $evidence = $this->evidence();
            $evidence['rendered_public_verification'] = false;
            if ($status === null) unset($evidence['rendered_public_verification_status']);
            else $evidence['rendered_public_verification_status'] = $status;
            $evidence['requirements'] = $this->requirements();

            $draft = $this->draft();
            $result = (new ArticlePublicationGate())->check($draft, $evidence, $draft->token);

            self::assertFalse($result->eligible);
            self::assertContains('RENDERED_PUBLIC_VERIFICATION_UNAVAILABLE', $result->blockers);
        }
    }

    public function test_rendered_public_status_must_be_verified_even_when_boolean_is_true(): void
    {
        foreach ([null, '', 'unknown', 'invalid', 'unverified'] as $status) {
            $evidence = $this->evidence();
            $evidence['rendered_public_verification'] = true;
            if ($status === null) unset($evidence['rendered_public_verification_status']);
            else $evidence['rendered_public_verification_status'] = $status;
            $evidence['requirements'] = $this->requirements();

            $draft = $this->draft();
            $result = (new ArticlePublicationGate())->check($draft, $evidence, $draft->token);

            self::assertFalse($result->eligible);
            self::assertContains('RENDERED_PUBLIC_VERIFICATION_UNAVAILABLE', $result->blockers);
        }
    }

    public function test_legacy_evidence_without_rendered_requirement_keeps_boolean_compatibility(): void
    {
        $evidence = $this->evidence();
        unset($evidence['rendered_public_verification_status'], $evidence['requirements']);

        $draft = $this->draft();
        $result = (new ArticlePublicationGate())->check($draft, $evidence, $draft->token);

        self::assertTrue($result->eligible);
        self::assertSame([], $result->blockers);
    }

    public function test_gate_blocks_unverified_required_article_media_even_when_semantic_is_not_applicable(): void
    {
        $evidence = $this->evidence();
        $evidence['semantic_readback_verified'] = false;
        $evidence['requirements'] = $this->requirements('article_media');

        $draft = $this->draft();
        $result = (new ArticlePublicationGate())->check($draft, $evidence, $draft->token);

        self::assertFalse($result->eligible);
        self::assertContains('MEDIAUSAGE_INCOMPLETE', $result->blockers);
        self::assertNotContains('SEMANTIC_READBACK_UNVERIFIED', $result->blockers);
    }

    public function test_gate_blocks_unverified_required_public_route_even_when_semantic_is_not_applicable(): void
    {
        $evidence = $this->evidence();
        $evidence['semantic_readback_verified'] = false;
        $evidence['requirements'] = $this->requirements('public_route');

        $draft = $this->draft();
        $result = (new ArticlePublicationGate())->check($draft, $evidence, $draft->token);

        self::assertFalse($result->eligible);
        self::assertContains('PUBLIC_ROUTE_NOT_READY', $result->blockers);
        self::assertNotContains('SEMANTIC_READBACK_UNVERIFIED', $result->blockers);
    }

    public function test_gate_blocks_unverified_required_rendered_public_readback_even_when_semantic_is_not_applicable(): void
    {
        $evidence = $this->evidence();
        $evidence['semantic_readback_verified'] = false;
        $evidence['requirements'] = $this->requirements('rendered_public');

        $draft = $this->draft();
        $result = (new ArticlePublicationGate())->check($draft, $evidence, $draft->token);

        self::assertFalse($result->eligible);
        self::assertContains('RENDERED_PUBLIC_VERIFICATION_UNAVAILABLE', $result->blockers);
        self::assertNotContains('SEMANTIC_READBACK_UNVERIFIED', $result->blockers);
    }

    /** @return array<string,mixed> */
    private function evidence(): array
    {
        $evidence = array_fill_keys([
            'research_acceptable', 'subject_resolved', 'duplicate_intent_handled',
            'category_resolved', 'semantic_plan_complete', 'semantic_readback_verified',
            'media_usage_complete', 'real_image_requirements_met', 'claim_compliance_acceptable',
            'seo_projection_valid', 'internal_links_valid', 'structured_data_valid', 'public_route_ready', 'rendered_public_verification',
        ], true);
        $evidence['rendered_public_verification_status'] = 'verified';
        return $evidence;
    }

    /** @return array<string,array<string,string>> */
    private function requirements(string $unverified = ''): array
    {
        $requirements = [
            'semantic_delta' => ['applicability' => 'NOT_APPLICABLE', 'policy' => 'VERIFY', 'state' => 'SKIPPED'],
            'article_media' => ['applicability' => 'REQUIRED', 'policy' => 'VERIFY', 'state' => 'VERIFIED'],
            'public_route' => ['applicability' => 'REQUIRED', 'policy' => 'VERIFY', 'state' => 'VERIFIED'],
            'rendered_public' => ['applicability' => 'REQUIRED', 'policy' => 'VERIFY', 'state' => 'VERIFIED'],
        ];
        if ($unverified !== '') $requirements[$unverified]['state'] = 'PENDING';
        return $requirements;
    }

    private function draft(): EditorialPostState
    {
        return new EditorialPostState(1, '1:1', 'post', 'draft', 'Title', 'Body', '', 'title', '/title/', 1, 1);
    }

    /** @return array{0:MediaRepository,1:MediaAssetRepository,2:MutableMediaUsageRepository&MediaUsageUpdater,3:ArticleMediaBlueprintRepository,4:MediaService} */
    private function mediaStores(): array
    {
        $media = new class implements MediaRepository {
            public array $items = [];
            public function findByCanonicalId(string $id): ?Media { return $this->items[$id] ?? null; }
            public function findByStableKey(string $key): ?Media { foreach ($this->items as $item) if ($item->stableKey === $key) return $item; return null; }
            public function create(Media $item): Media { return $this->items[$item->canonicalId] = $item; }
            public function update(Media $item, int $revision): Media { return $this->items[$item->canonicalId] = $item; }
            public function list(bool $includeRetired = false): array { return array_values($this->items); }
        };
        $assets = new class implements MediaAssetRepository {
            public array $items = [];
            public function findByAssetId(string $id): ?MediaAsset { return $this->items[$id] ?? null; }
            public function create(MediaAsset $asset): MediaAsset { return $this->items[$asset->assetId] = $asset; }
            public function update(MediaAsset $asset, int $expectedRevision = 1): MediaAsset { return $this->items[$asset->assetId] = $asset; }
            public function listByMediaId(string $id): array { return array_values(array_filter($this->items, static fn (MediaAsset $asset): bool => $asset->mediaId === $id)); }
            public function findByChecksum(string $checksum): array { return array_values(array_filter($this->items, static fn (MediaAsset $asset): bool => $asset->checksum === $checksum)); }
        };
        $usages = new class implements MutableMediaUsageRepository, MediaUsageUpdater {
            public array $items = [];
            public function create(MediaUsage $usage): MediaUsage { return $this->items[$usage->usageId] = $usage; }
            public function listByMediaId(string $id, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $usage): bool => $usage->mediaId === $id && ($role === null || $usage->role === $role))); }
            public function listByEndpoint(string $type, string $key, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $usage): bool => $usage->endpointType === $type && $usage->endpointKey === $key && ($role === null || $usage->role === $role))); }
            public function update(MediaUsage $usage): MediaUsage
            {
                $current = $this->items[$usage->usageId] ?? null;
                $next = new MediaUsage($usage->usageId, $usage->mediaId, $usage->endpointType, $usage->endpointKey, $usage->role, $usage->sortOrder, $usage->altText, $usage->caption, $usage->keywordGroups, $usage->title, ($current?->revision ?? $usage->revision) + 1, $usage->placementKey);
                return $this->items[$usage->usageId] = $next;
            }
            public function removeByEndpointRole(string $type, string $key, string $role): int { $before = count($this->items); foreach ($this->items as $id => $usage) if ($usage->endpointType === $type && $usage->endpointKey === $key && $usage->role === $role) unset($this->items[$id]); return $before - count($this->items); }
        };
        $blueprints = new class implements ArticleMediaBlueprintRepository {
            public array $items = [];
            public function findByPostAndSlot(int $postId, string $slot): ?MediaSeoBlueprint { return $this->items[$postId . ':' . $slot] ?? null; }
            public function save(MediaSeoBlueprint $blueprint): MediaSeoBlueprint { return $this->items[$blueprint->postId . ':' . $blueprint->slot] = $blueprint; }
            public function listByPost(int $postId): array { return array_values(array_filter($this->items, static fn (MediaSeoBlueprint $blueprint): bool => $blueprint->postId === $postId)); }
        };
        return [$media, $assets, $usages, $blueprints, new MediaService($media, $assets, $usages)];
    }

    /** @param list<MediaUsage> $usages @return list<array<string,string>> */
    private function usageTuples(array $usages): array
    {
        return array_values(array_map(static fn (MediaUsage $usage): array => [
            'usage_id' => $usage->usageId,
            'media_id' => $usage->mediaId,
            'endpoint_type' => $usage->endpointType,
            'endpoint_key' => $usage->endpointKey,
            'role' => $usage->role,
            'placement_key' => $usage->placementKey,
        ], array_values(array_filter($usages, static fn (MediaUsage $usage): bool => $usage->role === 'representative'))));
    }
}
