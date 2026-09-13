<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Entity\{PublicEntityCollectionQuery, PublicEntityEligibilityPolicy, PublicIdentityContract, PublicRouteResolver};
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class ClockTypeFrontendAcceptanceTest extends TestCase
{
    public function test_synthetic_clock_type_fixture_uses_profile_archive_and_persisted_identity(): void
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $authorityRepository = new InMemoryAuthorityRepository();
        $authority = new AuthorityService($authorityRepository, $types);
        $clockType = $authority->create('classification', 'nhk:classification:clock-type.public', 'Đồng hồ công cộng', ['family' => 'clock_type']);
        $authority->create('classification', 'nhk:classification:case-form.public', 'Đồng hồ công cộng', ['family' => 'case_form']);
        $identities = new ClockTypeFrontendFixtureIdentityRepository([
            'authority|' . $clockType->canonicalId . '|classification' => ['current_slug' => 'dong-ho-cong-cong'],
        ]);
        $routes = new PublicRouteResolver($authorityRepository, $types, null, null, $identities);
        $query = new PublicEntityCollectionQuery($authorityRepository, $types, new PublicIdentityContract($types, $identities), new PublicEntityEligibilityPolicy($authorityRepository, $types, $routes), $routes);

        $before = count($authorityRepository->listByType('classification', true));
        $archive = $query->archiveProfile('clock_type');
        $after = count($authorityRepository->listByType('classification', true));

        self::assertSame(1, $archive['total']);
        self::assertSame('clock_type', $archive['items'][0]['profile_key']);
        self::assertSame('[LOẠI ĐỒNG HỒ]', $archive['items'][0]['profile_badge']);
        self::assertSame('/phan-loai/dong-ho-cong-cong/', $archive['items'][0]['url']);
        self::assertSame($before, $after);
    }

    public function test_frontend_contract_keeps_clock_type_sections_and_direct_derived_states(): void
    {
        $theme = dirname(__DIR__, 4) . '/themes/nhk-v3';
        $entity = (string) file_get_contents($theme . '/entity.php');
        $index = (string) file_get_contents($theme . '/index.php');
        $functions = (string) file_get_contents($theme . '/functions.php');

        foreach (['clock-type-hierarchy', 'profile_key', 'Liên quan trực tiếp', 'Mở rộng từ quan hệ nền'] as $marker) self::assertStringContainsString($marker, $entity);
        self::assertStringContainsString('profile_badge', $index);
        self::assertStringContainsString('if ($profile === \'clock_type\')', $functions);
        self::assertStringNotContainsString('01a09872-6af8-7890-90b7-f913fab7bee4', $entity . $index . $functions);
        self::assertStringNotContainsString('nhk:classification:clock-type.dong-ho-cong-cong', $entity . $index . $functions);
    }

    public function test_missing_persisted_identity_is_not_replaced_by_a_template_link(): void
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $repository = new InMemoryAuthorityRepository();
        (new AuthorityService($repository, $types))->create('classification', 'nhk:classification:clock-type.no-route', 'Loại chưa có route', ['family' => 'clock_type']);
        $routes = new PublicRouteResolver($repository, $types);
        $query = new PublicEntityCollectionQuery($repository, $types, new PublicIdentityContract($types, new ClockTypeFrontendFixtureIdentityRepository([])), new PublicEntityEligibilityPolicy($repository, $types, $routes), $routes);

        self::assertSame([], $query->archiveProfile('clock_type')['items']);
    }
}

final class ClockTypeFrontendFixtureIdentityRepository implements \NHK\Core\Contracts\PublicIdentity\PublicIdentityRepository
{
    /** @param array<string,array<string,mixed>> $records */
    public function __construct(private array $records) {}
    public function allocate(array $record, string $idempotencyKey): array { return []; }
    public function change(array $record, string $oldPath, int $expectedRevision, string $idempotencyKey): array { return []; }
    public function findCurrentById(string $identityId): ?array { return null; }
    public function findCurrentByOwner(string $ownerKind, string $ownerId, string $routeType): ?array { return $this->records[$ownerKind . '|' . $ownerId . '|' . $routeType] ?? null; }
    public function slugExists(string $routeType, string $scope, string $slug, ?string $excludeIdentityId = null): bool { return false; }
    public function resolveHistoric(string $path): array { return []; }
}
