<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

use NHK\Core\Contracts\Dictionary\{DictionaryCandidateRepository, DictionaryMentionRepository};
use NHK\Core\Domain\Dictionary\{DictionaryCandidate, DictionaryCandidateState, DictionaryMention, DictionaryResolution};
use NHK\Core\Shared\Uuid\UuidCodec;

final class DictionaryPlanningService
{
    public function __construct(
        private DictionaryTermDetector $detector,
        private DictionaryResolver $resolver,
        private DictionaryCandidateRepository $candidates,
        private DictionaryMentionRepository $mentions,
        private DictionaryLinkPlanner $links,
        private $idGenerator = null,
    ) {}

    public function plan(
        string $text,
        string $sourceKind,
        string $sourceId,
        array $context = [],
        array $hints = [],
        array $approvedLabels = [],
    ): array {
        $resolved = [];
        $ambiguous = [];
        $candidateTerms = [];
        $warnings = [];
        $linkItems = [];
        $contextHash = $this->hash($context);

        foreach ($this->detector->detect($text, $approvedLabels, $hints) as $observation) {
            $resolution = $this->resolver->resolve((string) $observation['term'], $context);
            $conceptId = $resolution->conceptId;
            $mention = new DictionaryMention(
                $this->id(),
                $this->fingerprint($sourceKind, $sourceId, $resolution->normalizedTerm, $contextHash),
                strtoupper(trim($sourceKind)),
                trim($sourceId),
                $resolution->normalizedTerm,
                $contextHash,
                $conceptId,
                $context,
                (string) $observation['strength'],
                gmdate('Y-m-d H:i:s'),
            );
            $this->mentions->upsert($mention);

            if ($resolution->status === DictionaryResolution::RESOLVED) {
                $row = [
                    'term' => $observation['term'],
                    'normalized_term' => $resolution->normalizedTerm,
                    'concept_id' => $resolution->conceptId,
                    'preferred_label' => $resolution->preferredLabel,
                    'destination_type' => $resolution->destinationType,
                    'destination_id' => $resolution->destinationId,
                    'destination_url' => $resolution->destinationUrl,
                    'origin' => $observation['origin'],
                ];
                $resolved[] = $row;
                if (trim((string) $resolution->destinationUrl) !== '') {
                    $linkItems[] = [
                        'term' => $observation['term'],
                        'concept_id' => $resolution->conceptId ?: $resolution->destinationType . ':' . $resolution->destinationId,
                        'url' => $resolution->destinationUrl,
                    ];
                }
                continue;
            }

            if ($resolution->status === DictionaryResolution::AMBIGUOUS) {
                $ambiguous[] = ['term' => $observation['term'], 'normalized_term' => $resolution->normalizedTerm, 'candidates' => $resolution->candidates];
                continue;
            }

            if ($resolution->status === DictionaryResolution::SUPPRESSED || $this->candidates->suppressed($resolution->normalizedTerm, $contextHash)) {
                $warnings[] = 'DICTIONARY_TERM_SUPPRESSED';
                continue;
            }

            $candidate = new DictionaryCandidate(
                $this->id(),
                $resolution->normalizedTerm,
                $contextHash,
                [(string) $observation['term']],
                DictionaryCandidateState::NEEDS_REVIEW,
                $context,
                [],
                1,
                gmdate('Y-m-d H:i:s'),
                gmdate('Y-m-d H:i:s'),
                1,
            );
            $saved = $this->candidates->upsertObservation($candidate);
            $candidateTerms[] = [
                'candidate_id' => $saved->candidateId,
                'term' => $observation['term'],
                'normalized_term' => $saved->normalizedTerm,
                'state' => $saved->state,
                'occurrences' => $saved->occurrences,
                'origin' => $observation['origin'],
            ];
        }

        return [
            'resolved_terms' => $resolved,
            'ambiguous_terms' => $ambiguous,
            'candidate_terms' => $candidateTerms,
            'internal_link_candidates' => $this->links->plan($text, $linkItems),
            'warnings' => array_values(array_unique($warnings)),
            'blocking' => false,
        ];
    }

    private function id(): string
    {
        if (is_callable($this->idGenerator)) return (string) ($this->idGenerator)();
        if (class_exists(UuidCodec::class)) return UuidCodec::newV7();
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3) . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
    }

    private function hash(array $context): string
    {
        return hash('sha256', json_encode($this->sort($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    private function sort(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value);
        foreach ($value as $key => $item) $value[$key] = $this->sort($item);
        return $value;
    }

    private function fingerprint(string $kind, string $id, string $term, string $contextHash): string
    {
        return hash('sha256', strtoupper(trim($kind)) . "\0" . trim($id) . "\0" . $term . "\0" . $contextHash);
    }
}
