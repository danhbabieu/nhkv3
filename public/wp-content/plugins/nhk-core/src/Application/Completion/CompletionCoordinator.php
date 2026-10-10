<?php
declare(strict_types=1);

namespace NHK\Core\Application\Completion;

/**
 * Builds a derived completion packet from already-owned read-back results.
 *
 * This is deliberately not a persisted semantic owner or a replacement for
 * ProposalState. It gives application/admin callers one vocabulary for the
 * boundary between canonical Apply, public eligibility and frontend proof.
 */
final class CompletionCoordinator
{
    /** @param (callable(string):?array<string,mixed>)|null $capabilityResolver */
    public function __construct(private $capabilityResolver = null)
    {
    }

    /** @var list<string> */
    private const PUBLIC_CAPABLE = [
        'wp_post', 'knowledge', 'media', 'video',
        'brand', 'model', 'variant', 'movement', 'music', 'component',
        'classification', 'product', 'specimen',
    ];

    /** @return array<string,mixed> */
    public function finalize(string $ownerType, string $ownerId, array $evidence = []): array
    {
        $ownerType = strtolower(trim($ownerType));
        $ownerId = trim($ownerId);
        $outcomeObligations = is_array($evidence['outcome_obligations'] ?? null) ? $evidence['outcome_obligations'] : [];
        $capability = is_callable($this->capabilityResolver) ? ($this->capabilityResolver)($ownerType) : null;
        $publicCapable = (is_array($capability) ? (($capability['public_capable'] ?? $capability['requires_public_surface'] ?? false) === true) : in_array($ownerType, self::PUBLIC_CAPABLE, true))
            && ($evidence['public_projection_owner'] ?? true) !== false
            && strtolower(trim((string) ($evidence['owner_role'] ?? ''))) !== 'semantic_dependency';
        $blockers = $this->strings($evidence['blockers'] ?? []);
        $canonicalReadbackVerified = $this->readBack($evidence['canonical_readback'] ?? null);

        $canonical = $this->state(
            $evidence['canonical_state'] ?? null,
            $canonicalReadbackVerified,
            'COMPLETE',
            'BLOCKED',
        );
        // A caller-provided state is only an assertion about the phase. It
        // cannot replace the owner read-back required for truthful
        // completion. This keeps partial child state from being promoted by
        // an optimistic coordinator flag.
        if (!$canonicalReadbackVerified) $canonical = 'BLOCKED';
        $dependencies = $this->state(
            $evidence['dependency_state'] ?? $evidence['dependencies'] ?? null,
            true,
            'COMPLETE',
            'BLOCKED',
            'PARTIAL',
        );
        $relations = $this->state(
            $evidence['relation_or_usage_state'] ?? $evidence['relations'] ?? null,
            true,
            'COMPLETE',
            'BLOCKED',
            'PARTIAL',
            'NOT_APPLICABLE',
        );
        $content = $this->state($evidence['content_quality'] ?? null, null, 'CONTENT_COMPLETE', 'CONTENT_NEEDS_REVIEW', 'BLOCKED');
        $projectionConsistency = 'NOT_APPLICABLE';
        if ($ownerType === 'video' && array_key_exists('projection_consistency', $evidence)) {
            $projectionConsistency = $this->state($evidence['projection_consistency'], null, 'COMPLETE', 'BLOCKED', 'PARTIAL');
        }

        $publicObligation = $this->obligation($outcomeObligations, 'public');
        $frontendObligation = $this->obligation($outcomeObligations, 'frontend');
        if (($publicObligation['class'] ?? '') === 'NOT_APPLICABLE' || ($frontendObligation['class'] ?? '') === 'NOT_APPLICABLE') {
            $public = 'NOT_APPLICABLE';
            $frontend = 'NOT_APPLICABLE';
        } elseif ($outcomeObligations !== []) {
            $public = $this->surfaceState($evidence['public_state'] ?? null, $evidence['public_eligible'] ?? null, (string) ($publicObligation['class'] ?? 'OPTIONAL'), 'READY');
            $frontend = $this->surfaceState($evidence['frontend_state'] ?? null, $evidence['frontend_verified'] ?? null, (string) ($frontendObligation['class'] ?? 'OPTIONAL'), 'VERIFIED');
        } elseif (!$publicCapable) {
            $public = 'NOT_APPLICABLE';
            $frontend = 'NOT_APPLICABLE';
        } else {
            $public = $this->state(
                $evidence['public_state'] ?? null,
                $evidence['public_eligible'] ?? null,
                'READY',
                'BLOCKED',
                'PARTIAL',
                'NOT_APPLICABLE',
            );
            $frontend = $this->state(
                $evidence['frontend_state'] ?? null,
                $evidence['frontend_verified'] ?? null,
                'VERIFIED',
                'BLOCKED',
                'PARTIAL',
                'NOT_APPLICABLE',
            );
        }

        if ($canonical !== 'COMPLETE' && $blockers === []) $blockers[] = 'CANONICAL_READBACK_UNVERIFIED';
        if ($dependencies === 'BLOCKED' && $blockers === []) $blockers[] = 'DEPENDENCY_READBACK_UNVERIFIED';
        if ($relations === 'BLOCKED' && $blockers === []) $blockers[] = 'RELATION_OR_USAGE_RECONCILIATION_FAILED';
        $enrichment = $this->enrichmentReadiness($evidence, $content, $dependencies, $relations);
        $publication = $this->publicationReadiness($evidence, $public, $frontend, $projectionConsistency, $publicCapable, $outcomeObligations);
        $ownerBlockers = $this->ownerBlockers($blockers);
        array_push($ownerBlockers, ...$this->requiredSurfaceBlockers($outcomeObligations, $public, $frontend));
        if ($canonical !== 'COMPLETE' && $ownerBlockers === []) $ownerBlockers[] = 'CANONICAL_READBACK_UNVERIFIED';
        $complete = $canonical === 'COMPLETE' && $ownerBlockers === [];

        return [
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'proposal_state' => $evidence['proposal_state'] ?? null,
            'canonical_state' => $canonical,
            'canonical_readback' => is_array($evidence['canonical_readback'] ?? null) ? $evidence['canonical_readback'] : null,
            'canonical_readback_verified' => $canonicalReadbackVerified,
            'canonical_existence' => [
                'status' => $canonical,
                'blockers' => array_values(array_unique($ownerBlockers)),
                'readback_verified' => $canonicalReadbackVerified,
            ],
            'dependency_state' => $dependencies,
            'relation_or_usage_state' => $relations,
            'content_state' => $content,
            'projection_consistency' => $projectionConsistency,
            'public_state' => $public,
            'public_state_reason' => $this->surfaceReason($outcomeObligations, 'public', $public),
            'frontend_state' => $frontend,
            'frontend_state_reason' => $this->surfaceReason($outcomeObligations, 'frontend', $frontend),
            'outcome_obligations' => $outcomeObligations !== [] ? $outcomeObligations : null,
            'enrichment_readiness' => $enrichment,
            'publication_readiness' => $publication,
            'complete' => $complete,
            'status' => $complete ? 'COMPLETE' : ($canonical === 'BLOCKED' ? 'BLOCKED' : 'PARTIAL'),
            'blockers' => array_values(array_unique(array_merge($ownerBlockers, $this->strings($enrichment['blockers'] ?? []), $this->strings($publication['blockers'] ?? [])))),
        ];
    }

