<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\{VisualSupportPublicProjection, VisualSupportRequirementService, VisualSupportReverseReconciliationService};
use NHK\Core\Contracts\Media\VisualSupportRequirementRepository;
use NHK\Core\Domain\Media\{Media, MediaAsset, VisualSupportRequirement, VisualSupportRequirementStateRegistry};
use PHPUnit\Framework\TestCase;

final class VisualSupportReverseReconciliationTest extends TestCase
{
    private const SUBJECT = '018f5b74-5f0a-7d2e-9a93-c0e7d6dc3321';
    private const OTHER = '018f5b74-5f0a-7d2e-9a93-c0e7d6dc3322';

    public function test_note_without_media_persists_one_missing_requirement(): void
    {
        $repo = new VisualSupportMemoryRepository();
        $requirement = (new VisualSupportRequirementService($repo))->require(self::SUBJECT, 'variant', 'configuration', 'COMPONENT_DETAIL', 'technical_detail');
        self::assertSame(VisualSupportRequirementStateRegistry::MISSING, $requirement->state);
        self::assertSame($requirement->canonicalId, $repo->findBySemanticFingerprint($requirement->semanticFingerprint)?->canonicalId);
    }

    public function test_exact_subject_scope_facet_feature_and_intent_resolve(): void
    {
        $repo = new VisualSupportMemoryRepository();
        $context = $this->context(self::SUBJECT, 'COMPONENT_DETAIL');
        $requirement = (new VisualSupportRequirementService($repo))->require(self::SUBJECT, 'variant', 'configuration', 'COMPONENT_DETAIL', 'technical_detail');
        $result = $this->reconciler($repo)->reconcile($this->media($context), [$this->asset('PRIVATE')]);
        self::assertSame('resolved', $result['items'][0]['status']);
        self::assertSame('RESOLVED', $repo->findById($requirement->canonicalId)?->state);
    }

    public function test_same_brand_or_wrong_variant_does_not_resolve(): void
    {
        $repo = new VisualSupportMemoryRepository();
        $requirement = (new VisualSupportRequirementService($repo))->require(self::SUBJECT, 'variant', 'configuration', 'COMPONENT_DETAIL', 'technical_detail');
        $result = $this->reconciler($repo)->reconcile($this->media($this->context(self::OTHER, 'COMPONENT_DETAIL')), [$this->asset('PRIVATE')]);
        self::assertSame('no_candidates', $result['status']);
        self::assertSame('MISSING', $repo->findById($requirement->canonicalId)?->state);
    }

    public function test_same_model_without_exact_feature_does_not_resolve(): void
    {
        $repo = new VisualSupportMemoryRepository();
        $requirement = (new VisualSupportRequirementService($repo))->require(self::SUBJECT, 'model', 'configuration', 'DIAL', 'technical_detail');
        $result = $this->reconciler($repo)->reconcile($this->media($this->context(self::SUBJECT, 'HANDS')), [$this->asset('PRIVATE')]);
        self::assertSame('no_candidates', $result['status']);
        self::assertSame('MISSING', $repo->findById($requirement->canonicalId)?->state);
    }

    public function test_later_media_ingest_reverse_reconciles_old_missing_requirement(): void
    {
        $repo = new VisualSupportMemoryRepository();
        $context = $this->context(self::SUBJECT, 'MOVEMENT_LOGO', 'specimen_observation', 'specimen_observation', 'contextual_illustration');
        $requirement = (new VisualSupportRequirementService($repo))->require(self::SUBJECT, 'specimen_observation', 'specimen_observation', 'MOVEMENT_LOGO', 'contextual_illustration');
        $result = $this->reconciler($repo)->reconcile($this->media($context), [$this->asset('PRIVATE')]);
        self::assertSame($requirement->canonicalId, $result['affected'][0]);
        self::assertSame('RESOLVED', $repo->findById($requirement->canonicalId)?->state);
    }

