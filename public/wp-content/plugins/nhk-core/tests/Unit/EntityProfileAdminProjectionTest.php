<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Entity\{EntityProfileAdminProjection, EntityProfileRegistry, EntityProfileResolver};
use NHK\Core\Contracts\Entity\EntityDossierReader;
use NHK\Core\Domain\Authority\{AuthorityEntity, AuthorityState};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class EntityProfileAdminProjectionTest extends TestCase
{
    public function test_clock_type_card_uses_registry_identity_and_exposes_read_sections(): void
    {
        $entity = $this->entity('classification', 'Đồng hồ vai bò', [
            'family' => 'clock_type',
            'aliases' => ['vai bò', 'shoulder clock'],
            'description' => 'Loại đồng hồ có dáng vai bò.',
        ]);
        $projection = new EntityProfileAdminProjection(new EntityProfileRegistry(), new EntityProfileResolver(), new AdminProjectionFixtureDossierReader());

        $card = $projection->forEntity($entity);

        self::assertSame('AVAILABLE', $card['status']);
        self::assertSame('[LOẠI ĐỒNG HỒ]', $card['profile']['admin_badge']);
        self::assertSame('clock_type', $card['profile']['profile_key']);
        self::assertSame('classification', $card['identity']['entity_type']);
        self::assertSame('clock_type', $card['identity']['family']);
        self::assertSame($entity->canonicalId, $card['identity']['uuid']);
        self::assertSame($entity->stableKey, $card['identity']['stable_key']);
        self::assertSame(1, $card['identity']['revision']);
        self::assertSame('ACTIVE', $card['identity']['state']);
        self::assertSame($entity->payload['aliases'], $card['aliases']);
        self::assertSame($entity->payload['description'], $card['description']);
        foreach (['knowledge', 'media', 'video', 'articles', 'models', 'variants', 'specimens', 'products', 'brands', 'subtypes', 'parent', 'public_identity', 'seo', 'diagnostics'] as $key) {
            self::assertArrayHasKey($key, $card['sections'], $key);
        }
        self::assertSame('AVAILABLE_WITH_ITEMS', $card['sections']['models']['state']);
    }

    public function test_projection_preserves_empty_unavailable_and_blocked_section_states(): void
    {
        $card = (new EntityProfileAdminProjection(new EntityProfileRegistry(), new EntityProfileResolver(), new AdminProjectionStatesFixtureDossierReader()))->forEntity($this->entity('classification', 'Loại', ['family' => 'clock_type']));

        self::assertSame('AVAILABLE_EMPTY', $card['sections']['media']['state']);
        self::assertSame('UNAVAILABLE_IMPLEMENTATION_GAP', $card['sections']['brands']['state']);
        self::assertSame('BLOCKED', $card['sections']['video']['state']);
    }

    public function test_case_form_does_not_receive_clock_type_projection_capabilities(): void
    {
        $card = (new EntityProfileAdminProjection(new EntityProfileRegistry(), new EntityProfileResolver(), new AdminProjectionFixtureDossierReader()))->forEntity($this->entity('classification', 'Dáng vai bò', ['family' => 'case_form']));

        self::assertSame('UNAVAILABLE', $card['status']);
        self::assertSame('PROFILE_UNRESOLVED', $card['reason']);
        self::assertSame('FAMILY_NOT_CLOCK_TYPE', $card['diagnostics'][0]);
    }

    public function test_clock_type_admin_projection_reads_profile_aware_hierarchy_and_derived_brands(): void
    {
        $card = (new EntityProfileAdminProjection(new EntityProfileRegistry(), new EntityProfileResolver(), new AdminClockTypeDossierReader()))->forEntity($this->entity('classification', 'Đồng hồ công cộng', ['family' => 'clock_type']));

        self::assertSame('AVAILABLE_WITH_ITEMS', $card['sections']['subtypes']['state']);
        self::assertSame('Đồng hồ tháp', $card['sections']['subtypes']['items'][0]['name']);
        self::assertSame('AVAILABLE_WITH_ITEMS', $card['sections']['brands']['state']);
        self::assertSame('Odo', $card['sections']['brands']['items'][0]['title']);
        self::assertSame('AVAILABLE_EMPTY', $card['sections']['parent']['state']);
    }

    public function test_projection_source_has_no_persistence_or_writer_dependency(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Entity/EntityProfileAdminProjection.php');
        foreach (['AuthorityRepository', 'GraphWriter', 'KnowledgeWriter', 'INSERT ', 'UPDATE ', 'DELETE '] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source, $forbidden);
        }
    }

    public function test_admin_read_api_registers_entity_projection_route_without_a_write_route(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Http/AdminWorkbenchReadApi.php');

        self::assertStringContainsString('/admin/workbench/entity/', $source);
        self::assertStringContainsString('EntityProfileAdminProjection', $source);
        self::assertStringNotContainsString("methods' => 'POST'", $source);
    }

    public function test_plugin_exposes_the_clock_type_lifecycle_as_the_single_composition_seam(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Plugin.php');

        self::assertStringContainsString('nhk_v3_clock_type_creation_lifecycle', $source);
        self::assertStringContainsString('ClockTypeCreationLifecycle', $source);
    }

    private function entity(string $type, string $name, array $payload): AuthorityEntity
    {
        return new AuthorityEntity(UuidCodec::newV7(), $type, 'nhk:' . $type . ':projection', $name, 1, $payload, AuthorityState::ACTIVE, 1);
    }
}

