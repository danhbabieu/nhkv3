<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Entity\{EntityProfileReadFoundation, EntityProfileRegistry, EntityProfileResolver};
use NHK\Core\Contracts\Entity\EntityDossierReader;
use NHK\Core\Domain\Authority\{AuthorityEntity, AuthorityState};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class EntityProfileRegistryTest extends TestCase
{
    public function test_registry_describes_brand_and_clock_type_without_adding_authority_or_predicate_vocabulary(): void
    {
        $registry = new EntityProfileRegistry();

        self::assertSame(['brand', 'clock_type'], $registry->keys());
        self::assertSame('brand', $registry->get('brand')->matchingRule['entity_type']);
        self::assertSame('clock_type', $registry->get('clock_type')->matchingRule['family']);
        self::assertSame('classification', $registry->get('clock_type')->matchingRule['entity_type']);
        self::assertSame('clock-type-entity-hub-v1', $registry->get('clock_type')->relationQueryRecipe);
        self::assertSame(['clock-type'], $registry->get('clock_type')->readFamilyAliases);
        self::assertContains('brands', $registry->get('clock_type')->relationTargetGroups);
        self::assertContains('specimens', $registry->get('clock_type')->relationTargetGroups);
        self::assertFalse($registry->get('clock_type')->rootDetailRouteIntent['enabled']);
        self::assertContains('knowledge', $registry->get('clock_type')->supportedDossierSections);
        self::assertContains('video', $registry->get('clock_type')->supportedDossierSections);
        self::assertNotContains('brand_has_clock_type', $registry->get('clock_type')->capabilities);
        self::assertNotContains('classified_as', $registry->get('clock_type')->capabilities);
    }

    public function test_brand_resolves_from_canonical_entity_type_only(): void
    {
        $entity = $this->entity('brand', 'Đồng hồ vai bò', ['description' => 'not a classification']);

        $resolution = (new EntityProfileResolver())->resolveProfile($entity);

        self::assertSame('RESOLVED', $resolution->status);
        self::assertSame('brand', $resolution->profileKey);
        self::assertSame('not_applicable', $resolution->familyState);
    }

    public function test_canonical_clock_type_resolves_from_classification_and_exact_family(): void
    {
        $entity = $this->entity('classification', 'Đồng hồ vai bò', ['family' => 'clock_type']);

        $resolution = (new EntityProfileResolver())->resolveProfile($entity);

        self::assertSame('RESOLVED', $resolution->status);
        self::assertSame('clock_type', $resolution->profileKey);
        self::assertSame('canonical', $resolution->familyState);
        self::assertSame('clock_type', $resolution->canonicalFamily);
        self::assertSame('clock_type', $entity->payload['family']);
    }

    public function test_legacy_clock_type_is_read_compatibility_without_rewriting_entity(): void
    {
        $entity = $this->entity('classification', 'Đồng hồ vai bò', ['family' => 'clock-type']);
        $before = $entity->payload;

        $resolution = (new EntityProfileResolver())->resolveProfile($entity);

        self::assertSame('COMPATIBILITY_READ', $resolution->status);
        self::assertSame('clock_type', $resolution->profileKey);
        self::assertSame('legacy-compatible', $resolution->familyState);
        self::assertSame('clock-type', $resolution->storedFamily);
        self::assertSame('clock_type', $resolution->canonicalFamily);
        self::assertSame('DATA_COMPATIBILITY_GAP', $resolution->diagnostic);
        self::assertSame($before, $entity->payload);
    }

    public function test_other_missing_or_unknown_classification_family_is_unresolved_and_never_guessed(): void
    {
        $resolver = new EntityProfileResolver();

        $caseForm = $resolver->resolveProfile($this->entity('classification', 'Đồng hồ vai bò', ['family' => 'case_form']));
        $unknown = $resolver->resolveProfile($this->entity('classification', 'Đồng hồ vai bò', ['family' => 'future-facet']));
        $missing = $resolver->resolveProfile($this->entity('classification', 'Đồng hồ vai bò', []));

        foreach ([$caseForm, $unknown, $missing] as $resolution) {
            self::assertSame('PROFILE_UNRESOLVED', $resolution->status);
            self::assertNull($resolution->profileKey);
            self::assertSame('unresolved', $resolution->familyState);
        }
        self::assertSame('FAMILY_NOT_CLOCK_TYPE', $caseForm->diagnostic);
        self::assertSame('CLASSIFICATION_FAMILY_UNRESOLVED', $unknown->diagnostic);
        self::assertSame('CLASSIFICATION_FAMILY_UNRESOLVED', $missing->diagnostic);
    }

    public function test_profile_resolution_does_not_use_title_slug_or_stable_key_as_fallback(): void
    {
        $entity = $this->entity('classification', 'Đồng hồ vai bò', [], 'nhk:classification:clock-type.vai-bo');

        $resolution = (new EntityProfileResolver())->resolveProfile($entity);

        self::assertSame('PROFILE_UNRESOLVED', $resolution->status);
        self::assertSame('CLASSIFICATION_FAMILY_UNRESOLVED', $resolution->diagnostic);
    }

    public function test_clock_type_read_foundation_returns_shared_dossier_packet_without_brand_dependency(): void
    {
        $entity = $this->entity('classification', 'Đồng hồ công cộng', ['family' => 'clock_type']);
        $reader = new InMemoryEntityDossierReader();
        $foundation = new EntityProfileReadFoundation(new EntityProfileRegistry(), new EntityProfileResolver(), $reader);

        $packet = $foundation->forEntity($entity);

        self::assertSame('AVAILABLE', $packet['status']);
        self::assertSame('clock_type', $packet['entity_profile']['key']);
        self::assertSame('RESOLVED', $packet['profile_resolution']['status']);
        self::assertSame('classification', $packet['identity']['type']);
        self::assertSame([], $packet['relation_sections']['brands']);
        self::assertSame(['text' => 'reader-safe'], $packet['knowledge']['facets']['overview']);
        self::assertSame([], $packet['media_gallery']);
        self::assertSame([], $packet['relation_sections']['videos']);
        self::assertSame(1, $reader->calls);
    }

    public function test_brand_read_foundation_keeps_existing_dossier_shape_and_uses_brand_profile(): void
    {
        $entity = $this->entity('brand', 'Odo', ['description' => 'existing brand']);
        $reader = new InMemoryEntityDossierReader();
        $foundation = new EntityProfileReadFoundation(new EntityProfileRegistry(), new EntityProfileResolver(), $reader);

        $packet = $foundation->forEntity($entity);

        self::assertSame('AVAILABLE', $packet['status']);
        self::assertSame('brand', $packet['entity_profile']['key']);
        self::assertSame('brand', $packet['identity']['type']);
        self::assertSame([], $packet['relation_sections']['brands']);
        self::assertSame([], $packet['relation_sections']['videos']);
    }

    public function test_unresolved_profile_returns_typed_safe_outcome_without_reading_or_mutating_dossier(): void
    {
        $reader = new InMemoryEntityDossierReader();
        $foundation = new EntityProfileReadFoundation(new EntityProfileRegistry(), new EntityProfileResolver(), $reader);

        $packet = $foundation->forEntity($this->entity('classification', 'Đồng hồ vai bò', ['family' => 'case_form']));

        self::assertSame('UNAVAILABLE', $packet['status']);
        self::assertSame('PROFILE_UNRESOLVED', $packet['reason']);
        self::assertSame('FAMILY_NOT_CLOCK_TYPE', $packet['profile_resolution']['diagnostic']);
        self::assertSame(0, $reader->calls);
    }

    private function entity(string $type, string $name, array $payload, string $stableKey = 'classification.vai-bo'): AuthorityEntity
    {
        return new AuthorityEntity(UuidCodec::newV7(), $type, $stableKey, $name, 1, $payload, AuthorityState::ACTIVE, 1);
    }
}

final class InMemoryEntityDossierReader implements EntityDossierReader
{
    public int $calls = 0;

    public function forEntity(AuthorityEntity $entity): array
    {
        $this->calls++;

        return [
            'status' => 'AVAILABLE',
            'identity' => ['type' => $entity->entityType, 'name' => $entity->canonicalName, 'url' => '/phan-loai/vai-bo/'],
            'knowledge' => ['status' => 'AVAILABLE', 'facets' => ['overview' => ['text' => 'reader-safe']], 'claim_count' => 1, 'evidence_count' => 0],
            'relation_sections' => ['brands' => [], 'videos' => [], 'specimens' => [], 'products' => []],
            'primary_media' => [],
            'media_gallery' => [],
            'seo_projection' => ['canonical' => '/phan-loai/vai-bo/'],
            'coverage' => ['relation_count' => 0],
            'warnings' => [],
            'availability' => ['graph' => 'AVAILABLE', 'knowledge' => 'AVAILABLE'],
        ];
    }
}
