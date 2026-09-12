<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Application\Entity\{EntityProfileResolution, EntityProfileResolver};
use NHK\Core\Application\PublicIdentity\CanonicalPublicSlugPolicy;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Capture\{ClockTypeCanonicalMembershipDiagnosticsReader, ClockTypeCanonicalMembershipReader};
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Capture read-side classifier. It consumes the already-resolved primary
 * subject packet and emits a transient Clock-Type shadow packet.
 *
 * There is intentionally no Proposal, Graph, Authority, Knowledge, Media,
 * Video, Article or Public Identity dependency in this service.
 */
final class ClockTypeShadowClassifier
{
    public function __construct(
        private AuthorityRepository $authority,
        private EntityProfileResolver $profiles = new EntityProfileResolver(),
        private ?ClockTypeCanonicalMembershipReader $memberships = null,
    ) {}

    /** @param array<string,mixed> $captureContext */
    public function resolve(array $captureContext): ClockTypeShadowResolution
    {
        $primary = $this->primarySubject($captureContext);
        if ($primary === null) {
            return new ClockTypeShadowResolution(
                ClockTypeShadowResolution::UNAVAILABLE,
                [],
                null,
                [],
                [],
                ['PRIMARY_SUBJECT_REQUIRED'],
                [],
            );
        }

        $diagnostics = [];
        $basis = [];
        if ($this->hasBrandContext($captureContext)) $diagnostics[] = 'BRAND_NOT_CLASSIFICATION_EVIDENCE';

        try {
            $classifications = array_values(array_filter(
                $this->authority->listByType('classification'),
                static fn (mixed $entity): bool => $entity instanceof AuthorityEntity && $entity->active(),
            ));
        } catch (\Throwable) {
            return new ClockTypeShadowResolution(
                ClockTypeShadowResolution::UNAVAILABLE,
                [],
                null,
                [],
                [],
                array_values(array_unique([...$diagnostics, 'CLASSIFICATION_READ_UNAVAILABLE'])),
                $primary,
            );
        }

        $clockTypes = [];
        $nonClockNames = [];
        foreach ($classifications as $entity) {
            $profile = $this->profiles->resolveProfile($entity);
            if ($profile->resolved()) {
                $clockTypes[$entity->canonicalId] = ['entity' => $entity, 'profile' => $profile];
            } elseif ($profile->diagnostic === 'FAMILY_NOT_CLOCK_TYPE') {
                $nonClockNames[CanonicalPublicSlugPolicy::normalize($entity->canonicalName)] = true;
            }
        }

        // Tier C: already-canonical Graph/context evidence is stronger than
        // any text. A reader failure is not converted into an empty success.
        if ($this->memberships !== null) {
            try {
                $membershipDiagnostics = [];
                if ($this->memberships instanceof ClockTypeCanonicalMembershipDiagnosticsReader) {
                    $membershipRead = $this->memberships->readClockTypeMemberships((string) $primary['type'], (string) $primary['id']);
                    $members = $membershipRead->members;
                    $membershipDiagnostics = $membershipRead->diagnostics;
                    $diagnostics = array_values(array_unique([...$diagnostics, ...$membershipDiagnostics]));
                    if ($membershipRead->status === 'UNAVAILABLE') {
                        return new ClockTypeShadowResolution(
                            ClockTypeShadowResolution::UNAVAILABLE,
                            [],
                            null,
                            ['CANONICAL_GRAPH_MEMBERSHIP'],
                            [],
                            array_values(array_unique([...$diagnostics, 'CANONICAL_MEMBERSHIP_READ_UNAVAILABLE'])),
                            $primary,
                        );
                    }
                } else {
                    $members = $this->memberships->listClockTypesForSubject((string) $primary['type'], (string) $primary['id']);
                }
            } catch (\Throwable) {
                return new ClockTypeShadowResolution(
                    ClockTypeShadowResolution::UNAVAILABLE,
                    [],
                    null,
                    ['CANONICAL_GRAPH_MEMBERSHIP'],
                    [],
                    array_values(array_unique([...$diagnostics, 'CANONICAL_MEMBERSHIP_READ_UNAVAILABLE'])),
                    $primary,
                );
            }
            $canonicalCandidates = [];
            foreach ($members as $member) {
                if (!$member instanceof AuthorityEntity) {
                    $diagnostics[] = 'INVALID_CANONICAL_MEMBERSHIP_ROW';
                    continue;
                }
                $candidate = $this->candidateForEntity($member, 'CANONICAL_GRAPH_MEMBERSHIP', 'CANONICAL_READ', 'ALREADY_CANONICAL', $diagnostics);
                if ($candidate !== null) $canonicalCandidates[$candidate->classificationUuid] = $candidate;
            }
            if ($canonicalCandidates !== []) {
                $basis[] = 'CANONICAL_GRAPH_MEMBERSHIP';
                $canonicalCandidates = $this->sortCandidates(array_values($canonicalCandidates));
                if (count($canonicalCandidates) > 1) {
                    return $this->ambiguous($primary, $canonicalCandidates, $basis, $diagnostics, 'MULTIPLE_CANONICAL_CLOCK_TYPES');
                }
                $status = $canonicalCandidates[0]->profileStatus === EntityProfileResolution::COMPATIBILITY_READ
                    ? ClockTypeShadowResolution::DATA_COMPATIBILITY_GAP
                    : ClockTypeShadowResolution::RESOLVED_CANONICAL;
                return new ClockTypeShadowResolution(
                    $status,
                    $canonicalCandidates,
                    $canonicalCandidates[0],
                    $basis,
                    [],
                    array_values(array_unique($diagnostics)),
                    $primary,
                );
            }
        }

        // Tier A/B: only explicitly namespaced canonical identifiers and
        // exact names are eligible for this branch. A primary subject's slug,
        // stable key or title is never reinterpreted as a Classification.
        try {
            $explicit = $this->explicitCandidates($captureContext, $clockTypes, $nonClockNames, $diagnostics);
        } catch (\Throwable) {
            return new ClockTypeShadowResolution(
                ClockTypeShadowResolution::UNAVAILABLE,
                [],
                null,
                ['EXPLICIT_CANONICAL_CONTEXT'],
                [],
                array_values(array_unique([...$diagnostics, 'CLASSIFICATION_READ_UNAVAILABLE'])),
                $primary,
            );
        }
        if ($explicit !== []) $diagnostics = array_values(array_unique([...$diagnostics, ...$explicit['diagnostics']]));
        if ($explicit !== [] && $explicit['candidates'] !== []) {
            $basis[] = $explicit['basis'];
            $candidates = $this->sortCandidates($explicit['candidates']);
            if (count($candidates) > 1) return $this->ambiguous($primary, $candidates, $basis, $diagnostics, 'MULTIPLE_EXPLICIT_CLOCK_TYPES');
            return new ClockTypeShadowResolution(
                ClockTypeShadowResolution::RESOLVED_EXPLICIT,
                $candidates,
                $candidates[0],
                $basis,
                [],
                $diagnostics,
                $primary,
            );
        }

        // Tier D: an explicit user statement remains a shadow candidate. It
        // can never be promoted to a claim, evidence or relation here.
        $statementMatches = $this->textCandidates($this->userStatements($captureContext), $clockTypes, $nonClockNames, $diagnostics);
        if ($statementMatches !== []) {
            $basis[] = 'EXPLICIT_USER_CLOCK_TYPE_CANDIDATE';
            $statementMatches = $this->sortCandidates($statementMatches);
            if (count($statementMatches) > 1) return $this->ambiguous($primary, $statementMatches, $basis, $diagnostics, 'MULTIPLE_CLOCK_TYPE_CANDIDATES');
            return new ClockTypeShadowResolution(
                ClockTypeShadowResolution::RESOLVED_EXPLICIT,
                $statementMatches,
                $statementMatches[0],
                $basis,
                [],
                array_values(array_unique($diagnostics)),
                $primary,
            );
        }

        // Tier E: weak title/media observations are useful for review only.
        $weak = $this->textCandidates($this->weakInputs($captureContext), $clockTypes, $nonClockNames, $diagnostics, true);
        if ($weak !== []) {
            $basis[] = 'LEXICAL_MEDIA_REVIEW';
            $weak = $this->sortCandidates($weak);
            if (count($weak) > 1) return $this->ambiguous($primary, $weak, $basis, $diagnostics, 'MULTIPLE_WEAK_CLOCK_TYPE_CANDIDATES');
            return new ClockTypeShadowResolution(
                ClockTypeShadowResolution::REVIEW_CANDIDATE,
                $weak,
                null,
                $basis,
                [],
                array_values(array_unique([...$diagnostics, 'WEAK_INPUT_NOT_CANONICAL_EVIDENCE'])),
                $primary,
            );
        }

        return new ClockTypeShadowResolution(
            ClockTypeShadowResolution::NONE,
            [],
            null,
            $basis,
            [],
            array_values(array_unique([...$diagnostics, 'NO_CLOCK_TYPE_CANDIDATE'])),
            $primary,
        );
    }

