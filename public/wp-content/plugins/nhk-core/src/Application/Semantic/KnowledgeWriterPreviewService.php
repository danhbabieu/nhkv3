<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

use NHK\Core\Application\Mcp\McpSemanticContextResolver;
use NHK\Core\Domain\Knowledge\KnowledgeFacetProfile;
use NHK\Core\Domain\Seo\SeoReadinessResult;

/** Transient reader preview over the existing resolution, enrichment and editorial read models. */
final class KnowledgeWriterPreviewService
{
    private const PURPOSES = [
        'concise_answer' => ['profile' => 'video', 'depth' => 'concise'],
        'collector_explanation' => ['profile' => 'article', 'depth' => 'deep'],
        'article_section' => ['profile' => 'article', 'depth' => 'deep'],
        'video_description' => ['profile' => 'video', 'depth' => 'concise'],
        'media_caption' => ['profile' => 'media', 'depth' => 'concise'],
        'media_alt' => ['profile' => 'image', 'depth' => 'concise'],
        'entity_summary' => ['profile' => 'article', 'depth' => 'deep'],
        'technical_explanation' => ['profile' => 'article', 'depth' => 'deep'],
    ];

    public function __construct(
        private McpSemanticContextResolver $typedResolver,
        private SubjectResolutionService $textResolver,
        private SharedEnrichmentBoundary $enrichment,
        private ReaderJourneyPlanner $journey,
        private SharedEditorialComposer $composer,
        private EditorialQualityGate $qualityGate,
    ) {
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function preview(array $request): array
    {
        $purpose = array_key_exists('purpose', $request) ? (is_string($request['purpose']) ? trim($request['purpose']) : '') : 'concise_answer';
        $policy = self::PURPOSES[$purpose] ?? null;
        $depth = $request['depth'] ?? ($policy['depth'] ?? 'concise');
        $base = $this->emptyResult($purpose, $policy['profile'] ?? '', is_string($depth) ? $depth : '');
        $facets = $request['requested_facets'] ?? [];
        $instruction = $request['instruction'] ?? null;
        if ($policy === null) return $this->fail($base, 'blocked', 'UNKNOWN_PREVIEW_PURPOSE');
        if (!is_string($depth) || !in_array($depth, ['concise', 'deep'], true)) return $this->fail($base, 'blocked', 'UNKNOWN_PREVIEW_DEPTH');
        if (!is_array($facets) || !array_is_list($facets) || count($facets) > 12) return $this->fail($base, 'blocked', 'INVALID_REQUESTED_FACETS');
        foreach ($facets as $facet) if (!is_string($facet) || !in_array($facet, KnowledgeFacetProfile::FACETS, true)) return $this->fail($base, 'blocked', 'UNKNOWN_KNOWLEDGE_FACET');
        if (!is_string($instruction) || trim($instruction) === '' || mb_strlen($instruction) > 1000) return $this->fail($base, 'blocked', 'INVALID_PREVIEW_INSTRUCTION');
        $constraints = $request['output_constraints'] ?? [];
        if (!is_array($constraints) || array_diff(array_keys($constraints), ['format', 'language', 'max_chars']) !== []
            || (isset($constraints['format']) && $constraints['format'] !== 'text')
            || (isset($constraints['language']) && $constraints['language'] !== 'vi')
            || (isset($constraints['max_chars']) && (!is_int($constraints['max_chars']) || $constraints['max_chars'] < 1 || $constraints['max_chars'] > 4000))) {
            return $this->fail($base, 'blocked', 'INVALID_OUTPUT_CONSTRAINTS');
        }
        $locator = $request['subject'] ?? null;
        if (!is_array($locator)) return $this->fail($base, 'unresolved', 'SUBJECT_NOT_FOUND');
        $resolution = $this->resolveSubject($locator);
        $status = (string) ($resolution['status'] ?? 'unresolved');
        $primary = is_array($resolution['primary'] ?? null) ? $resolution['primary'] : [];
        if ($status !== 'resolved' || $primary === []) {
            $base['diagnostics'] = array_values(array_unique(array_merge($base['diagnostics'], (array) ($resolution['diagnostics'] ?? []))));
            return $this->fail($base, $status === 'ambiguous' ? 'ambiguous' : 'unresolved', $status === 'ambiguous' ? 'AMBIGUOUS_SUBJECT_REVIEW' : 'SUBJECT_NOT_FOUND');
        }
        $base['subject'] = [
            'canonical_id' => (string) ($primary['id'] ?? ''),
            'type' => (string) ($primary['type'] ?? ''),
            'name' => (string) ($primary['name'] ?? ''),
            'revision' => (int) ($primary['revision'] ?? 0),
            'resolution' => (string) ($primary['match'] ?? $resolution['primary_source'] ?? ''),
        ];
        $observations = array_values(array_filter((array) ($request['observations'] ?? []), 'is_array'));
        $observations = array_slice($observations, 0, 12);
        $base['context_used'] = array_map(static fn (array $item): array => ['treatment' => 'context', 'value' => is_scalar($item['value'] ?? null) ? (string) $item['value'] : ''], $observations);
        $topic = (string) ($primary['name'] ?? '');
        $envelope = UniversalInputEnvelope::fromArray([
            'owner_or_source_type' => 'preview', 'subject_resolution' => ['primary' => $primary],
            'observations' => $observations, 'title' => $topic,
        ]);
        $needs = array_map(static fn (string $facet): array => SemanticNeed::fromArray([
            'canonical_subject' => $primary, 'facet_key' => $facet, 'origin' => 'USER_EXPLICIT',
            'scope' => (string) ($primary['type'] ?? ''),
        ])->toArray(), array_values(array_unique($facets)));
        try {
            $shared = $this->enrichment->enrich($envelope->toArray() + [
                'profile' => $policy['profile'], 'topic' => $topic, 'retrieval_topic' => $topic . ' ' . trim($instruction),
                'semantic_needs' => $needs,
            ]);
        } catch (\Throwable) {
            return $this->fail($base, 'unavailable', 'PREVIEW_PIPELINE_UNAVAILABLE');
        }
        $content = is_array($shared['content'] ?? null) ? $shared['content'] : [];
        $pack = $content['pack'] ?? null;
        if (!$pack instanceof EditorialContextPack) return $this->fail($base, 'unavailable', 'PREVIEW_ENRICHMENT_UNAVAILABLE');
        try {
            $plan = $this->contextualize($this->journey->plan($pack), $primary);
            $draft = $this->composer->compose($plan);
            $seo = new SemanticSeoPlan(SeoReadinessResult::NOT_APPLICABLE, $policy['profile'], '', $primary, $topic, [], $topic, $topic, '', null, []);
            $quality = $this->qualityGate->evaluate($pack, $plan, $draft, $seo);
        } catch (\RuntimeException $error) {
            return $this->fail($base, $error->getMessage() === 'PUBLIC_INTERNAL_JARGON_LEAK' ? 'blocked' : 'unavailable', $error->getMessage() === 'PUBLIC_INTERNAL_JARGON_LEAK' ? 'PUBLIC_COPY_UNSAFE' : 'PREVIEW_PIPELINE_UNAVAILABLE');
        } catch (\Throwable) {
            return $this->fail($base, 'unavailable', 'PREVIEW_PIPELINE_UNAVAILABLE');
        }
        $base['semantic_needs'] = (array) ($content['semantic_needs'] ?? []);
        $base['used_knowledge'] = $this->usedKnowledge($draft, $plan);
        $base['excluded_knowledge'] = $this->excludedKnowledge($pack);
        $base['coverage'] = $this->coverage($pack, $plan, $facets);
        $base['gaps'] = array_values(array_unique(array_merge($this->reasonCodes((array) ($content['gaps'] ?? [])), $base['coverage']['uncovered_facets'])));
        $base['warnings'] = array_values(array_unique($quality->warnings));
        $base['quality'] = ['readiness' => $quality->readiness, 'dimensions' => $quality->dimensions, 'blockers' => $quality->blockers];
        $base['diagnostics'] = $this->reasonCodes((array) ($content['diagnostics'] ?? []));
        $answer = $draft->claimTrace === [] ? '' : $this->scrub($draft->body, $primary, $pack);
        if ($quality->blockers !== []) $answer = '';
        if (isset($constraints['max_chars']) && mb_strlen($answer) > $constraints['max_chars']) {
            $answer = '';
            $base['gaps'][] = 'OUTPUT_LENGTH_UNSATISFIED';
        }
        $base['answer'] = $answer;
        $base['status'] = $answer !== '' ? 'available' : ($pack->status === 'unavailable' ? 'unavailable' : 'sparse');
        return $base;
    }

    /** @param array<string,mixed> $locator @return array<string,mixed> */
    private function resolveSubject(array $locator): array
    {
        $query = trim((string) ($locator['query'] ?? ''));
        if ($query !== '' && !isset($locator['type']) && !isset($locator['canonical_uuid']) && !isset($locator['stable_key'])) {
            return $this->textResolver->resolveSources(['subject_hints' => [$query]]);
        }
        $type = trim((string) ($locator['type'] ?? $locator['entity_type'] ?? ''));
        $typed = $type !== '' ? [$type => [
            'canonical_uuid' => $locator['canonical_uuid'] ?? $locator['uuid'] ?? null,
            'stable_key' => $locator['stable_key'] ?? null,
            'name' => $query !== '' ? $query : ($locator['name'] ?? null),
        ]] : [
            'canonical_uuid' => $locator['canonical_uuid'] ?? null,
            'stable_key' => $locator['stable_key'] ?? null,
        ];
        $result = $this->typedResolver->resolve($typed);
        $resolved = array_values((array) ($result['resolved'] ?? []));
        return [
            'status' => ($result['ambiguities'] ?? []) !== [] ? 'ambiguous' : ($resolved !== [] ? 'resolved' : 'unresolved'),
            'primary' => count($resolved) === 1 ? $resolved[0] : null,
            'diagnostics' => array_map(static fn (array $item): string => (string) ($item['code'] ?? ''), array_filter((array) ($result['diagnostics'] ?? []), 'is_array')),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function usedKnowledge(EditorialDraft $draft, EditorialPlan $plan): array
    {
        $claims = [];
        foreach ($plan->sections as $section) foreach ((array) ($section['claims'] ?? []) as $claim) if (is_array($claim)) $claims[(string) ($claim['claim_id'] ?? '')] = $claim;
        $used = [];
        foreach ($draft->claimTrace as $trace) {
            $claim = $claims[(string) ($trace['claim_id'] ?? '')] ?? [];
            $used[] = [
                'claim_id' => (string) ($trace['claim_id'] ?? ''), 'revision' => (int) ($trace['claim_revision'] ?? 0),
                'original_subject' => $trace['original_subject'] ?? [], 'target_subject' => $trace['target_subject'] ?? [],
                'facet' => (string) ($claim['facet'] ?? ''), 'applicability' => (string) ($trace['applicability'] ?? ''),
                'specificity' => $trace['specificity'] ?? null, 'treatment' => (string) ($trace['editorial_treatment'] ?? ''),
                'evidence' => $claim['evidence'] ?? [], 'provenance' => $claim['provenance_references'] ?? [],
                'graph_path' => $trace['graph_path'] ?? [],
            ];
        }
        return $used;
    }

    private function contextualize(EditorialPlan $plan, array $primary): EditorialPlan
    {
        $sections = $plan->sections;
        foreach ($sections as &$section) {
            if (!is_array($section)) continue;
            if (!is_array($section['claims'] ?? null)) continue;
            foreach ($section['claims'] as &$claim) {
                if (!is_array($claim)) continue;
                $origin = (string) ($claim['original_subject']['id'] ?? $claim['subject_id'] ?? '');
                if ($origin === '' || $origin === (string) ($primary['id'] ?? '')) continue;
                $claim['editorial_treatment'] = 'SUPPORTING_CONTEXT';
                $claim['semantic_context_only'] = true;
                $claim['editorial_role'] = 'CONTEXT';
            }
            unset($claim);
        }
        unset($section);
        return new EditorialPlan($plan->status, $plan->profile, $plan->primarySubject, $plan->topic, $sections, $plan->inputContext, $plan->visualSupport, $plan->blockers, $plan->diagnostics);
    }

    /** @return list<array<string,mixed>> */
    private function excludedKnowledge(EditorialContextPack $pack): array
    {
        $excluded = [];
        foreach (array_slice($pack->excludedCandidates, 0, 50) as $claim) {
            if (!is_array($claim)) continue;
            $excluded[] = ['claim_id' => (string) ($claim['claim_id'] ?? ''), 'revision' => (int) ($claim['claim_revision'] ?? 0), 'reasons' => array_values(array_slice((array) ($claim['exclusion_reasons'] ?? []), 0, 5))];
        }
        return $excluded;
    }

    /** @param list<string> $facets @return array<string,mixed> */
    private function coverage(EditorialContextPack $pack, EditorialPlan $plan, array $facets): array
    {
        $covered = [];
        foreach ($plan->sections as $section) foreach ((array) ($section['claims'] ?? []) as $claim) if (is_array($claim) && is_string($claim['facet'] ?? null)) $covered[] = $claim['facet'];
        $covered = array_values(array_unique($covered));
        return ['status' => $covered === [] ? 'sparse' : 'partial', 'covered_facets' => $covered, 'uncovered_facets' => array_values(array_diff($facets, $covered)), 'aspects' => $pack->coverageAspects];
    }

    private function scrub(string $text, array $subject, EditorialContextPack $pack): string
    {
        $needles = [(string) ($subject['id'] ?? ''), (string) ($subject['stable_key'] ?? '')];
        foreach ($pack->selectedClaims as $claim) if (is_array($claim)) $needles[] = (string) ($claim['claim_id'] ?? '');
        $text = str_replace(array_filter($needles), '', $text);
        $text = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/i', '', $text) ?? $text;
        return trim($text);
    }

    /** @param array<mixed> $values @return list<string> */
    private function reasonCodes(array $values): array
    {
        return array_values(array_unique(array_filter($values, static fn (mixed $value): bool => is_string($value) && preg_match('/^[A-Z][A-Z0-9_]{2,80}$/', $value) === 1)));
    }

    /** @return array<string,mixed> */
    private function emptyResult(string $purpose, string $profile, string $depth): array
    {
        return ['status' => 'blocked', 'subject' => [], 'answer' => '', 'depth' => ['purpose' => $purpose, 'profile' => $profile, 'effective' => $depth], 'semantic_needs' => [], 'coverage' => [], 'used_knowledge' => [], 'excluded_knowledge' => [], 'context_used' => [], 'gaps' => [], 'warnings' => [], 'quality' => [], 'diagnostics' => [], 'read_only' => true];
    }

    /** @param array<string,mixed> $base @return array<string,mixed> */
    private function fail(array $base, string $status, string $code): array
    {
        $base['status'] = $status;
        $base['diagnostics'] = array_values(array_unique(array_merge($base['diagnostics'], [$code])));
        return $base;
    }
}
