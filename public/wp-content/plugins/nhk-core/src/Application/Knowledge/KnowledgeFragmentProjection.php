<?php
declare(strict_types=1);
namespace NHK\Core\Application\Knowledge;
final readonly class KnowledgeFragmentProjection
{
    public function __construct(public string $fragment, public string $content, public string $dependencyFingerprint, public bool $available = true) {}
}