    /** @param array<string,mixed> $captureContext @return array<string,mixed>|null */
    private function primarySubject(array $captureContext): ?array
    {
        $primary = $captureContext['subject_resolution']['primary'] ?? $captureContext['primary_subject'] ?? null;
        if (!is_array($primary)) return null;
        $id = trim((string) ($primary['id'] ?? $primary['canonical_id'] ?? ''));
        $type = trim((string) ($primary['type'] ?? $primary['entity_type'] ?? ''));
        if (!UuidCodec::isValid($id) || $type === '') return null;
        return [
            'id' => $id,
            'type' => $type,
            'name' => trim((string) ($primary['name'] ?? $primary['canonical_name'] ?? '')),
            'stable_key' => trim((string) ($primary['stable_key'] ?? '')),
        ];
    }

    /** @param array<string,mixed> $captureContext */
    private function hasBrandContext(array $captureContext): bool
    {
        foreach (['brand', 'brand_context', 'resolved_brand'] as $key) {
            if (($captureContext[$key] ?? null) !== null && $captureContext[$key] !== '') return true;
        }
        return false;
    }

    /** @param array<string,mixed> $captureContext @param array<string,array{entity:AuthorityEntity,profile:EntityProfileResolution}> $clockTypes @param array<string,bool> $nonClockNames @param list<string> $diagnostics @return array{basis:string,candidates:list<ClockTypeShadowCandidate>,diagnostics:list<string>} */
    private function explicitCandidates(array $captureContext, array $clockTypes, array $nonClockNames, array &$diagnostics): array
    {
        $entities = [];
        $basis = null;
        $ids = [];
        foreach (['classification_uuid', 'classification_id'] as $key) {
            $value = $captureContext[$key] ?? null;
            foreach (is_array($value) ? $value : [$value] as $id) if (is_string($id) && UuidCodec::isValid($id)) $ids[] = $id;
        }
        foreach ($ids as $id) {
            $entity = $this->authority->findByCanonicalId($id);
            if (!$entity instanceof AuthorityEntity || !$entity->active() || $entity->entityType !== 'classification') {
                $diagnostics[] = 'EXPLICIT_CLASSIFICATION_ID_UNRESOLVED';
                continue;
            }
            $entities[$entity->canonicalId] = $entity;
            $basis = 'EXPLICIT_CANONICAL_ID';
        }
        $keys = [];
        foreach (['classification_stable_key', 'clock_type_stable_key'] as $key) {
            $value = $captureContext[$key] ?? null;
            foreach (is_array($value) ? $value : [$value] as $stableKey) if (is_string($stableKey) && trim($stableKey) !== '') $keys[] = trim($stableKey);
        }
        foreach ($keys as $stableKey) {
            $entity = $this->authority->findByStableKey('classification', $stableKey);
            if (!$entity instanceof AuthorityEntity || !$entity->active()) {
                $diagnostics[] = 'EXPLICIT_CLASSIFICATION_STABLE_KEY_UNRESOLVED';
                continue;
            }
            $entities[$entity->canonicalId] = $entity;
            $basis ??= 'EXPLICIT_CANONICAL_STABLE_KEY';
        }
        $names = [];
        foreach (['clock_type_name', 'classification_name', 'clock_type_names', 'clock_type_hints'] as $key) {
            $value = $captureContext[$key] ?? null;
            foreach (is_array($value) ? $value : [$value] as $name) if (is_string($name) && trim($name) !== '') $names[] = trim($name);
        }
        foreach ($names as $name) {
            $needle = CanonicalPublicSlugPolicy::normalize($name);
            foreach ($clockTypes as $record) {
                if (CanonicalPublicSlugPolicy::normalize($record['entity']->canonicalName) === $needle) $entities[$record['entity']->canonicalId] = $record['entity'];
            }
            if (isset($nonClockNames[$needle])) $diagnostics[] = 'FAMILY_NOT_CLOCK_TYPE';
            if ($needle !== '' && !isset($entities[$this->entityIdByName($clockTypes, $needle)])) $diagnostics[] = 'EXACT_CLOCK_TYPE_NOT_FOUND';
            $basis ??= 'EXACT_CANONICAL_CONTEXT';
        }
        if ($entities === []) return ['basis' => (string) ($basis ?? 'EXPLICIT_CANONICAL_ID'), 'candidates' => [], 'diagnostics' => []];
        $candidates = [];
        $candidateDiagnostics = [];
        foreach ($entities as $entity) {
            $candidate = $this->candidateForEntity($entity, (string) ($basis ?? 'EXPLICIT_CANONICAL_ID'), 'SHADOW_REVIEW_REQUIRED', 'EXPLICIT_CONTEXT', $candidateDiagnostics);
            if ($candidate !== null) $candidates[$candidate->classificationUuid] = $candidate;
        }
        return ['basis' => (string) ($basis ?? 'EXPLICIT_CANONICAL_ID'), 'candidates' => array_values($candidates), 'diagnostics' => $candidateDiagnostics];
    }

