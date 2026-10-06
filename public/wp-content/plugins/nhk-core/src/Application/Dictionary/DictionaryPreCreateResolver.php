<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

use NHK\Core\Contracts\Dictionary\DictionaryEntryRepository;
use NHK\Core\Domain\Dictionary\{DictionaryConcept, DictionaryPreCreateResolution, LexicalEntry};

final class DictionaryPreCreateResolver
{
    private DictionaryTermNormalizer $normalizer;

    /** @param callable(string,array):bool|null $suppressionLookup */
    public function __construct(private DictionaryEntryRepository $entries, ?DictionaryTermNormalizer $normalizer = null, private $suppressionLookup = null)
    {
        $this->normalizer = $normalizer ?? new DictionaryTermNormalizer();
    }

    public function resolveEntryCreate(string $preferredForm, array $context = [], ?string $semanticType = null, ?string $semanticId = null): DictionaryPreCreateResolution
    {
        $normalized = $this->requiredNormalized($preferredForm);
        try {
            $entries = method_exists($this->entries, 'findPreCreateCandidates')
                ? $this->entries->findPreCreateCandidates($normalized, $context)
                : $this->entries->findByForm($normalized, $context);
            if (!is_array($entries)) return $this->review($normalized, $context, [], [], 'DICTIONARY_PRE_CREATE_DEPENDENCY_UNAVAILABLE');
            if (is_callable($this->suppressionLookup) && (bool) ($this->suppressionLookup)($normalized, $context)) {
                return $this->review($normalized, $context, [], [], 'SUPPRESSED_CANDIDATE');
            }

            $candidates = [];
            $revisions = [];
            $retired = [];
            $contextMismatch = [];
            $mappingIssues = [];
            foreach ($entries as $entry) {
                if (!$entry instanceof LexicalEntry) continue;
                $allSenses = $this->entries->listSenses($entry, []);
                $matchingSenses = $context === [] ? $allSenses : $this->entries->listSenses($entry, $context);
                $this->recordRevision($revisions, 'entry:' . $entry->entryId, $entry->revision);
                if ($entry->status === DictionaryConcept::RETIRED) {
                    $retired[] = $this->entryCandidate($entry, null, 'RETIRED');
                    continue;
                }
                if ($context !== [] && $allSenses !== [] && $matchingSenses === []) {
                    $contextMismatch[] = $this->entryCandidate($entry, null, 'CONTEXT_MISMATCH');
                    continue;
                }
                foreach ($matchingSenses as $sense) {
                    if (!$sense instanceof DictionaryConcept) continue;
                    $this->recordRevision($revisions, 'sense:' . $sense->conceptId, $sense->revision);
                    $candidate = $this->entryCandidate($entry, $sense, 'ACTIVE');
                    if (in_array((string) ($candidate['semantic_reference']['status'] ?? ''), ['INVALID', 'UNAVAILABLE_IMPLEMENTATION_GAP'], true)) {
                        $mappingIssues[] = $candidate;
                        continue;
                    }
                    if ($sense->status === DictionaryConcept::RETIRED) {
                        $candidate['status'] = 'RETIRED_SENSE';
                        $retired[] = $candidate;
                        continue;
                    }
                    $candidates[] = $candidate;
                }
            }

            if ($candidates === []) {
                if ($mappingIssues !== []) return $this->review($normalized, $context, $mappingIssues, $revisions, 'SEMANTIC_REFERENCE_UNAVAILABLE');
                if ($retired !== []) return $this->review($normalized, $context, $retired, $revisions, 'RETIRED_CANDIDATE');
                if ($contextMismatch !== []) return $this->review($normalized, $context, $contextMismatch, $revisions, 'CONTEXT_MISMATCH');
                return DictionaryPreCreateResolution::fromDecision(DictionaryPreCreateResolution::CREATE_NEW, $normalized, $context, [], $revisions, ['reason' => 'NO_APPLICABLE_CANDIDATE']);
            }
            if ($mappingIssues !== []) return $this->review($normalized, $context, array_merge($candidates, $mappingIssues), $revisions, 'SEMANTIC_REFERENCE_UNAVAILABLE');

            if ($semanticType !== null || $semanticId !== null) {
                $ownerMatched = array_values(array_filter($candidates, fn (array $candidate): bool => $this->ownerMatches($candidate, $semanticType, $semanticId)));
                if ($ownerMatched !== []) $candidates = $ownerMatched;
                else return $this->review($normalized, $context, $candidates, $revisions, 'SEMANTIC_OWNER_CONFLICT');
            }

            $entryIds = array_values(array_unique(array_map(static fn (array $candidate): string => $candidate['entry_id'], $candidates)));
            if (count($entryIds) !== 1 || count($candidates) !== 1) return $this->review($normalized, $context, $candidates, $revisions, 'MULTIPLE_CONTEXTUAL_SENSES');
            return DictionaryPreCreateResolution::fromDecision(DictionaryPreCreateResolution::REUSE_EXISTING, $normalized, $context, $candidates, $revisions, ['reason' => 'EXACT_OR_ALTERNATE_FORM_MATCH']);
        } catch (\Throwable) {
            return $this->review($normalized, $context, [], [], 'DICTIONARY_PRE_CREATE_DEPENDENCY_UNAVAILABLE');
        }
    }

