<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Shared\Uuid\UuidCodec;

/** Shared read-only public route/readiness boundary for every registered endpoint family. */
final class PublicEndpointEligibilityResolver
{
    /** @param array<string,callable(array<string,mixed>):?string> $routes
     *  @param array<string,callable():bool> $availability */
    public function __construct(
        private PublicEntityEligibilityPolicy $authorityPolicy,
        private array $routes = [],
        private array $availability = [],
    ) {}

    /** @return array{eligible:bool,status:string,route:?string,reason:string,reasons:list<string>} */
    public function evaluate(array $candidate): array
    {
        $type = trim((string) ($candidate['target_type'] ?? $candidate['type'] ?? ''));
        $id = trim((string) ($candidate['target_id'] ?? $candidate['id'] ?? ''));
        if ($type === '') return $this->blocked('UNKNOWN_TYPE');
        // wp_post uses WordPress' native endpoint key (blog:id), not a V3 UUID.
        if (($type !== 'wp_post' && !UuidCodec::isValid($id)) || ($type === 'wp_post' && $id === '')) return $this->blocked('INVALID_IDENTITY');
        if (isset($this->availability[$type]) && !(bool) ($this->availability[$type])()) return $this->unavailable('DEPENDENCY_UNAVAILABLE');
        if (!isset($this->routes[$type])) return $this->blocked('NO_PUBLIC_ROUTE');

        if (isset($candidate['active']) && $candidate['active'] !== true) return $this->blocked('INACTIVE');
        if (array_key_exists('readiness', $candidate) && $candidate['readiness'] !== 'ready') return $this->blocked('NOT_READY');
        if (array_key_exists('public', $candidate) && $candidate['public'] !== true) return $this->blocked('NOT_PUBLIC');
        if (array_key_exists('visibility', $candidate) && strtoupper((string) $candidate['visibility']) !== 'PUBLIC') return $this->blocked('NOT_PUBLIC');

        try { $route = ($this->routes[$type])($candidate); }
        catch (\Throwable) { return $this->unavailable('DEPENDENCY_UNAVAILABLE'); }
        if (!is_string($route) || trim($route) === '' || $route[0] !== '/') return $this->blocked('NO_PUBLIC_ROUTE');
        return ['eligible' => true, 'status' => 'eligible', 'route' => $route, 'reason' => 'PUBLIC_CANONICAL_ROUTE', 'reasons' => []];
    }

    public function projectionGap(string $type): array { return ['eligible' => false, 'status' => 'blocked', 'route' => null, 'reason' => 'REGISTRY_GAP', 'reasons' => ['REGISTRY_GAP', strtoupper(trim($type))]]; }

    private function blocked(string $reason): array { return ['eligible' => false, 'status' => 'blocked', 'route' => null, 'reason' => $reason, 'reasons' => [$reason]]; }
    private function unavailable(string $reason): array { return ['eligible' => false, 'status' => 'unavailable', 'route' => null, 'reason' => $reason, 'reasons' => [$reason]]; }
}
