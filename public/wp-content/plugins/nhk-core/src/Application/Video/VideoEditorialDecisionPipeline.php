<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

final class VideoEditorialDecisionPipeline
{
    public function __construct(private ?VideoEditorialRepairPlanner $repairs = null) { $this->repairs ??= new VideoEditorialRepairPlanner(); }

    public function run(array $package, array $decisionContext, callable $compose, callable $critique): array
    {
        $trace = [];
        $findings = [];
        for ($round = 0; $round <= 3; $round++) {
            $draft = $compose($package, $decisionContext, $round);
            if (is_array($draft)) $package = array_replace($package, $draft);
            $current = $critique($package, $decisionContext, $round);
            $findings = $this->normalizeFindings(is_array($current) ? $current : []);
            $trace[] = ['round' => $round, 'findings' => $findings, 'package_fingerprint' => hash('sha256', serialize($package))];
            if ($findings === []) return ['quality' => 'READY', 'editorial_package' => $package, 'trace' => $trace, 'rounds' => $round, 'findings' => []];
            if ($this->hasSeverity($findings, VideoConstraintSeverity::HARD_BLOCK)) return $this->outcome('HARD_BLOCK', $package, $trace, $round, $findings);
            if ($this->hasSeverity($findings, VideoConstraintSeverity::REVIEW_REQUIRED)) return $this->outcome('REVIEW_REQUIRED', $package, $trace, $round, $findings);
            $operations = $this->repairs->plan($findings, $package, $round);
            if ($operations === [] || $round === 3) return $this->outcome('REVIEW_REQUIRED', $package, $trace, $round, $findings);
            $package = $this->repairs->apply($package, $operations);
        }
        return $this->outcome('REVIEW_REQUIRED', $package, $trace, 3, $findings);
    }

    private function outcome(string $quality, array $package, array $trace, int $round, array $findings): array
    {
        return ['quality' => $quality, 'editorial_package' => $package, 'trace' => $trace, 'rounds' => $round, 'findings' => $findings];
    }

    private function normalizeFindings(array $findings): array
    {
        $result = [];
        foreach ($findings as $finding) {
            if ($finding instanceof VideoConstraintFinding) $result[] = $finding->toArray();
            elseif (is_array($finding)) $result[] = $finding;
        }
        return $result;
    }

    private function hasSeverity(array $findings, string $severity): bool
    {
        foreach ($findings as $finding) if (($finding['severity'] ?? '') === $severity) return true;
        return false;
    }
}
