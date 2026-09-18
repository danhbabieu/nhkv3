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
        $publicCapable = in_array($ownerType, self::PUBLIC_CAPABLE, true);
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
        $content = $ownerType === 'video'
            ? $this->state($evidence['content_quality'] ?? null, null, 'CONTENT_COMPLETE', 'CONTENT_NEEDS_REVIEW', 'BLOCKED')
            : 'NOT_APPLICABLE';
        $projectionConsistency = 'NOT_APPLICABLE';
        if ($ownerType === 'video' && array_key_exists('projection_consistency', $evidence)) {
            $projectionConsistency = $this->state($evidence['projection_consistency'], null, 'COMPLETE', 'BLOCKED', 'PARTIAL');
        }

        if (!$publicCapable) {
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
        if ($ownerType === 'video' && $content !== 'CONTENT_COMPLETE') $blockers[] = 'CONTENT_NEEDS_REVIEW';
        if ($ownerType === 'video' && $projectionConsistency === 'BLOCKED') $blockers[] = 'VIDEO_PROJECTION_REVISION_MISMATCH';
        if ($public === 'BLOCKED' && $publicCapable && $blockers === []) $blockers[] = 'PUBLIC_ELIGIBILITY_NOT_VERIFIED';
        if ($frontend === 'BLOCKED' && $publicCapable && $blockers === []) $blockers[] = 'FRONTEND_READBACK_NOT_VERIFIED';

        $complete = $canonical === 'COMPLETE'
            && in_array($dependencies, ['COMPLETE', 'NOT_APPLICABLE'], true)
            && in_array($relations, ['COMPLETE', 'NOT_APPLICABLE'], true)
            && in_array($content, ['CONTENT_COMPLETE', 'NOT_APPLICABLE'], true)
            && in_array($projectionConsistency, ['COMPLETE', 'NOT_APPLICABLE'], true)
            && in_array($public, ['READY', 'NOT_APPLICABLE'], true)
            && in_array($frontend, ['VERIFIED', 'NOT_APPLICABLE'], true)
            && $blockers === [];

        return [
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'proposal_state' => $evidence['proposal_state'] ?? null,
            'canonical_state' => $canonical,
            'canonical_readback_verified' => $canonicalReadbackVerified,
            'dependency_state' => $dependencies,
            'relation_or_usage_state' => $relations,
            'content_state' => $content,
            'projection_consistency' => $projectionConsistency,
            'public_state' => $public,
            'frontend_state' => $frontend,
            'complete' => $complete,
            'status' => $complete ? 'COMPLETE' : ($canonical === 'BLOCKED' ? 'BLOCKED' : 'PARTIAL'),
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    /** @param list<array<string,mixed>> $children @return array<string,mixed> */
    public function aggregateCapture(string $captureId, array $children, array $evidence = []): array
    {
        $packets = [];
        $blockers = $this->strings($evidence['blockers'] ?? []);
        foreach ($children as $child) {
            if (!is_array($child)) continue;
            $packet = is_array($child['completion'] ?? null)
                ? $child['completion']
                : $this->finalize((string) ($child['owner_type'] ?? ''), (string) ($child['owner_id'] ?? ''), $child);
            $packets[] = $packet;
            if (($packet['complete'] ?? false) !== true) array_push($blockers, ...$this->strings($packet['blockers'] ?? []));
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
        $canonical = ($evidence['canonical_state'] ?? null) === 'BLOCKED' ? 'BLOCKED' : 'COMPLETE';
        $complete = $canonical === 'COMPLETE' && $packets !== [] && $blockers === [] && array_reduce($packets, static fn (bool $ok, array $packet): bool => $ok && ($packet['complete'] ?? false) === true, true);
        $resumeChildren = [];
        foreach ($missingRequiredOwners as $required) $resumeChildren[] = $this->resumeChild($required['owner_type']);
        foreach ($packets as $packet) {
            if (($packet['complete'] ?? false) === true) continue;
            $resumeChildren[] = $this->resumeChild((string) ($packet['owner_type'] ?? ''));
        }
        $resumeChildren = array_values(array_unique(array_filter($resumeChildren, static fn (string $item): bool => $item !== '')));
        return [
            'owner_type' => 'capture',
            'owner_id' => trim($captureId),
            'canonical_state' => $canonical,
            'required_owners' => $requiredOwners,
            'missing_required_owners' => $missingRequiredOwners,
            'dependency_state' => $complete ? 'COMPLETE' : 'PARTIAL',
            'relation_or_usage_state' => 'NOT_APPLICABLE',
            'public_state' => 'NOT_APPLICABLE',
            'frontend_state' => 'NOT_APPLICABLE',
            'complete' => $complete,
            'status' => $complete ? 'COMPLETE' : ($canonical === 'BLOCKED' ? 'BLOCKED' : 'PARTIAL'),
            'blockers' => array_values(array_unique($blockers)),
            'children' => $packets,
            'resume_hints' => ['resume_children' => $resumeChildren],
        ];
    }

    private function readBack(mixed $value): bool
    {
        return is_array($value) && trim((string) ($value['canonical_id'] ?? $value['id'] ?? '')) !== '';
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
