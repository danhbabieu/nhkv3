<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/**
 * Ephemeral result of the shared language/structure/trust interpretation
 * stage. It deliberately has no canonical identifier and is never persisted.
 */
final readonly class StructuredInterpretationPacket
{
    /** @param array<string,mixed> $value */
    private function __construct(private array $value)
    {
    }

    /** @param array<string,mixed> $value */
    public static function fromArray(array $value): self
    {
        $defaults = [
            'status' => 'UNRESOLVED',
            'source_context' => [],
            'raw_input_reference' => null,
            'locale' => 'vi-VN',
            'lexical_spans' => [],
            'proper_name_spans' => [],
            'identifier_spans' => [],
            'configuration_spans' => [],
            'technical_term_spans' => [],
            'resolved_references' => [],
            'unresolved_terms' => [],
            'ambiguous_terms' => [],
            'subject_candidates' => [],
            'attribute_candidates' => [],
            'claim_candidates' => [],
            'relation_candidates' => [],
            'scope_signals' => [],
            'provenance_signals' => [],
            'evidence_signals' => [],
            'uncertainty_signals' => [],
            'editorial_signals' => [],
            'reuse_matches' => [],
            'dictionary_delta_candidates' => [],
            'dictionary_owner_command' => null,
            'knowledge_delta_candidates' => [],
            'relation_delta_candidates' => [],
            'semantic_query_seeds' => [],
            'diagnostics' => [],
            'outcomes' => [],
        ];
        return new self($value + $defaults);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->value;
    }
}
