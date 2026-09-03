<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Application\Knowledge\KnowledgeEnrichmentPlanner;
use NHK\Core\Domain\Knowledge\{KnowledgeEnrichmentCandidate, KnowledgeFacetProfile};

/** Read-only Video adapter for the shared Living Knowledge planner. */
final readonly class VideoKnowledgeEnrichmentPlanner
{
    public function __construct(private KnowledgeEnrichmentPlanner $planner)
    {
    }

    /** @param array<string,mixed> $context @return list<KnowledgeEnrichmentCandidate> */
    public function __invoke(array $context): array
    {
        $candidates = [];
        $hint = is_array($context['user_hint'] ?? null) ? trim((string) ($context['user_hint']['value'] ?? '')) : '';
        $transcript = $context['transcript_policy'] ?? null;
        foreach ($context['resolved'] ?? [] as $target) {
            if (!is_array($target) || !is_string($target['id'] ?? null) || !is_string($target['type'] ?? null)) continue;
            $profile = new KnowledgeFacetProfile('recognition', $this->scopeFor((string) $target['type']));
            if ($hint !== '') {
                $candidates = array_merge($candidates, $this->planner->plan((string) $target['id'], $profile, $hint, ['origin' => 'USER_HINT', 'source_url' => $context['source']['canonical_source_url'] ?? null]));
            }
            if (is_object($transcript) && method_exists($transcript, 'available') && $transcript->available() && is_string($transcript->text)) {
                $candidates = array_merge($candidates, $this->planner->plan((string) $target['id'], $profile, $transcript->text, ['origin' => 'TRANSCRIPT', 'transcript_provenance' => $transcript->provenance, 'transcript_hash' => $transcript->hash, 'source_url' => $context['source']['canonical_source_url'] ?? null]));
            }
        }
        return $candidates;
    }

    private function scopeFor(string $type): string
    {
        return in_array($type, ['brand', 'model', 'variant', 'movement'], true) ? $type : ($type === 'specimen' ? 'specimen_observation' : 'entity');
    }
}