final class AdminProjectionFixtureDossierReader implements EntityDossierReader
{
    public function forEntity(AuthorityEntity $entity): array
    {
        return [
            'status' => 'AVAILABLE',
            'knowledge' => ['status' => 'AVAILABLE', 'facets' => ['overview' => ['text' => 'read-only']], 'claim_count' => 1],
            'media_gallery' => [['id' => 'media-1']],
            'relation_sections' => [
                'models' => [['id' => 'model-1']], 'variants' => [], 'specimens' => [], 'products' => [],
                'brands' => [], 'videos' => [], 'articles' => [], 'classifications' => [],
            ],
            'identity' => ['url' => '/vai-bo/'],
            'seo_projection' => ['readiness' => 'READY'],
            'diagnostics' => [],
        ];
    }
}

final class AdminProjectionStatesFixtureDossierReader implements EntityDossierReader
{
    public function forEntity(AuthorityEntity $entity): array
    {
        return [
            'status' => 'AVAILABLE',
            'knowledge' => ['status' => 'AVAILABLE', 'facets' => [], 'claim_count' => 0],
            'media_gallery' => [],
            'relation_sections' => ['videos' => ['status' => 'BLOCKED', 'items' => []]],
            'identity' => [],
            'seo_projection' => null,
            'diagnostics' => [],
        ];
    }
}

final class AdminClockTypeDossierReader implements EntityDossierReader
{
    public function forEntity(AuthorityEntity $entity): array
    {
        return [
            'status' => 'AVAILABLE',
            'knowledge' => ['status' => 'AVAILABLE', 'facets' => []],
            'media_gallery' => [],
            'relation_sections' => ['brands' => [], 'classifications' => [], 'models' => [], 'variants' => [], 'specimens' => [], 'products' => [], 'videos' => [], 'articles' => []],
            'clock_type_hierarchy' => ['status' => 'AVAILABLE', 'parent' => [], 'children' => [['name' => 'Đồng hồ tháp', 'canonical_id' => 'child']], 'diagnostics' => []],
            'clock_type_derived_brands' => ['status' => 'AVAILABLE_WITH_ITEMS', 'items' => [['title' => 'Odo', 'canonical_id' => 'brand']], 'diagnostics' => []],
            'seo_projection' => null,
            'diagnostics' => [],
        ];
    }
}
