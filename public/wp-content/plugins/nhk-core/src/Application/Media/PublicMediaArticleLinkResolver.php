<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Domain\Media\MediaUsage;

/** Resolves a MediaUsage endpoint to an existing published WordPress article. */
final class PublicMediaArticleLinkResolver
{
    /** @param callable(int):mixed|null $postResolver @param callable(mixed):string|null $permalinkResolver */
    public function __construct(
        private $postResolver = null,
        private $permalinkResolver = null,
    ) {}

    public static function fromWordPress(): self
    {
        return new self(
            static fn (int $postId): mixed => function_exists('get_post') ? get_post($postId) : null,
            static fn (mixed $post): string => function_exists('get_permalink') ? (string) get_permalink($post) : '',
        );
    }

    /** @param list<MediaUsage> $usages */
    public function firstPublished(array $usages): ?string
    {
        usort($usages, static fn (MediaUsage $left, MediaUsage $right): int => [$left->sortOrder, $left->usageId] <=> [$right->sortOrder, $right->usageId]);
        foreach ($usages as $usage) {
            $url = $this->resolve($usage);
            if ($url !== null) return $url;
        }
        return null;
    }

    public function resolve(MediaUsage $usage): ?string
    {
        if ($usage->endpointType !== 'wp_post' || !preg_match('/^[1-9][0-9]*:([1-9][0-9]*)$/', trim($usage->endpointKey), $matches)) return null;
        $postResolver = $this->postResolver;
        $permalinkResolver = $this->permalinkResolver;
        if (!is_callable($postResolver) || !is_callable($permalinkResolver)) return null;
        $post = $postResolver((int) $matches[1]);
        if (!is_object($post) || (string) ($post->post_status ?? '') !== 'publish' || (($post->post_type ?? 'post') !== 'post')) return null;
        $url = trim((string) $permalinkResolver($post));
        return $url === '' ? null : $url;
    }
}
