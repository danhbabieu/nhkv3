<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Application\Consumption\{OwnerCapability, OwnerConsumptionPlan};
use NHK\Core\Application\Semantic\EnrichmentPack;

/** Maps the shared transient pack to Media presentation surfaces only. */
final class MediaConsumptionPlanner
{
    public function plan(EnrichmentPack $pack, array $mediaContext = []): OwnerConsumptionPlan
    {
        $content = $pack->toArray()['content'] ?? [];
        $claims = array_values(array_filter((array) ($content['selected_claims'] ?? []), 'is_array'));
        $observations = array_values(array_filter((array) ($mediaContext['observations'] ?? []), 'is_array'));
        $accepted = array_values(array_filter($claims, fn (array $claim): bool => $this->accepted($claim)));
        $visualClaims = array_values(array_filter($accepted, static fn (array $claim): bool => is_array($claim['visual_support'] ?? null) && strtolower((string) ($claim['visual_support']['status'] ?? '')) === 'supported'));
        $observationText = array_values(array_filter(array_map(static fn (array $item): string => trim((string) ($item['value'] ?? $item['text'] ?? $item['observation'] ?? '')), $observations)));
        $subject = trim((string) ($mediaContext['subject_name'] ?? ''));
        $captionParts = array_values(array_filter(array_merge($subject !== '' ? [$subject] : [], array_map(static fn (array $claim): string => trim((string) ($claim['text'] ?? $claim['claim_text'] ?? '')), $accepted))));
        $altParts = array_values(array_filter(array_merge($subject !== '' ? [$subject] : [], $observationText, array_map(static fn (array $claim): string => trim((string) ($claim['text'] ?? $claim['claim_text'] ?? '')), $visualClaims))));
        $contextClaims = array_values(array_filter($accepted, static fn (array $claim): bool => strtoupper((string) ($claim['editorial_treatment'] ?? '')) !== 'DIRECT_FACT'));
        $descriptionParts = array_values(array_filter(array_merge($captionParts, array_map(static fn (array $claim): string => trim((string) ($claim['text'] ?? $claim['claim_text'] ?? '')), $contextClaims))));
        $surfaces = ['caption' => $this->join($captionParts), 'alt_text' => $this->join($altParts), 'description' => $this->join($descriptionParts)];
        $dependencies = [];
        foreach (['caption' => $accepted, 'alt_text' => $visualClaims, 'description' => array_values(array_unique(array_merge($accepted, $contextClaims), SORT_REGULAR))] as $surface => $used) {
            $dependencies[$surface] = array_values(array_unique(array_filter(array_map(static fn (array $claim): string => trim((string) ($claim['claim_id'] ?? '')), $used))));
        }
        $trace = [];
        foreach ($accepted as $claim) {
            $id = trim((string) ($claim['claim_id'] ?? ''));
            if ($id === '') continue;
            $trace[$id] = ['claim_id' => $id, 'claim_revision' => (int) ($claim['claim_revision'] ?? $claim['revision'] ?? 0), 'surfaces' => array_values(array_filter(['caption' => in_array($id, $dependencies['caption'], true), 'alt_text' => in_array($id, $dependencies['alt_text'], true), 'description' => in_array($id, $dependencies['description'], true)]))];
        }
        $gaps = [];
        foreach ($surfaces as $surface => $value) if ($value === '') $gaps[$surface] = 'MEDIA_SURFACE_SPARSE';
        if ($accepted === [] && $observationText === []) foreach (array_keys($surfaces) as $surface) $gaps[$surface] = 'MEDIA_SURFACE_SPARSE';
        if ($content === [] || strtoupper((string) ($content['status'] ?? '')) === 'UNAVAILABLE') $gaps['content'] = 'MEDIA_ENRICHMENT_UNAVAILABLE';
        $quality = ['status' => $gaps === [] ? 'READY' : 'PARTIAL', 'blockers' => [], 'warnings' => array_values(array_unique(array_values($gaps)))];
        return OwnerConsumptionPlan::forCapability(OwnerCapability::forOwner('media_image', ['caption', 'alt_text', 'description'], true, true), ['id' => (string) ($mediaContext['owner_id'] ?? $mediaContext['media_id'] ?? ''), 'type' => 'media_image'], $surfaces, $dependencies, ['applicability' => 'preserved', 'specificity' => 'never_upgraded', 'treatment' => 'surface_policy', 'public_safety' => true], $trace, ['selected_claims' => count($accepted), 'visual_claims' => count($visualClaims)], $gaps, $quality);
    }

    private function accepted(array $claim): bool
    {
        if (($claim['eligibility'] ?? 'eligible') !== 'eligible' || ($claim['applicability'] ?? 'applicable') !== 'applicable' || ($claim['publicly_composable'] ?? true) !== true) return false;
        if (in_array(strtoupper((string) ($claim['semantic_role'] ?? '')), ['PROVENANCE_ONLY', 'GROUNDING', 'CONTROL_ONLY'], true)) return false;
        if (($claim['semantic_context_only'] ?? false) === true && strtoupper((string) ($claim['editorial_treatment'] ?? '')) === 'DIRECT_FACT') return false;
        return trim((string) ($claim['text'] ?? $claim['claim_text'] ?? '')) !== '';
    }

    private function join(array $parts): string
    {
        return implode(' ', array_values(array_unique(array_filter(array_map(static fn (mixed $part): string => trim((string) $part), $parts)))));
    }
}
