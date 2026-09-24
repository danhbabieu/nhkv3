<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Application\Consumption\OwnerConsumptionPlan;

/** Media-specific checks over a generic owner plan; never invents claim trace. */
final class MediaConsumptionQualityGate
{
    public function evaluate(OwnerConsumptionPlan $plan): array
    {
        $data = $plan->toArray();
        $blockers = [];
        $warnings = array_values(array_filter(array_map(static fn (mixed $gap): string => (string) $gap, $data['gaps'])));
        foreach ($data['surfaces'] as $surface => $text) {
            if ($text === '') $warnings[] = 'MEDIA_SURFACE_SPARSE';
            if (preg_match('/(?:canonical_id|claim_id|source_id|provenance|internal)/i', (string) $text) === 1) $blockers[] = 'INTERNAL_METADATA_LEAK';
        }
        if ($data['surfaces']['caption'] !== '' && $data['surfaces']['caption'] === $data['surfaces']['alt_text']) $warnings[] = 'MEDIA_SURFACES_IDENTICAL_BOILERPLATE';
        return ['status' => $blockers !== [] ? 'BLOCKED' : ($warnings !== [] ? 'PARTIAL' : 'READY'), 'blockers' => array_values(array_unique($blockers)), 'warnings' => array_values(array_unique($warnings)), 'trace_preserved' => true, 'repair_added_dependencies' => false];
    }
}
