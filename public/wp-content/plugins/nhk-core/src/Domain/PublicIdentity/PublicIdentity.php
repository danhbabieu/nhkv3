<?php
declare(strict_types=1);

namespace NHK\Core\Domain\PublicIdentity;

final readonly class PublicIdentity
{
    public function __construct(
        public string $identityId,
        public string $ownerKind,
        public string $ownerId,
        public string $routeType,
        public string $currentSlug,
        public string $collisionScope,
        public string $routePolicyVersion,
        public int $revision,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {
        if ($identityId === '' || !self::isToken($ownerKind) || trim($ownerId) === '') {
            throw new \InvalidArgumentException('Public identity owner is invalid.');
        }
        if (!self::isToken($routeType) || !self::isSlug($currentSlug) || trim($collisionScope) === '' || trim($routePolicyVersion) === '' || $revision < 1) {
            throw new \InvalidArgumentException('Public identity is invalid.');
        }
    }

    public function assertOwner(string $ownerKind, string $ownerId): PublicIdentityMutationResult
    {
        if (!self::isToken($ownerKind) || trim($ownerId) === '' || $this->ownerKind !== $ownerKind || $this->ownerId !== $ownerId) {
            return PublicIdentityMutationResult::rejected(PublicIdentityMutationResult::MALFORMED_OWNER);
        }

        return PublicIdentityMutationResult::accepted($this);
    }

    public function assertDoesNotCollideWithHistoricRoute(HistoricPublicRoute $historicRoute): PublicIdentityMutationResult
    {
        if ($this->routeType === $historicRoute->routeType && $this->collisionScope === $historicRoute->collisionScope && $this->currentSlug === $historicRoute->oldSlug) {
            return PublicIdentityMutationResult::rejected(PublicIdentityMutationResult::CONFLICT);
        }

        return PublicIdentityMutationResult::accepted($this);
    }

    public function replaceSlug(string $normalizedSlug, int $expectedRevision, HistoricPublicRoute $historicRoute): PublicIdentityMutationResult
    {
        if ($expectedRevision !== $this->revision) {
            return PublicIdentityMutationResult::rejected(PublicIdentityMutationResult::STALE_REVISION);
        }
        if (!self::isSlug($normalizedSlug)) {
            return PublicIdentityMutationResult::rejected(PublicIdentityMutationResult::UNKNOWN_ROUTE);
        }

        $replacementRevision = $this->revision + 1;
        if ($historicRoute->identityId !== $this->identityId || $historicRoute->routeType !== $this->routeType || $historicRoute->collisionScope !== $this->collisionScope || $historicRoute->oldSlug !== $this->currentSlug || $historicRoute->replacementRevision !== $replacementRevision) {
            return PublicIdentityMutationResult::rejected(PublicIdentityMutationResult::CONFLICT);
        }
        $updated = new self(
            $this->identityId,
            $this->ownerKind,
            $this->ownerId,
            $this->routeType,
            $normalizedSlug,
            $this->collisionScope,
            $this->routePolicyVersion,
            $replacementRevision,
            $this->createdAt,
            $this->updatedAt,
        );
        return PublicIdentityMutationResult::accepted($updated, $historicRoute);
    }

    private static function isToken(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/', $value) === 1;
    }

    private static function isSlug(string $value): bool
    {
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) === 1;
    }
}
