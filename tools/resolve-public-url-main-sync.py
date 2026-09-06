from pathlib import Path

# This script is only used after `git merge origin/main` has produced the known
# URL-policy overlap. The workflow first checks out main's versions of the five
# production conflicts; this script then restores only the feature seams that
# current main does not yet contain.

p = Path('public/wp-content/plugins/nhk-core/src/Application/PublicIdentity/CanonicalPublicSlugPolicy.php')
s = p.read_text()
marker = '    /**\n     * Build shortest-first public slug candidates from meaningful domain data.\n'
insert = '''    /** Compatibility instance API for application services. */
    public function slug(string $value): string
    {
        return self::normalize($value);
    }

    /**
     * Resolve the shortest available meaningful public slug. Technical IDs,
     * hashes and timestamps are never invented by this policy.
     *
     * @param list<string> $meaningfulQualifiers
     * @param callable(string):bool $isTaken
     */
    public function resolve(string $value, array $meaningfulQualifiers, callable $isTaken): string
    {
        $base = self::normalize($value);
        if ($base === '') throw new \\InvalidArgumentException('PUBLIC_SLUG_INVALID');
        if (!$isTaken($base)) return $base;
        foreach (self::candidates($value, $meaningfulQualifiers) as $candidate) {
            if ($candidate === $base) continue;
            if (!$isTaken($candidate)) return $candidate;
        }
        throw new \\RuntimeException('PUBLIC_SLUG_COLLISION_REQUIRES_RECONCILIATION');
    }

'''
if 'public function resolve(' not in s:
    if marker not in s:
        raise SystemExit('CanonicalPublicSlugPolicy marker missing')
    s = s.replace(marker, insert + marker)
p.write_text(s)

p = Path('public/wp-content/plugins/nhk-core/src/Application/PublicIdentity/PublicIdentityService.php')
s = p.read_text()
alloc_marker = '    public function changeSlug(string $identityId, string $slug, int $expectedRevision, string $idempotencyKey): array\n'
alloc = '''    /** @param list<string> $meaningfulQualifiers */
    public function allocateCanonical(string $ownerKind, string $ownerId, string $routeType, string $scope, string $publicName, array $meaningfulQualifiers, string $idempotencyKey): array
    {
        if ($ownerKind === '' || !UuidCodec::isValid($ownerId) || $routeType === '' || $scope === '' || $idempotencyKey === '') throw new \\InvalidArgumentException('PUBLIC_IDENTITY_INPUT_INVALID');
        $policy = new CanonicalPublicSlugPolicy();
        $slug = $policy->resolve($publicName, $meaningfulQualifiers, function (string $candidate) use ($routeType, $scope): bool {
            if (($this->nativeRouteExists)($candidate)) return true;
            return method_exists($this->repository, 'slugExists') && (bool) $this->repository->slugExists($routeType, $scope, $candidate, null);
        });
        return $this->repository->allocate($this->record($ownerKind, $ownerId, $routeType, $scope, $slug), $idempotencyKey);
    }

'''
if 'allocateCanonical(' not in s:
    if alloc_marker not in s:
        raise SystemExit('PublicIdentityService allocate marker missing')
    s = s.replace(alloc_marker, alloc + alloc_marker)
change_end = "        return $this->repository->change($record, (string) $current['current_path'], $expectedRevision, $idempotencyKey);\n    }\n\n    private function record"
reproject = """        return $this->repository->change($record, (string) $current['current_path'], $expectedRevision, $idempotencyKey);
    }

    /** Pre-public URL projection maintenance; semantic identity is unchanged. */
    public function reproject(string $identityId, string $publicName, string $scope, int $expectedRevision, string $idempotencyKey): array
    {
        if (trim($scope) === '' || $identityId === '' || $expectedRevision < 1 || $idempotencyKey === '') throw new \\InvalidArgumentException('PUBLIC_IDENTITY_INPUT_INVALID');
        $current = $this->repository->findCurrentById($identityId);
        if ($current === null) throw new \\RuntimeException('NOT_FOUND');
        if ((int) ($current['revision'] ?? 0) !== $expectedRevision) throw new \\RuntimeException('STALE_REVISION');
        $slug = CanonicalPublicSlugPolicy::normalize($publicName);
        if ($slug === '') throw new \\InvalidArgumentException('PUBLIC_SLUG_INVALID');
        if (($this->nativeRouteExists)($slug)) throw new \\RuntimeException('NATIVE_ROUTE_CONFLICT');
        $record = $current;
        $record['current_slug'] = $slug;
        $record['collision_scope'] = trim($scope);
        $record['route_policy_version'] = '2';
        return $this->repository->change($record, (string) $current['current_path'], $expectedRevision, $idempotencyKey);
    }

    private function record"""
if 'public function reproject(' not in s:
    if change_end not in s:
        raise SystemExit('PublicIdentityService reproject marker missing')
    s = s.replace(change_end, reproject)
p.write_text(s)

