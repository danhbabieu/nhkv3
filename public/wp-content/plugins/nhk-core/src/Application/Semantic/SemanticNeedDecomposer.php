<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Decomposes transient input into registered, subject-bound semantic needs. */
final class SemanticNeedDecomposer
{
    public function __construct(private TextInputInterpreter $interpreter, private ?object $facetVocabulary = null)
    {
    }

    public function decompose(SemanticInputEnvelope $envelope): SemanticNeedDecompositionResult
    {
        $input = $envelope->toArray();
        $resolution = is_array($input['subject_resolution'] ?? null) ? $input['subject_resolution'] : [];
        $subject = is_array($resolution['primary'] ?? null) ? $resolution['primary'] : [];
        $subjectId = trim((string) ($subject['id'] ?? $subject['canonical_subject_id'] ?? ''));
        $subjectType = trim((string) ($subject['type'] ?? $subject['entity_type'] ?? ''));
        if ($subjectId === '' || $subjectType === '') {
            return new SemanticNeedDecompositionResult([], [['reason' => 'CANONICAL_SUBJECT_REQUIRED']], ['CANONICAL_SUBJECT_REQUIRED'], $input);
        }

        $interpretation = $this->interpreter->interpret(
            (string) ($input['raw_text'] ?? ''),
            [],
            (array) ($input['subject_hints'] ?? []),
            (array) ($input['metadata'] ?? []),
        );
        $needs = [];
        $unresolved = [];
        $diagnostics = [];
        $seen = [];
        $components = array_merge((array) ($input['components'] ?? []), (array) ($input['observations'] ?? []));
        foreach ($components as $component) {
            if (!is_array($component)) {
                continue;
            }
            $facet = $this->normalize((string) ($component['facet_key'] ?? $component['facet'] ?? ''));
            $concept = $this->normalize((string) ($component['concept_key'] ?? $component['concept'] ?? ''));
            if ($facet === '' && $concept === '') {
                $unresolved[] = ['reason' => 'SEMANTIC_PRIMITIVE_MISSING', 'component' => $this->trace($component)];
                continue;
            }
            if (!$this->registered($facet, $concept)) {
                $unresolved[] = ['reason' => 'UNREGISTERED_SEMANTIC_PRIMITIVE', 'facet_key' => $facet, 'concept_key' => $concept];
                continue;
            }
            $origin = strtoupper(trim((string) ($component['origin'] ?? 'MACHINE_DERIVED')));
            $scope = $this->normalize((string) ($component['scope'] ?? ($origin === 'SPECIMEN_OBSERVATION' ? 'specimen' : 'unresolved')));
            $need = SemanticNeed::fromArray([
                'canonical_subject' => $subject,
                'concept_key' => $concept,
                'facet_key' => $facet,
                'scope' => $scope,
                'intent' => $this->normalize((string) ($component['intent'] ?? $input['content_intent'] ?? '')),
                'origin' => $origin,
                'confidence' => min(1.0, max(0.0, (float) ($component['confidence'] ?? ($origin === 'MACHINE_DERIVED' ? 0.5 : 0.8)))),
                'evidence_requirement' => strtoupper(trim((string) ($component['evidence_requirement'] ?? 'SUPPORTED_WITHIN_SCOPE'))),
                'retrieval_policy' => is_array($component['retrieval_policy'] ?? null) ? $component['retrieval_policy'] : [],
                'relaxation_policy' => is_array($component['relaxation_policy'] ?? null) ? $component['relaxation_policy'] : [],
            ]);
            if (isset($seen[$need->needId()])) {
                $diagnostics[] = 'DUPLICATE_NEED_COLLAPSED';
                continue;
            }
            $seen[$need->needId()] = true;
            $needs[] = $need;
        }

        return new SemanticNeedDecompositionResult($needs, $unresolved, array_values(array_unique($diagnostics)), $interpretation + ['subject_resolution' => ['primary' => $subject]]);
    }

    private function registered(string $facet, string $concept): bool
    {
        if ($facet === '' && $concept === '') {
            return false;
        }
        if ($this->facetVocabulary === null) {
            return false;
        }
        if (method_exists($this->facetVocabulary, 'isRegistered')) {
            return (bool) $this->facetVocabulary->isRegistered($facet, $concept);
        }
        if (method_exists($this->facetVocabulary, 'has')) {
            return (bool) $this->facetVocabulary->has($facet, $concept);
        }
        return false;
    }

    /** @param array<string,mixed> $component @return array<string,mixed> */
    private function trace(array $component): array
    {
        return array_intersect_key($component, array_flip(['facet_key', 'concept_key', 'facet', 'concept', 'scope', 'origin']));
    }

    private function normalize(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/u', '_', $value) ?? $value;
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }
}

final readonly class SemanticNeedDecompositionResult
{
    /** @param list<SemanticNeed> $needs @param list<array<string,mixed>> $unresolved @param list<string> $diagnostics @param array<string,mixed> $interpretation */
    public function __construct(private array $needs, private array $unresolved, private array $diagnostics, private array $interpretation)
    {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'needs' => array_map(static fn (SemanticNeed $need): array => $need->toArray(), $this->needs),
            'unresolved' => $this->unresolved,
            'diagnostics' => $this->diagnostics,
            'interpretation' => $this->interpretation,
        ];
    }
}
