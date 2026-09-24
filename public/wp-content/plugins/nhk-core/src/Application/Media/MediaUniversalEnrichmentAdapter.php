<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Application\Semantic\{ClaimRetrievalEngine, EditorialClaimRetrievalService, EditorialKnowledgeSelector, EnrichmentPack, SharedEnrichmentBoundary, UniversalInputEnvelope};

/** Read/planning-only seam until the governed Media enrichment lifecycle exists. */
final class MediaUniversalEnrichmentAdapter
{
    public function __construct(private ?SharedEnrichmentBoundary $shared = null)
    {
    }

    public static function fromEngine(ClaimRetrievalEngine $engine): self
    {
        $retrieval = new EditorialClaimRetrievalService($engine);
        return new self(new SharedEnrichmentBoundary($retrieval, new EditorialKnowledgeSelector()));
    }

    public function enrich(UniversalInputEnvelope $input): EnrichmentPack
    {
        if ($this->shared !== null) {
            $request = $input->toArray();
            $request['profile'] = 'media';
            $request['owner_or_source_type'] ??= 'media_image';
            $result = $this->shared->enrich($request);
            return EnrichmentPack::fromBranches([
                'content' => $result['content'] ?? ['status' => 'INCOMPLETE', 'diagnostics' => ['MEDIA_SHARED_CONTENT_UNAVAILABLE']],
                'relations' => $result['relations'] ?? ['status' => 'NOT_REQUESTED', 'candidates' => [], 'diagnostics' => []],
                'knowledge' => $result['knowledge'] ?? ['status' => 'NOT_REQUESTED', 'candidates' => [], 'proposals' => [], 'diagnostics' => []],
            ]);
        }
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
