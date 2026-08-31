<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Graph;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Media\MediaRepository;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Domain\Graph\EndpointTypeRegistry;

final class CoreEndpointResolverRegistrar
{
    public static function register(EndpointTypeRegistry $registry, EntityTypeRegistry $entityTypes, AuthorityRepository $authority, MediaRepository $media, VideoRepository $videos): void
    {
        $registry->register('wp_post', new WpPostEndpointResolver());
        foreach ($entityTypes->all() as $definition) {
            if ($definition->graphEnabled) $registry->register($definition->type, new AuthorityEndpointResolver($entityTypes, $authority));
        }
        $registry->register('media', new MediaEndpointResolver($media));
        $registry->register('video', new VideoEndpointResolver($videos));
    }
}
