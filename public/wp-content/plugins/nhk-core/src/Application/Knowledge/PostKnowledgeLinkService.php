<?php
declare(strict_types=1);

namespace NHK\Core\Application\Knowledge;

use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Domain\Graph\{GraphEdge, NodeReference};

final class PostKnowledgeLinkService
{
    public function __construct(private GraphService $graph) {}

    public function link(string $blogId, int $postId, string $claimId): GraphEdge
    {
        return $this->graph->create(new NodeReference('wp_post', $blogId . ':' . $postId), 'about', new NodeReference('knowledge', $claimId));
    }
}
