<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Immutable transient request for one independently retrievable meaning. */
final readonly class SemanticNeed
{
    /** @param array<string,mixed> $subject @param array<string,mixed> $retrievalPolicy @param array<string,mixed> $relaxationPolicy */
    private function __construct(
        private string $needId,
        private array $subject,
        private string $concept,
        private string $facet,
        private string $scope,
        private string $intent,
        private string $origin,
        private float $confidence,
        private string $evidenceRequirement,
        private array $retrievalPolicy,
        private array $relaxationPolicy,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $subject = is_array($data['canonical_subject'] ?? null) ? $data['canonical_subject'] : [];
        $subjectId = trim((string) ($subject['id'] ?? $subject['canonical_subject_id'] ?? ''));
        $subjectType = trim((string) ($subject['type'] ?? $subject['entity_type'] ?? ''));
        if ($subjectId === '' || $subjectType === '') {
            throw new \InvalidArgumentException('Semantic need requires a canonical subject identity.');
        }

        $concept = self::normalize((string) ($data['concept_key'] ?? ''));
        $facet = self::normalize((string) ($data['facet_key'] ?? ''));
        if ($concept === '' && $facet === '') {
            throw new \InvalidArgumentException('Semantic need requires a concept or facet.');
        }

        $confidence = (float) ($data['confidence'] ?? 0.0);
        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new \InvalidArgumentException('Semantic need confidence must be between 0 and 1.');
        }

        $relaxation = SemanticNeedRetrievalPolicy::normalize(is_array($data['relaxation_policy'] ?? null) ? $data['relaxation_policy'] : []);
        $retrieval = SemanticNeedRetrievalPolicy::normalize(is_array($data['retrieval_policy'] ?? null) ? $data['retrieval_policy'] : []);
        $origin = strtoupper(trim((string) ($data['origin'] ?? 'MACHINE_DERIVED')));
        $scope = self::normalize((string) ($data['scope'] ?? 'unresolved'));
        $intent = self::normalize((string) ($data['intent'] ?? ''));
        $evidence = strtoupper(trim((string) ($data['evidence_requirement'] ?? '')));
        $identity = implode('|', [$subjectId, $subjectType, (string) ($subject['revision'] ?? ''), $concept, $facet, $scope, $origin]);
        $needId = trim((string) ($data['need_id'] ?? '')) ?: 'need:' . hash('sha256', $identity);

        return new self($needId, $subject + ['id' => $subjectId, 'type' => $subjectType], $concept, $facet, $scope, $intent, $origin, $confidence, $evidence, $retrieval, $relaxation);
    }

    public function needId(): string { return $this->needId; }
    /** @return array<string,mixed> */
    public function canonicalSubject(): array { return $this->subject; }
    public function conceptKey(): string { return $this->concept; }
    public function facetKey(): string { return $this->facet; }
    public function scope(): string { return $this->scope; }
    public function intent(): string { return $this->intent; }
    public function origin(): string { return $this->origin; }
    public function confidence(): float { return $this->confidence; }
    public function evidenceRequirement(): string { return $this->evidenceRequirement; }
    /** @return array<string,mixed> */
    public function retrievalPolicy(): array { return $this->retrievalPolicy; }
    /** @return array<string,mixed> */
    public function relaxationPolicy(): array { return $this->relaxationPolicy; }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'need_id' => $this->needId,
            'canonical_subject' => $this->subject,
            'concept_key' => $this->concept,
            'facet_key' => $this->facet,
            'scope' => $this->scope,
            'intent' => $this->intent,
            'origin' => $this->origin,
            'confidence' => $this->confidence,
            'evidence_requirement' => $this->evidenceRequirement,
            'retrieval_policy' => $this->retrievalPolicy,
            'relaxation_policy' => $this->relaxationPolicy,
        ];
    }

    private static function normalize(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/u', '_', $value) ?? $value;
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }
}
