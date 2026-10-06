<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Dictionary;

use NHK\Core\Domain\Governance\CommandCanonicalizer;

final readonly class DictionaryPreCreateResolution
{
    public const REUSE_EXISTING = 'REUSE_EXISTING';
    public const ADD_FORM_TO_ENTRY = 'ADD_FORM_TO_ENTRY';
    public const ADD_SENSE_TO_ENTRY = 'ADD_SENSE_TO_ENTRY';
    public const ENRICH_EXISTING = 'ENRICH_EXISTING';
    public const REVIEW_REQUIRED = 'REVIEW_REQUIRED';
    public const CREATE_NEW = 'CREATE_NEW';

    private const ACTIONS = [
        self::REUSE_EXISTING,
        self::ADD_FORM_TO_ENTRY,
        self::ADD_SENSE_TO_ENTRY,
        self::ENRICH_EXISTING,
        self::REVIEW_REQUIRED,
        self::CREATE_NEW,
    ];

    private function __construct(
        public string $action,
        public string $normalizedForm,
        public array $context,
        public array $candidates,
        public array $dependencyRevisions,
        public array $diagnostics,
    ) {
    }

    public static function fromDecision(
        string $action,
        string $normalizedForm,
        array $context,
        array $candidates,
        array $dependencyRevisions,
        array $diagnostics,
    ): self {
        $action = strtoupper(trim($action));
        $normalizedForm = trim($normalizedForm);
        if (!in_array($action, self::ACTIONS, true)) throw new \InvalidArgumentException('DICTIONARY_PRE_CREATE_ACTION_INVALID');
        if ($normalizedForm === '') throw new \InvalidArgumentException('DICTIONARY_PRE_CREATE_NORMALIZED_FORM_REQUIRED');
        foreach ($dependencyRevisions as $revision) {
            if (!is_int($revision) && !ctype_digit((string) $revision)) throw new \InvalidArgumentException('DICTIONARY_PRE_CREATE_REVISION_INVALID');
            if ((int) $revision < 1) throw new \InvalidArgumentException('DICTIONARY_PRE_CREATE_REVISION_INVALID');
        }

        return new self($action, $normalizedForm, self::sort($context), array_values($candidates), self::sort($dependencyRevisions), self::sort($diagnostics));
    }

    public function canCreate(): bool
    {
        return $this->action === self::CREATE_NEW;
    }

    public function fingerprint(): string
    {
        return hash('sha256', CommandCanonicalizer::canonicalize($this->payload()));
    }

    public function toArray(): array
    {
        return $this->payload() + [
            'fingerprint' => $this->fingerprint(),
        ];
    }

    private function payload(): array
    {
        return [
            'action' => $this->action,
            'normalized_form' => $this->normalizedForm,
            'context' => $this->context,
            'candidates' => $this->candidates,
            'dependency_revisions' => $this->dependencyRevisions,
            'diagnostics' => $this->diagnostics,
        ];
    }

    private static function sort(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::sort($item);
        return $value;
    }
}
