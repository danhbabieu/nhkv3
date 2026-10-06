<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Knowledge;

use NHK\Core\Domain\Governance\CommandCanonicalizer;

/**
 * Read-only owner decision packet for Knowledge/Source/Evidence creation.
 * Matching remains in KnowledgePreCreateResolver; this value object only
 * carries the decision, candidates and revision binding to the mutation.
 */
final readonly class KnowledgePreCreateResolution
{
    public const REUSE_EXISTING = 'REUSE_EXISTING';
    public const ENRICH_EXISTING = 'ENRICH_EXISTING';
    public const QUALIFY_EXISTING = 'QUALIFY_EXISTING';
    public const CONTRADICT_EXISTING = 'CONTRADICT_EXISTING';
    public const REVIEW_REQUIRED = 'REVIEW_REQUIRED';
    public const CREATE_NEW = 'CREATE_NEW';

    private const ACTIONS = [
        self::REUSE_EXISTING,
        self::ENRICH_EXISTING,
        self::QUALIFY_EXISTING,
        self::CONTRADICT_EXISTING,
        self::REVIEW_REQUIRED,
        self::CREATE_NEW,
    ];

    private function __construct(
        public string $owner,
        public string $operation,
        public string $action,
        public array $normalizedInput,
        public array $candidates,
        public array $dependencyRevisions,
        public array $diagnostics,
    ) {
    }

    public static function fromDecision(
        string $owner,
        string $operation,
        string $action,
        array $normalizedInput,
        array $candidates,
        array $dependencyRevisions,
        array $diagnostics,
    ): self {
        $action = strtoupper(trim($action));
        if (!in_array($action, self::ACTIONS, true)) throw new \InvalidArgumentException('KNOWLEDGE_PRE_CREATE_ACTION_INVALID');
        foreach ($dependencyRevisions as $revision) {
            if (!is_int($revision) && !ctype_digit((string) $revision)) throw new \InvalidArgumentException('KNOWLEDGE_PRE_CREATE_REVISION_INVALID');
            if ((int) $revision < 1) throw new \InvalidArgumentException('KNOWLEDGE_PRE_CREATE_REVISION_INVALID');
        }

        return new self(
            trim($owner),
            trim($operation),
            $action,
            self::sort($normalizedInput),
            array_values($candidates),
            self::sort($dependencyRevisions),
            self::sort($diagnostics),
        );
    }

    public function canCreate(): bool
    {
        return $this->action === self::CREATE_NEW;
    }

    public function targetId(): ?string
    {
        foreach ($this->candidates as $candidate) {
            if (!is_array($candidate)) continue;
            foreach (['canonical_id', 'claim_id', 'source_id', 'evidence_id'] as $key) {
                $value = trim((string) ($candidate[$key] ?? ''));
                if ($value !== '') return $value;
            }
        }
        return null;
    }

    public function fingerprint(): string
    {
        return hash('sha256', CommandCanonicalizer::canonicalize($this->toArray(false)));
    }

    public function toArray(bool $withFingerprint = true): array
    {
        $payload = [
            'owner' => $this->owner,
            'operation' => $this->operation,
            'action' => $this->action,
            'normalized_input' => $this->normalizedInput,
            'candidates' => $this->candidates,
            'dependency_revisions' => $this->dependencyRevisions,
            'diagnostics' => $this->diagnostics,
        ];
        if ($withFingerprint) $payload['fingerprint'] = $this->fingerprint();
        return $payload;
    }

    private static function sort(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::sort($item);
        return $value;
    }
}