    /** @param array<string,array{entity:AuthorityEntity,profile:EntityProfileResolution}> $clockTypes */
    private function entityIdByName(array $clockTypes, string $needle): string
    {
        foreach ($clockTypes as $id => $record) if (CanonicalPublicSlugPolicy::normalize($record['entity']->canonicalName) === $needle) return $id;
        return '';
    }

    /** @param list<string> $texts @param array<string,array{entity:AuthorityEntity,profile:EntityProfileResolution}> $clockTypes @param array<string,bool> $nonClockNames @param list<string> $diagnostics @return array<string,ClockTypeShadowCandidate> */
    private function textCandidates(array $texts, array $clockTypes, array $nonClockNames, array &$diagnostics, bool $weak = false): array
    {
        $matches = [];
        foreach ($texts as $text) {
            $normalizedText = CanonicalPublicSlugPolicy::normalize($text);
            if ($normalizedText === '') continue;
            foreach ($clockTypes as $record) {
                $name = CanonicalPublicSlugPolicy::normalize($record['entity']->canonicalName);
                $matched = $name !== '' && str_contains($normalizedText, $name);
                if ($weak && !$matched) $matched = $this->weakLexicalMatch($normalizedText, $name);
                if (!$matched) continue;
                $candidate = $this->candidateForEntity(
                    $record['entity'],
                    $weak ? 'LEXICAL_MEDIA_REVIEW' : 'EXPLICIT_USER_CLOCK_TYPE_CANDIDATE',
                    $weak ? 'REVIEW_CANDIDATE' : 'SHADOW_REVIEW_REQUIRED',
                    $weak ? 'LEXICAL_OR_MEDIA_INFERENCE' : 'EXPLICIT_USER_STATEMENT',
                    $diagnostics,
                );
                if ($candidate !== null) $matches[$candidate->classificationUuid] = $candidate;
            }
            foreach (array_keys($nonClockNames) as $name) if ($name !== '' && str_contains($normalizedText, $name)) $diagnostics[] = 'AMBIGUOUS_CLASSIFICATION_FAMILY';
        }
        return $matches;
    }

