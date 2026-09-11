<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Contracts\Media\VisualSupportRequirementRepository;
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaUsageRoleRegistry, VisualSupportIntentRegistry};

final class VisualSupportReverseReconciliationService
{
    /** @param callable|null $usageBinder function(string $mediaId, array<string,mixed> $consumer): mixed @param callable|null $invalidation function(string $requirementId, int $revision): mixed */
    public function __construct(private VisualSupportRequirementRepository $requirements, private VisualSupportMediaSuitability $suitability = new VisualSupportMediaSuitability(), private $usageBinder = null, private $invalidation = null) {}

    /** @param list<MediaAsset> $assets @param array<string,mixed>|null $contexts @return array{status:string,items:list<array<string,mixed>>,affected:list<string>} */
    public function reconcile(Media $media, array $assets = [], ?array $contexts = null, int $limit = 100): array
    {
        $contexts ??= is_array($media->provenance['visual_support_contexts'] ?? null) ? $media->provenance['visual_support_contexts'] : [];
        $items = [];
        $affected = [];
        foreach ($contexts as $context) {
            if (!is_array($context)) continue;
            $candidates = array_merge($this->requirements->findCandidatesForMedia($context, $limit), $this->requirements->findReplacementCandidatesForMedia($context, $limit));
            foreach ($candidates as $requirement) {
                $fit = $this->suitability->evaluate($media, $assets, $context);
                if (!$fit['matched']) {
                    $items[] = ['requirement_id' => $requirement->canonicalId, 'status' => 'rejected', 'reason' => $fit['reason']];
                    continue;
                }
                $oldScore = (int) ($requirement->provenance['suitability_score'] ?? 0);
                if ($requirement->mediaId === $media->canonicalId || ($requirement->state === 'RESOLVED' && $oldScore >= $fit['score'])) continue;
                $history = $requirement->provenance['binding_history'] ?? [];
                if (!is_array($history)) $history = [];
                if ($requirement->mediaId !== null && $requirement->mediaId !== $media->canonicalId) $history[] = ['media_id' => $requirement->mediaId, 'media_revision' => $requirement->mediaRevision, 'replaced_at' => gmdate('c')];
                $provenance = ['matched' => $fit['reason'], 'suitability_score' => $fit['score'], 'reconciled_by' => 'canonical_media_ingest', 'binding_history' => $history];
                $resolved = $requirement->withResolution($media->canonicalId, $media->revision, $provenance);
                $saved = $this->requirements->save($resolved, $requirement->revision);
                $this->bindConsumers($saved, $media);
                if (is_callable($this->invalidation)) ($this->invalidation)($saved->canonicalId, $saved->revision);
                $affected[] = $saved->canonicalId;
                $items[] = ['requirement_id' => $saved->canonicalId, 'status' => 'resolved', 'media_id' => $saved->mediaId, 'revision' => $saved->revision, 'score' => $fit['score']];
            }
        }
        return ['status' => $items === [] ? 'no_candidates' : 'reconciled', 'items' => $items, 'affected' => array_values(array_unique($affected))];
    }

    private function bindConsumers(object $requirement, Media $media): void
    {
        if (!is_callable($this->usageBinder)) return;
        $consumers = $requirement->context['consumers'] ?? [];
        if (isset($requirement->context['consumer']) && is_array($requirement->context['consumer'])) $consumers[] = $requirement->context['consumer'];
        foreach ($consumers as $consumer) {
            if (!is_array($consumer) || trim((string) ($consumer['endpoint_type'] ?? $consumer['type'] ?? '')) === '' || trim((string) ($consumer['endpoint_key'] ?? $consumer['key'] ?? '')) === '') continue;
            $role = $this->usageRole($requirement->visualIntent);
            ($this->usageBinder)($media->canonicalId, ['endpoint_type' => (string) ($consumer['endpoint_type'] ?? $consumer['type']), 'endpoint_key' => (string) ($consumer['endpoint_key'] ?? $consumer['key']), 'role' => $role, 'requirement_id' => $requirement->canonicalId]);
        }
    }

    private function usageRole(string $intent): string
    {
        return match ($intent) {
            VisualSupportIntentRegistry::REPRESENTATIVE => MediaUsageRoleRegistry::REPRESENTATIVE,
            VisualSupportIntentRegistry::TECHNICAL_DETAIL, VisualSupportIntentRegistry::EVIDENCE_LIKE_ILLUSTRATION, VisualSupportIntentRegistry::CONTEXTUAL_ILLUSTRATION => MediaUsageRoleRegistry::TECHNICAL_DETAIL,
            default => MediaUsageRoleRegistry::TECHNICAL_DETAIL,
        };
    }
}