    /** @return array<string,mixed> */
    private function enrichmentReadiness(array $evidence, string $content, string $dependencies, string $relations): array
    {
        $readiness = is_array($evidence['enrichment_readiness'] ?? null) ? $evidence['enrichment_readiness'] : [];
        $status = strtoupper(trim((string) ($readiness['status'] ?? '')));
        if ($status === '') {
            $status = $content === 'CONTENT_COMPLETE' || $content === 'NOT_APPLICABLE' ? 'RICH' : ($content === 'CONTENT_NEEDS_REVIEW' ? 'NEEDS_REVIEW' : 'UNAVAILABLE');
        }
        return [
            'status' => $status,
            'blockers' => $this->strings($readiness['blockers'] ?? []),
            'warnings' => $this->strings($readiness['warnings'] ?? []),
            'gaps' => $this->strings($readiness['gaps'] ?? []),
            'dependency_state' => $dependencies,
            'relation_state' => $relations,
        ];
    }

    /** @return array<string,mixed> */
    private function publicationReadiness(array $evidence, string $public, string $frontend, string $projectionConsistency, bool $publicCapable, array $outcomeObligations = []): array
    {
        $readiness = is_array($evidence['publication_readiness'] ?? null) ? $evidence['publication_readiness'] : [];
        $status = strtoupper(trim((string) ($readiness['status'] ?? '')));
        $publicationObligation = $this->obligation($outcomeObligations, 'publication');
        if ($status === '') {
            $status = ($publicationObligation['class'] ?? '') === 'NOT_APPLICABLE'
                ? 'NOT_APPLICABLE'
                : (($publicationObligation['class'] ?? '') !== 'REQUIRED' && $outcomeObligations !== [] && $public === 'NOT_APPLICABLE' && $frontend === 'NOT_APPLICABLE'
                    ? 'NOT_APPLICABLE'
                    : (!$publicCapable ? 'NOT_APPLICABLE' : ($public === 'READY' && $frontend === 'VERIFIED' && $projectionConsistency !== 'BLOCKED' ? 'READY' : 'BLOCKED')));
        }
        $blockers = $this->strings($readiness['blockers'] ?? []);
        if ($publicCapable && $public === 'BLOCKED' && !in_array('PUBLIC_ELIGIBILITY_NOT_VERIFIED', $blockers, true)) $blockers[] = 'PUBLIC_ELIGIBILITY_NOT_VERIFIED';
        if ($publicCapable && $frontend === 'BLOCKED' && !in_array('FRONTEND_READBACK_NOT_VERIFIED', $blockers, true)) $blockers[] = 'FRONTEND_READBACK_NOT_VERIFIED';
        if ($projectionConsistency === 'BLOCKED' && !in_array('PROJECTION_REVISION_MISMATCH', $blockers, true)) $blockers[] = 'PROJECTION_REVISION_MISMATCH';
        return [
            'status' => $status,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => $this->strings($readiness['warnings'] ?? []),
            'surface' => trim((string) ($readiness['surface'] ?? '')),
        ];
    }

