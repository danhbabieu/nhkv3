<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Application\Knowledge\CanonicalDependencyValidator;
use NHK\Core\Application\Semantic\SubjectResolutionService;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Governance\Proposal;
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, NodeReference, PredicateRegistry};
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Pre-apply Video dependency boundary. It is deliberately read-only: repair
 * belongs to VideoProposalReconciliationService, while this class makes a
 * proposal's missing closure visible before Controlled Apply.
 */
final class VideoProposalEligibilityEvaluator
{
    /** @param callable(string,string):bool|null $targetActive */
    public function __construct(
        private VideoRepository $videos,
        private EndpointTypeRegistry $endpoints,
        private PredicateRegistry $predicates,
        private CanonicalDependencyValidator $dependencies,
        private ?SubjectResolutionService $subjectResolver = null,
        private $targetActive = null,
    ) {
    }

    /** @return list<string> */
    public function evaluate(Proposal $proposal): array
    {
        if ($proposal->entityType !== 'video' || $proposal->operation !== 'ingest') return [];
        $metadata = is_array($proposal->payload['metadata'] ?? null) ? $proposal->payload['metadata'] : [];
        $attachments = $metadata['semantic_attachments'] ?? null;
        if (!is_array($attachments) || $attachments === []) return ['NO_SEMANTIC_ATTACHMENT'];

        $reasons = [];
        $packet = is_array($metadata['subject_resolution_packet'] ?? null) ? $metadata['subject_resolution_packet'] : [];
        $subjectId = trim((string) ($packet['id'] ?? ''));
        $subjectType = trim((string) ($packet['type'] ?? ''));
        if (!UuidCodec::isValid($subjectId) || $subjectType === '') $reasons[] = 'SUBJECT_UNRESOLVED';

        $source = is_array($metadata['source'] ?? null) ? $metadata['source'] : [];
        if (array_key_exists('availability', $source) && (string) $source['availability'] !== 'available') $reasons[] = 'SOURCE_UNAVAILABLE';
        $externalId = trim((string) ($source['external_video_id'] ?? ($metadata['source']['external_video_id'] ?? '')));
        $canonicalId = trim((string) ($proposal->payload['canonical_id'] ?? ''));
        if ($externalId !== '') {
            $existing = $this->videos->findByExternalReference((string) ($source['platform'] ?? 'youtube'), $externalId);
            if ($existing !== null && $existing->canonicalId !== $canonicalId) $reasons[] = 'DUPLICATE_CANONICAL_VIDEO';
        }

        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                $reasons[] = 'SUBJECT_UNRESOLVED';
                continue;
            }
            $targetId = trim((string) ($attachment['target_uuid'] ?? ''));
            $targetType = trim((string) ($attachment['target_type'] ?? ''));
            $predicate = trim((string) ($attachment['predicate'] ?? 'about'));
            if (!UuidCodec::isValid($targetId) || $targetType === '') {
                $reasons[] = 'SUBJECT_UNRESOLVED';
            } elseif ($subjectId !== $targetId || $subjectType !== $targetType) {
                $reasons[] = 'SUBJECT_SCOPE_MISMATCH';
            }
            try {
                if (!$this->predicates->get($predicate)->allows('video', $targetType)) throw new \RuntimeException('TARGET_ENDPOINT_UNAVAILABLE');
                $this->endpoints->assertExists(new NodeReference($targetType, $targetId));
                if ($this->targetActive !== null && !(($this->targetActive)($targetType, $targetId))) $reasons[] = 'TARGET_ENDPOINT_UNAVAILABLE';
            } catch (\Throwable) {
                $reasons[] = 'TARGET_ENDPOINT_UNAVAILABLE';
            }

            $refs = $attachment['evidence_refs'] ?? null;
            if (!is_array($refs) || $refs === []) {
                $reasons[] = 'EVIDENCE_REQUIRED';
                continue;
            }
            foreach ($refs as $reference) {
                if (!is_array($reference) || array_keys($reference) !== ['evidence_id'] || !UuidCodec::isValid((string) $reference['evidence_id'])) {
                    $reasons[] = 'CANONICAL_EVIDENCE_REQUIRED';
                    continue;
                }
                try {
                    $evidence = $this->dependencies->evidence((string) $reference['evidence_id']);
                    $evidenceSubject = trim((string) ($evidence->metadata['subject_id'] ?? ''));
                    if ($evidenceSubject !== '' && $evidenceSubject !== $targetId) $reasons[] = 'SUBJECT_SCOPE_MISMATCH';
                } catch (\Throwable $error) {
                    $code = $error instanceof \NHK\Core\Domain\Knowledge\DependencyValidationException ? $error->errorCode : trim((string) $error->getCode());
                    $reasons[] = match ($code) {
                        'SOURCE_NOT_ACTIVE', 'CANONICAL_SOURCE_REQUIRED' => 'SOURCE_UNAVAILABLE',
                        default => $code !== '' && preg_match('/^[A-Z][A-Z0-9_]+$/', $code) === 1 ? $code : 'CANONICAL_EVIDENCE_REQUIRED',
                    };
                }
            }
        }

        if ($this->subjectResolver !== null && is_array($source)) {
            $hints = array_values(array_filter([(string) ($source['source_title'] ?? ''), $this->referenceHint((string) ($source['source_title'] ?? ''))], static fn (string $hint): bool => trim($hint) !== ''));
            if ($hints !== []) {
                $resolved = $this->subjectResolver->resolve($hints);
                $primary = is_array($resolved['primary'] ?? null) ? $resolved['primary'] : null;
                if ($primary === null) $reasons[] = 'SUBJECT_UNRESOLVED';
                elseif ((string) ($primary['id'] ?? '') !== $subjectId || (string) ($primary['type'] ?? '') !== $subjectType) $reasons[] = 'SUBJECT_SCOPE_MISMATCH';
            }
        }

        return array_values(array_unique($reasons));
    }

    private function referenceHint(string $title): string
    {
        return preg_match('/\b\d+\s*\/\s*\d+\b/u', $title, $match) === 1 ? $match[0] : '';
    }
}
