<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Audit;

use NHK\Core\Application\Audit\{CanonicalKnowledgeEvidenceAuditReader, ClockTypeClassificationAudit};
use NHK\Core\Application\Entity\EntityProfileResolver;
use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository;
use NHK\Core\Infrastructure\Knowledge\{WpdbEvidenceRepository, WpdbKnowledgeRepository, WpdbSourceRepository};

/** Production composition bridge; it only supplies existing read owners. */
final class WpdbClockTypeClassificationAuditFactory
{
    public static function create(GraphService $graph, object $database, ?callable $rowErrorSink = null): ClockTypeClassificationAudit
    {
        $authority = new WpdbAuthorityRepository(null, $rowErrorSink);
        $claims = new WpdbKnowledgeRepository($database);
        $sources = new WpdbSourceRepository($database);
        $evidence = new WpdbEvidenceRepository($database);
        return new ClockTypeClassificationAudit(
            $authority,
            $graph,
            new EntityProfileResolver(),
            new CanonicalKnowledgeEvidenceAuditReader($claims, $sources, $evidence),
            $authority,
        );
    }
}
