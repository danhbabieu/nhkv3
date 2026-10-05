<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Contracts\Capture\CaptureRepository;
use NHK\Core\Domain\Capture\{CaptureRecord, SubjectResolutionPacket};

/** Persists only the Capture-owned subject handoff for an existing Article. */
final class CaptureSubjectBindingRecovery
{
    public function __construct(private CaptureRepository $captures) {}

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
            if ($packet !== null) return $packet;
        }
        return null;
    }

    public function persist(CaptureRecord $capture, int $articleId, array $resolution): CaptureRecord
    {
        if ($articleId < 1 || $capture->articleId !== $articleId) throw new \RuntimeException('CAPTURE_ARTICLE_BINDING_UNAVAILABLE');
        $packet = SubjectResolutionPacket::fromArray($resolution);
        if ($packet === null || $packet->status !== 'resolved') throw new \RuntimeException('CAPTURE_SUBJECT_BINDING_UNAVAILABLE');
        $existing = $this->packet($capture);
        if ($existing !== null) return $capture;

        $packetArray = $packet->toArray();
        $context = $capture->context;
        $context['subject_resolution_packet'] = $packetArray;
        $diagnostics = $capture->diagnostics;
        $diagnostics['subject_resolution_packet'] = $packetArray;
        $diagnostics['subjects'] = $packet->toResolution();
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
        if ($readBack === null || $readBack->articleId !== $articleId || $readBackPacket?->canonicalSubjectId !== $packet->canonicalSubjectId || $readBackPacket?->entityType !== $packet->entityType || $readBackPacket?->revision !== $packet->revision) throw new \RuntimeException('CAPTURE_SUBJECT_BINDING_READBACK_UNAVAILABLE');
        return $readBack;
    }
}
