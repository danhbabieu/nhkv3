<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

use NHK\Core\Domain\Dictionary\DictionaryConcept;

/** Resolves only explicit, governed evidence; lexical similarity is never enough. */
final class DictionaryEnrichmentOwnerResolver
{
    public function __construct(private readonly mixed $canonicalResolver = null) {}

    /** @return array{classification:string,target:?array,evidence:list<mixed>,reason:string} */
    public function resolve(DictionaryConcept $sense, array $context = []): array
    {
        $explicit = $context['explicit_legacy_destination'] ?? null;
        if (is_array($explicit) && trim((string) ($explicit['type'] ?? '')) !== '' && trim((string) ($explicit['id'] ?? '')) !== '') {
            return $this->exact($explicit, ['explicit_legacy_destination'], 'existing explicit legacy destination');
        }
        if (isset($context['label_similarity'])) return ['classification' => count((array) $context['label_similarity']) > 1 ? 'AMBIGUOUS' : 'NO_OWNER', 'target' => null, 'evidence' => ['label_similarity'], 'reason' => 'label similarity is not governed evidence'];
        $mapping = $context['semantic_reference'] ?? null;
        if (is_array($mapping) && in_array(strtoupper((string) ($mapping['status'] ?? '')), ['INVALID', 'STALE'], true)) return ['classification' => 'NO_OWNER', 'target' => null, 'evidence' => ['existing_governed_mapping'], 'reason' => 'existing semantic reference is invalid or stale'];
        if (is_array($mapping) && in_array(strtoupper((string) ($mapping['status'] ?? '')), ['PRESENT_VALID', 'AVAILABLE'], true) && trim((string) ($mapping['type'] ?? '')) !== '' && trim((string) ($mapping['id'] ?? '')) !== '') return $this->exact($mapping, ['existing_governed_mapping'], 'existing mapping-level semantic reference');
        if ($sense->destinationType !== null && $sense->destinationId !== null && trim($sense->destinationType) !== '' && trim($sense->destinationId) !== '') {
            return $this->exact(['type' => $sense->destinationType, 'id' => $sense->destinationId], ['explicit_legacy_destination'], 'existing explicit legacy destination');
        }
        foreach (['exact_stable_identity', 'exact_approved_alias', 'curated_external_terminology', 'governed_provenance', 'unique_resolver_result'] as $key) {
            $value = $context[$key] ?? null;
            if (is_array($value) && isset($value['type'], $value['id'])) return $this->exact($value, [$key], $key);
            if (is_array($value) && count($value) === 1 && isset($value[0]['type'], $value[0]['id'])) return $this->exact($value[0], [$key], $key);
        }
        if (is_callable($this->canonicalResolver)) {
            $canonicalResolver = $this->canonicalResolver;
            $matches = array_values(array_filter((array) ($canonicalResolver($sense->preferredLabel) ?? []), static function (mixed $match): bool {
                if (!is_array($match) || trim((string) ($match['type'] ?? '')) === '' || trim((string) ($match['id'] ?? '')) === '') return false;
                return in_array((string) ($match['match_class'] ?? ''), [
                    'EXACT_CANONICAL_IDENTITY', 'EXACT_STABLE_KEY', 'EXACT_CANONICAL_NAME',
                    'EXACT_NORMALIZED_NAME_OR_ALIAS', 'EXACT_COMPOSITE_IDENTITY',
                ], true);
            }));
            if (count($matches) === 1) return $this->exact($matches[0], ['unique_resolver_result'], 'unique canonical resolver result');
            if (count($matches) > 1) return ['classification' => 'AMBIGUOUS', 'target' => null, 'evidence' => ['unique_resolver_result'], 'reason' => 'canonical resolver returned multiple exact owners'];
        }
        return ['classification' => 'NO_OWNER', 'target' => null, 'evidence' => [], 'reason' => 'no strong owner evidence'];
    }

    /** @param array<string,mixed> $target @param list<string> $evidence */
    private function exact(array $target, array $evidence, string $reason): array
    {
        return ['classification' => 'EXACT_UNIQUE', 'target' => ['type' => (string) $target['type'], 'id' => (string) $target['id'], 'revision' => $target['revision'] ?? null], 'evidence' => $evidence, 'reason' => $reason];
    }
}
