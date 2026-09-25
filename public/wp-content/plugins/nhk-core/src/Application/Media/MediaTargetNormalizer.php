<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Graph\EndpointRevisionReader;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Domain\Graph\NodeReference;
use NHK\Core\Domain\Media\MediaException;
use NHK\Core\Graph\Exception\InvalidEndpointReference;
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Converts every supported Media target locator into one verified endpoint
 * identity before it reaches fingerprints, Governance or persistence.
 */
final class MediaTargetNormalizer
{
    public function __construct(
        private MediaTargetRegistry|\NHK\Core\Domain\Graph\EndpointTypeRegistry $registry,
        private EntityTypeRegistry $entityTypes,
        private AuthorityRepository $authority,
    ) {
        if ($registry instanceof \NHK\Core\Domain\Graph\EndpointTypeRegistry) {
            $this->registry = new MediaTargetRegistry($registry);
        }
    }

    /** @param array<string,mixed> $target */
    public function normalize(array $target): MediaTargetReference
    {
        $type = strtolower(trim((string) ($target['type'] ?? '')));
        if ($type === '' || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $type) !== 1 || !$this->registry->has($type)) {
            throw new MediaException('MEDIA_TARGET_TYPE_INVALID');
        }

        $key = $this->keyFromTarget($type, $target);
        try {
            $reference = $this->registry->resolver($type)->normalize(new NodeReference($type, $key));
        } catch (InvalidEndpointReference|\InvalidArgumentException $error) {
            throw new MediaException('MEDIA_TARGET_REFERENCE_MALFORMED', 0, $error);
        }

        $resolver = $this->registry->resolver($type);
        if (!$resolver->exists($reference)) throw new MediaException('MEDIA_TARGET_NOT_FOUND');
        $state = method_exists($resolver, 'state') ? (array) $resolver->state($reference) : ['active' => true];
        if (($state['active'] ?? true) !== true) throw new MediaException('MEDIA_TARGET_NOT_FOUND');

        $canonicalUuid = null;
        $stableKey = null;
        if ($this->entityTypes->has($type)) {
            $entity = $this->authority->findByCanonicalId($key);
            if ($entity === null || $entity->entityType !== $type || !$entity->active()) {
                throw new MediaException('MEDIA_TARGET_NOT_FOUND');
            }
            $canonicalUuid = $entity->canonicalId;
            $stableKey = $entity->stableKey;
        }

        $revision = (int) ($state['revision'] ?? 0);
        if ($revision < 1 && $resolver instanceof EndpointRevisionReader) $revision = (int) ($resolver->revision($reference) ?? 0);
        if ($revision < 1) $revision = 1;

        return new MediaTargetReference($type, $reference->endpoint_key, $canonicalUuid, $stableKey, $revision);
    }

    /** @param array<string,mixed> $request */
    public function normalizeRequestTarget(array $request): array
    {
        $reference = $this->normalize($request);
        $result = ['type' => $reference->endpointType, 'id' => $reference->endpointKey];
        if ($reference->canonicalUuid !== null) $result['canonical_uuid'] = $reference->canonicalUuid;
        if ($reference->stableKey !== null) $result['stable_key'] = $reference->stableKey;
        $result['revision'] = $reference->revision;
        return $result;
    }

    /** @param array<string,mixed> $target */
    private function keyFromTarget(string $type, array $target): string
    {
        $id = trim((string) ($target['id'] ?? ''));
        $blogId = filter_var($target['blog_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $postId = filter_var($target['post_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($type === 'wp_post' && ($blogId !== false && $blogId !== null || $postId !== false && $postId !== null)) {
            if ($blogId === false || $postId === false || $blogId === null || $postId === null) {
                throw new MediaException('MEDIA_TARGET_REFERENCE_MALFORMED');
            }
            $fieldsKey = $blogId . ':' . $postId;
            if ($id !== '' && $id !== $fieldsKey) throw new MediaException('MEDIA_TARGET_REFERENCE_MALFORMED');
            return $fieldsKey;
        }
        if ($id === '') {
            $stableKey = trim((string) ($target['stable_key'] ?? ''));
            if ($stableKey === '' || !$this->entityTypes->has($type)) throw new MediaException('MEDIA_TARGET_REFERENCE_MALFORMED');
            $entity = $this->authority->findByStableKey($type, $stableKey);
            if ($entity === null) throw new MediaException('MEDIA_TARGET_NOT_FOUND');
            $id = $entity->canonicalId;
        }
        if ($this->entityTypes->has($type) && !UuidCodec::isValid($id)) throw new MediaException('MEDIA_TARGET_REFERENCE_MALFORMED');
        return $id;
    }
}
