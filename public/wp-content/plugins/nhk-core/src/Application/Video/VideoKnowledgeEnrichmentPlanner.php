<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Application\Knowledge\KnowledgeEnrichmentPlanner;
use NHK\Core\Domain\Knowledge\{KnowledgeEnrichmentCandidate, KnowledgeFacetProfile};

/** Read-only Video adapter for the shared Living Knowledge planner. */
final readonly class VideoKnowledgeEnrichmentPlanner
{
    /** @param callable(object):list<array<string,mixed>>|null $extractor */
    public function __construct(private KnowledgeEnrichmentPlanner $planner, private mixed $extractor = null)
    {
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function __invoke(array $context): array
    {
        $diagnostics = [];
        $target = $this->selectSubject($context, $diagnostics);
        $packet = ['status' => 'available', 'subject' => $target, 'candidates' => [], 'diagnostics' => $diagnostics, 'proposal_ready' => false, 'unresolved_reasons' => []];
        if ($target === null) {
            if ($diagnostics !== []) $packet['unresolved_reasons'][] = 'SUBJECT_RESOLUTION_REQUIRED';
            return $packet;
        }

        $hint = is_array($context['user_hint'] ?? null) ? trim((string) ($context['user_hint']['value'] ?? '')) : '';
        $transcript = $context['transcript_policy'] ?? null;
        $profile = new KnowledgeFacetProfile('recognition', $this->scopeFor((string) $target['type']));
        $candidates = [];
        if ($hint !== '') $candidates = array_merge($candidates, $this->planner->plan((string) $target['id'], $profile, $hint, ['origin' => 'USER_HINT', 'source_url' => $context['source']['canonical_source_url'] ?? null]));

        if (is_object($transcript) && method_exists($transcript, 'available') && $transcript->available()) {
            if ($this->extractor === null) {
                $diagnostics[] = 'TRANSCRIPT_FACT_EXTRACTION_UNAVAILABLE';
            } else {
                try {
                    $observations = ($this->extractor)($transcript);
                    if (!is_array($observations)) throw new \UnexpectedValueException('Transcript extractor returned an invalid packet.');
                    foreach ($observations as $observation) {
                        if (!is_array($observation) || trim((string) ($observation['observation'] ?? '')) === '' || strlen((string) $observation['observation']) > 4000) continue;
                        $candidates = array_merge($candidates, $this->planner->plan((string) $target['id'], $profile, trim((string) $observation['observation']), ['origin' => 'TRANSCRIPT', 'transcript_provenance' => $transcript->provenance, 'transcript_hash' => $transcript->hash, 'locator' => $observation['locator'] ?? null, 'source_url' => $context['source']['canonical_source_url'] ?? null, 'source_id' => $context['source_id'] ?? '', 'source_revision' => $context['source_revision'] ?? null]));
                    }
                } catch (\Throwable $error) {
                    $diagnostics[] = 'TRANSCRIPT_FACT_EXTRACTION_FAILED:' . $error->getMessage();
                }
            }
        }
        $packet['candidates'] = array_values(array_map($this->serialize(...), $candidates));
        $packet['diagnostics'] = array_values(array_unique($diagnostics));
        if (($context['source_id'] ?? '') === '' && array_filter($packet['candidates'], static fn (array $candidate): bool => ($candidate['classification'] ?? '') === 'same_claim') !== []) {
            $packet['diagnostics'][] = 'SOURCE_RESOLUTION_NEEDED';
            $packet['unresolved_reasons'][] = 'CANONICAL_SOURCE_UNRESOLVED';
        }
        $packet['proposal_ready'] = $this->proposalReady($packet['candidates']);
        if (!$packet['proposal_ready'] && $packet['candidates'] !== []) $packet['unresolved_reasons'][] = 'GOVERNED_REVIEW_REQUIRED';
        return $packet;
    }

    /** @param array<string,mixed> $context @param list<string> $diagnostics @return array<string,mixed>|null */
    private function selectSubject(array $context, array &$diagnostics): ?array
    {
        $resolved = array_values(array_filter($context['resolved'] ?? [], static fn (mixed $target): bool => is_array($target) && is_string($target['id'] ?? null) && is_string($target['type'] ?? null)));
        $contextText = $this->normalize(implode(' ', array_filter([
            is_array($context['user_hint'] ?? null) ? (string) ($context['user_hint']['value'] ?? '') : '',
            is_array($context['source'] ?? null) ? (string) ($context['source']['source_title'] ?? '') : '',
            is_array($context['source'] ?? null) ? (string) ($context['source']['source_description'] ?? '') : '',
        ])));
        $supported = array_values(array_filter($resolved, fn (array $target): bool => $this->contains($contextText, (string) ($target['name'] ?? ''))));
        if ($supported !== []) $resolved = $supported;
        $ambiguous = $context['ambiguous'] ?? [];
        foreach (['specimen', 'variant', 'model', 'movement', 'brand'] as $type) {
            $matches = array_values(array_filter($resolved, static fn (array $target): bool => $target['type'] === $type));
            $ambiguousMatches = array_values(array_filter($ambiguous, static fn (mixed $item): bool => is_array($item) && ($item['type'] ?? null) === $type));
            if ($ambiguousMatches !== [] || count($matches) > 1) {
                $diagnostics[] = 'AMBIGUOUS_SUBJECT';
                return null;
            }
            if ($matches !== []) return $matches[0];
        }
        $diagnostics[] = 'NO_SUPPORTED_SUBJECT';
        return null;
    }

    /** @param array<string,mixed> $candidate @return bool */
    private function candidateReady(array $candidate): bool
    {
        if (in_array($candidate['classification'] ?? '', ['new_claim'], true)) return true;
        if (($candidate['classification'] ?? '') === 'add_evidence') return ($candidate['provenance']['source_id'] ?? '') !== '' && ($candidate['provenance']['source_revision'] ?? null) !== null;
        return false;
    }

    private function proposalReady(array $candidates): bool { foreach ($candidates as $candidate) if ($this->candidateReady($candidate)) return true; return false; }

    /** @return array<string,mixed> */
    private function serialize(KnowledgeEnrichmentCandidate $candidate): array
    {
        $profile = $candidate->profile->toMetadata();
        $provenance = $candidate->provenance;
        return ['classification' => $candidate->classification, 'subject_id' => $candidate->subjectId, 'facet' => $profile['facet'], 'scope' => $profile['scope'], 'observation' => $candidate->observation, 'provenance' => $provenance, 'provenance_summary' => ['origin' => $provenance['origin'] ?? null, 'source_id' => $provenance['source_id'] ?? null, 'source_revision' => $provenance['source_revision'] ?? null, 'locator' => $provenance['locator'] ?? null], 'proposal_ready' => $this->candidateReady(['classification' => $candidate->classification, 'provenance' => $provenance])];
    }

    private function scopeFor(string $type): string
    {
        return in_array($type, ['brand', 'model', 'variant', 'movement'], true) ? $type : ($type === 'specimen' ? 'specimen_observation' : 'entity');
    }

    private function contains(string $haystack, string $needle): bool
    {
        $needle = $this->normalize($needle);
        return $needle !== '' && str_contains($haystack, $needle);
    }

    private function normalize(string $value): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }
}
