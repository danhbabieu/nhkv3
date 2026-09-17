<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Graph\RelationRevisionBinder;
use NHK\Core\Contracts\Graph\EndpointRevisionReader;
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, NodeReference};
use NHK\Core\Infrastructure\Graph\WpPostEndpointResolver;
use PHPUnit\Framework\TestCase;

final class RelationRevisionBindingTest extends TestCase
{
    /** @dataProvider authorityTypes */
    public function test_binds_current_source_and_target_revisions_for_about_relation(string $targetType): void
    {
        $sourceId = '018f7c48-6d87-7a1d-8c9e-3b8c4c8d1f22';
        $targetId = '018f7c48-6d87-7a1d-8c9e-3b8c4c8d1f23';
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('knowledge', new RevisionedResolver('knowledge', [$sourceId => 1]));
        $endpoints->register($targetType, new RevisionedResolver($targetType, [$targetId => 3]));

        $bound = (new RelationRevisionBinder($endpoints))->bind([
            'source_type' => 'knowledge', 'source_uuid' => $sourceId,
            'target_type' => $targetType, 'target_uuid' => $targetId,
            'predicate' => 'about',
        ]);

        self::assertSame(1, $bound['source_revision']);
        self::assertSame(3, $bound['target_revision']);
    }

    public static function authorityTypes(): iterable
    {
        yield 'brand' => ['brand'];
        yield 'model' => ['model'];
        yield 'variant' => ['variant'];
    }

    public function test_binds_wp_post_revision_from_native_modified_timestamp(): void
    {
        $post = (object) [
            'ID' => 487,
            'post_modified_gmt' => '2026-09-15 07:56:04',
            'post_modified' => '2026-09-15 14:56:04',
        ];
        $resolver = new WpPostEndpointResolver(
            static fn (int $postId): object|null => $postId === 487 ? $post : null,
            static fn (): int => 1,
        );
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('wp_post', $resolver);
        $endpoints->register('classification', new RevisionedResolver('classification', ['classification-1' => 1]));

        $bound = (new RelationRevisionBinder($endpoints))->bind([
            'source_type' => 'wp_post', 'source_uuid' => '1:487',
            'target_type' => 'classification', 'target_uuid' => 'classification-1',
            'predicate' => 'about',
        ]);

        self::assertSame(strtotime('2026-09-15 07:56:04 UTC'), $bound['source_revision']);
        self::assertSame(1, $bound['target_revision']);
    }

    public function test_binds_wp_post_revision_from_date_floating_draft_local_modified_timestamp(): void
    {
        $post = (object) [
            'ID' => 575,
            'post_modified_gmt' => '0000-00-00 00:00:00',
            'post_modified' => '2026-09-15 07:56:04',
        ];
        $resolver = new WpPostEndpointResolver(
            static fn (int $postId): object|null => $postId === 575 ? $post : null,
            static fn (): int => 1,
        );
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('wp_post', $resolver);
        $endpoints->register('classification', new RevisionedResolver('classification', ['classification-1' => 2]));

        $bound = (new RelationRevisionBinder($endpoints))->bind([
            'source_type' => 'wp_post', 'source_uuid' => '1:575',
            'target_type' => 'classification', 'target_uuid' => 'classification-1',
            'predicate' => 'about',
        ]);

        self::assertSame(strtotime('2026-09-15 07:56:04 UTC'), $bound['source_revision']);
        self::assertSame(2, $bound['target_revision']);
    }

    public function test_missing_wp_post_modification_timestamp_still_fails_closed(): void
    {
        $post = (object) [
            'ID' => 575,
            'post_modified_gmt' => '0000-00-00 00:00:00',
            'post_modified' => '0000-00-00 00:00:00',
        ];
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('wp_post', new WpPostEndpointResolver(
            static fn (int $postId): object|null => $postId === 575 ? $post : null,
            static fn (): int => 1,
        ));
        $endpoints->register('classification', new RevisionedResolver('classification', ['classification-1' => 2]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Relation endpoint revision is unavailable: wp_post:1:575');

        (new RelationRevisionBinder($endpoints))->bind([
            'source_type' => 'wp_post', 'source_uuid' => '1:575',
            'target_type' => 'classification', 'target_uuid' => 'classification-1',
            'predicate' => 'about',
        ]);
    }

}

final class RevisionedResolver implements EndpointRevisionReader
{
    public function __construct(private string $type, private array $revisions) {}
    public function supports(string $endpoint_type): bool { return $endpoint_type === $this->type; }
    public function normalize(NodeReference $reference): NodeReference { return $reference; }
    public function exists(NodeReference $reference): bool { return isset($this->revisions[$reference->endpoint_key]); }
    public function revision(NodeReference $reference): ?int { return $this->revisions[$reference->endpoint_key] ?? null; }
}
