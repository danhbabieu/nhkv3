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

        $labels = array_values(array_filter(
            $this->rows(($this->approvedLabelLookup)($normalized, $context)),
            fn (array $row): bool => $this->labelAppliesToContext($row, $context),
        ));
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
        $destinationType = trim((string) ($row['destination_type'] ?? $row['type'] ?? ''));
        $destinationId = trim((string) ($row['destination_id'] ?? $row['id'] ?? ''));
        $preferredLabel = trim((string) ($row['preferred_label'] ?? $row['name'] ?? ''));
        return new DictionaryResolution(
            DictionaryResolution::RESOLVED,
            $term,
            $normalized,
            isset($row['concept_id']) ? (string) $row['concept_id'] : null,
            $preferredLabel !== '' ? $preferredLabel : null,
            $destinationType !== '' ? $destinationType : null,
            $destinationId !== '' ? $destinationId : null,
            isset($row['destination_url']) ? (string) $row['destination_url'] : null,
            [$row],
            $context,
        );
    }

    /**
     * Curation/provenance metadata is not lexical identity. Only the bounded
     * scope fields in the Dictionary contract participate in applicability.
     */
    private function labelAppliesToContext(array $row, array $requested): bool
    {
        $labelLocale = trim((string) ($row['locale'] ?? ''));
        $requestedLocale = trim((string) ($requested['lexical_locale'] ?? ''));
        if ($labelLocale !== '' && $requestedLocale !== '' && strcasecmp($labelLocale, $requestedLocale) !== 0) return false;

        $labelContext = is_array($row['context'] ?? null) ? $row['context'] : [];
        foreach (['domain', 'region', 'community', 'usage_scope'] as $key) {
            if (!array_key_exists($key, $labelContext) || $labelContext[$key] === null || $labelContext[$key] === '') continue;
            if (!array_key_exists($key, $requested) || $requested[$key] === null || $requested[$key] === '' || $requested[$key] !== $labelContext[$key]) return false;
        }
        return true;
    }
}