    public function resolveFormAddition(string $entryId, string $form, array $context = []): DictionaryPreCreateResolution
    {
        $normalized = $this->requiredNormalized($form);
        $target = $this->findEntry($entryId);
        if (!$target instanceof LexicalEntry) return $this->review($normalized, $context, [], [], 'DICTIONARY_ENTRY_NOT_FOUND');
        try {
            $matches = $this->entries->findByForm($normalized, $context);
            if (!is_array($matches)) return $this->review($normalized, $context, [], ['entry:' . $entryId => $target->revision], 'DICTIONARY_PRE_CREATE_DEPENDENCY_UNAVAILABLE');
            $candidates = [];
            $revisions = ['entry:' . $entryId => $target->revision];
            foreach ($matches as $entry) {
                if (!$entry instanceof LexicalEntry) continue;
                $this->recordRevision($revisions, 'entry:' . $entry->entryId, $entry->revision);
                $candidates[] = $this->entryCandidate($entry, null, $entry->entryId === $entryId ? 'ACTIVE_TARGET' : 'ACTIVE_COLLISION');
            }
            if ($normalized === $target->normalizedPreferredForm || count(array_filter($candidates, static fn (array $candidate): bool => $candidate['entry_id'] === $entryId)) > 0) {
                return DictionaryPreCreateResolution::fromDecision(DictionaryPreCreateResolution::REUSE_EXISTING, $normalized, $context, $candidates, $revisions, ['reason' => 'FORM_ALREADY_ON_TARGET']);
            }
            if ($candidates !== []) return $this->review($normalized, $context, $candidates, $revisions, 'FORM_COLLIDES_WITH_OTHER_ENTRY');
            return DictionaryPreCreateResolution::fromDecision(DictionaryPreCreateResolution::ADD_FORM_TO_ENTRY, $normalized, $context, [['entry_id' => $entryId, 'revision' => $target->revision]], $revisions, ['reason' => 'NO_FORM_COLLISION']);
        } catch (\Throwable) {
            return $this->review($normalized, $context, [], ['entry:' . $entryId => $target->revision], 'DICTIONARY_PRE_CREATE_DEPENDENCY_UNAVAILABLE');
        }
    }

