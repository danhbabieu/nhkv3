<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryCurationService;
use NHK\Core\Application\Media\MediaService;
use NHK\Core\Contracts\Dictionary\{DictionaryCandidateRepository, DictionaryConceptRepository};
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository, MediaUsageUpdater};
use NHK\Core\Domain\Dictionary\{DictionaryCandidate, DictionaryCandidateState, DictionaryConcept, DictionaryLabel};
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaException, MediaUsage};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class DictionaryCurationServiceTest extends TestCase
{
    public function test_new_candidate_becomes_draft_not_public_concept(): void
    {
        $hash = hash('sha256', '{}');
        $candidate = new DictionaryCandidate('candidate-1', 'vai bò', $hash, ['Vai bò'], DictionaryCandidateState::NEEDS_REVIEW, ['usage_scope' => ['Vietnam']], [], 3, 'a', 'b', 1);
        [$candidateRepo, $conceptRepo] = $this->repositories($candidate);
        $service = new DictionaryCurationService($candidateRepo, $conceptRepo, static fn (): string => 'concept-1');

        $result = $service->createDraftFromCandidate('candidate-1', 1, 'Vai bò', 'Tên gọi dân gian tại Việt Nam.', ['public_slug' => 'vai-bo', 'term_type' => 'COLLOQUIAL']);

        self::assertSame(DictionaryConcept::DRAFT, $result['concept']->status);
        self::assertSame(DictionaryCandidateState::PROPOSED_NEW, $result['candidate']->state);
    }

    public function test_attach_existing_adds_alias_and_resolves_candidate(): void
    {
        $hash = hash('sha256', '{}');
        $candidate = new DictionaryCandidate('candidate-1', 'côn máng', $hash, ['Côn máng'], DictionaryCandidateState::NEEDS_REVIEW, [], [], 2, 'a', 'b', 1);
        [$candidateRepo, $conceptRepo] = $this->repositories($candidate, new DictionaryConcept('concept-1', 'Côn lòng máng', 'Khái niệm đã duyệt.', DictionaryConcept::APPROVED));
        $service = new DictionaryCurationService($candidateRepo, $conceptRepo);

        $result = $service->attachToExisting('candidate-1', 1, 'concept-1', DictionaryLabel::COLLOQUIAL, 'vi-VN');

        self::assertSame(DictionaryCandidateState::RESOLVED_EXISTING, $result['candidate']->state);
        self::assertSame('Côn máng', $result['label']->label);
        self::assertSame(DictionaryLabel::COLLOQUIAL, $result['label']->kind);
    }

    public function test_approved_concept_can_pin_existing_ready_media_as_dictionary_preferred_illustration(): void
    {
        $concept = new DictionaryConcept('concept-cuckoo-clock', 'Đồng hồ chim cúc cu', 'Đồng hồ cơ có cơ cấu phát âm thanh theo chu kỳ.', DictionaryConcept::APPROVED, null, null, null, ['public_slug' => 'dong-ho-chim-cuc-cu'], 3);
        $service = $this->serviceForConcept($concept);

        $usage = $service->selectPreferredIllustration($concept->conceptId, $concept->revision, '01a0ab0c-fde0-7c01-a89d-fc5eef832c89', 'Đồng hồ chim cúc cu', 'Ảnh đồng hồ chim cúc cu', 'Minh họa cho mục từ.');

        self::assertInstanceOf(MediaUsage::class, $usage);
        self::assertSame('dictionary_concept', $usage->endpointType);
        self::assertSame($concept->conceptId, $usage->endpointKey);
        self::assertSame('representative', $usage->role);
        self::assertSame('preferred_illustration', $usage->placementKey);
        self::assertSame('USER_EXPLICIT', $usage->selectionSource);
        self::assertSame('PINNED', $usage->selectionPolicy);
    }

    public function test_replacing_dictionary_illustration_preserves_article_usages_and_replay_is_idempotent(): void
    {
        $concept = new DictionaryConcept('concept-cuckoo-clock', 'Đồng hồ chim cúc cu', 'Định nghĩa lexical.', DictionaryConcept::APPROVED, null, null, null, ['public_slug' => 'dong-ho-chim-cuc-cu'], 4);
        $fixture = $this->mediaFixtureForBoundary($concept);
        $service = $fixture->service;
        $mediaA = '01a0ab0c-fde0-7c01-a89d-fc5eef832c89';
        $mediaB = '01a0ab0c-fde0-7c01-a89d-fc5eef832c90';

        $beforeUsageIds = array_map(static fn (MediaUsage $usage): string => $usage->usageId, $fixture->usages->items);
        $beforeArticleModelUsages = $this->usageSnapshots($fixture->usages->items);
        $beforeMediaIds = array_map(static fn (Media $media): string => $media->canonicalId, $fixture->media->items);
        $beforeAssetIds = array_map(static fn (MediaAsset $asset): string => $asset->assetId, $fixture->assets->items);

        $first = $service->selectPreferredIllustration($concept->conceptId, $concept->revision, $mediaA, 'Côn 111', 'Côn 111 nhìn toàn cảnh', 'Ảnh A');
        $replacement = $service->selectPreferredIllustration($concept->conceptId, $concept->revision, $mediaB, 'Côn 111', 'Côn 111 nhìn toàn cảnh', 'Ảnh B');
        $replay = $service->selectPreferredIllustration($concept->conceptId, $concept->revision, $mediaB, 'Côn 111', 'Côn 111 nhìn toàn cảnh', 'Ảnh B');

        self::assertInstanceOf(MediaUsage::class, $first);
        self::assertInstanceOf(MediaUsage::class, $replacement);
        self::assertInstanceOf(MediaUsage::class, $replay);
        self::assertSame($mediaB, $replacement->mediaId);
        self::assertSame($replacement->usageId, $replay->usageId);
        self::assertSame($replacement->mediaId, $replay->mediaId);
        self::assertSame('preferred_illustration', $replacement->placementKey);
        self::assertSame('USER_EXPLICIT', $replacement->selectionSource);
        self::assertSame('PINNED', $replacement->selectionPolicy);
        self::assertNotSame($mediaB, $mediaA, 'Replacement must not rewrite old Article/Model usages on Media A.');
        self::assertSame($beforeArticleModelUsages, $this->usageSnapshots($fixture->usages->items));
        $afterUsageIds = array_map(static fn (MediaUsage $usage): string => $usage->usageId, $fixture->usages->items);
        self::assertCount(count($beforeUsageIds) + 1, $afterUsageIds);
        self::assertSame($beforeUsageIds, array_values(array_intersect($beforeUsageIds, $afterUsageIds)));
        self::assertSame($beforeMediaIds, array_map(static fn (Media $media): string => $media->canonicalId, $fixture->media->items));
        self::assertSame($beforeAssetIds, array_map(static fn (MediaAsset $asset): string => $asset->assetId, $fixture->assets->items));
    }

    /** @dataProvider blockedIllustrationInputs */
    public function test_unapproved_or_ineligible_dictionary_illustration_is_typed_blocked_result(string $status, string $mediaId, array $context, string $expectedReason, string $candidateState): void
    {
        $concept = new DictionaryConcept('concept-cuckoo-clock', 'Đồng hồ chim cúc cu', 'Định nghĩa lexical.', $status, null, null, null, array_merge(['public_slug' => 'dong-ho-chim-cuc-cu'], $context), 1);
        $fixture = $this->mediaFixtureForBoundary($concept, $candidateState);
        $service = $fixture->service;

        $result = $service->selectPreferredIllustration($concept->conceptId, $concept->revision, $mediaId);

        self::assertIsArray($result);
        self::assertContains($result['status'] ?? null, ['BLOCKED', 'REVIEW_REQUIRED']);
        self::assertSame($expectedReason, $result['reason'] ?? null);
        self::assertArrayNotHasKey('usage', $result);
    }

    public static function blockedIllustrationInputs(): array
    {
        return [
            'draft concept' => [DictionaryConcept::DRAFT, '01a0ab0c-fde0-7c01-a89d-fc5eef832c89', [], 'DICTIONARY_CONCEPT_NOT_APPROVED', DictionaryCandidateState::NEEDS_REVIEW],
            'retired concept' => [DictionaryConcept::RETIRED, '01a0ab0c-fde0-7c01-a89d-fc5eef832c89', [], 'DICTIONARY_CONCEPT_NOT_APPROVED', DictionaryCandidateState::NEEDS_REVIEW],
            'rejected candidate' => [DictionaryConcept::APPROVED, '01a0ab0c-fde0-7c01-a89d-fc5eef832c89', [], 'DICTIONARY_CANDIDATE_REJECTED', DictionaryCandidateState::REJECTED],
            'ambiguous concept' => [DictionaryConcept::APPROVED, '01a0ab0c-fde0-7c01-a89d-fc5eef832c89', ['illustration_scope' => ['ambiguous' => true]], 'MEDIA_SCOPE_AMBIGUOUS', DictionaryCandidateState::NEEDS_REVIEW],
            'non-ready media' => [DictionaryConcept::APPROVED, '01a0ab0c-fde0-7c01-a89d-fc5eef832c91', [], 'MEDIA_NOT_READY', DictionaryCandidateState::NEEDS_REVIEW],
            'private media asset' => [DictionaryConcept::APPROVED, '01a0ab0c-fde0-7c01-a89d-fc5eef832c92', [], 'MEDIA_PUBLIC_ASSET_REQUIRED', DictionaryCandidateState::NEEDS_REVIEW],
            'placeholder media' => [DictionaryConcept::APPROVED, '01a0ab0c-fde0-7c01-a89d-fc5eef832c93', [], 'MEDIA_PLACEHOLDER_NOT_ALLOWED', DictionaryCandidateState::NEEDS_REVIEW],
        ];
    }

    public function test_dictionary_context_does_not_copy_into_global_media_or_attachment_and_cuon_111_stays_outside_evidence_graph(): void
    {
        $concept = new DictionaryConcept('concept-cuon-111', 'Côn 111', 'Tên gọi lexical.', DictionaryConcept::APPROVED, null, null, null, ['public_slug' => 'con-111'], 2);
        $fixture = $this->mediaFixtureForBoundary($concept);
        $before = $fixture->stateSnapshot();

        $fixture->service->selectPreferredIllustration($concept->conceptId, $concept->revision, '01a0ab0c-fde0-7c01-a89d-fc5eef832c89', 'Côn 111', 'Côn 111 nhìn toàn cảnh', 'Minh họa riêng cho mục từ.');

        self::assertSame($before['media_ids'], $fixture->stateSnapshot()['media_ids']);
        self::assertSame($before['asset_ids'], $fixture->stateSnapshot()['asset_ids']);
        self::assertSame($before['attachment_metadata'], $fixture->attachmentMetadata);
        self::assertSame($before['binary_copies'], $fixture->binaryCopies);
        self::assertSame([], $fixture->evidenceRows);
        self::assertSame([], $fixture->graphRows);
    }

    private function serviceForConcept(DictionaryConcept $concept): DictionaryCurationService
    {
        return $this->mediaFixtureForBoundary($concept)->service;
    }

    public function mediaFixtureForBoundary(DictionaryConcept $concept, string $candidateState = DictionaryCandidateState::NEEDS_REVIEW): DictionaryIllustrationFixture
    {
        $candidate = new DictionaryCandidate('candidate-illustration', 'côn 111', hash('sha256', '{}'), ['Côn 111'], $candidateState, [], [], 1, 'a', 'b', 1);
        [$candidateRepo, $conceptRepo] = $this->repositories($candidate, $concept);
        $media = new DictionaryIllustrationMediaRepository([
            new Media('01a0ab0c-fde0-7c01-a89d-fc5eef832c89', 'dictionary-media-a', 'Media A neutral', 'ready'),
            new Media('01a0ab0c-fde0-7c01-a89d-fc5eef832c90', 'dictionary-media-b', 'Media B neutral', 'ready'),
            new Media('01a0ab0c-fde0-7c01-a89d-fc5eef832c91', 'dictionary-media-draft', 'Media draft', 'draft'),
            new Media('01a0ab0c-fde0-7c01-a89d-fc5eef832c92', 'dictionary-media-private', 'Media private', 'ready'),
            new Media('01a0ab0c-fde0-7c01-a89d-fc5eef832c93', 'dictionary-media-placeholder', 'Media placeholder', 'ready', ['system_role' => 'placeholder']),
        ]);
        $assets = new DictionaryIllustrationAssetRepository([
            $this->asset('01a0ab0c-fde0-7c01-a89d-fc5eef832c89', 'dictionary-a.webp', 'PUBLIC'),
            $this->asset('01a0ab0c-fde0-7c01-a89d-fc5eef832c90', 'dictionary-b.webp', 'PUBLIC'),
            $this->asset('01a0ab0c-fde0-7c01-a89d-fc5eef832c91', 'dictionary-draft.webp', 'PUBLIC'),
            $this->asset('01a0ab0c-fde0-7c01-a89d-fc5eef832c92', 'dictionary-private.webp', 'PRIVATE'),
            $this->asset('01a0ab0c-fde0-7c01-a89d-fc5eef832c93', 'dictionary-placeholder.webp', 'PUBLIC'),
        ]);
        $usages = new DictionaryIllustrationUsageRepository([
            $this->usage('01a0ab0c-fde0-7c01-a89d-fc5eef832c89', 'wp_post', 'post:article-1', 'technical_detail', 'article-detail', 'Article Usage'),
            $this->usage('01a0ab0c-fde0-7c01-a89d-fc5eef832c89', 'model', 'model-1', 'representative', 'model-representative', 'Model Usage'),
        ]);
        $mediaService = new MediaService($media, $assets, $usages);
        $service = new DictionaryCurationService($candidateRepo, $conceptRepo, null, null, $mediaService, $media, $assets, $usages);
        return new DictionaryIllustrationFixture($service, $media, $assets, $usages);
    }

    private function asset(string $mediaId, string $storageKey, string $visibility): MediaAsset
    {
        return new MediaAsset(UuidCodec::newV7(), $mediaId, 'derivative', $storageKey, hash('sha256', $storageKey), 'image/webp', 1200, 1200, 800, $visibility, ['canonical_filename' => $storageKey]);
    }

    private function usage(string $mediaId, string $endpointType, string $endpointKey, string $role, string $placement, string $title): MediaUsage
    {
        return new MediaUsage(UuidCodec::newV7(), $mediaId, $endpointType, $endpointKey, $role, 0, '', '', [], $title, 1, $placement);
    }

    private function repositories(DictionaryCandidate $candidate, ?DictionaryConcept $existingConcept = null): array
    {
        $candidateRepo = new class($candidate) implements DictionaryCandidateRepository {
            public function __construct(private DictionaryCandidate $candidate) {}
            public function upsertObservation(DictionaryCandidate $candidate): DictionaryCandidate { return $this->candidate; }
            public function suppressed(string $normalizedTerm, string $contextHash): bool { return $this->candidate->suppressed(); }
            public function listForReview(int $limit = 100): array { return [$this->candidate]; }
            public function findById(string $candidateId): ?DictionaryCandidate { return $candidateId === $this->candidate->candidateId ? $this->candidate : null; }
            public function saveDecision(DictionaryCandidate $candidate, int $expectedRevision): DictionaryCandidate { if ($this->candidate->revision !== $expectedRevision) throw new \RuntimeException('conflict'); return $this->candidate = $candidate; }
        };
        $conceptRepo = new class($existingConcept) implements DictionaryConceptRepository {
            public array $labels = [];
            public function __construct(private ?DictionaryConcept $concept) {}
            public function findById(string $conceptId): ?DictionaryConcept { return $this->concept?->conceptId === $conceptId ? $this->concept : null; }
            public function findApprovedByNormalizedLabel(string $normalizedLabel, array $context = []): array { return []; }
            public function listApproved(int $limit = 500): array { return $this->concept?->approved() ? [$this->concept] : []; }
            public function listLabels(string $conceptId, bool $includeInactive = false): array { return $this->labels; }
            public function createConcept(DictionaryConcept $concept): DictionaryConcept { return $this->concept = $concept; }
            public function updateConcept(DictionaryConcept $concept, int $expectedRevision): DictionaryConcept { return $this->concept = $concept; }
            public function addLabel(DictionaryLabel $label): DictionaryLabel { $this->labels[] = $label; return $label; }
        };
        return [$candidateRepo, $conceptRepo];
    }

    /** @param list<MediaUsage> $usages @return array<string,array<string,mixed>> */
    private function usageSnapshots(array $usages): array
    {
        $snapshots = [];
        foreach ($usages as $usage) {
            if (!$usage instanceof MediaUsage || !in_array($usage->endpointType, ['wp_post', 'model'], true)) continue;
            $snapshots[$usage->usageId] = [
                'usageId' => $usage->usageId,
                'mediaId' => $usage->mediaId,
                'endpointType' => $usage->endpointType,
                'endpointKey' => $usage->endpointKey,
                'role' => $usage->role,
                'sortOrder' => $usage->sortOrder,
                'altText' => $usage->altText,
                'caption' => $usage->caption,
                'keywordGroups' => $usage->keywordGroups,
                'title' => $usage->title,
                'revision' => $usage->revision,
                'placementKey' => $usage->placementKey,
                'selectionSource' => $usage->selectionSource,
                'selectionPolicy' => $usage->selectionPolicy,
                'activeSlot' => $usage->activeSlot,
            ];
        }
        ksort($snapshots);
        return $snapshots;
    }
}

