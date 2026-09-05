<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

use NHK\Core\Domain\Dictionary\DictionaryResolution;

final class DictionaryResolver
{
    public function __construct(
        private $approvedLabelLookup,
        private $entityLookup,
        private $knowledgeLookup,
        private $articleLookup,
        private $suppressionLookup,
        private ?DictionaryTermNormalizer $normalizer = null,
    ) {
        $this->normalizer ??= new DictionaryTermNormalizer();
    }

    public function resolve(string $term, array $context = []): DictionaryResolution
    {
        $normalized = $this->normalizer->normalize($term);
        if ($normalized === '') {
            return new DictionaryResolution(DictionaryResolution::UNKNOWN, $term, '', context: $context);
        }

        $labels = $this->rows(($this->approvedLabelLookup)($normalized, $context));
        if (count($labels) > 1) {
            return new DictionaryResolution(DictionaryResolution::AMBIGUOUS, $term, $normalized, candidates: $labels, context: $context);
        }
        if (count($labels) === 1) return $this->fromRow($term, $normalized, $labels[0], $context);

        foreach ([$this->entityLookup, $this->knowledgeLookup, $this->articleLookup] as $lookup) {
            $rows = $this->rows($lookup($normalized, $context));
            if (count($rows) > 1) {
                return new DictionaryResolution(DictionaryResolution::AMBIGUOUS, $term, $normalized, candidates: $rows, context: $context);
            }
            if (count($rows) === 1) return $this->fromRow($term, $normalized, $rows[0], $context);
        }

        if (($this->suppressionLookup)($normalized, $context) === true) {
            return new DictionaryResolution(DictionaryResolution::SUPPRESSED, $term, $normalized, context: $context);
        }

        return new DictionaryResolution(DictionaryResolution::UNKNOWN, $term, $normalized, context: $context);
    }

    private function rows(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    private function fromRow(string $term, string $normalized, array $row, array $context): DictionaryResolution
    {
        return new DictionaryResolution(
            DictionaryResolution::RESOLVED,
            $term,
            $normalized,
            isset($row['concept_id']) ? (string) $row['concept_id'] : null,
            isset($row['preferred_label']) ? (string) $row['preferred_label'] : null,
            isset($row['destination_type']) ? (string) $row['destination_type'] : null,
            isset($row['destination_id']) ? (string) $row['destination_id'] : null,
            isset($row['destination_url']) ? (string) $row['destination_url'] : null,
            [$row],
            $context,
        );
    }
}
