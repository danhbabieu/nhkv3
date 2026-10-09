<?php
declare(strict_types=1);

namespace NHK\Core\Application\Completion;

use NHK\Core\Domain\Governance\CommandCanonicalizer;

/**
 * Derives the requested outcome from already-resolved Capture intent.
 *
 * This class is deliberately receipt-oriented: it does not resolve subjects,
 * create owners, infer relations or perform public writes.
 */
final class OutcomeObligationCompiler
{
    private const VERSION = '1';

    /** @param array<string,mixed> $intent @param array<string,mixed> $signals @return array<string,mixed> */
    public function compile(string $captureId, array $intent, array $signals = []): array
    {
        $captureId = trim($captureId);
        if ($captureId === '') throw new \InvalidArgumentException('OUTCOME_OBLIGATION_CAPTURE_ID_REQUIRED');

        $ownerTypes = $this->strings($signals['owner_types'] ?? []);
        $dependencyTypes = $this->strings($signals['dependency_owner_types'] ?? []);
        $ownerCapabilities = is_array($signals['owner_capabilities'] ?? null) ? $signals['owner_capabilities'] : [];
        $ownerRevisions = is_array($signals['owner_revisions'] ?? null) ? $signals['owner_revisions'] : [];
        $publicRequested = ($signals['publish'] ?? false) === true
            || ($signals['public_request'] ?? false) === true
            || ($signals['frontend_request'] ?? false) === true;
        $homepageRequested = ($signals['homepage_request'] ?? false) === true;
        $homepagePolicyRequired = ($signals['homepage_policy_required'] ?? false) === true;
        $nonDependencyTypes = array_values(array_diff($ownerTypes, $dependencyTypes));

        if ($publicRequested && $nonDependencyTypes === []) {
            throw new \InvalidArgumentException('OUTCOME_OBLIGATION_APPLICABILITY_AMBIGUOUS');
        }
        foreach ($nonDependencyTypes as $ownerType) {
            if ($publicRequested && !array_key_exists($ownerType, $ownerCapabilities)) {
                throw new \InvalidArgumentException('OUTCOME_OBLIGATION_APPLICABILITY_AMBIGUOUS');
            }
        }

        $publicCapable = false;
        foreach ($nonDependencyTypes as $ownerType) {
            $capability = is_array($ownerCapabilities[$ownerType] ?? null) ? $ownerCapabilities[$ownerType] : [];
            if (($capability['public_capable'] ?? $capability['requires_public_surface'] ?? false) === true) {
                $publicCapable = true;
                break;
            }
        }
        $allDependencies = $ownerTypes !== [] && $nonDependencyTypes === [];
        $public = $allDependencies
            ? $this->obligation('NOT_APPLICABLE', 'SEMANTIC_DEPENDENCY_NO_PUBLIC_OWNER')
            : ($publicRequested
                ? $this->obligation('REQUIRED', 'EXPLICIT_PUBLIC_REQUEST')
                : ($publicCapable
                    ? $this->obligation('CONDITIONAL', 'PUBLIC_NOT_REQUESTED')
                    : $this->obligation('NOT_APPLICABLE', 'OWNER_NOT_PUBLIC_CAPABLE')));
        $frontend = $allDependencies
            ? $this->obligation('NOT_APPLICABLE', 'SEMANTIC_DEPENDENCY_NO_FRONTEND_OWNER')
            : ($publicRequested
                ? $this->obligation('REQUIRED', 'EXPLICIT_PUBLIC_REQUEST')
                : ($publicCapable
                    ? $this->obligation('CONDITIONAL', 'FRONTEND_NOT_REQUESTED')
                    : $this->obligation('NOT_APPLICABLE', 'OWNER_NOT_FRONTEND_CAPABLE')));

        $obligations = [
            'canonical' => $this->obligation('REQUIRED', 'CANONICAL_OWNER_ACCEPTED'),
            'governance' => $this->obligation(
                ($signals['governance_requested'] ?? false) === true ? 'REQUIRED' : 'OPTIONAL',
                ($signals['governance_requested'] ?? false) === true ? 'SEMANTIC_MUTATION_REQUESTED' : 'NO_GOVERNANCE_MUTATION_REQUESTED',
            ),
            'relations' => $this->obligation(
                ($signals['relations_required'] ?? false) === true ? 'REQUIRED' : (($signals['relations_requested'] ?? false) === true ? 'CONDITIONAL' : 'OPTIONAL'),
                ($signals['relations_required'] ?? false) === true ? 'REGISTERED_RELATION_REQUIRED' : 'RELATIONS_NOT_REQUIRED',
            ),
            'public' => $public,
            'frontend' => $frontend,
            'homepage' => $this->obligation(
                $homepageRequested || $homepagePolicyRequired ? 'REQUIRED' : 'OPTIONAL',
                $homepageRequested ? 'EXPLICIT_HOMEPAGE_REQUEST' : ($homepagePolicyRequired ? 'HOMEPAGE_POLICY_REQUIRED' : 'HOMEPAGE_NOT_REQUESTED'),
            ),
            'publication' => $this->obligation(
                ($signals['publish'] ?? false) === true ? 'REQUIRED' : (($signals['public_request'] ?? false) === true ? 'CONDITIONAL' : 'OPTIONAL'),
                ($signals['publish'] ?? false) === true ? 'EXPLICIT_PUBLICATION_REQUEST' : 'PUBLICATION_NOT_REQUESTED',
            ),
        ];

        $intent = $this->normalizeIntent($intent);
        $ownerTypes = array_values(array_unique($ownerTypes));
        sort($ownerTypes, SORT_STRING);
        $dependencyTypes = array_values(array_unique($dependencyTypes));
        sort($dependencyTypes, SORT_STRING);
        $domain = $this->domain($ownerTypes);
        $recipe = [
            'domain' => $domain,
            'canonical' => $obligations['canonical'],
            'public' => $obligations['public'],
            'frontend' => $obligations['frontend'],
            'homepage' => $obligations['homepage'],
        ];
        $binding = [
            'capture_id' => $captureId,
            'intent' => $intent,
            'domain' => $domain,
            'recipe' => $recipe,
            'owner_types' => $ownerTypes,
            'dependency_owner_types' => $dependencyTypes,
            'owner_revisions' => $ownerRevisions,
            'public_request' => $publicRequested,
            'homepage_request' => $homepageRequested,
            'homepage_policy_required' => $homepagePolicyRequired,
            'obligations' => $obligations,
        ];

        return [
            'version' => self::VERSION,
            'capture_id' => $captureId,
            'intent' => $intent,
            'domain' => $domain,
            'recipe' => $recipe,
            'owner_types' => $ownerTypes,
            'dependency_owner_types' => $dependencyTypes,
            'public_request' => $publicRequested,
            'obligations' => $obligations,
            'fingerprint' => hash('sha256', CommandCanonicalizer::canonicalize($binding)),
        ];
    }

