<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Presentation\PresentationReadiness;
use PHPUnit\Framework\TestCase;

final class PresentationReadinessTest extends TestCase
{
    public function test_semantic_activity_is_independent_from_presentation_readiness(): void
    {
        $activeWithoutContent = PresentationReadiness::evaluate(
            ['active' => true],
            ['route' => '/brand/nhk/', 'content' => [], 'public_eligible' => true],
        );
        self::assertSame(PresentationReadiness::SEMANTIC_ACTIVE, $activeWithoutContent->semanticState());
        self::assertSame('INCOMPLETE', $activeWithoutContent->presentationStatus());

        $inactiveWithContent = PresentationReadiness::evaluate(
            ['active' => false],
            ['route' => '/brand/retired/', 'content' => ['description' => 'old'], 'public_eligible' => true],
        );
        self::assertSame(PresentationReadiness::SEMANTIC_INACTIVE, $inactiveWithContent->semanticState());
        self::assertSame('BLOCKED', $inactiveWithContent->presentationStatus());
    }

    public function test_existing_route_content_and_projector_state_determine_ready_or_unavailable(): void
    {
        $ready = PresentationReadiness::evaluate(
            ['active' => true],
            ['route' => '/brand/nhk/', 'content' => ['description' => 'NHK'], 'public_eligible' => true],
        );
        self::assertSame('READY', $ready->presentationStatus());

        $unavailable = PresentationReadiness::evaluate(
            ['active' => true],
            ['route_status' => 'unavailable', 'content_status' => 'unavailable', 'public_eligible' => true],
        );
        self::assertSame('UNAVAILABLE', $unavailable->presentationStatus());
    }

    public function test_substantive_public_knowledge_signal_makes_an_active_route_ready_without_summary_or_media(): void
    {
        $ready = PresentationReadiness::evaluate(
            ['active' => true],
            [
                'route' => '/dong-ho-cong-cong/',
                'public_eligible' => true,
                'public_signals' => ['knowledge' => ['claim_count' => 7]],
            ],
        );

        self::assertSame('READY', $ready->presentationStatus());
        self::assertSame([], $ready->reasons());
    }

    public function test_identity_only_is_not_a_public_presentation_signal(): void
    {
        $incomplete = PresentationReadiness::evaluate(
            ['active' => true],
            [
                'route' => '/dong-ho-cong-cong/',
                'public_eligible' => true,
                'content' => ['name' => 'Đồng hồ công cộng'],
            ],
        );

        self::assertSame('INCOMPLETE', $incomplete->presentationStatus());
        self::assertSame(['PRESENTATION_CONTENT_MISSING'], $incomplete->reasons());
    }
}
