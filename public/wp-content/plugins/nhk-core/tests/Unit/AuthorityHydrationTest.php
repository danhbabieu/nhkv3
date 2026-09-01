<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use Error;
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Infrastructure\Authority\AuthorityRowHydrator;
use PHPUnit\Framework\TestCase;

final class AuthorityHydrationTest extends TestCase
{
    public function test_valid_binary_uuid_row_hydrates_to_authority_entity(): void
    {
        $id = '018f0f4e-7b4d-7c72-9b18-5c2b3f3d6f11';
        $entity = (new AuthorityRowHydrator())->hydrate($this->row($id, 'brand', 'valid-brand', 'Valid Brand'));

        self::assertInstanceOf(AuthorityEntity::class, $entity);
        self::assertSame($id, $entity->canonicalId);
        self::assertSame('brand', $entity->entityType);
    }

    public function test_malformed_row_is_omitted_without_hiding_valid_neighbor(): void
    {
        $hydrated = (new AuthorityRowHydrator())->hydrateMany([
            $this->row('018f0f4e-7b4d-7c72-9b18-5c2b3f3d6f11', 'brand', 'first', 'First'),
            $this->row('not-binary', 'brand', 'broken', 'Broken'),
            $this->row('018f0f4e-7b4d-7c72-9b18-5c2b3f3d6f12', 'music', 'third', 'Third'),
        ]);

        self::assertCount(2, $hydrated['items']);
        self::assertSame(['first', 'third'], array_map(static fn (AuthorityEntity $item): string => $item->stableKey, $hydrated['items']));
        self::assertSame(['INVALID_DOMAIN_ROW'], array_column($hydrated['errors'], 'reason_code'));
    }

    public function test_infrastructure_or_programming_error_is_not_converted_to_empty_collection(): void
    {
        $hydrator = new AuthorityRowHydrator(static function (): string {
            throw new Error('autoload/runtime programming failure');
        });

        $this->expectException(Error::class);
        $hydrator->hydrate($this->row('018f0f4e-7b4d-7c72-9b18-5c2b3f3d6f11', 'brand', 'valid-brand', 'Valid Brand'));
    }

    public function test_malformed_json_has_a_precise_row_reason_code(): void
    {
        $row = $this->row('018f0f4e-7b4d-7c72-9b18-5c2b3f3d6f11', 'brand', 'broken-json', 'Broken JSON');
        $row['payload'] = '{';

        $result = (new AuthorityRowHydrator())->hydrateMany([$row]);

        self::assertSame('INVALID_JSON', $result['errors'][0]['reason_code']);
    }

    private function row(string $uuid, string $type, string $key, string $name): array
    {
        return [
            'canonical_uuid' => strlen($uuid) === 36 ? hex2bin(str_replace('-', '', $uuid)) : $uuid,
            'entity_type' => $type,
            'stable_key' => $key,
            'canonical_name' => $name,
            'schema_version' => '1',
            'payload' => '{}',
            'state' => '1',
            'revision' => '1',
            'created_at' => null,
            'updated_at' => null,
            'retired_at' => null,
        ];
    }
}
