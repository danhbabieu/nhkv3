<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Transient SEO read model; it never owns or mutates semantic truth. */
final readonly class SemanticSeoPlan
{
    public function __construct(
        public string $readiness,
        public string $profile,
        public string $searchIntent,
        public array $primarySubject,
        public string $topicFocus,
        public array $semanticCluster,
        public string $title,
        public string $h1,
        public string $metaDescription,
        public ?string $canonicalUrl,
        public array $openGraph,
        public array $internalLinks = [],
        public array $dictionaryContext = [],
        public array $structuredData = [],
        public array $claimTrace = [],
        public array $diagnostics = [],
        public array $blockers = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['readiness' => $this->readiness, 'profile' => $this->profile, 'search_intent' => $this->searchIntent, 'primary_subject' => $this->primarySubject, 'topic_focus' => $this->topicFocus, 'semantic_cluster' => $this->semanticCluster, 'title' => $this->title, 'h1' => $this->h1, 'meta_description' => $this->metaDescription, 'canonical_url' => $this->canonicalUrl, 'open_graph' => $this->openGraph, 'internal_links' => $this->internalLinks, 'dictionary_context' => $this->dictionaryContext, 'structured_data' => $this->structuredData, 'claim_trace' => $this->claimTrace, 'diagnostics' => $this->diagnostics, 'blockers' => $this->blockers];
    }
}
