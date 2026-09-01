<?php
declare(strict_types=1);
namespace NHK\Core\Domain\Authority;
use NHK\Core\Graph\Exception\InvalidEndpointReference;
final readonly class AuthorityEntity {
    public function __construct(public string $canonicalId, public string $entityType, public string $stableKey, public string $canonicalName, public int $schemaVersion, public array $payload, public AuthorityState $state=AuthorityState::ACTIVE, public int $revision=1, public ?string $createdAt=null, public ?string $updatedAt=null, public ?string $retiredAt=null) {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $canonicalId)) throw new InvalidEndpointReference('Authority canonical UUID is invalid.');
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{0,190}$/', $stableKey)) throw new InvalidEndpointReference('Stable key is invalid.');
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $entityType) || $canonicalName==='' || $schemaVersion < 1 || $revision < 1) throw new InvalidEndpointReference('Authority entity is invalid.');
    }
    public function active(): bool { return $this->state===AuthorityState::ACTIVE; }
}
