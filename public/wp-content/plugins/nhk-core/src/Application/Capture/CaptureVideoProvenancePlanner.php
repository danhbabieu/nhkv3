<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Domain\Governance\CommandCanonicalizer;
use NHK\Core\Infrastructure\Admin\VideoRelationAdminContract;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Core\Application\Video\VideoThumbnailSelector;

/**
 * Plans the source-specific provenance dependencies for a Capture-owned Video.
 * This class is deliberately a planner: it has no repository or semantic
 * writer and cannot turn a subject identifier into Evidence by itself.
 */
final class CaptureVideoProvenancePlanner
{
    public function __construct(private ?VideoThumbnailSelector $thumbnailSelector = null)
    {
    }

    /** @return array<string,mixed> */
    public function plan(string $captureId, array $videoProposal, array $sourceSnapshot, array $resolvedSubject, array $context = []): array
    {
        $video = is_array($videoProposal['payload'] ?? null) ? $videoProposal : ['payload' => $videoProposal];
        $payload = is_array($video['payload'] ?? null) ? $video['payload'] : [];
        $video = $this->preserveCanonicalOwnerBinding($video);
        $payload = is_array($video['payload'] ?? null) ? $video['payload'] : [];
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        // A resumed child is rehydrated from the immutable Capture asset. The
        // adapter/source snapshot is therefore authoritative when present,
        // with the original proposal's stored source packet as a lossless
        // fallback. This keeps dependency materialization independent from
        // reparsing an empty addendum.
        $storedSource = is_array($metadata['source'] ?? null) ? $metadata['source'] : (is_array($metadata['source_snapshot'] ?? null) ? $metadata['source_snapshot'] : []);
        $sourceSnapshot = array_merge($storedSource, $sourceSnapshot);
        $resolvedSubject = $this->lockedSubject($resolvedSubject, $metadata, (bool) ($context['preserve_original_subject'] ?? false));
        $thumbnailSelection = $this->thumbnailSelection($sourceSnapshot);
        if ($thumbnailSelection !== []) {
            $sourceSnapshot['thumbnail_selection'] = $thumbnailSelection;
            $videoProposal = $this->withThumbnailSelection($video, $thumbnailSelection);
            $video = $videoProposal;
        }
        $sourceTitle = trim((string) ($sourceSnapshot['source_title'] ?? ''));
        $subjectId = trim((string) ($resolvedSubject['id'] ?? ''));
        $subjectType = trim((string) ($resolvedSubject['type'] ?? ''));
        $subjectName = trim((string) ($resolvedSubject['name'] ?? ''));
        $externalId = trim((string) ($sourceSnapshot['external_video_id'] ?? $metadata['source']['external_video_id'] ?? ''));
        $platform = strtolower(trim((string) ($sourceSnapshot['platform'] ?? $metadata['source']['platform'] ?? 'youtube')));
        $locator = trim((string) ($sourceSnapshot['canonical_source_url'] ?? $payload['url'] ?? ''));
        $unsupported = $this->unsupportedClassifications($sourceTitle, (string) ($context['user_hint'] ?? ''));

        $base = [$platform, $externalId, $locator, $subjectType, $subjectId];
        $sourceKey = 'nhk:source:video:' . hash('sha256', CommandCanonicalizer::canonicalize([$platform, $externalId, $locator]));
        $claimKey = 'nhk:knowledge:video-provenance:' . hash('sha256', CommandCanonicalizer::canonicalize($base));
        $evidenceKey = 'video-provenance:evidence:' . hash('sha256', CommandCanonicalizer::canonicalize([$sourceKey, $claimKey]));
        $emptyVideo = $this->withAttachments($video, []);

        $identityFields = $this->sourceIdentityFields($sourceSnapshot);
        $identityMatches = $this->identityMatchesSubject($identityFields, $resolvedSubject);
        $handoff = $this->explicitSubjectHandoff($resolvedSubject);
        $conflicts = $this->sourceConflicts($context, $handoff['subject'] ?? []);
        $diagnostics = [
            'source_title' => $sourceTitle,
            'subject_id' => $subjectId,
            'subject_type' => $subjectType,
            'identity_fields' => array_keys($identityFields),
            'identity_matches' => $identityMatches,
            'subject_match' => (string) ($resolvedSubject['match'] ?? ''),
            'explicit_subject' => $handoff['subject'] ?? null,
            'conflicts' => $conflicts,
        ];
        if (($handoff['status'] ?? '') !== 'valid') {
            return [
                'status' => 'REVIEW_REQUIRED',
                'blockers' => [(string) ($handoff['reason'] ?? 'SUBJECT_UNRESOLVED')],
                'dependencies' => [],
                'video_proposal' => $emptyVideo,
                'evidence_idempotency_key' => $evidenceKey,
                'reuse_scope' => 'source-specific-external-video',
                'unsupported_classifications' => $unsupported,
                'diagnostics' => $diagnostics,
            ];
        }
        if ($conflicts !== []) {
            return [
                'status' => 'REVIEW_REQUIRED',
                'blockers' => ['SOURCE_SUBJECT_IDENTITY_CONFLICT'],
                'dependencies' => [],
                'video_proposal' => $emptyVideo,
                'evidence_idempotency_key' => $evidenceKey,
                'reuse_scope' => 'source-specific-external-video',
                'unsupported_classifications' => $unsupported,
                'diagnostics' => $diagnostics,
                'relation' => ['target_type' => $subjectType, 'target_uuid' => $subjectId, 'predicate' => 'about', 'origin' => 'EXPLICIT_USER_RELATION'],
            ];
        }
        // A valid uuid_exact packet is the immutable semantic handoff from
        // Capture. Trusted source text remains provenance/editorial input and
        // may diagnose a conflict, but it cannot veto or replace this packet.
        if (!$handoff['explicit'] && ($sourceTitle === '' || $subjectId === '' || $subjectType === '' || $identityMatches === [])) {
            return [
                'status' => 'REVIEW_REQUIRED',
                'blockers' => ['SOURCE_SUBJECT_IDENTITY_UNCONFIRMED'],
                'dependencies' => [],
                'video_proposal' => $emptyVideo,
                'evidence_idempotency_key' => $evidenceKey,
                'reuse_scope' => 'source-specific-external-video',
                'unsupported_classifications' => $unsupported,
                'diagnostics' => $diagnostics,
            ];
        }

        $identity = 'canonical ' . ($subjectName !== '' ? $subjectName : $subjectId);
        $sourcePayload = [
            'stable_key' => $sourceKey,
            'title' => $sourceTitle !== '' ? $sourceTitle : 'YouTube video ' . ($externalId !== '' ? $externalId : 'source'),
            'source_type' => 'website',
            'locator' => $locator !== '' ? $locator : null,
            'metadata' => [
                'visibility' => 'PRIVATE',
                'origin' => 'CAPTURE_VIDEO_SOURCE_SNAPSHOT',
                'platform' => $platform,
                'external_video_id' => $externalId,
            ],
        ];
        $claimPayload = [
            'stable_key' => $claimKey,
            'text' => 'The source identifies this Video as concerning ' . $identity . '.',
            'claim_type' => 'provenance',
            'provenance' => [
                'origin' => 'CAPTURE_VIDEO_SOURCE_PROVENANCE',
                'metadata' => [
                    'verification_status' => 'PRIVATE',
                    'knowledge_status' => 'PRIVATE',
                    'subject_id' => $subjectId,
                    'subject_type' => $subjectType,
                    'platform' => $platform,
                    'external_video_id' => $externalId,
                    'source_stable_key' => $sourceKey,
                ],
            ],
        ];
        $evidencePayload = [
            'claim_id' => null,
            'source_id' => null,
            'excerpt' => $sourceTitle !== '' ? $sourceTitle : $locator,
            'relation' => 'supports',
            'locator' => $locator !== '' ? $locator : null,
            'metadata' => [
                'visibility' => 'PRIVATE',
                'origin' => 'CAPTURE_VIDEO_SOURCE_PROVENANCE',
                'platform' => $platform,
                'external_video_id' => $externalId,
                'subject_id' => $subjectId,
                'subject_type' => $subjectType,
                'source_stable_key' => $sourceKey,
                'claim_stable_key' => $claimKey,
            ],
        ];

        $originalRelation = $this->originalRelation($metadata, $subjectType, $subjectId);
        return [
            'status' => 'READY',
            'blockers' => [],
            'dependencies' => [
                $this->arguments('source', $sourceKey, $sourcePayload, 'video-provenance:source:' . hash('sha256', $sourceKey)),
                $this->arguments('knowledge', $subjectId, $claimPayload, 'video-provenance:claim:' . hash('sha256', $claimKey)),
            ],
            'evidence' => $evidencePayload,
            'source_stable_key' => $sourceKey,
            'claim_stable_key' => $claimKey,
            'evidence_idempotency_key' => $evidenceKey,
            'reuse_scope' => 'source-specific-external-video',
            'unsupported_classifications' => $unsupported,
            'video_proposal' => $emptyVideo,
            'relation' => [
                'target_type' => $subjectType,
                'target_uuid' => $subjectId,
                'predicate' => (string) ($originalRelation['predicate'] ?? 'about'),
                'origin' => (string) ($originalRelation['origin'] ?? 'EXPLICIT_USER_RELATION'),
                'reason' => (string) ($originalRelation['reason'] ?? 'Source-specific provenance handoff.'),
                'confidence' => (float) ($originalRelation['confidence'] ?? 1.0),
            ],
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * Capture owns one Video identity handoff. Keep the Proposal subject and
     * payload owner bound to that same canonical UUID before dependencies are
     * materialized; a missing or conflicting binding must never be repaired by
     * generating a second Video identity in the executor.
     *
     * @param array<string,mixed> $video
     * @return array<string,mixed>
     */
    private function preserveCanonicalOwnerBinding(array $video): array
    {
        if (($video['entity_type'] ?? 'video') !== 'video' || ($video['operation'] ?? 'ingest') !== 'ingest') return $video;
        $payload = is_array($video['payload'] ?? null) ? $video['payload'] : [];
        $payloadId = trim((string) ($payload['canonical_id'] ?? ''));
        $subjectId = trim((string) ($video['subject_id'] ?? ''));
        if ($payloadId !== '' && !UuidCodec::isValid($payloadId)) throw new \RuntimeException('VIDEO_CANONICAL_IDENTITY_INVALID');
        if ($subjectId !== '' && !UuidCodec::isValid($subjectId)) throw new \RuntimeException('VIDEO_CANONICAL_IDENTITY_INVALID');
        if ($payloadId !== '' && $subjectId !== '' && strtolower($payloadId) !== strtolower($subjectId)) throw new \RuntimeException('VIDEO_CANONICAL_IDENTITY_CONFLICT');
        $canonicalId = $payloadId !== '' ? $payloadId : $subjectId;
        // Standalone preview packets may intentionally defer UUID allocation
        // until the governed writer. Capture-owned packets with either side
        // present are normalized here; they must never carry two identities.
        if ($canonicalId === '') return $video;
        $payload['canonical_id'] = $canonicalId;
        $video['payload'] = $payload;
        $video['subject_id'] = $canonicalId;
        return $video;
    }

    /** @return array{status:string,explicit:bool,subject:array<string,mixed>,reason:string} */
    private function explicitSubjectHandoff(array $subject): array
    {
        $id = trim((string) ($subject['id'] ?? ''));
        $type = strtolower(trim((string) ($subject['type'] ?? '')));
        $explicit = strtolower(trim((string) ($subject['match'] ?? ''))) === 'uuid_exact';
        if (!$explicit) return ['status' => 'valid', 'explicit' => false, 'subject' => [], 'reason' => ''];
        if (!UuidCodec::isValid($id)) return ['status' => 'invalid', 'explicit' => true, 'subject' => $subject, 'reason' => 'SUBJECT_UNRESOLVED'];
        if (!in_array($type, (new VideoRelationAdminContract())->targetTypes(), true)) return ['status' => 'invalid', 'explicit' => true, 'subject' => $subject, 'reason' => 'SUBJECT_TYPE_UNSUPPORTED'];
        if (array_key_exists('active', $subject) && $subject['active'] !== true) return ['status' => 'invalid', 'explicit' => true, 'subject' => $subject, 'reason' => 'SUBJECT_INACTIVE'];
        if (array_key_exists('revision', $subject) && (int) $subject['revision'] < 1) return ['status' => 'invalid', 'explicit' => true, 'subject' => $subject, 'reason' => 'SUBJECT_REVISION_INVALID'];
        return ['status' => 'valid', 'explicit' => true, 'subject' => $subject, 'reason' => ''];
    }

    /** @return list<array<string,mixed>> */
    private function sourceConflicts(array $context, array $explicitSubject): array
    {
        if ($explicitSubject === []) return [];
        $explicitId = strtolower(trim((string) ($explicitSubject['id'] ?? '')));
        $conflicts = [];
        foreach ((array) ($context['source_subject_candidates'] ?? []) as $candidate) {
            if (!is_array($candidate) || !UuidCodec::isValid((string) ($candidate['id'] ?? ''))) continue;
            $candidateId = strtolower(trim((string) $candidate['id']));
            $match = strtolower(trim((string) ($candidate['match'] ?? '')));
            $confidence = (float) ($candidate['confidence'] ?? 0);
            if ($candidateId !== '' && $candidateId !== $explicitId && $confidence >= 0.9 && in_array($match, ['uuid_exact', 'stable_key_exact', 'exact_name_or_alias', 'exact_variant_reference', 'exact_variant_name_reference'], true)) {
                $conflicts[] = ['candidate' => $candidate, 'explicit_subject_id' => $explicitSubject['id'], 'reason' => 'Source metadata resolves strongly to another canonical entity.'];
            }
        }
        return $conflicts;
    }

    /** @return array<string,mixed> */
    public function attachEvidence(array $plan, string $sourceId, string $claimId, string $evidenceId): array
    {
        if (($plan['status'] ?? '') !== 'READY') return $plan;
        $evidence = is_array($plan['evidence'] ?? null) ? $plan['evidence'] : [];
        $evidence['source_id'] = $sourceId;
        $evidence['claim_id'] = $claimId;
        $dependencies = (array) ($plan['dependencies'] ?? []);
        $dependencies[] = $this->arguments('evidence', $claimId, $evidence, (string) ($plan['evidence_idempotency_key'] ?? ''));
        $relation = is_array($plan['relation'] ?? null) ? $plan['relation'] : [];
        $attachment = [
            'target_type' => (string) ($relation['target_type'] ?? ''),
            'target_uuid' => (string) ($relation['target_uuid'] ?? ''),
            'predicate' => (string) ($relation['predicate'] ?? 'about'),
            'origin' => (string) ($relation['origin'] ?? 'EXPLICIT_USER_RELATION'),
            'reason' => (string) ($relation['reason'] ?? 'Source-specific provenance handoff.'),
            'confidence' => (float) ($relation['confidence'] ?? 1.0),
            'evidence_refs' => [['evidence_id' => $evidenceId]],
        ];
        $plan['dependencies'] = $dependencies;
        $plan['video_proposal'] = $this->withAttachments((array) ($plan['video_proposal'] ?? []), [$attachment]);
        $plan['evidence'] = $evidence;
        $plan['evidence_id'] = $evidenceId;
        return $plan;
    }

    /**
     * Adds the governed Evidence dependency packet without putting a
     * placeholder reference into the Video relation. The relation is attached
     * only after the Evidence apply has returned a canonical read-back UUID.
     *
     * @return array<string,mixed>
     */
    public function attachEvidenceDependency(array $plan, string $sourceId, string $claimId): array
    {
        if (($plan['status'] ?? '') !== 'READY') return $plan;
        $evidence = is_array($plan['evidence'] ?? null) ? $plan['evidence'] : [];
        $evidence['source_id'] = $sourceId;
        $evidence['claim_id'] = $claimId;
        $dependencies = (array) ($plan['dependencies'] ?? []);
        $dependencies[] = $this->arguments('evidence', $claimId, $evidence, (string) ($plan['evidence_idempotency_key'] ?? ''));
        $plan['dependencies'] = $dependencies;
        $plan['evidence'] = $evidence;
        return $plan;
    }

    /** @return array<string,mixed> */
    private function lockedSubject(array $resolvedSubject, array $metadata, bool $preserveOriginal): array
    {
        $packet = is_array($metadata['subject_resolution_packet'] ?? null) ? $metadata['subject_resolution_packet'] : [];
        // The Capture packet is the semantic handoff. Once it is explicitly
        // uuid_exact, even an invalid packet must remain visible so the Video
        // gate can fail closed; a resolver result must never replace it.
        if (strtolower(trim((string) ($packet['match'] ?? ''))) === 'uuid_exact') return $packet;
        $about = [];
        foreach ((array) ($metadata['semantic_attachments'] ?? []) as $attachment) {
            if (!is_array($attachment) || strtolower(trim((string) ($attachment['predicate'] ?? 'about'))) !== 'about') continue;
            $id = trim((string) ($attachment['target_uuid'] ?? $attachment['target_id'] ?? ''));
            $type = strtolower(trim((string) ($attachment['target_type'] ?? '')));
            if (UuidCodec::isValid($id) && $type !== '') $about[$type . ':' . strtolower($id)] = ['id' => $id, 'type' => $type];
        }
        $candidates = [];
        foreach ([$packet, count($about) === 1 ? array_values($about)[0] : []] as $candidate) {
            if (!is_array($candidate) || !UuidCodec::isValid((string) ($candidate['id'] ?? '')) || trim((string) ($candidate['type'] ?? '')) === '') continue;
            $candidates[] = $candidate;
        }
        if ($preserveOriginal && $candidates !== []) return $candidates[0];
        if (UuidCodec::isValid((string) ($resolvedSubject['id'] ?? '')) && trim((string) ($resolvedSubject['type'] ?? '')) !== '') return $resolvedSubject;
        return $candidates[0] ?? $resolvedSubject;
    }

    /** @return array<string,mixed> */
    private function originalRelation(array $metadata, string $subjectType, string $subjectId): array
    {
        foreach ((array) ($metadata['semantic_attachments'] ?? []) as $attachment) {
            if (!is_array($attachment)) continue;
            if ((string) ($attachment['target_type'] ?? '') !== $subjectType || (string) ($attachment['target_uuid'] ?? '') !== $subjectId) continue;
            if ((string) ($attachment['predicate'] ?? 'about') !== 'about') continue;
            return $attachment;
        }
        return [];
    }

    /** @return array<string,mixed> */
    private function arguments(string $entityType, string $subjectId, array $payload, string $idempotencyKey): array
    {
        return ['operation' => 'ingest', 'entity_type' => $entityType, 'subject_id' => $subjectId, 'payload' => $payload, 'idempotency_key' => $idempotencyKey];
    }

    /** @param array<string,mixed> $video @return array<string,mixed> */
    private function withAttachments(array $video, array $attachments): array
    {
        $payload = is_array($video['payload'] ?? null) ? $video['payload'] : [];
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $metadata['semantic_attachments'] = $attachments;
        $payload['metadata'] = $metadata;
        $video['payload'] = $payload;
        return $video;
    }

    /** @return array<string,mixed> */
    private function thumbnailSelection(array $source): array
    {
        $existing = is_array($source['thumbnail_selection'] ?? null) ? $source['thumbnail_selection'] : [];
        if (trim((string) ($existing['url'] ?? '')) !== '' && (int) ($existing['width'] ?? 0) > 0 && (int) ($existing['height'] ?? 0) > 0) return $existing;
        if ($this->thumbnailSelector === null) return [];
        $candidates = is_array($source['thumbnail_candidates'] ?? null) ? $source['thumbnail_candidates'] : [];
        if ($candidates === []) {
            foreach ((array) ($source['thumbnail_urls'] ?? $source['thumbnails'] ?? []) as $url) {
                if (trim((string) $url) !== '') $candidates[] = ['url' => trim((string) $url)];
            }
        }
        return $candidates === [] ? [] : $this->thumbnailSelector->select($candidates);
    }

    /** @param array<string,mixed> $video @return array<string,mixed> */
    private function withThumbnailSelection(array $video, array $selection): array
    {
        $payload = is_array($video['payload'] ?? null) ? $video['payload'] : [];
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        foreach (['source', 'source_snapshot'] as $key) {
            if (!is_array($metadata[$key] ?? null)) continue;
            $metadata[$key]['thumbnail_selection'] = $selection;
        }
        if (!is_array($metadata['source'] ?? null)) $metadata['source'] = ['thumbnail_selection' => $selection];
        $metadata['thumbnail_selection'] = $selection;
        $payload['metadata'] = $metadata;
        $video['payload'] = $payload;
        return $video;
    }

    /** @return array<string,string> */
    private function sourceIdentityFields(array $snapshot): array
    {
        $fields = [];
        foreach ([
            'source_title' => $snapshot['source_title'] ?? '',
            'source_description' => $snapshot['source_description'] ?? '',
            'channel_title' => $snapshot['channel_title'] ?? '',
        ] as $field => $value) {
            $value = trim((string) $value);
            if ($value !== '') $fields[$field] = $value;
        }
        return $fields;
    }

    /**
     * Official source metadata may confirm the locked subject. This identity
     * check is intentionally separate from Evidence creation: matching a
     * trusted source identity field never becomes an Evidence reference.
     *
     * @param array<string,string> $fields @return list<string>
     */
    private function identityMatchesSubject(array $fields, array $subject): array
    {
        $normalizedFields = [];
        foreach ($fields as $field => $value) {
            $normalized = $this->normalize($value);
            if ($normalized !== '') $normalizedFields[$field] = $normalized;
        }
        if ($normalizedFields === []) return [];

        $identityPhrases = $this->identityPhrases($subject);
        $subjectType = strtolower(trim((string) ($subject['type'] ?? '')));
        if ($subjectType === 'model') {
            // A model may be named by a parent/brand component and a model
            // discriminator. They may be split across trusted fields, but
            // every component of one already-authorized canonical phrase must
            // be present. This is deliberately not a token-bag search.
            foreach ($identityPhrases as $phrase) {
                if (count($phrase) < 2) continue;
                $matches = $this->fieldsCoveringTerms($normalizedFields, $phrase);
                if ($matches !== []) return $matches;
            }
        } else {
            foreach ($identityPhrases as $phrase) {
                if (count($phrase) !== 1) {
                    $matches = $this->fieldsCoveringPhrase($normalizedFields, $phrase);
                    if ($matches !== []) return $matches;
                } elseif (($field = $this->fieldContainingTerm($normalizedFields, $phrase[0])) !== null) {
                    return [$field];
                }
            }
        }

        if ($subjectType === 'variant') {
            foreach ($normalizedFields as $field => $value) if ($this->variantReferenceIdentifies($value, $subject)) return [$field];
        }
        return [];
    }

    /** @return list<list<string>> */
    private function identityPhrases(array $subject): array
    {
        $values = [
            (string) ($subject['canonical_name'] ?? ''),
            (string) ($subject['name'] ?? ''),
            (string) ($subject['display_name'] ?? ''),
            (string) ($subject['label'] ?? ''),
        ];
        foreach ((array) ($subject['aliases'] ?? []) as $alias) if (is_string($alias)) $values[] = $alias;
        foreach ($this->stableKeyIdentityTerms($subject) as $term) $values[] = $term;

        $phrases = [];
        foreach ($values as $value) {
            $terms = $this->identityTerms($value);
            if ($terms === []) continue;
            $key = implode('|', $terms);
            $phrases[$key] = $terms;
        }
        return array_values($phrases);
    }

    /** @return list<string> */
    private function identityTerms(string $value): array
    {
        $normalized = $this->normalize($value);
        if ($normalized === '') return [];
        return array_values(array_unique(array_filter(
            preg_split('/\s+/u', $normalized) ?: [],
            static fn (string $term): bool => $term !== '',
        )));
    }

    /** @param array<string,string> $fields @param list<string> $terms @return list<string> */
    private function fieldsCoveringTerms(array $fields, array $terms): array
    {
        $matches = [];
        foreach ($terms as $term) {
            $field = $this->fieldContainingTerm($fields, $term);
            if ($field === null) return [];
            $matches[] = $field;
        }
        return array_values(array_unique($matches));
    }

    /** @param array<string,string> $fields @param list<string> $terms @return list<string> */
    private function fieldsCoveringPhrase(array $fields, array $terms): array
    {
        foreach ($fields as $field => $value) if ($this->fieldContainsPhrase($value, $terms)) return [$field];
        return [];
    }

    /** @param array<string,string> $fields */
    private function fieldContainingTerm(array $fields, string $term): ?string
    {
        foreach ($fields as $field => $value) if ($this->fieldContainsTerm($value, $term)) return $field;
        return null;
    }

    /** @param list<string> $terms */
    private function fieldContainsPhrase(string $field, array $terms): bool
    {
        if (count($terms) === 1) return $this->fieldContainsTerm($field, $terms[0]);
        $fieldTerms = $this->identityTerms($field);
        $size = count($terms);
        for ($offset = 0, $limit = count($fieldTerms) - $size; $offset <= $limit; ++$offset) {
            if (array_slice($fieldTerms, $offset, $size) === $terms) return true;
        }
        return false;
    }

    private function fieldContainsTerm(string $field, string $term): bool
    {
        $term = $this->normalize($term);
        if ($term === '') return false;
        $fieldTerms = $this->identityTerms($field);
        $termTerms = $this->identityTerms($term);
        if ($termTerms === []) return false;
        if (count($termTerms) > 1) return $this->fieldContainsPhrase($field, $termTerms);
        if (in_array($termTerms[0], $fieldTerms, true)) return true;

        // Bounded display-alias normalization: a canonical single token may
        // be displayed as adjacent localized tokens. Only contiguous 2–3
        // token runs are compacted, and only an exact canonical token can
        // match; no edit-distance/fuzzy matching.
        $compactTerm = str_replace(' ', '', $termTerms[0]);
        if (strlen($compactTerm) < 3) return false;
        for ($size = 2; $size <= 3; ++$size) {
            for ($offset = 0, $limit = count($fieldTerms) - $size; $offset <= $limit; ++$offset) {
                if (str_replace(' ', '', implode(' ', array_slice($fieldTerms, $offset, $size))) === $compactTerm) return true;
            }
        }
        return false;
    }

    private function variantReferenceIdentifies(string $title, array $subject): bool
    {
        $reference = (string) ($subject['reference'] ?? '');
        if ($reference === '' && preg_match('/\b\d+\s*\/\s*\d+\b/u', (string) ($subject['name'] ?? ''), $match) === 1) $reference = $match[0];
        $reference = $this->normalize($reference);
        return $reference !== '' && str_contains($title, $reference);
    }

    /** @return list<string> */
    private function stableKeyIdentityTerms(array $subject): array
    {
        if (($subject['type'] ?? '') !== 'variant') return [];
        $stableKey = trim((string) ($subject['stable_key'] ?? ''));
        if ($stableKey === '') return [];
        $parts = explode(':', $stableKey);
        $term = trim((string) end($parts));
        return $term === '' ? [] : [$term];
    }

    /** @return list<string> */
    private function unsupportedClassifications(string $sourceTitle, string $userHint): array
    {
        $text = $this->normalize($sourceTitle . ' ' . $userHint);
        $result = [];
        if (str_contains($text, 'con dong')) $result[] = 'Côn đồng';
        if (str_contains($text, 'con thep')) $result[] = 'Côn thép';
        return $result;
    }

    private function normalize(string $value): string
    {
        if (class_exists('Normalizer')) $value = (string) \Normalizer::normalize($value, \Normalizer::FORM_KD);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        if (function_exists('remove_accents')) $value = (string) remove_accents($value);
        elseif (function_exists('transliterator_transliterate')) $value = (string) transliterator_transliterate('Any-Latin; Latin-ASCII', $value);
        elseif (function_exists('iconv')) $value = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return trim((string) preg_replace('/[^a-z0-9]+/i', ' ', $value));
    }
}
