<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Application\Semantic\{EnrichmentPack, UniversalInputEnvelope};

/** Read/planning-only seam until the governed Media enrichment lifecycle exists. */
final class MediaUniversalEnrichmentAdapter
{
    public function enrich(UniversalInputEnvelope $input): EnrichmentPack
    {
        return EnrichmentPack::fromBranches([
            'content' => [
                'status' => 'UNAVAILABLE',
                'input' => ['owner_or_source_type' => $input->toArray()['owner_or_source_type'] ?? 'media_image'],
                'diagnostics' => ['MEDIA_ADAPTER_NOT_YET_CONNECTED'],
            ],
            'relations' => ['status' => 'NOT_REQUESTED', 'candidates' => [], 'diagnostics' => []],
            'knowledge' => ['status' => 'NOT_REQUESTED', 'candidates' => [], 'proposals' => [], 'diagnostics' => []],
        ]);
    }
}
