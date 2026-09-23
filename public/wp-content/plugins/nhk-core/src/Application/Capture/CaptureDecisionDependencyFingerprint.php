<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Domain\Governance\CommandCanonicalizer;

/** Stable dependency identity for deciding whether a review may be re-evaluated. */
final class CaptureDecisionDependencyFingerprint
{
    public const VERSION = 'capture-decision-dependencies-2';
    public const POLICY_VERSION = 'video-intake-policy-2';

    /** @param array<string,mixed> $context @param array<string,mixed> $diagnostics @param array<string,mixed> $input */
    public static function forState(string $requestFingerprint, array $context, array $diagnostics, array $input = []): string
    {
        $packet = self::packet($context, $diagnostics);
        $dependencies = [];
        foreach (['canonical_context', 'evidence_context', 'visual_context'] as $key) {
            $dependencies[$key] = self::dependencyRevisions($context[$key] ?? $diagnostics[$key] ?? []);
        }
        $preparation = is_array($diagnostics['content_preparation'] ?? null) ? $diagnostics['content_preparation'] : [];
        $dependencies['enrichment'] = self::dependencyRevisions($preparation['enrichment'] ?? []);
        return hash('sha256', CommandCanonicalizer::canonicalize([
            'version' => self::VERSION,
            'policy_version' => self::POLICY_VERSION,
            'request_fingerprint' => $requestFingerprint,
            'subject' => $packet,
            'dependencies' => $dependencies,
            'input_context' => self::withoutVolatile([
                'raw_input' => $context['raw_input'] ?? '',
                'subject_hints' => $context['subject_hints'] ?? [],
                'content_intent' => $context['content_intent'] ?? [],
                'original_request' => $context['original_request'] ?? [],
                'subject_reconciliation' => $input['subject_reconciliation'] ?? $context['subject_reconciliation'] ?? [],
                'decision_trace' => $preparation['decision_trace'] ?? [],
                'constraint_findings' => $preparation['constraint_findings'] ?? [],
                'quality_decision' => $preparation['quality_decision'] ?? '',
            ]),
        ]));
    }

    public static function current(CaptureRecord $capture, array $input = []): string
    {
        return self::forState($capture->requestFingerprint, $capture->context, $capture->diagnostics, $input);
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $diagnostics @return array<string,mixed> */
    private static function packet(array $context, array $diagnostics): array
    {
        $packet = is_array($context['subject_resolution_packet'] ?? null)
            ? $context['subject_resolution_packet']
            : (is_array($diagnostics['subject_resolution_packet'] ?? null) ? $diagnostics['subject_resolution_packet'] : []);
        return array_intersect_key($packet, array_flip(['status', 'canonical_subject_id', 'entity_type', 'stable_key', 'canonical_name', 'revision', 'source']));
    }

    /** @return array<string,mixed> */
    private static function dependencyRevisions(mixed $value): array
    {
        $found = [];
        $walk = function (mixed $node, string $path = '') use (&$walk, &$found): void {
            if (!is_array($node)) return;
            foreach ($node as $key => $child) {
                $key = (string) $key;
                $childPath = $path === '' ? $key : $path . '.' . $key;
                if (in_array($key, ['revision', 'claim_revision', 'source_revision', 'evidence_revision', 'canonical_revision'], true) && (is_int($child) || is_string($child))) {
                    $found[$childPath] = (string) $child;
                }
                $walk($child, $childPath);
            }
        };
        $walk($value);
        ksort($found);
        return $found;
    }

    private static function withoutVolatile(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        $result = [];
        foreach ($value as $key => $child) {
            if (in_array((string) $key, ['updated_at', 'created_at', 'revision'], true) && (string) $key !== 'revision') continue;
            $result[$key] = self::withoutVolatile($child);
        }
        return $result;
    }
}
