<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Entity\{PublicEndpointEligibilityResolver, PublicEntityEligibilityPolicy, PublicRouteResolver};
use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class PublicEndpointEligibilityResolverTest extends TestCase
{
    /** @return array<string,mixed> */
    private function resolver(array $routes = [], array $availability = []): array
    {
        $authority = new InMemoryAuthorityRepository(); $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $route = new PublicRouteResolver($authority, $types);
        $policy = new PublicEntityEligibilityPolicy($authority, $types, $route);
        return [new PublicEndpointEligibilityResolver($policy, $routes, $availability), $authority];
    }

    public function test_all_registered_endpoint_families_have_explicit_route_policy_or_reason(): void
    {
        [$resolver] = $this->resolver(['brand' => static fn (array $c): string => '/brand/']);
        $types = ['wp_post', 'brand', 'model', 'variant', 'movement', 'music', 'component', 'classification', 'specimen', 'product', 'media', 'video', 'knowledge', 'source', 'evidence'];
        foreach ($types as $type) {
            $result = $resolver->evaluate(['target_type' => $type, 'target_id' => '550e8400-e29b-41d4-a716-446655440000', 'active' => true, 'readiness' => 'ready', 'public' => true]);
            self::assertContains($result['reason'], ['NO_PUBLIC_ROUTE', 'PUBLIC_CANONICAL_ROUTE'], $type);
        }
    }

    /** @dataProvider endpointCases */
    public function test_endpoint_gate_cases_fail_closed(string $type, array $overrides, string $reason): void
    {
        [$resolver] = $this->resolver([$type => static fn (array $candidate): string => '/usable/']);
        $candidate = array_merge(['target_type' => $type, 'target_id' => '550e8400-e29b-41d4-a716-446655440000', 'active' => true, 'readiness' => 'ready', 'public' => true], $overrides);
        self::assertSame($reason, $resolver->evaluate($candidate)['reason']);
    }

    public static function endpointCases(): array
    {
        return [
            'inactive' => ['video', ['active' => false], 'INACTIVE'],
            'private' => ['source', ['public' => false], 'NOT_PUBLIC'],
            'draft' => ['media', ['readiness' => 'draft'], 'NOT_READY'],
            'invalid identity' => ['brand', ['target_id' => 'not-a-uuid'], 'INVALID_IDENTITY'],
        ];
    }

    public function test_registered_endpoint_without_a_public_route_is_excluded(): void
    {
        [$resolver] = $this->resolver();
        $result = $resolver->evaluate(['target_type' => 'evidence', 'target_id' => '550e8400-e29b-41d4-a716-446655440000', 'active' => true, 'readiness' => 'ready', 'public' => true]);
        self::assertSame('NO_PUBLIC_ROUTE', $result['reason']);
    }

    public function test_unavailable_dependency_is_distinct_from_empty_or_no_route(): void
    {
        [$resolver] = $this->resolver(['video' => static fn (array $candidate): string => '/video/'], ['video' => static fn (): bool => false]);
        $result = $resolver->evaluate(['target_type' => 'video', 'target_id' => '550e8400-e29b-41d4-a716-446655440000', 'active' => true, 'readiness' => 'ready', 'public' => true]);
        self::assertSame('unavailable', $result['status']); self::assertSame('DEPENDENCY_UNAVAILABLE', $result['reason']);
    }
}