    /** @return array{class:string,reason:string} */
    private function obligation(array $plan, string $dimension): array
    {
        $obligation = is_array($plan['obligations'][$dimension] ?? null) ? $plan['obligations'][$dimension] : [];
        return [
            'class' => strtoupper(trim((string) ($obligation['class'] ?? ''))),
            'reason' => trim((string) ($obligation['reason'] ?? '')),
        ];
    }

    private function surfaceState(mixed $explicit, mixed $fallback, string $obligationClass, string $readyState): string
    {
        $allowed = [$readyState, 'BLOCKED', 'PARTIAL', 'NOT_APPLICABLE'];
        if (is_string($explicit) && in_array(strtoupper(trim($explicit)), $allowed, true)) return strtoupper(trim($explicit));
        if (is_bool($fallback)) return $fallback ? $readyState : 'BLOCKED';
        return $obligationClass === 'REQUIRED' ? 'BLOCKED' : 'NOT_APPLICABLE';
    }

    /** @return list<string> */
    private function requiredSurfaceBlockers(array $plan, string $public, string $frontend): array
    {
        $blockers = [];
        if ($this->obligation($plan, 'public')['class'] === 'REQUIRED' && $public !== 'READY') $blockers[] = 'PUBLIC_ELIGIBILITY_NOT_VERIFIED';
        if ($this->obligation($plan, 'frontend')['class'] === 'REQUIRED' && $frontend !== 'VERIFIED') $blockers[] = 'FRONTEND_READBACK_NOT_VERIFIED';
        return $blockers;
    }

    private function surfaceReason(array $plan, string $dimension, string $state): string
    {
        $reason = $this->obligation($plan, $dimension)['reason'];
        if ($reason !== '') return $reason;
        return $state === 'NOT_APPLICABLE' ? 'NO_APPLICABLE_PUBLIC_SURFACE' : '';
    }

