<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

final class VideoStatementDecisionEngine
{
    public function evaluate(array $statements, array $canonicalContext, array $evidenceContext, array $visualContext = []): VideoStatementDecisionResult
    {
        $items = [];
        $findings = [];
        foreach ($statements as $index => $statement) {
            if (!is_array($statement)) continue;
            $id = trim((string) ($statement['id'] ?? 'statement-' . $index));
            $classification = $this->classify($statement, $canonicalContext, $evidenceContext);
            [$action, $reason] = $this->treatment($classification, $statement);
            $support = [
                'canonical' => $this->withoutBodies(is_array($statement['canonical'] ?? null) ? $statement['canonical'] : $canonicalContext),
                'evidence' => $this->withoutBodies(is_array($statement['evidence'] ?? null) ? $statement['evidence'] : $evidenceContext),
            ];
            $scope = [
                'subject' => (string) ($statement['scope'] ?? 'video'),
                'attribution' => (string) ($statement['attribution'] ?? ''),
            ];
            $item = [
                'statement' => $this->normalizedStatement($statement),
                'classification' => $classification,
                'support' => $support,
                'scope' => $scope,
                'confidence' => $this->confidence($statement, $classification),
                'action' => $action,
                'reason' => $reason,
            ];
            $items[] = $item;
            foreach ($this->findingsFor($statement, $id, $classification, $action, $reason) as $finding) $findings[] = $finding;
        }
        return new VideoStatementDecisionResult($items, $findings);
    }

    private function classify(array $statement, array $canonicalContext, array $evidenceContext): string
    {
        if (($statement['canonical_match'] ?? false) === true || ($statement['support_type'] ?? '') === 'canonical' || $this->matchesCanonicalContext($statement, $canonicalContext)) return VideoStatementClassification::CANONICAL_SUPPORTED;
        if (($statement['observation'] ?? false) === true || ($statement['provenance'] ?? '') === 'OBSERVED_FROM_MEDIA') return VideoStatementClassification::USER_OBSERVATION;
        if (($statement['source_supported'] ?? false) === true || in_array(($statement['provenance'] ?? ''), ['CATALOG_SUPPORTED', 'EXTERNAL_RESEARCH'], true)) return VideoStatementClassification::SOURCE_SUPPORTED;
        if (($statement['inferable'] ?? false) === true && ($statement['within_scope'] ?? false) === true) return VideoStatementClassification::INFERABLE_WITHIN_SCOPE;
        if (($statement['conflicting'] ?? false) === true) return VideoStatementClassification::CONFLICTING;
        if (($statement['uncertain'] ?? false) === true || ($statement['user_hint'] ?? false) === true) return VideoStatementClassification::UNCERTAIN;
        return VideoStatementClassification::UNSUPPORTED_EXPANSION;
    }

    private function treatment(string $classification, array $statement): array
    {
        return match ($classification) {
            VideoStatementClassification::CANONICAL_SUPPORTED => [VideoEditorialAction::USE_AS_IS, 'Canonical context supports this statement.'],
            VideoStatementClassification::USER_OBSERVATION => [VideoEditorialAction::ATTRIBUTE_AND_SCOPE, 'Observation is limited to the depicted specimen/video.'],
            VideoStatementClassification::SOURCE_SUPPORTED => [VideoEditorialAction::ATTRIBUTE_AND_SCOPE, 'Source support is retained with bounded attribution.'],
            VideoStatementClassification::INFERABLE_WITHIN_SCOPE => [VideoEditorialAction::QUALIFY_INFERENCE, 'Inference is permitted only within the supplied scope.'],
            VideoStatementClassification::CONFLICTING => [VideoEditorialAction::PREFER_CANONICAL, 'Canonical or evidence-supported context takes precedence over the conflicting input.'],
            VideoStatementClassification::UNCERTAIN => [VideoEditorialAction::NARROW_SCOPE, 'Uncertain detail is narrowed rather than asserted universally.'],
            default => [VideoEditorialAction::REMOVE_UNSUPPORTED, 'Unsupported expansion is removed from public copy.'],
        };
    }

    private function findingsFor(array $statement, string $id, string $classification, string $action, string $reason): array
    {
        $findings = [];
        if ($classification === VideoStatementClassification::CONFLICTING) {
            $findings[] = (new VideoConstraintFinding('FACTUAL_CONFLICT', ($statement['core'] ?? false) ? VideoConstraintSeverity::REVIEW_REQUIRED : VideoConstraintSeverity::INFO, 'claim', $id, $action, $reason))->toArray();
        }
        if ($classification === VideoStatementClassification::UNSUPPORTED_EXPANSION) {
            $findings[] = (new VideoConstraintFinding('UNSUPPORTED_EXPANSION', VideoConstraintSeverity::REPAIRABLE, 'claim', $id, $action, $reason))->toArray();
        }
        if (($statement['visual_required'] ?? false) === true && ($statement['visual_supported'] ?? false) !== true) {
            $severity = ($statement['core'] ?? false) ? VideoConstraintSeverity::HARD_BLOCK : VideoConstraintSeverity::REPAIRABLE;
            $repair = ($statement['core'] ?? false) ? VideoEditorialAction::REDUCE_SPECIFICITY : VideoEditorialAction::REMOVE_UNSUPPORTED;
            $findings[] = (new VideoConstraintFinding('VISUAL_SUPPORT_' . (($statement['core'] ?? false) ? 'CORE' : 'SECONDARY'), $severity, 'claim', $id, $repair, ($statement['core'] ?? false) ? 'Core claim requires visual support.' : 'Secondary visual detail can be omitted.'))->toArray();
        }
        return $findings;
    }

    private function confidence(array $statement, string $classification): float
    {
        if (isset($statement['confidence']) && is_numeric($statement['confidence'])) return max(0.0, min(1.0, (float) $statement['confidence']));
        return match ($classification) {
            VideoStatementClassification::CANONICAL_SUPPORTED => 1.0,
            VideoStatementClassification::SOURCE_SUPPORTED => 0.9,
            VideoStatementClassification::USER_OBSERVATION => 0.75,
            VideoStatementClassification::INFERABLE_WITHIN_SCOPE => 0.7,
            default => 0.2,
        };
    }

    private function normalizedStatement(array $statement): array
    {
        $result = [];
        foreach ($statement as $key => $value) if (!in_array((string) $key, ['raw_input', 'body', 'content'], true)) $result[$key] = $value;
        return $result;
    }

    private function matchesCanonicalContext(array $statement, array $canonicalContext): bool
    {
        $text = $this->normalize((string) ($statement['text'] ?? ''));
        if ($text === '') return false;
        foreach ($canonicalContext as $row) {
            if (!is_array($row)) continue;
            $candidate = $this->normalize((string) ($row['text'] ?? $row['name'] ?? $row['title'] ?? ''));
            if ($candidate !== '' && ($candidate === $text || (($statement['canonical_id'] ?? null) !== null && (string) ($row['id'] ?? '') === (string) $statement['canonical_id']))) return true;
        }
        return false;
    }

    private function withoutBodies(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        $result = [];
        foreach ($value as $key => $item) {
            if (in_array((string) $key, ['raw_input', 'content', 'body', 'post_content'], true)) continue;
            $result[$key] = $this->withoutBodies($item);
        }
        return $result;
    }

    private function normalize(string $value): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }
}