    private function weakLexicalMatch(string $text, string $name): bool
    {
        if ($name === '' || str_contains($name, $text)) return true;
        $parts = array_values(array_filter(explode('-', $name), static fn (string $part): bool => !in_array($part, ['dong', 'ho', 'clock', 'type'], true)));
        if (count($parts) < 2) return false;
        for ($length = min(3, count($parts)); $length >= 2; $length--) {
            for ($offset = 0; $offset <= count($parts) - $length; $offset++) {
                if (str_contains($text, implode('-', array_slice($parts, $offset, $length)))) return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $captureContext @return list<string> */
    private function userStatements(array $captureContext): array
    {
        $texts = [];
        foreach (['user_statement', 'explicit_clock_type_statement', 'raw_input'] as $key) if (is_string($captureContext[$key] ?? null) && trim($captureContext[$key]) !== '') $texts[] = trim($captureContext[$key]);
        $interpretation = is_array($captureContext['interpretation'] ?? null) ? $captureContext['interpretation'] : [];
        foreach ((array) ($interpretation['user_claim_candidates'] ?? []) as $candidate) if (is_array($candidate) && is_string($candidate['text'] ?? null) && trim($candidate['text']) !== '') $texts[] = trim($candidate['text']);
        return array_values(array_unique($texts));
    }

    /** @param array<string,mixed> $captureContext @return list<string> */
    private function weakInputs(array $captureContext): array
    {
        $texts = [];
        foreach (['title', 'filename', 'alt', 'caption', 'ocr', 'visual_similarity'] as $key) if (is_string($captureContext[$key] ?? null) && trim($captureContext[$key]) !== '') $texts[] = trim($captureContext[$key]);
        foreach ((array) ($captureContext['assets'] ?? []) as $asset) {
            if (!is_array($asset)) continue;
            foreach (['filename', 'alt', 'caption', 'ocr', 'observation', 'observed_text'] as $key) if (is_string($asset[$key] ?? null) && trim($asset[$key]) !== '') $texts[] = trim($asset[$key]);
        }
        foreach ((array) ($captureContext['observations'] ?? []) as $observation) {
            if (is_string($observation) && trim($observation) !== '') $texts[] = trim($observation);
            if (is_array($observation) && is_string($observation['text'] ?? null) && trim($observation['text']) !== '') $texts[] = trim($observation['text']);
        }
        return array_values(array_unique($texts));
    }

    /** @param list<ClockTypeShadowCandidate> $candidates */
    private function ambiguous(array $primary, array $candidates, array $basis, array $diagnostics, string $reason): ClockTypeShadowResolution
    {
        return new ClockTypeShadowResolution(
            ClockTypeShadowResolution::AMBIGUOUS,
            $candidates,
            null,
            $basis,
            [$reason],
            array_values(array_unique([...$diagnostics, 'AMBIGUOUS_CLASSIFICATION_REVIEW'])),
            $primary,
        );
    }

    /** @param list<ClockTypeShadowCandidate> $candidates @return list<ClockTypeShadowCandidate> */
    private function sortCandidates(array $candidates): array
    {
        usort($candidates, static fn (ClockTypeShadowCandidate $left, ClockTypeShadowCandidate $right): int => strcmp($left->classificationUuid, $right->classificationUuid));
        return $candidates;
    }

    /** @param list<string> $diagnostics */
    private function candidateForEntity(AuthorityEntity $entity, string $basis, string $reviewClass, string $origin, array &$diagnostics): ?ClockTypeShadowCandidate
    {
        if (!$entity->active() || $entity->entityType !== 'classification') {
            $diagnostics[] = 'INVALID_CLOCK_TYPE_ENDPOINT';
            return null;
        }
        $profile = $this->profiles->resolveProfile($entity);
        if (!$profile->resolved() || $profile->profileKey !== 'clock_type') {
            if ($profile->diagnostic !== null) $diagnostics[] = $profile->diagnostic;
            return null;
        }
        $candidateDiagnostics = [];
        if ($profile->status === EntityProfileResolution::COMPATIBILITY_READ) {
            $candidateDiagnostics[] = 'DATA_COMPATIBILITY_GAP';
            $origin = 'COMPATIBILITY_READ';
        }
        return ClockTypeShadowCandidate::fromEntity($entity, $basis, $reviewClass, $origin, $profile->status, $candidateDiagnostics);
    }
}
