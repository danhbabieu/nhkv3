<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Authority;

use InvalidArgumentException;
use JsonException;
use NHK\Core\Domain\Authority\{AuthorityEntity, AuthorityState};
use NHK\Core\Graph\Exception\InvalidEndpointReference;
use NHK\Core\Shared\Uuid\UuidCodec;
use ValueError;

final class AuthorityRowHydrator
{
    /** @var callable(string): string */
    private $uuidDecoder;

    /** @param callable(string): string|null $uuidDecoder */
    public function __construct(?callable $uuidDecoder = null)
    {
        $this->uuidDecoder = $uuidDecoder ?? static fn (string $binary): string => UuidCodec::fromBinary($binary);
    }

    /** @throws MalformedAuthorityRow */
    public function hydrate(array $row): AuthorityEntity
    {
        $stableKey = isset($row['stable_key']) ? (string) $row['stable_key'] : null;
        try {
            $payload = json_decode((string) ($row['payload'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            $rawState = (string) ($row['state'] ?? '');
            if (!is_array($payload) || preg_match('/^[01]$/', $rawState) !== 1) {
                throw new InvalidArgumentException('Authority row payload or state is invalid.');
            }

            return new AuthorityEntity(
                ($this->uuidDecoder)((string) ($row['canonical_uuid'] ?? '')),
                (string) ($row['entity_type'] ?? ''),
                (string) ($row['stable_key'] ?? ''),
                (string) ($row['canonical_name'] ?? ''),
                (int) ($row['schema_version'] ?? 0),
                $payload,
                $rawState === '1' ? AuthorityState::ACTIVE : AuthorityState::RETIRED,
                (int) ($row['revision'] ?? 0),
                $row['created_at'] ?? null,
                $row['updated_at'] ?? null,
                $row['retired_at'] ?? null,
            );
        } catch (JsonException $error) {
            throw new MalformedAuthorityRow($error->getMessage(), $stableKey, 'INVALID_JSON');
        } catch (InvalidArgumentException|InvalidEndpointReference|ValueError $error) {
            throw new MalformedAuthorityRow($error->getMessage(), $stableKey, 'INVALID_DOMAIN_ROW');
        }
    }

    /** @param list<array<string,mixed>> $rows @return array{items:list<AuthorityEntity>,errors:list<array{reason_code:string,stable_key:?string}>} */
    public function hydrateMany(array $rows): array
    {
        $items = [];
        $errors = [];
        foreach ($rows as $row) {
            try {
                $items[] = $this->hydrate($row);
            } catch (MalformedAuthorityRow $error) {
                $errors[] = ['reason_code' => $error->reasonCode, 'stable_key' => $error->stableKey];
            }
        }
        return ['items' => $items, 'errors' => $errors];
    }
}