p = Path('public/wp-content/plugins/nhk-core/src/Application/Entity/PublicRouteResolver.php')
s = p.read_text()
s = s.replace('use NHK\\Core\\Application\\PublicIdentity\\CanonicalPublicSlugPolicy;', 'use NHK\\Core\\Application\\PublicIdentity\\{CanonicalPublicSlugPolicy, PublicIdentityReadRegistry};\nuse NHK\\Core\\Contracts\\PublicIdentity\\PublicIdentityRepository;')
s = s.replace('private ?\\Closure $nativeRootExists = null) {}', 'private ?\\Closure $nativeRootExists = null, private ?PublicIdentityRepository $publicIdentities = null) {}')
needle = '''    private function publicSlug(AuthorityEntity $entity, ?string $parentId = null): ?string
    {
        foreach ($this->candidateSlugs($entity) as $candidate) {'''
replacement = '''    private function publicSlug(AuthorityEntity $entity, ?string $parentId = null): ?string
    {
        $repository = $this->publicIdentities ?? PublicIdentityReadRegistry::repository();
        if ($repository !== null) {
            try {
                $identity = $repository->findCurrentByOwner('authority', $entity->canonicalId, $entity->entityType);
                if (is_array($identity)) {
                    $stored = trim((string) ($identity['current_slug'] ?? ''));
                    return $stored !== '' && CanonicalPublicSlugPolicy::isCanonical($stored) ? $stored : null;
                }
            } catch (\\Throwable) {
                // Demo compatibility fallback only; semantic identity is never changed.
            }
        }
        foreach ($this->candidateSlugs($entity) as $candidate) {'''
if 'PublicIdentityReadRegistry::repository()' not in s:
    if needle not in s:
        raise SystemExit('PublicRouteResolver marker missing')
    s = s.replace(needle, replacement)
p.write_text(s)

p = Path('public/wp-content/plugins/nhk-core/src/Application/Video/VideoUrlPolicy.php')
s = p.read_text()
s = s.replace('use NHK\\Core\\Domain\\Video\\Video;', 'use NHK\\Core\\Application\\PublicIdentity\\{CanonicalPublicSlugPolicy, PublicIdentityReadRegistry};\nuse NHK\\Core\\Contracts\\PublicIdentity\\PublicIdentityRepository;\nuse NHK\\Core\\Domain\\Video\\Video;')
s = s.replace('final class VideoUrlPolicy\n{', 'final class VideoUrlPolicy\n{\n    public function __construct(private ?PublicIdentityRepository $publicIdentities = null) {}')
s = s.replace("        $identity = is_array($metadata['public_identity'] ?? null) ? $metadata['public_identity'] : [];\n        $blockers = [];\n        $slug = trim((string) ($identity['current_slug'] ?? ''));\n        if ($slug === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) $blockers[] = 'PUBLIC_IDENTITY_NOT_PERSISTED';", "        $blockers = [];\n        $slug = $this->publicSlug($video, $metadata, $blockers);\n        if ($slug === '' || !CanonicalPublicSlugPolicy::isCanonical($slug)) $blockers[] = 'PUBLIC_IDENTITY_NOT_PERSISTED';")
ctx_marker = '    /** @return array<string,mixed> */\n    private function context(array $metadata): array\n'
helper = '''    /** @param list<string> $blockers */
    private function publicSlug(Video $video, array $metadata, array &$blockers): string
    {
        $repository = $this->publicIdentities ?? PublicIdentityReadRegistry::repository();
        if ($repository !== null) {
            try {
                $identity = $repository->findCurrentByOwner('video', $video->canonicalId, 'video');
            } catch (\\Throwable) {
                $blockers[] = 'PUBLIC_IDENTITY_STORAGE_UNAVAILABLE';
                return '';
            }
            if (is_array($identity)) return trim((string) ($identity['current_slug'] ?? ''));
        }
        $identity = is_array($metadata['public_identity'] ?? null) ? $metadata['public_identity'] : [];
        return trim((string) ($identity['current_slug'] ?? ''));
    }

'''
if 'private function publicSlug(Video' not in s:
    if ctx_marker not in s:
        raise SystemExit('VideoUrlPolicy marker missing')
    s = s.replace(ctx_marker, helper + ctx_marker)
p.write_text(s)

p = Path('public/wp-content/plugins/nhk-core/nhk-core.php')
s = p.read_text()
s = s.replace('namespace NHK\\Core;\n\n', 'namespace NHK\\Core;\n\nuse NHK\\Core\\Application\\PublicIdentity\\PublicIdentityReadRegistry;\n')
s = s.replace('use NHK\\Core\\Infrastructure\\Frontend\\FrontendSemanticBootstrap;', 'use NHK\\Core\\Infrastructure\\Frontend\\FrontendSemanticBootstrap;\nuse NHK\\Core\\Infrastructure\\PublicIdentity\\{WordPressPublicSlugBridge, WpdbPublicIdentityRepository};')
if 'PublicIdentityReadRegistry::register' not in s:
    s = s.replace('Plugin::boot(__FILE__);', "global $wpdb;\nif (is_object($wpdb)) PublicIdentityReadRegistry::register(new WpdbPublicIdentityRepository($wpdb));\nPlugin::boot(__FILE__);\n(new WordPressPublicSlugBridge())->register();")
p.write_text(s)
