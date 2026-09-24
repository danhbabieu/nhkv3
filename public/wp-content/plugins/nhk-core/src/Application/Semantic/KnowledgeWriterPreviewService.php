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
        $base = $this->emptyResult($purpose, $policy['profile'] ?? '', '');
        $facets = $request['requested_facets'] ?? [];
        $instruction = $request['instruction'] ?? null;
        if ($policy === null) return $this->fail($base, 'blocked', 'UNKNOWN_PREVIEW_PURPOSE');
        if (!is_string($depth) || !in_array($depth, ['concise', 'deep'], true)) return $this->fail($base, 'blocked', 'UNKNOWN_PREVIEW_DEPTH');
        if ($depth !== $policy['depth']) return $this->fail($base, 'blocked', 'INCOMPATIBLE_PREVIEW_DEPTH');
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
        if (!$this->validLocator($locator)) return $this->fail($base, 'blocked', 'INVALID_SUBJECT_LOCATOR');
        $observations = $request['observations'] ?? [];
        if (!is_array($observations) || !array_is_list($observations) || count($observations) > 12) return $this->fail($base, 'blocked', 'INVALID_PREVIEW_OBSERVATIONS');
        foreach ($observations as $observation) {
            if (!is_array($observation) || array_diff(array_keys($observation), ['value']) !== []
                || !is_string($observation['value'] ?? null) || trim($observation['value']) === ''
                || mb_strlen($observation['value']) > 500) return $this->fail($base, 'blocked', 'INVALID_PREVIEW_OBSERVATIONS');
        }
        $resolution = $this->resolveSubject($locator);
        $status = (string) ($resolution['status'] ?? 'unresolved');
        $primary = is_array($resolution['primary'] ?? null) ? $resolution['primary'] : [];
        if ($status !== 'resolved' || $primary === []) {
            $base['diagnostics'] = $this->reasonCodes((array) ($resolution['diagnostics'] ?? []));
            return $this->fail($base, $status === 'conflict' ? 'blocked' : ($status === 'ambiguous' ? 'ambiguous' : 'unresolved'),
                $status === 'conflict' ? 'SUBJECT_LOCATOR_CONFLICT' : ($status === 'ambiguous' ? 'AMBIGUOUS_SUBJECT_REVIEW' : 'SUBJECT_NOT_FOUND'));
        }
        $base['subject'] = [
            'canonical_id' => (string) ($primary['id'] ?? ''),
            'type' => (string) ($primary['type'] ?? ''),
            'name' => (string) ($primary['name'] ?? ''),
            'revision' => (int) ($primary['revision'] ?? 0),
            'resolution' => (string) ($primary['match'] ?? $resolution['primary_source'] ?? ''),
        ];
        if (mb_strlen($base['subject']['name']) > 200) return $this->fail($this->emptyResult($purpose, $policy['profile'], ''), 'blocked', 'SUBJECT_PROJECTION_UNSAFE');
        $base['context_used'] = array_map(static fn (array $item): array => ['treatment' => 'context', 'value' => trim($item['value'])], $observations);
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
            if (($plan->diagnostics['depth'] ?? null) !== $depth) return $this->fail($base, 'blocked', 'PREVIEW_DEPTH_MISMATCH');
            $base['depth']['effective'] = $depth;
            $draft = $this->composer->compose($plan);
            $seo = new SemanticSeoPlan(SeoReadinessResult::NOT_APPLICABLE, $policy['profile'], '', $primary, $topic, [], $topic, $topic, '', null, []);
            $quality = $this->qualityGate->evaluate($pack, $plan, $draft, $seo);
        } catch (\RuntimeException $error) {
            return $this->fail($base, $error->getMessage() === 'PUBLIC_INTERNAL_JARGON_LEAK' ? 'blocked' : 'unavailable', $error->getMessage() === 'PUBLIC_INTERNAL_JARGON_LEAK' ? 'PUBLIC_COPY_UNSAFE' : 'PREVIEW_PIPELINE_UNAVAILABLE');
        } catch (\Throwable) {
            return $this->fail($base, 'unavailable', 'PREVIEW_PIPELINE_UNAVAILABLE');
        }
        $base['semantic_needs'] = array_slice((array) ($content['semantic_needs'] ?? []), 0, 24);
        $base['excluded_knowledge'] = $this->excludedKnowledge($pack);
        $base['warnings'] = $this->reasonCodes($quality->warnings);
        $base['quality'] = ['readiness' => $quality->readiness, 'dimensions' => $quality->dimensions, 'blockers' => $this->reasonCodes($quality->blockers)];
        $base['diagnostics'] = $this->reasonCodes((array) ($content['diagnostics'] ?? []));
        $answer = $draft->claimTrace === [] || $quality->blockers !== [] ? '' : trim($draft->body);
        if ($answer !== '' && !$this->readerSafe($answer, $primary, $pack)) {
            $answer = '';
            $base['diagnostics'][] = 'PUBLIC_COPY_UNSAFE';
        }
        if ($answer !== '' && mb_strlen($answer) > ($constraints['max_chars'] ?? 4000)) {
            $answer = '';
            $base['gaps'][] = 'OUTPUT_LENGTH_UNSATISFIED';
        }
        $base['answer'] = $answer;
        $base['used_knowledge'] = $answer !== '' ? array_slice($this->usedKnowledge($draft, $plan, $answer), 0, 50) : [];
        $base['coverage'] = $this->coverage($base['used_knowledge'], $facets);
        $base['gaps'] = array_values(array_slice(array_unique(array_merge($this->reasonCodes((array) ($content['gaps'] ?? [])), $base['gaps'], $base['coverage']['uncovered_facets'])), 0, 30));
        $base['diagnostics'] = $this->reasonCodes($base['diagnostics']);
        $base['status'] = $answer !== '' ? 'available' : (in_array('PUBLIC_COPY_UNSAFE', $base['diagnostics'], true) ? 'blocked' : ($pack->status === 'unavailable' ? 'unavailable' : 'sparse'));
        return $base;
    }

    /** @param array<string,mixed> $locator */
    private function validLocator(array $locator): bool
    {
        if (array_diff(array_keys($locator), ['type', 'entity_type', 'canonical_uuid', 'uuid', 'stable_key', 'query', 'name']) !== []) return false;
        foreach ($locator as $key => $value) {
            if (!is_string($value) || trim($value) === '' || mb_strlen($value) > (in_array($key, ['query', 'name'], true) ? 200 : 160)) return false;
        }
        if (isset($locator['type'], $locator['entity_type']) && $locator['type'] !== $locator['entity_type']) return false;
        if (isset($locator['uuid'], $locator['canonical_uuid']) && $locator['uuid'] !== $locator['canonical_uuid']) return false;
        return !isset($locator['query'], $locator['name']) || $locator['query'] === $locator['name'];
    }

    /** Resolve each supplied locator independently so UUID precedence cannot hide contradictions. */
    private function resolveSubject(array $locator): array
    {
        $type = trim($locator['type'] ?? $locator['entity_type'] ?? '');
        $locators = [];
        foreach (['canonical_uuid' => $locator['canonical_uuid'] ?? $locator['uuid'] ?? null,
            'stable_key' => $locator['stable_key'] ?? null, 'name' => $locator['query'] ?? $locator['name'] ?? null] as $key => $value) {
            if ($value !== null) $locators[$key] = trim($value);
        }
        if ($locators === []) return ['status' => 'unresolved', 'primary' => null, 'diagnostics' => []];
        $primary = null;
        foreach ($locators as $key => $value) {
            if ($key === 'name' && $type === '') {
                $text = $this->textResolver->resolveSources(['subject_hints' => [$value]]);
                $matches = array_values((array) ($text['resolved'] ?? []));
                $resolution = ['status' => $text['status'] ?? 'unresolved', 'primary' => count($matches) === 1 ? $matches[0] : null,
                    'diagnostics' => (array) ($text['diagnostics'] ?? [])];
            } else {
                $packet = $type !== '' ? [$type => [$key => $value]] : [$key => $value];
                $result = $this->typedResolver->resolve($packet);
                $resolved = array_values((array) ($result['resolved'] ?? []));
                $resolution = [
                    'status' => ($result['conflicts'] ?? []) !== [] ? 'conflict' : (($result['ambiguities'] ?? []) !== [] || count($resolved) > 1 ? 'ambiguous' : ($resolved !== [] ? 'resolved' : 'unresolved')),
                    'primary' => count($resolved) === 1 ? $resolved[0] : null,
                    'diagnostics' => array_map(static fn (array $item): string => (string) ($item['code'] ?? ''), array_filter((array) ($result['diagnostics'] ?? []), 'is_array')),
                ];
            }
            if (($resolution['status'] ?? '') !== 'resolved' || !is_array($resolution['primary'] ?? null)) {
                return count($locators) > 1 && ($resolution['status'] ?? '') !== 'ambiguous'
                    ? ['status' => 'conflict', 'primary' => null, 'diagnostics' => $resolution['diagnostics'] ?? []] : $resolution;
            }
            $candidate = $resolution['primary'];
            if ($primary !== null && ($candidate['id'] ?? null) !== ($primary['id'] ?? null)) {
                return ['status' => 'conflict', 'primary' => null, 'diagnostics' => []];
            }
            $primary ??= $candidate;
        }
        return ['status' => 'resolved', 'primary' => $primary, 'diagnostics' => []];
    }

    /** @return list<array<string,mixed>> */
    private function usedKnowledge(EditorialDraft $draft, EditorialPlan $plan, string $answer): array
    {
        $claims = [];
        foreach ($plan->sections as $section) foreach ((array) ($section['claims'] ?? []) as $claim) {
            if (is_array($claim)) $claims[(string) ($section['id'] ?? '') . '|' . (string) ($claim['claim_id'] ?? '')] = $claim;
        }
        $used = [];
        $seenText = [];
        $normalizedAnswer = $this->proseKey($answer);
        foreach ($draft->claimTrace as $trace) {
            $claim = $claims[(string) ($trace['section_id'] ?? '') . '|' . (string) ($trace['claim_id'] ?? '')] ?? [];
            $textKey = $this->proseKey((string) ($claim['text'] ?? $claim['claim_text'] ?? ''));
            if ($textKey === '' || isset($seenText[$textKey]) || !str_contains($normalizedAnswer, $textKey)) continue;
            $seenText[$textKey] = true;
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

    private function proseKey(string $text): string
    {
        return trim((string) (preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text)) ?? ''));
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
            $excluded[] = ['claim_id' => (string) ($claim['claim_id'] ?? ''), 'revision' => (int) ($claim['claim_revision'] ?? 0), 'reasons' => array_slice($this->reasonCodes((array) ($claim['exclusion_reasons'] ?? [])), 0, 5)];
        }
        return $excluded;
    }

    /** @param list<array<string,mixed>> $used @param list<string> $facets @return array<string,mixed> */
    private function coverage(array $used, array $facets): array
    {
        $covered = [];
        foreach ($used as $claim) if (is_string($claim['facet'] ?? null) && $claim['facet'] !== '') $covered[] = $claim['facet'];
        $covered = array_values(array_unique($covered));
        $uncovered = array_values(array_diff($facets, $covered));
        return ['status' => $covered === [] ? 'sparse' : ($uncovered === [] ? 'complete' : 'partial'),
            'covered_facets' => $covered, 'uncovered_facets' => $uncovered];
    }

    /** Reject the whole answer when control syntax or a known private identifier appears. */
    private function readerSafe(string $text, array $subject, EditorialContextPack $pack): bool
    {
        $needles = [(string) ($subject['id'] ?? ''), (string) ($subject['stable_key'] ?? '')];
        foreach ($pack->selectedClaims as $claim) {
            if (!is_array($claim)) continue;
            foreach (['claim_id', 'subject_id'] as $key) $needles[] = (string) ($claim[$key] ?? '');
            foreach (['source_ids', 'evidence_ids'] as $key) foreach ((array) ($claim[$key] ?? []) as $id) if (is_string($id)) $needles[] = $id;
            foreach ((array) ($claim['provenance_references'] ?? []) as $ids) foreach ((array) $ids as $id) if (is_string($id)) $needles[] = $id;
        }
        foreach ($needles as $needle) if (mb_strlen($needle) >= 6 && str_contains($text, $needle)) return false;
        return preg_match('/\b(?:source|evidence|provenance|proposal|claim|knowledge|capture|graph|canonical|stable|subject)(?:[_-]?(?:id|ids|uuid|key|revision|state|status|references?))?\b\s*[:=]|\b(?:source|evidence|provenance|proposal|claim|knowledge|capture|graph|canonical|stable|subject)[_-](?:id|ids|uuid|key|revision|state|status|references?)\b|\b[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b|\b(?:brand|model|variant|movement|classification|specimen|product):[a-z0-9_-]+\b|<!--|<[^>]+>/iu', $text) === 0;
    }

    /** @param array<mixed> $values @return list<string> */
    private function reasonCodes(array $values): array
    {
        return array_slice(array_values(array_unique(array_filter($values, static fn (mixed $value): bool => is_string($value) && preg_match('/^[A-Z][A-Z0-9_]{2,80}$/', $value) === 1))), 0, 30);
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
        $base['diagnostics'] = $this->reasonCodes(array_merge([$code], $base['diagnostics']));
        return $base;
    }
}