    /** @param list<string> $blockers @return list<string> */
    private function ownerBlockers(array $blockers): array
    {
        return array_values(array_filter($blockers, static function (string $blocker): bool {
            return !in_array($blocker, [
                'CONTENT_NEEDS_REVIEW', 'LOW_INFORMATION_GAIN', 'PUBLIC_ELIGIBILITY_NOT_VERIFIED',
                'FRONTEND_READBACK_NOT_VERIFIED', 'VIDEO_PROJECTION_REVISION_MISMATCH',
            ], true);
        }));
    }

    /** @param list<array<string,mixed>> $children @return array<string,mixed> */
    public function aggregateCapture(string $captureId, array $children, array $evidence = []): array
    {
        $outcomeObligations = is_array($evidence['outcome_obligations'] ?? null) ? $evidence['outcome_obligations'] : [];
        $children = $this->normalizeSemanticDependencyChildren($children, (array) ($evidence['semantic_dependency_owner_types'] ?? []));
        $children = self::effectiveChildren($children);
        $packets = [];
        $blockers = $this->strings($evidence['blockers'] ?? []);
        foreach ($children as $child) {
            if (!is_array($child)) continue;
            $packet = is_array($child['completion'] ?? null)
                ? $this->recomputeCurrentCompletion($child['completion'])
                : $this->finalize((string) ($child['owner_type'] ?? ''), (string) ($child['owner_id'] ?? ''), $child);
            if (is_array($child['completion'] ?? null)) $child['completion'] = $packet;
            $packets[] = $packet;
            if (($packet['complete'] ?? false) !== true) array_push($blockers, ...$this->strings($packet['blockers'] ?? []));
            if (($packet['publication_readiness']['status'] ?? '') === 'BLOCKED') array_push($blockers, ...$this->strings($packet['publication_readiness']['blockers'] ?? []));
        }
        $requiredOwners = $this->ownerSpecs($evidence['required_owners'] ?? []);
        $missingRequiredOwners = array_values(array_filter($requiredOwners, function (array $required) use ($packets): bool {
            foreach ($packets as $packet) {
                if (!is_array($packet)) continue;
                if (strtolower((string) ($packet['owner_type'] ?? '')) !== $required['owner_type']) continue;
                $requiredId = $required['owner_id'];
                if ($requiredId !== '' && $requiredId === trim((string) ($packet['owner_id'] ?? ''))) return false;
            }
            return true;
        }));
        if ($missingRequiredOwners !== []) $blockers[] = 'REQUIRED_OWNER_READBACK_UNVERIFIED';
        $effectiveChildrenReadbackVerified = $packets !== []
            && array_reduce($packets, static function (bool $verified, array $packet): bool {
                return $verified
                    && trim((string) ($packet['owner_type'] ?? '')) !== ''
                    && trim((string) ($packet['owner_id'] ?? '')) !== ''
                    && ($packet['complete'] ?? false) === true
                    && ($packet['canonical_readback_verified'] ?? false) === true;
            }, true);
        $canonicalReadbackVerified = $this->readBack($evidence['canonical_readback'] ?? null)
            || $effectiveChildrenReadbackVerified;
        $canonical = ($evidence['canonical_state'] ?? null) === 'BLOCKED' || !$canonicalReadbackVerified ? 'BLOCKED' : 'COMPLETE';
        if (!$canonicalReadbackVerified) $blockers[] = 'CANONICAL_READBACK_UNVERIFIED';
        $complete = $canonical === 'COMPLETE' && $packets !== [] && $blockers === [] && array_reduce($packets, static fn (bool $ok, array $packet): bool => $ok && ($packet['complete'] ?? false) === true, true);
        $resumeChildren = [];
        foreach ($missingRequiredOwners as $required) $resumeChildren[] = $this->resumeChild($required['owner_type']);
        foreach ($packets as $packet) {
            if (($packet['complete'] ?? false) === true) continue;
            $resumeChildren[] = $this->resumeChild((string) ($packet['owner_type'] ?? ''));
        }
        $resumeChildren = array_values(array_unique(array_filter($resumeChildren, static fn (string $item): bool => $item !== '')));
        $relationStates = array_values(array_filter(array_map(static fn (array $packet): string => strtoupper(trim((string) ($packet['relation_or_usage_state'] ?? ''))), $packets), static fn (string $state): bool => $state !== '' && $state !== 'NOT_APPLICABLE'));
        $publicStates = array_values(array_filter(array_map(static fn (array $packet): string => strtoupper(trim((string) ($packet['public_state'] ?? ''))), $packets), static fn (string $state): bool => $state !== '' && $state !== 'NOT_APPLICABLE'));
        $frontendStates = array_values(array_filter(array_map(static fn (array $packet): string => strtoupper(trim((string) ($packet['frontend_state'] ?? ''))), $packets), static fn (string $state): bool => $state !== '' && $state !== 'NOT_APPLICABLE'));
        $aggregateState = static function (array $states, string $complete): string {
            if (in_array('BLOCKED', $states, true)) return 'BLOCKED';
            if (in_array('PARTIAL', $states, true)) return 'PARTIAL';
            return in_array($complete, $states, true) || in_array('READY', $states, true) || in_array('VERIFIED', $states, true) ? $complete : 'NOT_APPLICABLE';
        };
        $aggregatePublicState = $aggregateState($publicStates, 'READY');
        $aggregateFrontendState = $aggregateState($frontendStates, 'VERIFIED');
        if ($this->obligation($outcomeObligations, 'public')['class'] === 'REQUIRED' && $aggregatePublicState === 'NOT_APPLICABLE') $aggregatePublicState = 'BLOCKED';
        if ($this->obligation($outcomeObligations, 'frontend')['class'] === 'REQUIRED' && $aggregateFrontendState === 'NOT_APPLICABLE') $aggregateFrontendState = 'BLOCKED';
        array_push($blockers, ...$this->requiredSurfaceBlockers($outcomeObligations, $aggregatePublicState, $aggregateFrontendState));
        $complete = $canonical === 'COMPLETE' && $packets !== [] && $blockers === [] && array_reduce($packets, static fn (bool $ok, array $packet): bool => $ok && ($packet['complete'] ?? false) === true, true);
        return [
            'owner_type' => 'capture',
            'owner_id' => trim($captureId),
            'canonical_state' => $canonical,
            'canonical_readback_verified' => $canonicalReadbackVerified,
            'canonical_existence' => [
                'status' => $canonical,
                'blockers' => $canonical === 'COMPLETE' ? [] : ['CANONICAL_READBACK_UNVERIFIED'],
                'readback_verified' => $canonicalReadbackVerified,
            ],
            'required_owners' => $requiredOwners,
            'missing_required_owners' => $missingRequiredOwners,
            'dependency_state' => $complete ? 'COMPLETE' : 'PARTIAL',
            'relation_or_usage_state' => $aggregateState($relationStates, 'COMPLETE'),
            'public_state' => $aggregatePublicState,
            'public_state_reason' => $this->surfaceReason($outcomeObligations, 'public', $aggregatePublicState),
            'frontend_state' => $aggregateFrontendState,
            'frontend_state_reason' => $this->surfaceReason($outcomeObligations, 'frontend', $aggregateFrontendState),
            'enrichment_readiness' => [
                'status' => $complete ? 'RICH' : 'PARTIAL',
                'blockers' => [],
                'warnings' => [],
                'gaps' => $resumeChildren,
            ],
            'publication_readiness' => [
                'status' => $outcomeObligations !== [] && ($this->obligation($outcomeObligations, 'publication')['class'] === 'REQUIRED')
                    ? (($aggregatePublicState === 'READY' && $aggregateFrontendState === 'VERIFIED') ? 'READY' : 'BLOCKED')
                    : 'NOT_APPLICABLE',
                'blockers' => $outcomeObligations !== [] && ($this->obligation($outcomeObligations, 'publication')['class'] === 'REQUIRED') ? $this->requiredSurfaceBlockers($outcomeObligations, $aggregatePublicState, $aggregateFrontendState) : [],
                'warnings' => [],
                'surface' => '',
            ],
            'outcome_obligations' => $outcomeObligations !== [] ? $outcomeObligations : null,
            'complete' => $complete,
            'status' => $complete ? 'COMPLETE' : ($canonical === 'BLOCKED' ? 'BLOCKED' : 'PARTIAL'),
            'blockers' => array_values(array_unique($blockers)),
            'children' => $packets,
            'resume_hints' => ['resume_children' => $resumeChildren],
        ];
    }

