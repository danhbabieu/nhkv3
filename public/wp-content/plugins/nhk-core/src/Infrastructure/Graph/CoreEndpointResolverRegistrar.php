<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Graph;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Media\MediaRepository;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Domain\Graph\EndpointTypeRegistry;

final class CoreEndpointResolverRegistrar
{
    public static function register(EndpointTypeRegistry $registry, EntityTypeRegistry $entityTypes, AuthorityRepository $authority, MediaRepository $media, VideoRepository $videos, ?KnowledgeRepository $claims = null, ?SourceRepository $sources = null, ?EvidenceRepository $evidence = null): void
    {
        $registry->register('wp_post', new WpPostEndpointResolver());
        foreach ($entityTypes->all() as $definition) {
            if ($definition->graphEnabled) $registry->register($definition->type, new AuthorityEndpointResolver($entityTypes, $authority));
        }
        $registry->register('media', new MediaEndpointResolver($media));
        $registry->register('video', new VideoEndpointResolver($videos));
        if ($claims !== null) $registry->register('knowledge', new KnowledgeEndpointResolver($claims));
        if ($sources !== null) $registry->register('source', new SourceEndpointResolver($sources));
        if ($evidence !== null) $registry->register('evidence', new EvidenceEndpointResolver($evidence));
    }
}