final class DictionaryIllustrationFixture
{
    public function __construct(
        public DictionaryCurationService $service,
        public DictionaryIllustrationMediaRepository $media,
        public DictionaryIllustrationAssetRepository $assets,
        public DictionaryIllustrationUsageRepository $usages,
        public array $attachmentMetadata = [],
        public array $binaryCopies = [],
        public array $evidenceRows = [],
        public array $graphRows = [],
    ) {}

    /** @return array{media_ids:list<string>,asset_ids:list<string>,attachment_metadata:array,binary_copies:array} */
    public function stateSnapshot(): array
    {
        return [
            'media_ids' => array_map(static fn (Media $item): string => $item->canonicalId, $this->media->items),
            'asset_ids' => array_map(static fn (MediaAsset $item): string => $item->assetId, $this->assets->items),
            'attachment_metadata' => $this->attachmentMetadata,
            'binary_copies' => $this->binaryCopies,
        ];
    }
}

final class DictionaryIllustrationMediaRepository implements MediaRepository
{
    /** @param list<Media> $items */
    public function __construct(public array $items) {}
    public function findByCanonicalId(string $id): ?Media { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
    public function findByStableKey(string $key): ?Media { foreach ($this->items as $item) if ($item->stableKey === $key) return $item; return null; }
    public function create(Media $media): Media { $this->items[] = $media; return $media; }
    public function update(Media $media, int $expectedRevision): Media { foreach ($this->items as $index => $item) if ($item->canonicalId === $media->canonicalId) return $this->items[$index] = $media; return $media; }
    public function list(bool $includeRetired = false): array { return $this->items; }
}

final class DictionaryIllustrationAssetRepository implements MediaAssetRepository
{
    /** @param list<MediaAsset> $items */
    public function __construct(public array $items) {}
    public function findByAssetId(string $id): ?MediaAsset { foreach ($this->items as $item) if ($item->assetId === $id) return $item; return null; }
    public function create(MediaAsset $asset): MediaAsset { $this->items[] = $asset; return $asset; }
    public function update(MediaAsset $asset, int $expectedRevision = 1): MediaAsset { foreach ($this->items as $index => $item) if ($item->assetId === $asset->assetId) return $this->items[$index] = $asset; return $asset; }
    public function listByMediaId(string $id): array { return array_values(array_filter($this->items, static fn (MediaAsset $item): bool => $item->mediaId === $id)); }
    public function findByChecksum(string $checksum): array { return array_values(array_filter($this->items, static fn (MediaAsset $item): bool => $item->checksum === $checksum)); }
}

final class DictionaryIllustrationUsageRepository implements MediaUsageRepository, MediaUsageUpdater
{
    /** @param list<MediaUsage> $items */
    public function __construct(public array $items = []) {}
    public function create(MediaUsage $usage): MediaUsage
    {
        foreach ($this->items as $item) {
            if ($item->endpointType === $usage->endpointType && $item->endpointKey === $usage->endpointKey && $item->role === $usage->role && $item->placementKey === $usage->placementKey) {
                throw new MediaException('MEDIA_USAGE_IDENTITY_CONFLICT');
            }
        }
        $this->items[] = $usage;
        return $usage;
    }
    public function update(MediaUsage $usage): MediaUsage
    {
        foreach ($this->items as $index => $item) {
            if ($item->usageId !== $usage->usageId) continue;
            $updated = new MediaUsage($usage->usageId, $usage->mediaId, $usage->endpointType, $usage->endpointKey, $usage->role, $usage->sortOrder, $usage->altText, $usage->caption, $usage->keywordGroups, $usage->title, $usage->revision + 1, $usage->placementKey, $usage->selectionSource, $usage->selectionPolicy, $usage->activeSlot);
            return $this->items[$index] = $updated;
        }
        throw new MediaException('MEDIA_USAGE_NOT_FOUND');
    }
    public function listByMediaId(string $id, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $item): bool => $item->mediaId === $id && ($role === null || $item->role === $role))); }
    public function listByEndpoint(string $type, string $key, ?string $role = null): array { return array_values(array_filter($this->items, static fn (MediaUsage $item): bool => $item->endpointType === $type && $item->endpointKey === $key && ($role === null || $item->role === $role))); }
}