    /**
     * Completion input is ordered from historical projections to the current
     * recomputation. For one canonical owner, the last projection is the
     * effective current outcome; earlier projections remain in receipts and
     * audit history but cannot continue to poison the aggregate.
     *
     * @param list<array<string,mixed>> $children
     * @return list<array<string,mixed>>
     */
    public static function effectiveChildren(array $children): array
    {
        $currentKeyedTypes = [];
        foreach ($children as $child) {
            if (!is_array($child)) continue;
            $packet = is_array($child['completion'] ?? null) ? $child['completion'] : $child;
            $ownerType = strtolower(trim((string) ($packet['owner_type'] ?? '')));
            $ownerId = trim((string) ($packet['owner_id'] ?? ''));
            $isCurrent = ($child['current_outcome'] ?? false) === true || ($packet['current_outcome'] ?? false) === true;
            if ($ownerType !== '' && $ownerId !== '' && $isCurrent) $currentKeyedTypes[$ownerType] = true;
        }
        if ($currentKeyedTypes !== []) {
            $children = array_values(array_filter($children, static function (mixed $child) use ($currentKeyedTypes): bool {
                if (!is_array($child)) return false;
                $packet = is_array($child['completion'] ?? null) ? $child['completion'] : $child;
                $ownerType = strtolower(trim((string) ($packet['owner_type'] ?? '')));
                $ownerId = trim((string) ($packet['owner_id'] ?? ''));
                return $ownerId !== '' || !isset($currentKeyedTypes[$ownerType]);
            }));
        }
        $positions = [];
        $currentPositions = [];
        $effective = [];
        foreach ($children as $child) {
            if (!is_array($child)) continue;
            $packet = is_array($child['completion'] ?? null) ? $child['completion'] : $child;
            $ownerType = strtolower(trim((string) ($packet['owner_type'] ?? '')));
            $ownerId = trim((string) ($packet['owner_id'] ?? ''));
            $identity = $ownerType !== '' && $ownerId !== ''
                ? $ownerType . '|' . $ownerId
                : '__unkeyed__' . count($effective);
            $isCurrent = ($child['current_outcome'] ?? false) === true || ($packet['current_outcome'] ?? false) === true;
            if (isset($positions[$identity])) {
                if ($isCurrent || !isset($currentPositions[$identity])) $effective[$positions[$identity]] = $child;
                if ($isCurrent) $currentPositions[$identity] = true;
                continue;
            }
            $positions[$identity] = count($effective);
            if ($isCurrent) $currentPositions[$identity] = true;
            $effective[] = $child;
        }
        return array_values($effective);
    }

