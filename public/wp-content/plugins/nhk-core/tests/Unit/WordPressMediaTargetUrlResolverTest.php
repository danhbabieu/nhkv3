<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Entity\PublicRouteResolver;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeDefinition, EntityTypeRegistry};
use NHK\Core\Infrastructure\WordPress\WordPressMediaTargetUrlResolver;
use PHPUnit\Framework\TestCase;

final class WordPressMediaTargetUrlResolverTest extends TestCase
{
    private const BRAND = '01a07614-832d-7f27-959c-74eb0cd63f31';
    private const MODEL = '01a07614-832d-7f27-959c-74eb0cd63f32';
    private const CLOCK_TYPE = '01a07614-832d-7f27-959c-74eb0cd63f33';

    public function test_resolves_native_article_and_authority_public_routes_without_using_url_as_identity(): void
    {
        $resolver = $this->resolver(static fn (string $url): ?array =>
            str_contains($url, '/carillon-') ? ['type' => 'wp_post', 'id' => '1:18'] : null
        );

        self::assertSame(
            ['type' => 'wp_post', 'id' => '1:18'],
            $resolver->resolve('https://demo.1945.vn/carillon-la-gi-trong-dong-ho-co-phap-dung-nham-carillon-la-ten-hang/'),
        );
        self::assertSame(
            ['type' => 'brand', 'id' => self::BRAND],
            $resolver->resolve('https://demo.1945.vn/hermle/'),
        );
        self::assertSame(
            ['type' => 'model', 'id' => self::MODEL],
            $resolver->resolve('https://demo.1945.vn/hermle/model-test/'),
        );
        self::assertSame(
            ['type' => 'classification', 'id' => self::CLOCK_TYPE],
            $resolver->resolve('https://demo.1945.vn/dong-ho-qua-lac/'),
        );
    }

    public function test_external_or_ambiguous_url_fails_closed(): void
    {
        $resolver = $this->resolver(static fn (string $url): ?array => null);

        try {
            $resolver->resolve('https://example.com/hermle/');
            self::fail('External target URL must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('MEDIA_TARGET_URL_INVALID', $error->getMessage());
        }
    }

    private function resolver(callable $postResolver): WordPressMediaTargetUrlResolver
    {
        $brand = new AuthorityEntity(self::BRAND, 'brand', 'nhk:brand:hermle', 'Hermle', 1);
        $model = new AuthorityEntity(self::MODEL, 'model', 'nhk:model:test', 'Model test', 1, ['brand_uuid' => self::BRAND]);
        $clockType = new AuthorityEntity(self::CLOCK_TYPE, 'classification', 'nhk:classification:clock-type.pendulum', 'Đồng hồ quả lắc', 1, ['family' => 'clock_type']);
        $items = [$brand, $model, $clockType];

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
        foreach (['brand', 'model', 'classification'] as $type) $types->register(new EntityTypeDefinition($type, 1, true));
        $routes = new PublicRouteResolver($authority, $types, null, static fn (string $slug): bool => false);

        return new WordPressMediaTargetUrlResolver(
            $routes,
            $postResolver,
            static fn (): string => 'https://demo.1945.vn/',
        );
    }
}
