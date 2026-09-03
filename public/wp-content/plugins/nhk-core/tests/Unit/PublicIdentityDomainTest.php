<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Domain\PublicIdentity\HistoricPublicRoute;
use NHK\Core\Domain\PublicIdentity\PublicIdentity;
use NHK\Core\Domain\PublicIdentity\PublicIdentityMutationResult;
use NHK\Core\Domain\PublicIdentity\PublicUrlResult;
use PHPUnit\Framework\TestCase;

final class PublicIdentityDomainTest extends TestCase
{
    public function test_current_identity_preserves_owner_route_policy_and_revision_fields(): void
    {
        $identity = $this->identity();

        self::assertSame('identity-001', $identity->identityId);
        self::assertSame('authority', $identity->ownerKind);
        self::assertSame('018f3f72-5a7e-7d20-9acf-0b1c2d3e4f50', $identity->ownerId);
        self::assertSame('brand', $identity->routeType);
        self::assertSame('odo', $identity->currentSlug);
        self::assertSame('root', $identity->collisionScope);
        self::assertSame('public-route-v1', $identity->routePolicyVersion);
        self::assertSame(3, $identity->revision);
    }

    public function test_owner_mismatch_is_rejected_with_the_typed_malformed_owner_code(): void
    {
        $result = $this->identity()->assertOwner('video', '018f3f72-5a7e-7d20-9acf-0b1c2d3e4f50');

        self::assertFalse($result->accepted);
        self::assertSame(PublicIdentityMutationResult::MALFORMED_OWNER, $result->code);
    }

    public function test_current_identity_rejects_a_historic_route_in_the_same_route_scope(): void
    {
        $historic = new HistoricPublicRoute(
            'identity-002',
            'brand',
            'root',
            '/odo/',
            'odo',
            4,
            '2026-09-04T00:00:00+00:00',
            '2026-09-04T00:00:00+00:00',
        );

        $result = $this->identity()->assertDoesNotCollideWithHistoricRoute($historic);

        self::assertFalse($result->accepted);
        self::assertSame(PublicIdentityMutationResult::CONFLICT, $result->code);
    }

    public function test_explicit_replacement_advances_revision_once_and_preserves_policy_version(): void
    {
        $replacement = $this->identity()->replaceSlug('o-do', 3);

        self::assertTrue($replacement->accepted);
        self::assertSame(4, $replacement->identity->revision);
        self::assertSame('public-route-v1', $replacement->identity->routePolicyVersion);
        self::assertSame('odo', $replacement->historicRoute->oldSlug);
        self::assertSame(4, $replacement->historicRoute->replacementRevision);
    }

    public function test_stale_revision_is_rejected_without_a_replacement(): void
    {
        $replacement = $this->identity()->replaceSlug('o-do', 2);

        self::assertFalse($replacement->accepted);
        self::assertSame(PublicIdentityMutationResult::STALE_REVISION, $replacement->code);
        self::assertNull($replacement->identity);
        self::assertNull($replacement->historicRoute);
    }

    public function test_public_url_never_accepts_a_uuid_or_stable_key_as_a_final_path(): void
    {
        $uuid = '018f3f72-5a7e-7d20-9acf-0b1c2d3e4f50';

        foreach ([$uuid, 'nhk:brand:odo'] as $internalIdentity) {
            try {
                new PublicUrlResult('/' . $internalIdentity . '/', true, [], [], 3);
                self::fail('An internal identity must not be accepted in a public path.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function identity(): PublicIdentity
    {
        return new PublicIdentity(
            'identity-001',
            'authority',
            '018f3f72-5a7e-7d20-9acf-0b1c2d3e4f50',
            'brand',
            'odo',
            'root',
            'public-route-v1',
            3,
            '2026-09-03T00:00:00+00:00',
            '2026-09-03T00:00:00+00:00',
        );
    }
}
