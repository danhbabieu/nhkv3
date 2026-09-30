<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

use NHK\Core\Application\Dictionary\DictionarySeedPlanner;

/** External, read-only orchestration over Dictionary and semantic retrieval. */
final class SemanticEnrichmentPlanner
{
    /** @param callable(array<string,mixed>):list<array<string,mixed>>|null $ownerResolver @param callable(array<string,mixed>):list<array<string,mixed>>|null $graphDiscoverer @param callable(array<string,mixed>):list<array<string,mixed>>|null $knowledgeRetriever */
    public function __construct(
        private DictionarySeedPlanner $dictionary,
        private $ownerResolver = null,
        private $graphDiscoverer = null,
        private $knowledgeRetriever = null,
    ) {}

    /** @param StructuredInterpretationPacket|array<string,mixed> $packet @param array<string,mixed> $options @return array<string,mixed> */
    public function plan(StructuredInterpretationPacket|array $packet, array $options = []): array
    {
        $value = $packet instanceof StructuredInterpretationPacket ? $packet->toArray() : $packet;
        $source = is_array($value['source_context'] ?? null) ? $value['source_context'] : [];
        $dictionary = $this->dictionary->plan($value, $options['dictionary'] ?? []);
        $base = ['read_only' => true, 'mutated' => false, 'dictionary' => $dictionary, 'owners' => [], 'graph_candidates' => [], 'knowledge' => ['applicable' => [], 'rejected' => []], 'diagnostics' => []];

        $lineageContext = ['source_kind' => ($source['raw_or_derived'] ?? 'RAW') === 'DERIVED' ? ($source['source_kind'] ?? '') : 'RAW', 'lineage' => $source['lineage'] ?? []];
        if (!(new DerivedLineageGuard())->isIndependent($lineageContext)) {
            $base['status'] = 'REVIEW_REQUIRED';
            $base['diagnostics'][] = 'DERIVED_PROSE_NOT_INDEPENDENT_EVIDENCE';
            return $base;
        }

        $owners = [];
        foreach ($dictionary['items'] as $seed) {
            if (!in_array(($seed['classification'] ?? ''), ['RESOLVED_EXISTING', 'ALIAS_TO_EXISTING'], true) || !is_callable($this->ownerResolver)) continue;
            try {
                foreach ((array) (($this->ownerResolver)($seed) ?? []) as $owner) if (is_array($owner)) $owners[] = $owner + ['seed' => $seed['normalized_form']];
            } catch (\Throwable $error) {
                $base['diagnostics'][] = 'CANONICAL_OWNER_RESOLUTION_UNAVAILABLE';
            }
        }
        $base['owners'] = $owners;
        if ($owners === [] && is_callable($this->knowledgeRetriever)) {
            try {
                ($this->knowledgeRetriever)(['id' => '', 'type' => '', 'unresolved_seeds' => $dictionary['items']]);
            } catch (\Throwable) {
                $base['diagnostics'][] = 'KNOWLEDGE_RETRIEVAL_UNAVAILABLE';
                $base['status'] = 'UNAVAILABLE';
                return $base;
            }
        }
        foreach ($owners as $owner) {
            if (is_callable($this->graphDiscoverer)) {
                try {
                    foreach ((array) (($this->graphDiscoverer)($owner) ?? []) as $candidate) if (is_array($candidate)) $base['graph_candidates'][] = $candidate;
                } catch (\Throwable) {
                    $base['diagnostics'][] = 'GRAPH_DISCOVERY_UNAVAILABLE';
                }
            }
            if (!is_callable($this->knowledgeRetriever)) continue;
            try {
                foreach ((array) (($this->knowledgeRetriever)($owner) ?? []) as $claim) {
                    if (!is_array($claim)) continue;
                    if ($this->applicable($claim)) $base['knowledge']['applicable'][] = $claim;
                    else $base['knowledge']['rejected'][] = $claim + ['rejection_reasons' => $this->rejectionReasons($claim)];
                }
            } catch (\Throwable) {
                $base['diagnostics'][] = 'KNOWLEDGE_RETRIEVAL_UNAVAILABLE';
                $base['status'] = 'UNAVAILABLE';
                return $base;
            }
        }
        $base['status'] = in_array('KNOWLEDGE_RETRIEVAL_UNAVAILABLE', $base['diagnostics'], true) ? 'UNAVAILABLE' : 'AVAILABLE';
        return $base;
    }

    /** @param array<string,mixed> $claim */
    private function applicable(array $claim): bool
    {
        $scope = strtolower(trim((string) ($claim['scope_compatibility'] ?? $claim['scope_status'] ?? 'compatible')));
        $applicability = strtolower(trim((string) ($claim['applicability'] ?? 'applicable')));
        $evidence = strtolower(trim((string) ($claim['evidence_status'] ?? ($claim['evidence']['status'] ?? 'missing'))));
        return $scope === 'compatible' && $applicability === 'applicable' && in_array($evidence, ['eligible', 'not_required', 'verified', 'approved'], true);
    }

    /** @param array<string,mixed> $claim @return list<string> */
    private function rejectionReasons(array $claim): array
    {
        $reasons = [];
        $scope = strtolower(trim((string) ($claim['scope_compatibility'] ?? $claim['scope_status'] ?? 'compatible')));
        $applicability = strtolower(trim((string) ($claim['applicability'] ?? 'applicable')));
        $evidence = strtolower(trim((string) ($claim['evidence_status'] ?? ($claim['evidence']['status'] ?? 'missing'))));
        if ($scope !== 'compatible' || $applicability !== 'applicable') $reasons[] = 'INAPPLICABLE_SCOPE';
        if (!in_array($evidence, ['eligible', 'not_required', 'verified', 'approved'], true)) $reasons[] = 'EVIDENCE_MISSING';
        return $reasons;
    }
}
