<?php
declare(strict_types=1);

namespace NHK\Core\Application\Knowledge;

use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, KnowledgePreCreateResolution, Source};
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Knowledge-owner identity resolution. This is deliberately deterministic:
 * retrieval may find candidates, but wording similarity alone never proves
 * canonical identity.
 */
final class KnowledgePreCreateResolver
{
    public function __construct(private KnowledgeRepository $claims, private SourceRepository $sources, private EvidenceRepository $evidence)
    {
    }

    public function resolveClaimCreate(string $stableKey, string $text, string $type = 'fact', array $provenance = []): KnowledgePreCreateResolution
    {
        $stableKey = trim($stableKey);
        $text = trim($text);
        $metadata = is_array($provenance['metadata'] ?? null) ? $provenance['metadata'] : [];
        $normalized = $this->normalizeText($text);
        $input = ['stable_key' => $stableKey, 'claim_text' => $normalized, 'claim_type' => $type, 'context' => $this->claimContext($metadata)];
        if ($stableKey === '' || $text === '') return $this->review('claim', 'create', $input, [], [], 'KNOWLEDGE_PRE_CREATE_INPUT_INVALID');

        try {
            $claims = $this->claims->list(true);
            $sameKey = array_values(array_filter($claims, static fn (mixed $claim): bool => $claim instanceof KnowledgeClaim && $claim->stableKey === $stableKey));
            foreach ($sameKey as $claim) {
                $candidate = $this->claimCandidate($claim, 'STABLE_KEY_MATCH');
                if (!$claim->active) return $this->review('claim', 'create', $input, [$candidate], $this->claimRevisions([$claim]), 'RETIRED_STABLE_KEY_CANDIDATE');
                if ($this->claimEquivalent($claim, $normalized, $type, $metadata)) return $this->resolution('claim', 'create', KnowledgePreCreateResolution::REUSE_EXISTING, $input, [$candidate], $this->claimRevisions([$claim]), 'EXACT_STABLE_KEY_IDENTITY');
                return $this->review('claim', 'create', $input, [$candidate], $this->claimRevisions([$claim]), 'STABLE_KEY_COLLISION');
            }

            $exact = [];
            $scoped = [];
            foreach ($claims as $claim) {
                if (!$claim instanceof KnowledgeClaim || $claim->claimType !== $type) continue;
                $claimMetadata = is_array($claim->provenance['metadata'] ?? null) ? $claim->provenance['metadata'] : [];
                if ($this->sameClaimContext($claimMetadata, $metadata)) $scoped[] = $claim;
                if ($this->normalizeText($claim->claimText) === $normalized && $this->sameClaimContext($claimMetadata, $metadata)) $exact[] = $claim;
            }
            if (count($exact) === 1) {
                $claim = $exact[0];
                $candidate = $this->claimCandidate($claim, 'DETERMINISTIC_EXACT_PROPOSITION');
                if (!$claim->active) return $this->review('claim', 'create', $input, [$candidate], $this->claimRevisions([$claim]), 'RETIRED_EQUIVALENT_CANDIDATE');
                return $this->resolution('claim', 'create', KnowledgePreCreateResolution::REUSE_EXISTING, $input, [$candidate], $this->claimRevisions([$claim]), 'EXACT_SCOPED_PROPOSITION');
            }
            if (count($exact) > 1) return $this->review('claim', 'create', $input, array_map(fn (KnowledgeClaim $claim): array => $this->claimCandidate($claim, 'MULTIPLE_EXACT_CANDIDATES'), $exact), $this->claimRevisions($exact), 'MULTIPLE_EXACT_CANDIDATES');
            $possibleEquivalent = array_values(array_filter($scoped, fn (KnowledgeClaim $claim): bool => $this->possibleEquivalentClaim($claim->claimText, $normalized)));
            if ($possibleEquivalent !== []) return $this->review('claim', 'create', $input, array_map(fn (KnowledgeClaim $claim): array => $this->claimCandidate($claim, 'POSSIBLE_EQUIVALENT_SAME_SCOPE'), $possibleEquivalent), $this->claimRevisions($possibleEquivalent), 'POSSIBLE_EQUIVALENT_SAME_SCOPE');

            return $this->resolution('claim', 'create', KnowledgePreCreateResolution::CREATE_NEW, $input, [], [], 'NO_APPLICABLE_CLAIM_IDENTITY');
        } catch (\Throwable) {
            return $this->review('claim', 'create', $input, [], [], 'KNOWLEDGE_PRE_CREATE_DEPENDENCY_UNAVAILABLE');
        }
    }

