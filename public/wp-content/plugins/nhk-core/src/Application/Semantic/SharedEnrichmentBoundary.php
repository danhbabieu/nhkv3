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

    /**
     * Shared bounded policy for editorial surfaces. Physical Media ingest,
     * canonical ownership and Governance remain outside this read-only policy.
     *
     * @return array<string,int>
     */
    public static function comprehensiveEditorialPolicy(string $profile): array
    {
        $profile = strtolower(trim($profile));
        if (!in_array($profile, ['article', 'video', 'image', 'media'], true)) return [];
        return [
            'result_limit' => 200,
            'selection_limit' => 20,
            'aspect_target' => 12,
            'token_budget' => 3000,
        ];
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function enrich(array $request): array
    {
        $profile = strtolower(trim((string) ($request['profile'] ?? '')));
        if (($request['comprehensive_editorial'] ?? false) === true) {
            $request = array_replace(self::comprehensiveEditorialPolicy($profile), $request);
        }
        $pack = $this->core->enrich(UniversalInputEnvelope::fromArray($request), $request);
        $result = $pack->toArray();
        $result['profile'] = $profile;
        return $result;
    }
}
