<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Application\Semantic\SubjectResolutionService;
use NHK\Core\Contracts\Capture\CaptureRepository;
use NHK\Core\Domain\Capture\{CaptureRecord, SubjectResolutionPacket};

/** Persists the Capture-owned subject handoff and verifies canonical read-back. */
final class CaptureSubjectBindingRecovery
{
    public function __construct(private CaptureRepository $captures, private ?SubjectResolutionService $subjects = null) {}

    public function packet(CaptureRecord $capture): ?SubjectResolutionPacket
    {
        foreach ([
            $capture->context['subject_resolution_packet'] ?? null,
            $capture->diagnostics['subject_resolution_packet'] ?? null,
            $capture->diagnostics['subjects'] ?? null,
            $capture->context['subject_resolution'] ?? null,
        ] as $candidate) {
            if (!is_array($candidate)) continue;
            $packet = SubjectResolutionPacket::fromArray($candidate);
            if ($packet?->status === 'resolved') return $packet;
        }
        return null;
    }

    /**
     * Recover a resolved packet for a historical Capture from its existing
     * subject context. This is read-only; persist() remains the write boundary.
     *
     * @param list<array<string,mixed>> $candidates
     * @param list<string> $hints
     * @param callable(list<string>):array<string,mixed> $resolver
     */
    public function resolve(CaptureRecord $capture, array $candidates, array $hints, callable $resolver): ?SubjectResolutionPacket
    {
        $existing = $this->packet($capture);
        if ($existing !== null) return $existing;

        $fallback = null;
        foreach ($candidates as $candidate) {
            $packet = SubjectResolutionPacket::fromArray($candidate);
            if ($packet === null) continue;
            $fallback ??= $packet;
            if ($packet->status === 'resolved') return $packet;
        }

        $contextHints = array_merge(
            $hints,
            $this->hints($capture->context['subject_hints'] ?? []),
            $this->hints($capture->context['planning_input']['subject_hints'] ?? []),
        );
        $contextHints = array_values(array_unique(array_filter(array_map('trim', $contextHints), static fn (string $hint): bool => $hint !== '')));
        if ($contextHints === []) return $fallback;

        $resolved = $resolver($contextHints);
        $packet = SubjectResolutionPacket::fromArray($resolved);
        return $packet?->status === 'resolved' ? $packet : $fallback;
    }

    /** @param array<string,mixed> $metadata */
    public function persist(CaptureRecord $capture, int $articleId, array $resolution, array $metadata = []): CaptureRecord
    {
        if ($articleId > 0 && $capture->articleId !== $articleId) throw new \RuntimeException('CAPTURE_ARTICLE_BINDING_UNAVAILABLE');
        if ($articleId < 1 && $capture->articleId !== null) throw new \RuntimeException('CAPTURE_ARTICLE_BINDING_UNAVAILABLE');
        $packet = SubjectResolutionPacket::fromArray($resolution);
        if ($packet === null || $packet->status !== 'resolved') throw new \RuntimeException('CAPTURE_SUBJECT_BINDING_UNAVAILABLE');
        $packet = $this->canonicalPacket($packet);
        $existing = $this->packet($capture);
        if ($existing !== null) {
            if (!$this->sameBinding($existing, $packet)) throw new \RuntimeException('CAPTURE_SUBJECT_BINDING_CONFLICT');
            $readBack = $this->captures->findById($capture->captureId);
            $readBackPacket = $readBack === null ? null : $this->packet($readBack);
            if ($readBack === null || $readBackPacket === null || !$this->sameBinding($readBackPacket, $packet)) throw new \RuntimeException('CAPTURE_SUBJECT_BINDING_READBACK_UNAVAILABLE');
            return $readBack;
        }

        $packetArray = $packet->toArray();
        $context = $capture->context;
        $context['subject_resolution_packet'] = $packetArray;
        $diagnostics = $capture->diagnostics;
        $diagnostics['subject_resolution_packet'] = $packetArray;
        $diagnostics['subjects'] = $packet->toResolution();
        if ($metadata !== []) $diagnostics['subject_reconciliation'] = $metadata;
        $this->captures->save(new CaptureRecord(
            $capture->captureId,
            $capture->idempotencyKey,
            $capture->requestFingerprint,
            $capture->stage,
            $capture->status,
            $capture->articleId,
            $capture->articleStateToken,
            $capture->assets,
            $context,
            $diagnostics,
            $capture->phaseReceipts,
            $capture->revision + 1,
            $capture->createdAt,
            gmdate('Y-m-d H:i:s.u'),
        ));
        $readBack = $this->captures->findById($capture->captureId);
        $readBackPacket = $readBack === null ? null : $this->packet($readBack);
        if ($readBack === null || ($articleId > 0 && $readBack->articleId !== $articleId) || ($articleId < 1 && $readBack->articleId !== null) || $readBackPacket?->canonicalSubjectId !== $packet->canonicalSubjectId || $readBackPacket?->entityType !== $packet->entityType || $readBackPacket?->revision !== $packet->revision) throw new \RuntimeException('CAPTURE_SUBJECT_BINDING_READBACK_UNAVAILABLE');
        return $readBack;
    }

