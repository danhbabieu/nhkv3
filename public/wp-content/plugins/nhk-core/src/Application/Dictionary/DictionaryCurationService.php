<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

use NHK\Core\Contracts\Dictionary\{DictionaryCandidateRepository, DictionaryConceptRepository};
use NHK\Core\Domain\Dictionary\{DictionaryCandidate, DictionaryCandidateState, DictionaryConcept, DictionaryLabel};
use NHK\Core\Shared\Uuid\UuidCodec;

final class DictionaryCurationService
{
    private DictionaryTermNormalizer $normalizer;

    public function __construct(private DictionaryCandidateRepository $candidates, private DictionaryConceptRepository $concepts, private $idGenerator = null, ?DictionaryTermNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new DictionaryTermNormalizer();
    }

    public function createDraftFromCandidate(string $candidateId, int $expectedRevision, string $preferredLabel, string $definition, array $context = []): array
    {
        $candidate = $this->requireCandidate($candidateId, $expectedRevision);
        if ($candidate->suppressed()) throw new \RuntimeException('DICTIONARY_CANDIDATE_SUPPRESSED');
        $preferredLabel = trim($preferredLabel);
        if ($preferredLabel === '') throw new \InvalidArgumentException('DICTIONARY_PREFERRED_LABEL_REQUIRED');
        $concept = new DictionaryConcept($this->id(), $preferredLabel, trim($definition), DictionaryConcept::DRAFT, null, null, null, array_merge($candidate->context, $context));
        $concept = $this->concepts->createConcept($concept);
        $this->concepts->addLabel(new DictionaryLabel($concept->conceptId, $preferredLabel, $this->normalizer->normalize($preferredLabel), DictionaryLabel::PREFERRED, 'vi-VN', $candidate->context));
        foreach ($candidate->rawForms as $raw) {
            $raw = trim((string) $raw);
            if ($raw === '' || $this->normalizer->normalize($raw) === $this->normalizer->normalize($preferredLabel)) continue;
            $this->concepts->addLabel(new DictionaryLabel($concept->conceptId, $raw, $this->normalizer->normalize($raw), DictionaryLabel::ALTERNATE, 'vi-VN', $candidate->context));
        }
        $updated = $this->withState($candidate, DictionaryCandidateState::PROPOSED_NEW, ['concept_id' => $concept->conceptId]);
        return ['concept' => $concept, 'candidate' => $this->candidates->saveDecision($updated, $expectedRevision)];
    }

    public function attachToExisting(string $candidateId, int $expectedRevision, string $conceptId, string $labelKind = DictionaryLabel::ALTERNATE, ?string $locale = 'vi-VN'): array
    {
        $candidate = $this->requireCandidate($candidateId, $expectedRevision);
        $concept = $this->concepts->findById($conceptId);
        if ($concept === null || !$concept->approved()) throw new \RuntimeException('DICTIONARY_APPROVED_CONCEPT_REQUIRED');
        $raw = trim((string) ($candidate->rawForms[0] ?? $candidate->normalizedTerm));
        $label = $this->concepts->addLabel(new DictionaryLabel($concept->conceptId, $raw, $candidate->normalizedTerm, $labelKind, $locale, $candidate->context));
        $updated = $this->withState($candidate, DictionaryCandidateState::RESOLVED_EXISTING, ['concept_id' => $concept->conceptId]);
        return ['concept' => $concept, 'label' => $label, 'candidate' => $this->candidates->saveDecision($updated, $expectedRevision)];
    }

    public function decide(string $candidateId, int $expectedRevision, string $state, array $decision = []): DictionaryCandidate
    {
        if (!in_array($state, [DictionaryCandidateState::AMBIGUOUS, DictionaryCandidateState::REJECTED, DictionaryCandidateState::IGNORED, DictionaryCandidateState::DO_NOT_SUGGEST, DictionaryCandidateState::NEEDS_REVIEW], true)) throw new \InvalidArgumentException('DICTIONARY_DECISION_STATE_NOT_ALLOWED');
        $candidate = $this->requireCandidate($candidateId, $expectedRevision);
        return $this->candidates->saveDecision($this->withState($candidate, $state, $decision), $expectedRevision);
    }

    public function approveConcept(string $conceptId, int $expectedRevision, ?string $destinationType = null, ?string $destinationId = null, ?string $destinationUrl = null, array $context = []): DictionaryConcept
    {
        $current = $this->concepts->findById($conceptId);
        if ($current === null || $current->revision !== $expectedRevision) throw new \RuntimeException('DICTIONARY_CONCEPT_REVISION_CONFLICT');
        $merged = array_merge($current->context, $context);
        if (trim((string) $destinationUrl) === '' && trim((string) ($merged['public_slug'] ?? '')) === '') throw new \RuntimeException('DICTIONARY_PUBLIC_SLUG_OR_OWNER_REQUIRED');
        $approved = new DictionaryConcept($current->conceptId, $current->preferredLabel, $current->definition, DictionaryConcept::APPROVED, $destinationType, $destinationId, $destinationUrl, $merged, $current->revision);
        return $this->concepts->updateConcept($approved, $expectedRevision);
    }

    private function requireCandidate(string $candidateId, int $expectedRevision): DictionaryCandidate
    {
        $candidate = $this->candidates->findById($candidateId);
        if ($candidate === null) throw new \RuntimeException('DICTIONARY_CANDIDATE_NOT_FOUND');
        if ($candidate->revision !== $expectedRevision) throw new \RuntimeException('DICTIONARY_CANDIDATE_REVISION_CONFLICT');
        return $candidate;
    }

    private function withState(DictionaryCandidate $candidate, string $state, array $decision): DictionaryCandidate
    {
        return new DictionaryCandidate($candidate->candidateId, $candidate->normalizedTerm, $candidate->contextHash, $candidate->rawForms, $state, $candidate->context, array_merge($candidate->suggestions, ['review_decision' => $decision]), $candidate->occurrences, $candidate->firstSeenAt, gmdate('Y-m-d H:i:s.u'), $candidate->revision + 1);
    }

    private function id(): string
    {
        if (is_callable($this->idGenerator)) return (string) ($this->idGenerator)();
        return UuidCodec::newV7();
    }
}
