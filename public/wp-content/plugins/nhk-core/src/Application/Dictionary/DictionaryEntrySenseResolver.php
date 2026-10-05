<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

use NHK\Core\Contracts\Dictionary\DictionaryEntryRepository;
use NHK\Core\Domain\Dictionary\{DictionaryConcept, LexicalEntry};

final class DictionaryEntrySenseResolver
{
    public function __construct(private DictionaryEntryRepository $entries, private $destinationValidator = null, private ?DictionaryTermNormalizer $normalizer = null)
    {
        $this->normalizer ??= new DictionaryTermNormalizer();
    }

    public function resolve(string $term, array $context = []): array
    {
        $normalized = $this->normalizer->normalize($term);
        if ($normalized === '') return ['status' => 'UNKNOWN', 'term' => $term, 'normalized_term' => ''];

        $entries = array_values(array_filter(
            $this->entries->findByForm($normalized, $context),
            static fn (mixed $entry): bool => $entry instanceof LexicalEntry && $entry->status === DictionaryConcept::APPROVED,
        ));
        $senses = [];
        $rawSenseCount = 0;
        foreach ($entries as $entry) {
            $raw = $this->entries->listSenses($entry, []);
            $rawSenseCount += count($raw);
            foreach ($this->entries->listSenses($entry, $context) as $sense) {
                if (!$sense instanceof DictionaryConcept || !$sense->approved()) continue;
                $senses[$sense->conceptId] = [$entry, $sense];
            }
        }
        if (count($senses) !== 1) {
            return ['status' => ($senses !== [] || $rawSenseCount > 1) ? 'AMBIGUOUS' : 'UNKNOWN', 'term' => $term, 'normalized_term' => $normalized, 'candidates' => array_values(array_map(static fn (array $pair): array => ['entry_id' => $pair[0]->entryId, 'sense_id' => $pair[1]->conceptId, 'preferred_label' => $pair[1]->preferredLabel], $senses))];
        }

        [$entry, $sense] = array_values($senses)[0];
        $destinationType = null;
        $destinationId = null;
        $semanticReference = null;
        if (method_exists($this->entries, 'semanticReference')) {
            try {
                $semanticReference = $this->entries->semanticReference($entry->entryId, $sense->conceptId);
                $referenceStatus = strtoupper((string) ($semanticReference['status'] ?? 'ABSENT'));
                if ($referenceStatus !== 'ABSENT') {
                    $destinationType = trim((string) ($semanticReference['type'] ?? '')) ?: null;
                    $destinationId = trim((string) ($semanticReference['id'] ?? '')) ?: null;
                    if (!in_array($referenceStatus, ['AVAILABLE', 'PRESENT_VALID'], true) || $destinationType === null || $destinationId === null) {
                        return ['status' => 'UNKNOWN', 'term' => $term, 'normalized_term' => $normalized, 'reason' => 'DICTIONARY_SEMANTIC_REFERENCE_INVALID'];
                    }
                }
            } catch (\Throwable) {
                return ['status' => 'UNKNOWN', 'term' => $term, 'normalized_term' => $normalized, 'reason' => 'DICTIONARY_SEMANTIC_REFERENCE_UNAVAILABLE'];
            }
        }

        if ($destinationType === null || $destinationId === null) {
            $destinationType = trim((string) ($sense->destinationType ?? '')) ?: null;
            $destinationId = trim((string) ($sense->destinationId ?? '')) ?: null;
        }

        $url = null;
        if ($destinationType !== null && $destinationId !== null && is_callable($this->destinationValidator)) {
            try {
                $validated = ($this->destinationValidator)($destinationType, $destinationId, $sense->destinationUrl);
                if ($validated === false) return ['status' => 'UNKNOWN', 'term' => $term, 'normalized_term' => $normalized, 'reason' => 'DICTIONARY_SEMANTIC_REFERENCE_INVALID'];
                $url = is_string($validated) && trim($validated) !== '' ? trim($validated) : null;
            } catch (\Throwable) {
                $url = null;
            }
        }

        if ($destinationType === null || $destinationId === null) {
            $slug = trim((string) ($entry->context['public_slug'] ?? ''));
            if ($entry->status !== DictionaryConcept::APPROVED || $slug === '') {
                return ['status' => 'UNKNOWN', 'term' => $term, 'normalized_term' => $normalized, 'reason' => 'DICTIONARY_ENTRY_PUBLIC_IDENTITY_MISSING'];
            }
            $destinationType = 'dictionary';
            $destinationId = $entry->entryId;
            $url = '/tu-dien/' . $slug . '/';
        }
        return ['status' => 'RESOLVED', 'term' => $term, 'normalized_term' => $normalized, 'entry_id' => $entry->entryId, 'sense_id' => $sense->conceptId, 'preferred_label' => $sense->preferredLabel, 'destination_type' => $destinationType, 'destination_id' => $destinationId, 'destination_url' => $url, 'context' => $context];
    }
}