    public function resolveSourceCreate(string $stableKey, string $title, string $type = 'website', ?string $locator = null, array $metadata = []): KnowledgePreCreateResolution
    {
        $input = ['stable_key' => trim($stableKey), 'title' => trim($title), 'source_type' => $type, 'locator' => $this->normalizeLocator($locator), 'metadata' => $metadata];
        if ($input['stable_key'] === '' || $input['title'] === '') return $this->review('source', 'create', $input, [], [], 'SOURCE_PRE_CREATE_INPUT_INVALID');
        try {
            $sources = $this->sources->list(true);
            $matches = [];
            foreach ($sources as $source) {
                if (!$source instanceof Source) continue;
                $reason = null;
                if ($source->stableKey === $stableKey) $reason = 'STABLE_KEY_MATCH';
                elseif ($input['locator'] !== null && $this->normalizeLocator($source->locator) === $input['locator']) $reason = 'CANONICAL_LOCATOR_MATCH';
                elseif ($this->sameExternalIdentity($source, $type, $metadata)) $reason = 'EXTERNAL_SOURCE_IDENTITY_MATCH';
                if ($reason !== null) $matches[] = [$source, $reason];
            }
            if ($matches === []) return $this->resolution('source', 'create', KnowledgePreCreateResolution::CREATE_NEW, $input, [], [], 'NO_APPLICABLE_SOURCE_IDENTITY');
            if (count($matches) > 1) return $this->review('source', 'create', $input, array_map(fn (array $match): array => $this->sourceCandidate($match[0], $match[1]), $matches), $this->sourceRevisions(array_map(static fn (array $match): Source => $match[0], $matches)), 'MULTIPLE_SOURCE_IDENTITIES');
            [$source, $reason] = $matches[0];
            $candidate = $this->sourceCandidate($source, $reason);
            if (!$source->active) return $this->review('source', 'create', $input, [$candidate], $this->sourceRevisions([$source]), 'RETIRED_SOURCE_CANDIDATE');
            return $this->resolution('source', 'create', KnowledgePreCreateResolution::REUSE_EXISTING, $input, [$candidate], $this->sourceRevisions([$source]), $reason);
        } catch (\Throwable) {
            return $this->review('source', 'create', $input, [], [], 'SOURCE_PRE_CREATE_DEPENDENCY_UNAVAILABLE');
        }
    }