    /** @param array<string,mixed> $resolution */
    public function supersede(CaptureRecord $capture, int $articleId, array $resolution): CaptureRecord
    {
        if ($articleId < 1 || $capture->articleId !== $articleId) throw new \RuntimeException('CAPTURE_ARTICLE_BINDING_UNAVAILABLE');
        $packet = SubjectResolutionPacket::fromArray($resolution);
        if ($packet === null || $packet->status !== 'resolved') throw new \RuntimeException('CAPTURE_SUBJECT_BINDING_UNAVAILABLE');
        $packet = $this->canonicalPacket($packet);
        $existing = $this->packet($capture);
        if ($existing !== null && $this->sameBinding($existing, $packet)) return $this->persist($capture, $articleId, $packet->toResolution());

        $packetArray = $packet->toArray();
        $history = is_array($capture->context['subject_resolution_packet_history'] ?? null) ? $capture->context['subject_resolution_packet_history'] : [];
        if ($existing !== null && $history === []) $history[] = $existing->toArray();
        $context = $capture->context;
        $context['subject_resolution_packet'] = $packetArray;
        $context['subject_resolution_packet_history'] = $history;
        $diagnostics = $capture->diagnostics;
        $diagnostics['subject_resolution_packet'] = $packetArray;
        $diagnostics['subjects'] = $packet->toResolution();
        $diagnostics['subject_packet_supersession'] = ['previous' => $existing?->toArray(), 'current' => $packetArray];
        $this->captures->save(new CaptureRecord(
            $capture->captureId,
            $capture->idempotencyKey,
            $capture->requestFingerprint,
            $capture->stage,
            $capture->status,
            $capture->articleId,
            $capture->articleStateToken,
            $capture->assets,
            $context,
            $diagnostics,
            $capture->phaseReceipts,
            $capture->revision + 1,
            $capture->createdAt,
            gmdate('Y-m-d H:i:s.u'),
        ));
        $readBack = $this->captures->findById($capture->captureId);
        $readBackPacket = $readBack === null ? null : $this->packet($readBack);
        if ($readBack === null || $readBack->articleId !== $articleId || $readBackPacket === null || !$this->sameBinding($readBackPacket, $packet)) throw new \RuntimeException('CAPTURE_SUBJECT_BINDING_READBACK_UNAVAILABLE');
        return $readBack;
    }

    private function sameBinding(SubjectResolutionPacket $left, SubjectResolutionPacket $right): bool
    {
        return $left->canonicalSubjectId === $right->canonicalSubjectId
            && $left->entityType === $right->entityType
            && $left->stableKey === $right->stableKey
            && $left->canonicalName === $right->canonicalName
            && $left->revision === $right->revision;
    }

    private function canonicalPacket(SubjectResolutionPacket $packet): SubjectResolutionPacket
    {
        if ($this->subjects === null) return $packet;
        $resolution = $this->subjects->resolveSources(['canonical_uuid' => [$packet->canonicalSubjectId]]);
        $primary = is_array($resolution['primary'] ?? null) ? $resolution['primary'] : [];
        if (($resolution['status'] ?? '') !== 'resolved'
            || trim((string) ($primary['id'] ?? '')) !== $packet->canonicalSubjectId
            || trim((string) ($primary['type'] ?? '')) !== $packet->entityType) {
            $code = ($resolution['status'] ?? '') === 'ambiguous'
                ? 'CAPTURE_SUBJECT_BINDING_AMBIGUOUS'
                : 'CAPTURE_SUBJECT_BINDING_UNAVAILABLE';
            throw new \RuntimeException($code);
        }

        try {
            return new SubjectResolutionPacket(
                'resolved',
                $packet->canonicalSubjectId,
                $packet->entityType,
                trim((string) ($primary['stable_key'] ?? $packet->stableKey)),
                trim((string) ($primary['name'] ?? $packet->canonicalName)),
                max(1, (int) ($primary['revision'] ?? $packet->revision)),
                trim((string) ($primary['match'] ?? $packet->matchReason)),
                $packet->diagnostics + ['canonical_readback_verified' => true],
                $packet->primarySource !== '' ? $packet->primarySource : (string) ($resolution['primary_source'] ?? ''),
            );
        } catch (\Throwable $error) {
            throw new \RuntimeException('CAPTURE_SUBJECT_BINDING_UNAVAILABLE', 0, $error);
        }
    }

    /** @return list<string> */
    private function hints(mixed $value): array
    {
        if (is_string($value)) return [$value];
        $hints = [];
        foreach ((array) $value as $item) {
            if (is_string($item)) $hints[] = $item;
            elseif (is_array($item)) {
                $hint = trim((string) ($item['id'] ?? $item['canonical_subject_id'] ?? $item['stable_key'] ?? $item['name'] ?? ''));
                if ($hint !== '') $hints[] = $hint;
            }
        }
        return $hints;
    }
}