    /** @param list<array<string,mixed>> $children @param list<mixed> $dependencyTypes @return list<array<string,mixed>> */
    private function normalizeSemanticDependencyChildren(array $children, array $dependencyTypes): array
    {
        $dependencyTypes = array_values(array_unique(array_filter(array_map(static fn (mixed $type): string => strtolower(trim((string) $type)), $dependencyTypes))));
        if ($dependencyTypes === []) return $children;
        foreach ($children as $index => $child) {
            if (!is_array($child)) continue;
            $wrapped = is_array($child['completion'] ?? null);
            $packet = $wrapped ? $child['completion'] : $child;
            $type = strtolower(trim((string) ($packet['owner_type'] ?? '')));
            if (!in_array($type, $dependencyTypes, true)) continue;
            $ownerId = trim((string) ($packet['owner_id'] ?? ''));
            $readback = is_array($packet['canonical_readback'] ?? null) ? $packet['canonical_readback'] : [];
            if ($readback === [] && ($packet['canonical_readback_verified'] ?? false) === true && $ownerId !== '') $readback = ['canonical_id' => $ownerId];
            $blockers = array_values(array_filter(array_map('strval', (array) ($packet['blockers'] ?? [])), static fn (string $blocker): bool => !in_array($blocker, ['PUBLIC_ELIGIBILITY_NOT_VERIFIED', 'FRONTEND_READBACK_NOT_VERIFIED'], true)));
            $normalized = $this->finalize($type, $ownerId, [
                'canonical_state' => $packet['canonical_state'] ?? null,
                'canonical_readback' => $readback,
                'dependency_state' => $packet['dependency_state'] ?? null,
                'relation_or_usage_state' => $packet['relation_or_usage_state'] ?? null,
                'blockers' => $blockers,
                'owner_role' => 'semantic_dependency',
                'public_projection_owner' => false,
            ]);
            if ($wrapped) $child['completion'] = $normalized;
            else $child = $normalized;
            $children[$index] = $child;
        }
        return $children;
    }