    public function resolveSenseAddition(string $entryId, string $conceptId, array $context = []): DictionaryPreCreateResolution
    {
        $target = $this->findEntry($entryId);
        if (!$target instanceof LexicalEntry) return $this->review($conceptId, $context, [], [], 'DICTIONARY_ENTRY_NOT_FOUND');
        try {
            $revisions = ['entry:' . $entryId => $target->revision];
            $senses = $this->entries->listSenses($target, []);
            foreach ($senses as $sense) if ($sense instanceof DictionaryConcept) {
                $this->recordRevision($revisions, 'sense:' . $sense->conceptId, $sense->revision);
                if ($sense->conceptId === $conceptId) return DictionaryPreCreateResolution::fromDecision(DictionaryPreCreateResolution::REUSE_EXISTING, $conceptId, $context, [['entry_id' => $entryId, 'sense_id' => $conceptId]], $revisions, ['reason' => 'SENSE_ALREADY_MAPPED']);
            }
            $existingEntry = $this->entries->findForConcept($conceptId);
            if ($existingEntry instanceof LexicalEntry && $existingEntry->entryId !== $entryId) {
                $this->recordRevision($revisions, 'entry:' . $existingEntry->entryId, $existingEntry->revision);
                return $this->review($conceptId, $context, [['entry_id' => $existingEntry->entryId, 'sense_id' => $conceptId]], $revisions, 'SENSE_ALREADY_MAPPED_TO_OTHER_ENTRY');
            }
            return DictionaryPreCreateResolution::fromDecision(DictionaryPreCreateResolution::ADD_SENSE_TO_ENTRY, $conceptId, $context, [['entry_id' => $entryId, 'sense_id' => $conceptId]], $revisions, ['reason' => 'SENSE_NOT_MAPPED_TO_TARGET']);
        } catch (\Throwable) {
            return $this->review($conceptId, $context, [], ['entry:' . $entryId => $target->revision], 'DICTIONARY_PRE_CREATE_DEPENDENCY_UNAVAILABLE');
        }
    }

    private function findEntry(string $entryId): ?LexicalEntry
    {
        if (!method_exists($this->entries, 'findById')) return null;
        $entry = $this->entries->findById($entryId);
        return $entry instanceof LexicalEntry ? $entry : null;
    }

    private function requiredNormalized(string $term): string
    {
        $normalized = $this->normalizer->normalize(trim($term));
        if ($normalized === '') throw new \InvalidArgumentException('DICTIONARY_PRE_CREATE_FORM_REQUIRED');
        return $normalized;
    }

    private function entryCandidate(LexicalEntry $entry, ?DictionaryConcept $sense, string $status): array
    {
        $mapping = $sense !== null ? $this->semanticReference($entry, $sense) : ['status' => 'ABSENT', 'source' => 'NONE'];
        $mappingAvailable = ($mapping['status'] ?? '') === 'AVAILABLE';
        return [
            'entry_id' => $entry->entryId,
            'sense_id' => $sense?->conceptId,
            'entry_revision' => $entry->revision,
            'sense_revision' => $sense?->revision,
            'status' => $status,
            'preferred_form' => $entry->preferredForm,
            'context' => $sense?->context ?? $entry->context,
            'destination_type' => $mappingAvailable ? ($mapping['type'] ?? null) : $sense?->destinationType,
            'destination_id' => $mappingAvailable ? ($mapping['id'] ?? null) : $sense?->destinationId,
            'semantic_reference' => $mapping,
        ];
    }

    /** Mapping is authoritative; legacy Concept destination is an explicit fallback only. */
    private function semanticReference(LexicalEntry $entry, DictionaryConcept $sense): array
    {
        if (!method_exists($this->entries, 'semanticReference')) return ['status' => 'ABSENT', 'source' => 'LEGACY_FALLBACK'];
        $reference = $this->entries->semanticReference($entry->entryId, $sense->conceptId);
        if (!is_array($reference)) return ['status' => 'UNAVAILABLE_IMPLEMENTATION_GAP', 'source' => 'MAPPING'];
        if (($reference['status'] ?? '') === 'AVAILABLE') return $reference + ['source' => 'MAPPING'];
        if (in_array(($reference['status'] ?? ''), ['INVALID', 'UNAVAILABLE_IMPLEMENTATION_GAP'], true)) return $reference;
        return $reference + ['source' => 'LEGACY_FALLBACK'];
    }

    private function ownerMatches(array $candidate, ?string $type, ?string $id): bool
    {
        $type = $type !== null ? trim($type) : null;
        $id = $id !== null ? trim($id) : null;
        return $type !== null && $type !== '' && $id !== null && $id !== '' && $candidate['destination_type'] === $type && $candidate['destination_id'] === $id;
    }

    private function recordRevision(array &$revisions, string $key, int $revision): void
    {
        if ($revision > 0) $revisions[$key] = $revision;
    }

    private function review(string $normalized, array $context, array $candidates, array $revisions, string $reason): DictionaryPreCreateResolution
    {
        return DictionaryPreCreateResolution::fromDecision(DictionaryPreCreateResolution::REVIEW_REQUIRED, $normalized, $context, $candidates, $revisions, ['reason' => $reason]);
    }
}
