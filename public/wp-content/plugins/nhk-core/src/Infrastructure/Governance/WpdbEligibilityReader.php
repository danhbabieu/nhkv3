<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Governance;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Governance\{EligibilityReader, ProposalRepository};
use NHK\Core\Contracts\Graph\GraphRepository;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\Media\MediaRepository;
use NHK\Core\Contracts\Video\VideoRepository;

final class WpdbEligibilityReader implements EligibilityReader
{
    public function __construct(private AuthorityRepository $authority, private ProposalRepository $proposals, private ?GraphRepository $graph = null, private ?MediaRepository $media = null, private ?VideoRepository $videos = null, private ?KnowledgeRepository $claims = null, private ?SourceRepository $sources = null, private ?EvidenceRepository $evidence = null) {}

    public function isApplied(string $dependencyUuid): bool
    {
        return $this->proposals->find($dependencyUuid)?->state->value === 'applied';
    }

    public function targetRevision(string $targetUuid): ?int
    {
        foreach ([
            $this->authority->findByCanonicalId($targetUuid),
            $this->graph?->findByUuid($targetUuid),
            $this->media?->findByCanonicalId($targetUuid),
            $this->videos?->findByCanonicalId($targetUuid),
            $this->claims?->findByCanonicalId($targetUuid),
            $this->sources?->findByCanonicalId($targetUuid),
            $this->evidence?->findByCanonicalId($targetUuid),
        ] as $target) if ($target !== null && property_exists($target, 'revision')) return (int) $target->revision;
        return null;
    }

    public function targetExists(string $targetUuid): bool
    {
        return $this->targetRevision($targetUuid) !== null;
    }
}