    public function test_one_media_binds_three_consumers_without_media_duplication(): void
    {
        $repo = new VisualSupportMemoryRepository();
        $context = $this->context(self::SUBJECT, 'COMPONENT_DETAIL');
        $context['consumers'] = [['endpoint_type' => 'article', 'endpoint_key' => '101'], ['endpoint_type' => 'video', 'endpoint_key' => '202'], ['endpoint_type' => 'entity', 'endpoint_key' => '303']];
        (new VisualSupportRequirementService($repo))->require(self::SUBJECT, 'variant', 'configuration', 'COMPONENT_DETAIL', 'technical_detail', $context);
        $bound = [];
        $invalidated = [];
        $media = $this->media($context);
        $result = (new VisualSupportReverseReconciliationService($repo, usageBinder: static function (string $mediaId, array $consumer) use (&$bound): void { $bound[] = [$mediaId, $consumer]; }, invalidation: static function (string $requirementId, int $revision) use (&$invalidated): void { $invalidated[] = [$requirementId, $revision]; }))->reconcile($media, [$this->asset('PRIVATE')]);
        self::assertCount(3, $bound);
        self::assertCount(1, array_unique(array_column($bound, 0)));
        self::assertCount(1, $result['affected']);
        self::assertCount(1, $invalidated);
    }

    public function test_replay_is_idempotent_and_does_not_duplicate_usage_or_requirement(): void
    {
        $repo = new VisualSupportMemoryRepository();
        $context = $this->context(self::SUBJECT, 'DIAL');
        $requirement = (new VisualSupportRequirementService($repo))->require(self::SUBJECT, 'variant', 'recognition', 'DIAL', 'technical_detail');
        $reconciler = $this->reconciler($repo);
        $media = $this->media($context);
        $assets = [$this->asset('PRIVATE')];
        $reconciler->reconcile($media, $assets);
        $revision = $repo->findById($requirement->canonicalId)?->revision;
        $reconciler->reconcile($media, $assets);
        self::assertSame($revision, $repo->findById($requirement->canonicalId)?->revision);
        self::assertCount(1, $repo->all());
    }

    public function test_better_candidate_replaces_binding_and_retains_old_provenance(): void
    {
        $repo = new VisualSupportMemoryRepository();
        $context = $this->context(self::SUBJECT, 'DIAL_LOGO', 'variant', 'recognition');
        $requirement = (new VisualSupportRequirementService($repo))->require(self::SUBJECT, 'variant', 'recognition', 'DIAL_LOGO', 'technical_detail');
        $first = $this->media($context, 'draft', '018f5b74-5f0a-7d2e-9a93-c0e7d6dc3331');
        $reconciler = $this->reconciler($repo);
        $reconciler->reconcile($first, [$this->asset('PRIVATE', $first->canonicalId)]);
        $second = $this->media($context, 'ready', '018f5b74-5f0a-7d2e-9a93-c0e7d6dc3332');
        $reconciler->reconcile($second, [$this->asset('PRIVATE', $second->canonicalId)]);
        $saved = $repo->findById($requirement->canonicalId);
        self::assertSame($second->canonicalId, $saved?->mediaId);
        self::assertSame($first->canonicalId, $saved?->provenance['binding_history'][0]['media_id']);
    }

    public function test_private_media_can_resolve_internal_but_public_projection_is_empty(): void
    {
        $repo = new VisualSupportMemoryRepository();
        $context = $this->context(self::SUBJECT, 'CASE_INTERIOR', 'specimen_observation', 'specimen_observation');
        $requirement = (new VisualSupportRequirementService($repo))->require(self::SUBJECT, 'specimen_observation', 'specimen_observation', 'CASE_INTERIOR', 'technical_detail');
        $media = $this->media($context);
        $this->reconciler($repo)->reconcile($media, [$this->asset('PRIVATE')]);
        self::assertSame('RESOLVED', $repo->findById($requirement->canonicalId)?->state);
        self::assertNull((new VisualSupportPublicProjection())->resolve($repo->findById($requirement->canonicalId), $media, [$this->asset('PRIVATE')]));
    }

    public function test_visual_support_does_not_create_claim_evidence_graph_or_broaden_specimen(): void
    {
        $repo = new VisualSupportMemoryRepository();
        $context = $this->context(self::SUBJECT, 'STAMP', 'specimen_observation', 'specimen_observation', 'evidence_like_illustration');
        $requirement = (new VisualSupportRequirementService($repo))->require(self::SUBJECT, 'specimen_observation', 'specimen_observation', 'STAMP', 'evidence_like_illustration');
        $this->reconciler($repo)->reconcile($this->media($context), [$this->asset('PRIVATE')]);
        $saved = $repo->findById($requirement->canonicalId);
        self::assertArrayNotHasKey('claim_id', $saved?->provenance ?? []);
        self::assertArrayNotHasKey('evidence_id', $saved?->provenance ?? []);
        self::assertArrayNotHasKey('graph_edge_id', $saved?->provenance ?? []);
        self::assertSame('specimen_observation', $saved?->scope);
    }

