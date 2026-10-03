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

        $entries = array_values(array_filter($this->entries->findByForm($normalized, $context), static fn (mixed $entry): bool => $entry instanceof LexicalEntry));
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
        $url = null;
        if ($sense->destinationType !== null && $sense->destinationId !== null && is_callable($this->destinationValidator)) {
            try { $validated = ($this->destinationValidator)($sense->destinationType, $sense->destinationId, $sense->destinationUrl); if ($validated === false) return ['status' => 'UNKNOWN', 'term' => $term, 'normalized_term' => $normalized, 'reason' => 'DICTIONARY_SEMANTIC_REFERENCE_INVALID']; $url = is_string($validated) && trim($validated) !== '' ? trim($validated) : null; }
            catch (\Throwable) { $url = null; }
        }
        return ['status' => 'RESOLVED', 'term' => $term, 'normalized_term' => $normalized, 'entry_id' => $entry->entryId, 'sense_id' => $sense->conceptId, 'preferred_label' => $sense->preferredLabel, 'destination_type' => $sense->destinationType, 'destination_id' => $sense->destinationId, 'destination_url' => $url, 'context' => $context];
    }
}