    public function resolveEvidence(string $evidenceId, string $claimId, string $sourceId, string $excerpt, string $relation = 'supports', ?string $locator = null, array $metadata = []): KnowledgePreCreateResolution
    {
        $input = ['evidence_id' => trim($evidenceId), 'claim_id' => trim($claimId), 'source_id' => trim($sourceId), 'relation' => $relation, 'excerpt' => $this->normalizeText($excerpt), 'locator' => $this->normalizeLocator($locator, false), 'metadata' => $metadata];
        if ($evidenceId !== '' && !UuidCodec::isValid($evidenceId)) return $this->review('evidence', 'create', $input, [], [], 'EVIDENCE_IDENTITY_INVALID');
        try {
            $existingById = $evidenceId === '' ? null : $this->evidence->findByCanonicalId($evidenceId);
            if ($existingById instanceof Evidence) {
                $candidate = $this->evidenceCandidate($existingById, 'EVIDENCE_ID_MATCH');
                if ($this->sameEvidence($existingById, $claimId, $sourceId, $excerpt, $relation, $locator, $metadata)) {
                    return $existingById->active
                        ? $this->resolution('evidence', 'create', KnowledgePreCreateResolution::REUSE_EXISTING, $input, [$candidate], [$existingById->canonicalId => $existingById->revision], 'EVIDENCE_IDEMPOTENT_REPLAY')
                        : $this->review('evidence', 'create', $input, [$candidate], [$existingById->canonicalId => $existingById->revision], 'RETIRED_EVIDENCE_CANDIDATE');
                }
                return $this->review('evidence', 'create', $input, [$candidate], [$existingById->canonicalId => $existingById->revision], 'EVIDENCE_ID_COLLISION');
            }
            $matches = [];
            foreach ($this->evidence->listByClaim($claimId, true) as $evidence) {
                if (!$evidence instanceof Evidence || $evidence->sourceId !== $sourceId || $evidence->relation !== $relation) continue;
                if ($this->sameEvidence($evidence, $claimId, $sourceId, $excerpt, $relation, $locator, $metadata)) $matches[] = $evidence;
                elseif ($input['locator'] !== null && $this->normalizeLocator($evidence->locator, false) === $input['locator']) return $this->review('evidence', 'create', $input, [$this->evidenceCandidate($evidence, 'SAME_SUPPORT_LOCATOR_DIFFERENT_EXCERPT')], [$evidence->canonicalId => $evidence->revision], 'SAME_SUPPORT_LOCATOR_DIFFERENT_EXCERPT');
            }
            if (count($matches) === 1) {
                $evidence = $matches[0];
                $candidate = $this->evidenceCandidate($evidence, 'SUPPORT_IDENTITY_MATCH');
                return $evidence->active
                    ? $this->resolution('evidence', 'create', KnowledgePreCreateResolution::REUSE_EXISTING, $input, [$candidate], [$evidence->canonicalId => $evidence->revision], 'SAME_SUPPORT_IDENTITY')
                    : $this->review('evidence', 'create', $input, [$candidate], [$evidence->canonicalId => $evidence->revision], 'RETIRED_EVIDENCE_CANDIDATE');
            }
            if (count($matches) > 1) return $this->review('evidence', 'create', $input, array_map(fn (Evidence $evidence): array => $this->evidenceCandidate($evidence, 'MULTIPLE_SUPPORT_IDENTITIES'), $matches), array_combine(array_map(static fn (Evidence $evidence): string => $evidence->canonicalId, $matches), array_map(static fn (Evidence $evidence): int => $evidence->revision, $matches)), 'MULTIPLE_SUPPORT_IDENTITIES');
            return $this->resolution('evidence', 'create', KnowledgePreCreateResolution::CREATE_NEW, $input, [], [], 'NEW_SUPPORT_UNIT');
        } catch (\Throwable) {
            return $this->review('evidence', 'create', $input, [], [], 'EVIDENCE_PRE_CREATE_DEPENDENCY_UNAVAILABLE');
        }
    }

    private function resolution(string $owner, string $operation, string $action, array $input, array $candidates, array $revisions, string $reason): KnowledgePreCreateResolution
    {
        return KnowledgePreCreateResolution::fromDecision($owner, $operation, $action, $input, $candidates, $revisions, ['reason' => $reason, 'resolver_version' => 'knowledge-pre-create-1']);
    }

    private function review(string $owner, string $operation, array $input, array $candidates, array $revisions, string $reason): KnowledgePreCreateResolution
    {
        return $this->resolution($owner, $operation, KnowledgePreCreateResolution::REVIEW_REQUIRED, $input, $candidates, $revisions, $reason);
    }

    private function claimContext(array $metadata): array
    {
        $context = array_intersect_key($metadata, array_flip(['subject_id', 'facet', 'scope', 'claim_type']));
        ksort($context);
        return $context;
    }

    private function sameClaimContext(array $left, array $right): bool
    {
        return $this->claimContext($left) === $this->claimContext($right);
    }

    private function claimEquivalent(KnowledgeClaim $claim, string $normalized, string $type, array $metadata): bool
    {
        $claimMetadata = is_array($claim->provenance['metadata'] ?? null) ? $claim->provenance['metadata'] : [];
        return $claim->claimType === $type && $this->normalizeText($claim->claimText) === $normalized && $this->sameClaimContext($claimMetadata, $metadata);
    }