    /** @return array{class:string,reason:string} */
    private function obligation(string $class, string $reason): array
    {
        return ['class' => $class, 'reason' => $reason];
    }

    /** @param array<string,mixed> $intent @return array<string,mixed> */
    private function normalizeIntent(array $intent): array
    {
        $normalized = $intent;
        foreach (['intent', 'mode', 'profile'] as $key) {
            if (array_key_exists($key, $normalized)) $normalized[$key] = strtoupper(trim((string) $normalized[$key]));
        }
        return $normalized;
    }

    /** @param list<string> $ownerTypes */
    private function domain(array $ownerTypes): string
    {
        foreach ($ownerTypes as $ownerType) {
            $ownerType = strtolower(trim($ownerType));
            if (str_starts_with($ownerType, 'dictionary_') || $ownerType === 'dictionary') return 'dictionary';
            if (in_array($ownerType, ['media', 'media_asset', 'media_usage'], true)) return 'media';
            if ($ownerType === 'video') return 'video';
            if ($ownerType === 'wp_post') return 'article';
            if (in_array($ownerType, ['brand', 'model', 'variant', 'movement', 'music', 'component', 'classification', 'product', 'specimen', 'authority'], true)) return 'authority';
            if (in_array($ownerType, ['knowledge', 'source', 'evidence'], true)) return 'knowledge';
            if (in_array($ownerType, ['graph', 'relation'], true)) return 'graph';
        }
        return 'generic';
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (!is_array($value)) return [];
        return array_values(array_filter(array_map(static fn (mixed $item): string => strtolower(trim((string) $item)), $value), static fn (string $item): bool => $item !== ''));
    }
}
