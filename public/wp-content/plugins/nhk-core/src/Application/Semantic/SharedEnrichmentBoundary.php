<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

use NHK\Core\Application\Knowledge\{KnowledgeEnrichmentPlanner, KnowledgeEnrichmentProposalFactory};

/** Compatibility façade for the universal transient enrichment core. */
final class SharedEnrichmentBoundary
{
    private UniversalEnrichmentCore $core;

    /** @param callable(array<string,mixed>):array<string,mixed>|null $relations */
    public function __construct(
        EditorialClaimRetrievalService $retrieval,
        EditorialKnowledgeSelector $selector,
        ?KnowledgeEnrichmentPlanner $knowledge = null,
        ?KnowledgeEnrichmentProposalFactory $proposalFactory = null,
        mixed $relations = null,
        ?SemanticNeedDecomposer $decomposer = null,
    ) {
        $this->core = new UniversalEnrichmentCore(
            $retrieval,
            $selector,
            $knowledge,
            $proposalFactory,
            $relations,
            $decomposer ?? new SemanticNeedDecomposer(new TextInputInterpreter(), new SemanticNeedVocabulary()),
        );
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function enrich(array $request): array
    {
        $pack = $this->core->enrich(UniversalInputEnvelope::fromArray($request), $request);
        $result = $pack->toArray();
        $result['profile'] = strtolower(trim((string) ($request['profile'] ?? '')));
        return $result;
    }
}
