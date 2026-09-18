<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

use NHK\Core\Application\Media\{MediaService, PublicMediaAssetSelector};
use NHK\Core\Contracts\Dictionary\{DictionaryCandidateRepository, DictionaryConceptRepository};
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository, MediaUsageUpdater};
use NHK\Core\Domain\Dictionary\{DictionaryCandidate, DictionaryCandidateState, DictionaryConcept, DictionaryLabel};
use NHK\Core\Domain\Media\{Media, MediaUsage, MediaUsageRoleRegistry};
use NHK\Core\Shared\Uuid\UuidCodec;

final class DictionaryCurationService
{
    private DictionaryTermNormalizer $normalizer;

    public function __construct(
        private DictionaryCandidateRepository $candidates,
        private DictionaryConceptRepository $concepts,
        private $idGenerator = null,
        ?DictionaryTermNormalizer $normalizer = null,
        private ?MediaService $mediaService = null,
        private ?MediaRepository $media = null,
        private ?MediaAssetRepository $assets = null,
        private ?MediaUsageRepository $usages = null,
    )
    {
        $this->normalizer = $normalizer ?? new DictionaryTermNormalizer();
    }

    /**
     * Pin an existing canonical public Media as the concept illustration.
     * This is a usage reconciliation only: Media, assets and attachments are
     * never created or copied by dictionary curation.
     *
     * @return MediaUsage|array<string,mixed>
     */
    public function selectPreferredIllustration(string $conceptId, int $expectedRevision, string $mediaId, string $title = '', string $altText = '', string $caption = ''): MediaUsage|array
    {
        $concept = $this->concepts->findById($conceptId);
        if (!$concept instanceof DictionaryConcept) return $this->blocked('BLOCKED', 'DICTIONARY_CONCEPT_NOT_FOUND');
        if ($concept->revision !== $expectedRevision) return $this->blocked('BLOCKED', 'DICTIONARY_CONCEPT_REVISION_CONFLICT');
        if (!$concept->approved()) return $this->blocked('BLOCKED', 'DICTIONARY_CONCEPT_NOT_APPROVED');

        $candidate = $this->candidates->listForReview(100);
        foreach ($candidate as $item) {
            if (!$item instanceof DictionaryCandidate) continue;
            if ($item->state === DictionaryCandidateState::REJECTED) return $this->blocked('BLOCKED', 'DICTIONARY_CANDIDATE_REJECTED');
        }
        if (($concept->context['illustration_scope']['ambiguous'] ?? false) === true) return $this->blocked('REVIEW_REQUIRED', 'MEDIA_SCOPE_AMBIGUOUS');
        if (!$this->mediaService instanceof MediaService || !$this->media instanceof MediaRepository || !$this->assets instanceof MediaAssetRepository || !$this->usages instanceof MediaUsageRepository) {
            return $this->blocked('REVIEW_REQUIRED', 'MEDIA_REUSE_BOUNDARY_UNAVAILABLE');
        }

        $media = $this->media->findByCanonicalId($mediaId);
        if (!$media instanceof Media) return $this->blocked('BLOCKED', 'MEDIA_NOT_FOUND');
        if (!$media->active || $media->readiness !== 'ready') return $this->blocked('REVIEW_REQUIRED', 'MEDIA_NOT_READY');
        if ($media->isSystemPlaceholder()) return $this->blocked('REVIEW_REQUIRED', 'MEDIA_PLACEHOLDER_NOT_ALLOWED');
        if (!(new PublicMediaAssetSelector())->canonical($this->assets->listByMediaId($mediaId)) instanceof \NHK\Core\Domain\Media\MediaAsset) return $this->blocked('REVIEW_REQUIRED', 'MEDIA_PUBLIC_ASSET_REQUIRED');

        $endpointType = 'dictionary_concept';
        $placement = 'preferred_illustration';
        $role = MediaUsageRoleRegistry::REPRESENTATIVE;
        $existing = array_values(array_filter($this->usages->listByEndpoint($endpointType, $conceptId, $role), static fn (mixed $usage): bool => $usage instanceof MediaUsage && $usage->placementKey === $placement));
        if (count($existing) > 1) return $this->blocked('REVIEW_REQUIRED', 'DICTIONARY_ILLUSTRATION_CONFLICT');

        $existingUsage = $existing[0] ?? null;
        $selectionSource = 'USER_EXPLICIT';
        $selectionPolicy = 'PINNED';
        $activeSlot = $placement;
        if ($existingUsage instanceof MediaUsage) {
            if (!$this->usages instanceof MediaUsageUpdater) return $this->blocked('REVIEW_REQUIRED', 'MEDIA_USAGE_UPDATE_UNAVAILABLE');
            $candidateUsage = new MediaUsage($existingUsage->usageId, $mediaId, $endpointType, $conceptId, $role, 0, trim($altText), trim($caption), [], trim($title), $existingUsage->revision, $placement, $selectionSource, $selectionPolicy, $activeSlot);
            if ($this->sameUsage($existingUsage, $candidateUsage)) return $existingUsage;
            return $this->usages->update($candidateUsage);
        }

        return $this->mediaService->addUsage($mediaId, $endpointType, $conceptId, $role, 0, trim($altText), trim($caption), [], trim($title), $placement, $selectionSource, $selectionPolicy, $activeSlot);
    }

