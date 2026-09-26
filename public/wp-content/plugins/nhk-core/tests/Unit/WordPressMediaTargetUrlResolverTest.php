<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Entity\PublicRouteResolver;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\PublicIdentity\HistoricPublicRouteResolver;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeDefinition, EntityTypeRegistry};
use NHK\Core\Infrastructure\WordPress\WordPressMediaTargetUrlResolver;
use PHPUnit\Framework\TestCase;

final class WordPressMediaTargetUrlResolverTest extends TestCase
{
    private const BRAND = '01a07614-832d-7f27-959c-74eb0cd63f31';
    private const MODEL = '01a07614-832d-7f27-959c-74eb0cd63f32';
    private const VARIANT = '01a07614-832d-7f27-959c-74eb0cd63f33';
    private const CLOCK_TYPE = '01a07614-832d-7f27-959c-74eb0cd63f34';

    public function test_resolves_post_nested_authority_and_clock_type_routes_to_canonical_ids(): void
    {
        $resolver = $this->resolver(static function (string $url): ?array {
            return str_contains($url, '/carillon-') ? ['type' => 'wp_post', 'id' => '1:18'] : null;
        });

        self::assertSame(['type' => 'wp_post', 'id' => '1:18'], $resolver->resolve(
            'https://demo.1945.vn/carillon-la-gi-trong-dong-ho-co-phap-dung-nham-carillon-la-ten-hang/'
        ));
        self::assertSame(['type' => 'brand', 'id' => self::BRAND], $resolver->resolve('https://demo.1945.vn/hermle/'));
        self::assertSame(['type' => 'model', 'id' => self::MODEL], $resolver->resolve('https://demo.1945.vn/hermle/model-test/'));
        self::assertSame(['type' => 'variant', 'id' => self::VARIANT], $resolver->resolve('https://demo.1945.vn/hermle/model-test/variant-test/'));
        self::assertSame(['type' => 'classification', 'id' => self::CLOCK_TYPE], $resolver->resolve('https://demo.1945.vn/dong-ho-qua-lac/'));
    }

    public function test_rejects_external_malformed_and_route_drifted_urls(): void
    {
        $resolver = $this->resolver(static fn (string $url): ?array => str_contains($url, '/hermle/') ? ['type' => 'wp_post', 'id' => '1:18'] : null);

        foreach ([
            'https://example.com/hermle/' => 'MEDIA_TARGET_URL_INVALID',
            'http://demo.1945.vn/hermle/' => 'MEDIA_TARGET_URL_INVALID',
            'https://demo.1945.vn/hermle/?utm_source=x' => 'MEDIA_TARGET_URL_INVALID',
            'https://demo.1945.vn/hermle/#fragment' => 'MEDIA_TARGET_URL_INVALID',
            'https://demo.1945.vn/hermle//model/' => 'MEDIA_TARGET_URL_INVALID',
            'https://demo.1945.vn/hermle/../model/' => 'MEDIA_TARGET_URL_INVALID',
            'https://demo.1945.vn/wrong-hermle/' => 'MEDIA_TARGET_NOT_FOUND',
        ] as $url => $diagnostic) {
            try {
                $resolver->resolve($url);
                self::fail('Expected resolver failure for ' . $url);
            } catch (\Throwable $error) {
                self::assertSame($diagnostic, $error->getMessage(), $url);
            }
        }
    }

    public function test_historic_route_must_resolve_to_one_current_canonical_target(): void
    {
        $historic = new class implements HistoricPublicRouteResolver {
            public function resolveHistoric(string $path): array
            {
                return $path === '/old-hermle/'
                    ? ['status' => 'FOUND', 'target' => '/hermle/', 'hops' => 1]
                    : ['status' => 'NOT_FOUND'];
            }
        };

        $resolver = $this->resolver(static fn (string $url): ?array => null, $historic);

        self::assertSame(['type' => 'brand', 'id' => self::BRAND], $resolver->resolve('https://demo.1945.vn/old-hermle/'));
    }

    public function test_rejects_ambiguous_route_without_using_url_as_identity(): void
    {
        $resolver = $this->resolver(static fn (string $url): ?array => str_contains($url, '/hermle/') ? ['type' => 'wp_post', 'id' => '1:18'] : null);

        try {
            $resolver->resolve('https://demo.1945.vn/hermle/');
            self::fail('Ambiguous public route must fail closed.');
        } catch (\Throwable $error) {
            self::assertSame('MEDIA_TARGET_URL_AMBIGUOUS', $error->getMessage());
        }
    }

    /** @param list<AuthorityEntity>|null $items */
    private function resolver(callable $postResolver, ?HistoricPublicRouteResolver $historic = null, ?array $items = null): WordPressMediaTargetUrlResolver
    {
        $brand = new AuthorityEntity(self::BRAND, 'brand', 'nhk:brand:hermle', 'Hermle', 1, []);
        $model = new AuthorityEntity(self::MODEL, 'model', 'nhk:model:test', 'Model test', 1, ['brand_uuid' => self::BRAND]);
        $variant = new AuthorityEntity(self::VARIANT, 'variant', 'nhk:variant:test', 'Variant test', 1, ['model_uuid' => self::MODEL]);
        $clockType = new AuthorityEntity(self::CLOCK_TYPE, 'classification', 'nhk:classification:clock-type.pendulum', 'Đồng hồ quả lắc', 1, ['family' => 'clock_type']);
        $items ??= [$brand, $model, $variant, $clockType];

        $authority = new class($items) implements AuthorityRepository {
            /** @param list<AuthorityEntity> $items */
            public function __construct(private array $items) {}
            public function findByCanonicalId(string $id): ?AuthorityEntity { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
            public function findByStableKey(string $type, string $key): ?AuthorityEntity { foreach ($this->items as $item) if ($item->entityType === $type && $item->stableKey === $key) return $item; return null; }
            public function create(AuthorityEntity $entity): AuthorityEntity { return $entity; }
            public function update(AuthorityEntity $entity, int $expectedRevision): AuthorityEntity { return $entity; }
            public function rekey(AuthorityEntity $entity, string $oldStableKey, string $newStableKey, int $expectedRevision): AuthorityEntity { return $entity; }
            public function listByType(string $type, bool $includeRetired = false): array { return array_values(array_filter($this->items, static fn (AuthorityEntity $item): bool => $item->entityType === $type)); }
        };

        $types = new EntityTypeRegistry();
        foreach (['brand', 'model', 'variant', 'classification'] as $type) $types->register(new EntityTypeDefinition($type, 1, true));
        $routes = new PublicRouteResolver($authority, $types, null, static fn (string $slug): bool => false);

        return new WordPressMediaTargetUrlResolver(
            $routes,
            $postResolver,
            static fn (): string => 'https://demo.1945.vn/',
            $historic,
        );
    }
}
