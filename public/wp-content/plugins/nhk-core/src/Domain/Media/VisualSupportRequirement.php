<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Media;

use NHK\Core\Domain\Knowledge\KnowledgeFacetProfile;
use NHK\Core\Shared\Uuid\UuidCodec;

final readonly class VisualSupportRequirement
{
    public function __construct(
        public string $canonicalId,
        public string $subjectType,
        public string $subjectId,
        public string $scope,
        public string $facet,
        public string $featureKey,
        public string $visualIntent,
        public string $state = VisualSupportRequirementStateRegistry::MISSING,
        public ?string $mediaId = null,
        public ?int $mediaRevision = null,
        public array $context = [],
        public array $provenance = [],
        public string $unresolvedReason = 'VISUAL_NOT_FOUND',
        public string $semanticFingerprint = '',
        public string $idempotencyFingerprint = '',
        public int $revision = 1,
    ) {
        if (!UuidCodec::isValid($canonicalId) || !UuidCodec::isValid($subjectId)) throw new \InvalidArgumentException('Visual support identity is invalid.');
        if ($subjectType === '' || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $subjectType)) throw new \InvalidArgumentException('Visual support subject type is invalid.');
        if (!in_array($scope, KnowledgeFacetProfile::SCOPES, true) || !in_array($facet, KnowledgeFacetProfile::FACETS, true)) throw new \InvalidArgumentException('Visual support facet or scope is invalid.');
        MediaDetailTypeRegistry::assertKnown($featureKey);
        VisualSupportIntentRegistry::assertKnown($visualIntent);
        VisualSupportRequirementStateRegistry::assertKnown($state);
        if ($mediaId !== null && !UuidCodec::isValid($mediaId)) throw new \InvalidArgumentException('Visual support Media identity is invalid.');
        if ($mediaRevision !== null && $mediaRevision < 1) throw new \InvalidArgumentException('Visual support Media revision is invalid.');
        if ($revision < 1 || $semanticFingerprint === '' || $idempotencyFingerprint === '') throw new \InvalidArgumentException('Visual support revision or fingerprint is invalid.');
        if ($state === VisualSupportRequirementStateRegistry::RESOLVED && ($mediaId === null || $mediaRevision === null)) throw new \InvalidArgumentException('Resolved visual support requires a Media binding.');
    }

    /** @param array<string,mixed> $context */
    public static function create(string $subjectId, string $scope, string $facet, string $featureKey, string $visualIntent, array $context = [], string $subjectType = 'entity'): self
    {
        $semantic = self::fingerprint($subjectType, $subjectId, $scope, $facet, $featureKey, $visualIntent);
        return new self(UuidCodec::newV7(), $subjectType, $subjectId, $scope, $facet, $featureKey, $visualIntent, VisualSupportRequirementStateRegistry::MISSING, null, null, $context, [], 'VISUAL_NOT_FOUND', $semantic, hash('sha256', $semantic . '|' . self::canonicalJson($context)), 1);
    }

    public function withResolution(string $mediaId, int $mediaRevision, array $provenance = []): self
    {
        return new self($this->canonicalId, $this->subjectType, $this->subjectId, $this->scope, $this->facet, $this->featureKey, $this->visualIntent, VisualSupportRequirementStateRegistry::RESOLVED, $mediaId, $mediaRevision, $this->context, $provenance, '', $this->semanticFingerprint, $this->idempotencyFingerprint, $this->revision + 1);
    }

    public function withReview(string $reason, array $provenance = []): self
    {
        return new self($this->canonicalId, $this->subjectType, $this->subjectId, $this->scope, $this->facet, $this->featureKey, $this->visualIntent, VisualSupportRequirementStateRegistry::REVIEW_REQUIRED, $this->mediaId, $this->mediaRevision, $this->context, $provenance, $reason, $this->semanticFingerprint, $this->idempotencyFingerprint, $this->revision + 1);
    }

    public function withMissing(string $reason, array $provenance = []): self
    {
        return new self($this->canonicalId, $this->subjectType, $this->subjectId, $this->scope, $this->facet, $this->featureKey, $this->visualIntent, VisualSupportRequirementStateRegistry::MISSING, null, null, $this->context, $provenance, $reason, $this->semanticFingerprint, $this->idempotencyFingerprint, $this->revision + 1);
    }

    private static function fingerprint(string $subjectType, string $subjectId, string $scope, string $facet, string $featureKey, string $visualIntent): string
    {
        return hash('sha256', self::canonicalJson([$subjectType, $subjectId, $scope, $facet, $featureKey, $visualIntent]));
    }

    private static function canonicalJson(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