    private function readBack(mixed $value): bool
    {
        return is_array($value) && trim((string) ($value['canonical_id'] ?? $value['id'] ?? '')) !== '';
    }

    /** @param array<string,mixed> $packet @return array<string,mixed> */
    private function recomputeCurrentCompletion(array $packet): array
    {
        if (!is_array($packet['canonical_readback'] ?? null) || !$this->readBack($packet['canonical_readback'])) return $packet;
        $evidence = [
            'proposal_state' => $packet['proposal_state'] ?? null,
            'canonical_state' => $packet['canonical_state'] ?? null,
            'canonical_readback' => $packet['canonical_readback'],
            'dependency_state' => $packet['dependency_state'] ?? null,
            'relation_or_usage_state' => $packet['relation_or_usage_state'] ?? null,
            'content_quality' => $packet['content_state'] ?? null,
            'public_state' => $packet['public_state'] ?? null,
            'frontend_state' => $packet['frontend_state'] ?? null,
            'outcome_obligations' => is_array($packet['outcome_obligations'] ?? null) ? $packet['outcome_obligations'] : [],
            'blockers' => $packet['blockers'] ?? [],
            'owner_role' => ($packet['public_state'] ?? null) === 'NOT_APPLICABLE' && ($packet['frontend_state'] ?? null) === 'NOT_APPLICABLE' ? 'semantic_dependency' : null,
            'public_projection_owner' => !(($packet['public_state'] ?? null) === 'NOT_APPLICABLE' && ($packet['frontend_state'] ?? null) === 'NOT_APPLICABLE'),
        ];
        if (array_key_exists('projection_consistency', $packet) && strtoupper(trim((string) $packet['projection_consistency'])) !== 'NOT_APPLICABLE') $evidence['projection_consistency'] = $packet['projection_consistency'];
        return $this->finalize((string) ($packet['owner_type'] ?? ''), (string) ($packet['owner_id'] ?? ''), $evidence);
    }

    /** @param list<string> $allowed */
    private function state(mixed $explicit, mixed $fallback, string ...$allowed): string
    {
        if (is_string($explicit) && in_array(strtoupper(trim($explicit)), $allowed, true)) return strtoupper(trim($explicit));
        if (is_bool($fallback)) return $fallback ? $allowed[0] : ($allowed[1] ?? 'BLOCKED');
        if (is_array($fallback)) return $allowed[0];
        return $allowed[1] ?? $allowed[0];
    }

    /** @return list<array{owner_type:string,owner_id:string}> */
    private function ownerSpecs(mixed $value): array
    {
        if (!is_array($value)) return [];
        $owners = [];
        foreach ($value as $item) {
            if (is_string($item)) $item = ['owner_type' => $item];
            if (!is_array($item)) continue;
            $type = strtolower(trim((string) ($item['owner_type'] ?? $item['type'] ?? '')));
            if ($type === '') continue;
            $owners[] = ['owner_type' => $type, 'owner_id' => trim((string) ($item['owner_id'] ?? $item['id'] ?? ''))];
        }
        return array_values(array_unique($owners, SORT_REGULAR));
    }

    private function resumeChild(string $ownerType): string
    {
        return match (strtolower(trim($ownerType))) {
            'wp_post' => 'article',
            default => strtolower(trim($ownerType)),
        };
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (!is_array($value)) return [];
        return array_values(array_filter(array_map(static fn (mixed $item): string => trim((string) $item), $value), static fn (string $item): bool => $item !== ''));
    }
}
