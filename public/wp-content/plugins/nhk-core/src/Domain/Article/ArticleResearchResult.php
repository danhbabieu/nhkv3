<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Article;

final readonly class ArticleResearchResult
{
    public function __construct(
        public array $subjectResolution,
        public array $inventory,
        public array $overlap,
        public array $knowledgeInventory,
        public array $relationPlan,
        public array $internalLinks,
        public array $categoryPlan,
        public array $mediaPlan,
        public array $videoPlan,
        public array $seoBlueprint,
        public array $compliance,
        public array $blockers,
        public array $warnings,
        public bool $readyForDraft,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['subject_resolution' => $this->subjectResolution, 'inventory' => $this->inventory, 'overlap_analysis' => $this->overlap, 'knowledge_inventory' => $this->knowledgeInventory, 'relation_plan' => $this->relationPlan, 'internal_link_plan' => $this->internalLinks, 'category_plan' => $this->categoryPlan, 'media_plan' => $this->mediaPlan, 'video_plan' => $this->videoPlan, 'seo_blueprint' => $this->seoBlueprint, 'claim_compliance' => $this->compliance, 'blockers' => $this->blockers, 'warnings' => $this->warnings, 'ready_for_draft' => $this->readyForDraft];
    }
}