    public function test_missing_visual_is_an_honest_domain_state_not_infrastructure_failure(): void
    {
        $repo = new VisualSupportMemoryRepository();
        $requirement = (new VisualSupportRequirementService($repo))->require(self::SUBJECT, 'variant', 'movement', 'ROD_BANK', 'technical_detail');
        self::assertSame('MISSING', $requirement->state);
        self::assertSame('VISUAL_NOT_FOUND', $requirement->unresolvedReason);
    }

    /** @return array<string,mixed> */
    private function context(string $subjectId, string $feature, string $scope = 'variant', string $facet = 'configuration', string $intent = 'technical_detail'): array
    {
        return ['subject_type' => 'entity', 'subject_id' => $subjectId, 'scope' => $scope, 'facet' => $facet, 'feature_key' => $feature, 'visual_intent' => $intent];
    }

    private function media(array $context, string $readiness = 'ready', ?string $id = null): Media
    {
        return new Media($id ?? '018f5b74-5f0a-7d2e-9a93-c0e7d6dc3341', 'capture.media.' . strtolower($context['feature_key']), 'Captured visual', $readiness, ['visual_support_contexts' => [$context]], true, 1);
    }

    private function asset(string $visibility, ?string $mediaId = null): MediaAsset
    {
        return new MediaAsset('018f5b74-5f0a-7d2e-9a93-c0e7d6dc3351', $mediaId ?? '018f5b74-5f0a-7d2e-9a93-c0e7d6dc3341', 'original', 'capture/visual.jpg', str_repeat('a', 64), 'image/jpeg', 1000, 800, 600, $visibility, []);
    }

    private function reconciler(VisualSupportMemoryRepository $repo): VisualSupportReverseReconciliationService
    {
        return new VisualSupportReverseReconciliationService($repo);
    }
}

final class VisualSupportMemoryRepository implements VisualSupportRequirementRepository
{
    /** @var array<string,VisualSupportRequirement> */
    private array $rows = [];
    public function save(VisualSupportRequirement $requirement, int $expectedRevision = 0): VisualSupportRequirement
    {
        if (isset($this->rows[$requirement->canonicalId]) && $expectedRevision !== $this->rows[$requirement->canonicalId]->revision) throw new \RuntimeException('VISUAL_REQUIREMENT_REVISION_CONFLICT');
        $this->rows[$requirement->canonicalId] = $requirement;
        return $requirement;
    }
    public function findById(string $id): ?VisualSupportRequirement { return $this->rows[$id] ?? null; }
    public function findBySemanticFingerprint(string $fingerprint): ?VisualSupportRequirement { foreach ($this->rows as $row) if ($row->semanticFingerprint === $fingerprint) return $row; return null; }
    public function findByIdempotencyFingerprint(string $fingerprint): ?VisualSupportRequirement { foreach ($this->rows as $row) if ($row->idempotencyFingerprint === $fingerprint) return $row; return null; }
    public function findCandidatesForMedia(array $context, int $limit = 100): array { return $this->matching($context, [VisualSupportRequirementStateRegistry::MISSING, VisualSupportRequirementStateRegistry::REVIEW_REQUIRED], $limit); }
    public function findReplacementCandidatesForMedia(array $context, int $limit = 100): array { return $this->matching($context, [VisualSupportRequirementStateRegistry::RESOLVED], $limit); }
    public function listForAdmin(array $filters = [], int $limit = 100): array { return array_slice(array_values($this->rows), 0, min(100, max(1, $limit))); }
    /** @return list<VisualSupportRequirement> */
    public function all(): array { return array_values($this->rows); }
    private function matching(array $context, array $states, int $limit): array
    {
        $rows = [];
        foreach ($this->rows as $row) {
            if (!in_array($row->state, $states, true)) continue;
            foreach (['subject_type', 'subject_id', 'scope', 'facet', 'feature_key', 'visual_intent'] as $key) {
                $property = match ($key) { 'subject_type' => $row->subjectType, 'subject_id' => $row->subjectId, 'scope' => $row->scope, 'facet' => $row->facet, 'feature_key' => $row->featureKey, 'visual_intent' => $row->visualIntent };
                if ((string) $context[$key] !== (string) $property) continue 2;
            }
            $rows[] = $row;
        }
        return array_slice($rows, 0, min(100, max(1, $limit)));
    }
}