    private function claimCandidate(KnowledgeClaim $claim, string $reason): array
    {
        return ['canonical_id' => $claim->canonicalId, 'stable_key' => $claim->stableKey, 'revision' => $claim->revision, 'active' => $claim->active, 'reason' => $reason];
    }

    private function possibleEquivalentClaim(string $existing, string $incoming): bool
    {
        $left = $this->claimTokens($existing);
        $right = $this->claimTokens($incoming);
        if ($left === [] || $right === []) return false;
        $intersection = count(array_intersect($left, $right));
        $union = count(array_unique(array_merge($left, $right)));
        return $union > 0 && ($intersection / $union) >= 0.5;
    }

    /** @return list<string> */
    private function claimTokens(string $value): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $this->normalizeText($value), -1, PREG_SPLIT_NO_EMPTY);
        return is_array($tokens) ? array_values(array_unique($tokens)) : [];
    }

    private function sourceCandidate(Source $source, string $reason): array
    {
        return ['canonical_id' => $source->canonicalId, 'stable_key' => $source->stableKey, 'revision' => $source->revision, 'active' => $source->active, 'reason' => $reason];
    }

    private function evidenceCandidate(Evidence $evidence, string $reason): array
    {
        return ['canonical_id' => $evidence->canonicalId, 'claim_id' => $evidence->claimId, 'source_id' => $evidence->sourceId, 'revision' => $evidence->revision, 'active' => $evidence->active, 'reason' => $reason];
    }

    /** @param list<KnowledgeClaim> $claims */
    private function claimRevisions(array $claims): array
    {
        return array_combine(array_map(static fn (KnowledgeClaim $claim): string => $claim->canonicalId, $claims), array_map(static fn (KnowledgeClaim $claim): int => $claim->revision, $claims));
    }

    /** @param list<Source> $sources */
    private function sourceRevisions(array $sources): array
    {
        return array_combine(array_map(static fn (Source $source): string => $source->canonicalId, $sources), array_map(static fn (Source $source): int => $source->revision, $sources));
    }

    private function sameExternalIdentity(Source $source, string $type, array $metadata): bool
    {
        $incoming = trim((string) ($metadata['external_identity'] ?? $metadata['external_id'] ?? ''));
        $existing = trim((string) ($source->metadata['external_identity'] ?? $source->metadata['external_id'] ?? ''));
        return $incoming !== '' && $existing !== '' && $incoming === $existing && $source->sourceType === $type;
    }

    private function sameEvidence(Evidence $evidence, string $claimId, string $sourceId, string $excerpt, string $relation, ?string $locator, array $metadata): bool
    {
        return $evidence->claimId === $claimId
            && $evidence->sourceId === $sourceId
            && $evidence->relation === $relation
            && $this->normalizeText($evidence->excerpt) === $this->normalizeText($excerpt)
            && $this->normalizeLocator($evidence->locator, false) === $this->normalizeLocator($locator, false)
            && $this->canonicalize($evidence->metadata) === $this->canonicalize($metadata);
    }

    private function normalizeText(string $value): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        return trim($value, " \t\n\r\0\x0B.!?。！？");
    }

    private function normalizeLocator(?string $locator, bool $dropFragment = true): ?string
    {
        $locator = trim((string) ($locator ?? ''));
        if ($locator === '') return null;
        $parts = parse_url($locator);
        if (!is_array($parts) || trim((string) ($parts['host'] ?? '')) === '') return $locator;
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) && !in_array([$scheme, (int) $parts['port']], [['http', 80], ['https', 443]], true) ? ':' . (int) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '/');
        $path = rtrim($path, '/') ?: '/';
        $query = '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str((string) $parts['query'], $params);
            if (is_array($params)) { ksort($params); $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986); }
        }
        $fragment = !$dropFragment && isset($parts['fragment']) && trim((string) $parts['fragment']) !== '' ? '#' . trim((string) $parts['fragment']) : '';
        return $scheme . '://' . $host . $port . $path . ($query !== '' ? '?' . $query : '') . $fragment;
    }

    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) $value[$key] = $this->canonicalize($item);
        }
        ksort($value);
        return $value;
    }
}
