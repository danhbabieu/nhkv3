<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Capture;

use NHK\Core\Shared\Uuid\UuidCodec;

/** Immutable, durable semantic context shared by all Capture child workflows. */
final readonly class SubjectResolutionPacket
{
    /** @param array<string,mixed> $diagnostics */
    public function __construct(
        public string $status,
        public string $canonicalSubjectId,
        public string $entityType,
        public string $stableKey,
        public string $canonicalName,
        public int $revision,
        public string $matchReason,
        public array $diagnostics = [],
        public string $primarySource = '',
    ) {
        if (!in_array($status, ['resolved', 'ambiguous', 'unresolved', 'conflict'], true)) throw new \InvalidArgumentException('Subject resolution packet status is invalid.');
        if ($status === 'resolved' && (!UuidCodec::isValid($canonicalSubjectId) || $entityType === '' || $revision < 1)) throw new \InvalidArgumentException('Resolved subject packet is incomplete.');
        if ($status !== 'resolved' && $canonicalSubjectId !== '') throw new \InvalidArgumentException('Unresolved subject packet cannot carry a canonical subject.');
    }

    /** @param array<string,mixed> $resolution */
    public static function fromResolution(array $resolution): self
    {
        $primary = is_array($resolution['primary'] ?? null) ? $resolution['primary'] : [];
        $id = trim((string) ($primary['id'] ?? ''));
        $status = strtolower(trim((string) ($resolution['status'] ?? 'unresolved')));
        if (!in_array($status, ['resolved', 'ambiguous', 'unresolved', 'conflict'], true)) $status = 'unresolved';
        if ($status === 'resolved' && (!UuidCodec::isValid($id) || trim((string) ($primary['type'] ?? '')) === '')) $status = 'unresolved';
        return new self(
            $status,
            $status === 'resolved' ? $id : '',
            $status === 'resolved' ? trim((string) ($primary['type'] ?? '')) : '',
            $status === 'resolved' ? trim((string) ($primary['stable_key'] ?? '')) : '',
            $status === 'resolved' ? trim((string) ($primary['name'] ?? '')) : '',
            $status === 'resolved' ? max(1, (int) ($primary['revision'] ?? 1)) : 1,
            $status === 'resolved' ? trim((string) ($primary['match'] ?? $primary['match_reason'] ?? '')) : '',
            [
                'candidates' => is_array($resolution['candidates'] ?? null) ? $resolution['candidates'] : [],
                'unresolved' => array_values(array_map('strval', (array) ($resolution['unresolved'] ?? []))),
                'conflicts' => is_array($resolution['conflicts'] ?? null) ? $resolution['conflicts'] : [],
                'diagnostics' => array_values(array_map('strval', (array) ($resolution['diagnostics'] ?? []))),
            ],
            $status === 'resolved' ? trim((string) ($resolution['primary_source'] ?? '')) : '',
        );
    }

    /** @param array<string,mixed> $value */
    public static function fromArray(array $value): ?self
    {
        try {
            $primary = is_array($value['primary'] ?? null) ? $value['primary'] : [];
            if ($primary !== []) $value = array_replace($value, $primary);
            $packet = new self(
                strtolower(trim((string) ($value['status'] ?? ''))),
                trim((string) ($value['canonical_subject_id'] ?? $value['id'] ?? '')),
                trim((string) ($value['entity_type'] ?? $value['type'] ?? '')),
                trim((string) ($value['stable_key'] ?? '')),
                trim((string) ($value['canonical_name'] ?? $value['name'] ?? '')),
                max(1, (int) ($value['revision'] ?? 1)),
                trim((string) ($value['match_reason'] ?? $value['match'] ?? '')),
                is_array($value['diagnostics'] ?? null) ? $value['diagnostics'] : [],
                trim((string) ($value['primary_source'] ?? '')),
            );
            return $packet;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'packet_version' => 1,
            'status' => $this->status,
            'canonical_subject_id' => $this->canonicalSubjectId,
            'entity_type' => $this->entityType,
            'stable_key' => $this->stableKey,
            'canonical_name' => $this->canonicalName,
            'revision' => $this->revision,
            'match_reason' => $this->matchReason,
            // Read-compatible aliases for existing child adapters. The
            // typed fields above remain canonical; aliases are projections,
            // never independently writable subject state.
            'id' => $this->canonicalSubjectId,
            'type' => $this->entityType,
            'name' => $this->canonicalName,
            'match' => $this->matchReason,
            'primary_source' => $this->primarySource,
            'diagnostics' => $this->diagnostics,
        ];
    }

    /** @return array<string,mixed> */
    public function toResolution(): array
    {
        $primary = $this->status === 'resolved' ? [
            'id' => $this->canonicalSubjectId,
            'type' => $this->entityType,
            'stable_key' => $this->stableKey,
            'name' => $this->canonicalName,
            'revision' => $this->revision,
            'match' => $this->matchReason,
        ] : null;
        return [
            'status' => $this->status,
            'primary' => $primary,
            'subjects' => $primary === null ? [] : [$primary],
            'resolved' => $primary === null ? [] : [$primary],
            'candidates' => $this->diagnostics['candidates'] ?? [],
            'unresolved' => $this->diagnostics['unresolved'] ?? [],
            'conflicts' => $this->diagnostics['conflicts'] ?? [],
            'diagnostics' => $this->diagnostics['diagnostics'] ?? [],
            'primary_source' => $this->primarySource,
        ];
    }
}

