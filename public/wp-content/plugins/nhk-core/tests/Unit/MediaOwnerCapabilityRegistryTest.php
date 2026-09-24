<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\MediaOwnerCapability;
use NHK\Core\Application\Media\MediaOwnerCapabilityRegistry;
use NHK\Core\Domain\Media\MediaUsageRoleRegistry;
use PHPUnit\Framework\TestCase;

final class MediaOwnerCapabilityRegistryTest extends TestCase
{
    public function test_registered_endpoint_can_declare_media_roles_and_surface_requirements(): void
    {
        $registry = new MediaOwnerCapabilityRegistry();
        $registry->register(MediaOwnerCapability::forEndpoint(
            'model',
            [MediaUsageRoleRegistry::REPRESENTATIVE],
            true,
            true,
            true,
        ));

        $capability = $registry->forEndpoint('MODEL');

        self::assertNotNull($capability);
        self::assertTrue($capability->supportsRole(MediaUsageRoleRegistry::REPRESENTATIVE));
        self::assertTrue($capability->requiresProjection);
        self::assertTrue($capability->requiresPublicSurface);
        self::assertTrue($capability->requiresFrontendReadback);
    }

    public function test_unknown_or_unsupported_endpoint_is_not_inferred(): void
    {
        $registry = new MediaOwnerCapabilityRegistry();

        self::assertNull($registry->forEndpoint('unregistered_owner'));
    }
}
