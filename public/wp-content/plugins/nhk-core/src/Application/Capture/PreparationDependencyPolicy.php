<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Application\Video\VideoConstraintSeverity;

/** Pure contextual classification for preparation findings. */
final class PreparationDependencyPolicy
{
    /** @param array<string,mixed> $input @param array<string,mixed> $resolution @param list<array<string,mixed>> $constraintFindings @param array<string,mixed> $context @return list<array<string,mixed>> */
    public function classify(array $input, array $resolution, array $constraintFindings, array $context = []): array
    {
        $findings = [];
        $seen = [];

        $add = function (PreparationDependencyFinding $finding) use (&$findings, &$seen): void {
            if (isset($seen[$finding->code])) return;
            $seen[$finding->code] = true;
            $findings[] = $finding->toArray();
        };

        $resolutionStatus = strtolower(trim((string) ($resolution['status'] ?? 'unresolved')));
        if (in_array($resolutionStatus, ['ambiguous', 'conflict'], true) || ($resolutionStatus !== 'resolved' && $this->requiresExactIdentity($input, $context))) {
            $add(new PreparationDependencyFinding(
                $resolutionStatus === 'conflict' ? 'SUBJECT_CONFLICT_REVIEW_REQUIRED' : 'PRIMARY_SUBJECT_AMBIGUOUS',
                PreparationDependencyClass::CRITICAL_IDENTITY,
                'BLOCKED',
                'REVIEW_REQUIRED',
                'The current operation requires one authoritative canonical subject.',
                ['intent' => strtoupper(trim((string) ($input['intent'] ?? $context['content_intent']['intent'] ?? ''))), 'resolution_status' => $resolutionStatus],
            ));
        }

        foreach ($constraintFindings as $finding) {
            if (!is_array($finding)) continue;
            $severity = strtoupper(trim((string) ($finding['severity'] ?? 'INFO')));
            if ($severity === VideoConstraintSeverity::INFO) continue;
            $code = trim((string) ($finding['code'] ?? 'VIDEO_DECISION_REVIEW_REQUIRED'));
            $declaredClass = PreparationDependencyClass::tryFrom(strtoupper(trim((string) ($finding['dependency_class'] ?? ''))));
            $identity = $declaredClass === PreparationDependencyClass::CRITICAL_IDENTITY;
            $class = $identity ? PreparationDependencyClass::CRITICAL_IDENTITY : PreparationDependencyClass::REQUIRED_FACTUAL_DEPENDENCY;
            $escalation = match ($severity) {
                VideoConstraintSeverity::HARD_BLOCK => 'BLOCKED',
                VideoConstraintSeverity::REVIEW_REQUIRED => 'REVIEW_REQUIRED',
                default => null,
            };
            $readiness = $escalation === 'BLOCKED' ? 'BLOCKED' : ($escalation === null ? 'INCOMPLETE' : 'UNAVAILABLE');
            $add(new PreparationDependencyFinding($code, $class, $readiness, $escalation, (string) ($finding['reason'] ?? 'Statement dependency requires review.'), ['source' => 'statement_decision', 'claim_id' => $finding['claim_id'] ?? null]));
        }

        // Requirements must be supplied by an internal application boundary;
        // caller-provided capture input cannot downgrade a dependency.
        foreach ((array) ($context['trusted_dependency_requirements'] ?? []) as $requirement) {
            if (!is_array($requirement)) continue;
            $code = trim((string) ($requirement['code'] ?? ''));
            $class = PreparationDependencyClass::tryFrom(strtoupper(trim((string) ($requirement['kind'] ?? ''))));
            if ($code === '' || $class === null) throw new \InvalidArgumentException('Preparation dependency requirement is invalid.');
            $readiness = strtoupper(trim((string) ($requirement['readiness'] ?? 'INCOMPLETE')));
            $explicitEscalation = array_key_exists('escalation', $requirement) ? strtoupper(trim((string) $requirement['escalation'])) : null;
            $escalation = $explicitEscalation !== null && $explicitEscalation !== ''
                ? $explicitEscalation
                : $this->defaultEscalation($class, $readiness, ($requirement['required'] ?? false) === true);
            $add(new PreparationDependencyFinding($code, $class, $readiness, $escalation, trim((string) ($requirement['reason'] ?? 'Dependency readiness is incomplete.')), ['source' => 'dependency_requirement', 'intent' => strtoupper(trim((string) ($input['intent'] ?? $context['content_intent']['intent'] ?? '')))]));
        }

        return $findings;
    }

    private function requiresExactIdentity(array $input, array $context): bool
    {
        if (($context['requires_exact_subject'] ?? false) === true) return true;
        $intent = strtoupper(trim((string) ($input['intent'] ?? $context['content_intent']['intent'] ?? '')));
        return in_array($intent, ['VIDEO', 'KNOWLEDGE_DELTA', 'KNOWLEDGE_REPAIR'], true);
    }

    private function defaultEscalation(PreparationDependencyClass $class, string $readiness, bool $required): ?string
    {
        if (in_array($readiness, ['READY', 'NOT_APPLICABLE'], true)) return null;
        if ($class === PreparationDependencyClass::OPTIONAL_ENRICHMENT) return null;
        if ($class === PreparationDependencyClass::PUBLICATION_ONLY && !$required) return null;
        return $readiness === 'BLOCKED' ? 'BLOCKED' : 'REVIEW_REQUIRED';
    }
}