    public function createDraftFromCandidate(string $candidateId, int $expectedRevision, string $preferredLabel, string $definition, array $context = []): array
    {
        $candidate = $this->requireCandidate($candidateId, $expectedRevision);
        if ($candidate->suppressed()) throw new \RuntimeException('DICTIONARY_CANDIDATE_SUPPRESSED');
        $preferredLabel = trim($preferredLabel);
        if ($preferredLabel === '') throw new \InvalidArgumentException('DICTIONARY_PREFERRED_LABEL_REQUIRED');
        $concept = new DictionaryConcept($this->id(), $preferredLabel, trim($definition), DictionaryConcept::DRAFT, null, null, null, array_merge($candidate->context, $context));
        $concept = $this->concepts->createConcept($concept);
        $this->concepts->addLabel(new DictionaryLabel($concept->conceptId, $preferredLabel, $this->normalizer->normalize($preferredLabel), DictionaryLabel::PREFERRED, 'vi-VN', $candidate->context));
        foreach ($candidate->rawForms as $raw) {
            $raw = trim((string) $raw);
            if ($raw === '' || $this->normalizer->normalize($raw) === $this->normalizer->normalize($preferredLabel)) continue;
            $this->concepts->addLabel(new DictionaryLabel($concept->conceptId, $raw, $this->normalizer->normalize($raw), DictionaryLabel::ALTERNATE, 'vi-VN', $candidate->context));
        }
        $updated = $this->withState($candidate, DictionaryCandidateState::PROPOSED_NEW, ['concept_id' => $concept->conceptId]);
        return ['concept' => $concept, 'candidate' => $this->candidates->saveDecision($updated, $expectedRevision)];
    }

    public function attachToExisting(string $candidateId, int $expectedRevision, string $conceptId, string $labelKind = DictionaryLabel::ALTERNATE, ?string $locale = 'vi-VN'): array
    {
        $candidate = $this->requireCandidate($candidateId, $expectedRevision);
        $concept = $this->concepts->findById($conceptId);
        if ($concept === null || !$concept->approved()) throw new \RuntimeException('DICTIONARY_APPROVED_CONCEPT_REQUIRED');
        $raw = trim((string) ($candidate->rawForms[0] ?? $candidate->normalizedTerm));
        $label = $this->concepts->addLabel(new DictionaryLabel($concept->conceptId, $raw, $candidate->normalizedTerm, $labelKind, $locale, $candidate->context));
        $updated = $this->withState($candidate, DictionaryCandidateState::RESOLVED_EXISTING, ['concept_id' => $concept->conceptId]);
        return ['concept' => $concept, 'label' => $label, 'candidate' => $this->candidates->saveDecision($updated, $expectedRevision)];
    }

    public function decide(string $candidateId, int $expectedRevision, string $state, array $decision = []): DictionaryCandidate
    {
        if (!in_array($state, [DictionaryCandidateState::AMBIGUOUS, DictionaryCandidateState::REJECTED, DictionaryCandidateState::IGNORED, DictionaryCandidateState::DO_NOT_SUGGEST, DictionaryCandidateState::NEEDS_REVIEW], true)) throw new \InvalidArgumentException('DICTIONARY_DECISION_STATE_NOT_ALLOWED');
        $candidate = $this->requireCandidate($candidateId, $expectedRevision);
        return $this->candidates->saveDecision($this->withState($candidate, $state, $decision), $expectedRevision);
    }

    public function approveConcept(string $conceptId, int $expectedRevision, ?string $destinationType = null, ?string $destinationId = null, ?string $destinationUrl = null, array $context = []): DictionaryConcept
    {
        $current = $this->concepts->findById($conceptId);
        if ($current === null || $current->revision !== $expectedRevision) throw new \RuntimeException('DICTIONARY_CONCEPT_REVISION_CONFLICT');
        $merged = array_merge($current->context, $context);
        if (trim((string) $destinationUrl) === '' && trim((string) ($merged['public_slug'] ?? '')) === '') throw new \RuntimeException('DICTIONARY_PUBLIC_SLUG_OR_OWNER_REQUIRED');
        $approved = new DictionaryConcept($current->conceptId, $current->preferredLabel, $current->definition, DictionaryConcept::APPROVED, $destinationType, $destinationId, $destinationUrl, $merged, $current->revision);
        return $this->concepts->updateConcept($approved, $expectedRevision);
    }

    private function requireCandidate(string $candidateId, int $expectedRevision): DictionaryCandidate
    {
        $candidate = $this->candidates->findById($candidateId);
        if ($candidate === null) throw new \RuntimeException('DICTIONARY_CANDIDATE_NOT_FOUND');
        if ($candidate->revision !== $expectedRevision) throw new \RuntimeException('DICTIONARY_CANDIDATE_REVISION_CONFLICT');
        return $candidate;
    }

    private function withState(DictionaryCandidate $candidate, string $state, array $decision): DictionaryCandidate
    {
        return new DictionaryCandidate($candidate->candidateId, $candidate->normalizedTerm, $candidate->contextHash, $candidate->rawForms, $state, $candidate->context, array_merge($candidate->suggestions, ['review_decision' => $decision]), $candidate->occurrences, $candidate->firstSeenAt, gmdate('Y-m-d H:i:s.u'), $candidate->revision + 1);
    }

    private function id(): string
    {
        if (is_callable($this->idGenerator)) return (string) ($this->idGenerator)();
        return UuidCodec::newV7();
    }

    private function blocked(string $status, string $reason): array
    {
        return ['status' => $status, 'reason' => $reason];
    }

    private function sameUsage(MediaUsage $left, MediaUsage $right): bool
    {
        return $left->mediaId === $right->mediaId
            && $left->endpointType === $right->endpointType
            && $left->endpointKey === $right->endpointKey
            && $left->role === $right->role
            && $left->sortOrder === $right->sortOrder
            && $left->altText === $right->altText
            && $left->caption === $right->caption
            && $left->keywordGroups === $right->keywordGroups
            && $left->title === $right->title
            && $left->placementKey === $right->placementKey
            && $left->selectionSource === $right->selectionSource
            && $left->selectionPolicy === $right->selectionPolicy
            && $left->activeSlot === $right->activeSlot;
    }
}
