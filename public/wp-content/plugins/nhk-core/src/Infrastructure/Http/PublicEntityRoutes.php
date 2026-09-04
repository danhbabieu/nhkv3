<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

use NHK\Core\Application\Entity\EntityPageQuery;
use NHK\Core\Application\Entity\PublicRouteResolver;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Application\PublicIdentity\HistoricPublicRouteService;

final class PublicEntityRoutes
{
    private const ROOT_ENTITY = 'entity';
    private const ROOT_NATIVE = 'native_wordpress';
    private const ROOT_NOT_FOUND = 'not_found';
    private const ROOT_IDENTITY_CONFLICT = 'IDENTITY_CONFLICT';

    /** @var array<string,string> */
    private const CANONICAL_ARCHIVES = [
        'brand' => 'thuong-hieu', 'model' => 'mau', 'movement' => 'bo-may', 'music' => 'ban-nhac',
        'component' => 'linh-kien', 'classification' => 'phan-loai', 'specimen' => 'hien-vat', 'product' => 'san-pham',
    ];

    public function __construct(private EntityPageQuery $query, private EntityTypeRegistry $types, private ?HistoricPublicRouteService $historic = null) {}

    public function register(): void
    {
        add_filter('query_vars', function (array $vars): array { foreach (['nhk_entity_type', 'nhk_entity_key', 'nhk_entity_page', 'nhk_entity_q', 'nhk_entity_alias', 'nhk_legacy_archive', 'nhk_public_entity_type', 'nhk_public_entity_a', 'nhk_public_entity_b', 'nhk_public_entity_c'] as $name) if (!in_array($name, $vars, true)) $vars[] = $name; return $vars; });
        add_filter('request', [$this, 'preserveNativeRootRoute'], 20);
        add_action('init', [$this, 'rewrite']);
        add_action('template_redirect', [$this, 'legacyArchiveRedirect'], 1);
        add_action('template_redirect', [$this, 'legacyIdentityRedirect'], 1);
        add_action('template_redirect', [$this, 'legacyDetailRedirect'], 1);
        add_action('template_redirect', [$this, 'historicRouteRedirect'], 1);
        add_filter('template_include', [$this, 'template']);
    }

    public function historicRouteRedirect(): void
    {
        if ($this->historic === null || is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || PHP_SAPI === 'cli' || !is_404()) return;
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if (!is_string($path) || str_starts_with($path, '/video/')) return;
        $result = $this->historic->resolveHistoric($path);
        if (($result['status'] ?? '') !== 'FOUND' || (string) ($result['target'] ?? '') === $path) return;
        wp_safe_redirect(home_url((string) $result['target']), 301, 'NHK historic public route'); exit;
    }

    public function rewrite(): void
    {
        $reserved = implode('|', array_map(static fn (string $root): string => preg_quote($root, '#'), PublicRouteResolver::reservedRoots()));
        add_rewrite_rule('^(?!' . $reserved . ')([a-z0-9-]+)/([a-z0-9-]+)/([a-z0-9-]+)/?$', 'index.php?nhk_public_entity_type=variant&nhk_public_entity_a=$matches[1]&nhk_public_entity_b=$matches[2]&nhk_public_entity_c=$matches[3]', 'top');
        add_rewrite_rule('^(?!' . $reserved . ')([a-z0-9-]+)/([a-z0-9-]+)/?$', 'index.php?nhk_public_entity_type=model&nhk_public_entity_a=$matches[1]&nhk_public_entity_b=$matches[2]', 'top');
        // Keep the native slug query attached to the root request. WP_Query
        // can therefore resolve a Post before NHK decides whether a Brand
        // route is actually claimable.
        add_rewrite_rule('^(?!' . $reserved . ')([a-z0-9-]+)/?$', 'index.php?name=$matches[1]&nhk_public_entity_type=brand&nhk_public_entity_a=$matches[1]', 'top');
        foreach (self::CANONICAL_ARCHIVES as $type => $namespace) {
            add_rewrite_rule('^' . preg_quote($namespace, '#') . '/page/([1-9][0-9]*)/?$', 'index.php?nhk_entity_type=' . $type . '&nhk_entity_alias=' . $namespace . '&nhk_entity_page=$matches[1]', 'top');
            add_rewrite_rule('^' . preg_quote($namespace, '#') . '/?$', 'index.php?nhk_entity_type=' . $type . '&nhk_entity_alias=' . $namespace, 'top');
        }
        foreach ($this->types->all() as $definition) {
            $namespace = PublicRouteResolver::namespaceFor($definition->type);
            if ($namespace === null) continue;
            add_rewrite_rule('^' . preg_quote($namespace, '#') . '/([a-z0-9-]+)/?$', 'index.php?nhk_public_entity_type=' . $definition->type . '&nhk_public_entity_a=' . $namespace . '&nhk_public_entity_b=$matches[1]', 'top');
        }
        foreach (self::CANONICAL_ARCHIVES as $type => $namespace) {
            add_rewrite_rule('^' . preg_quote($type, '#') . '/page/([1-9][0-9]*)/?$', 'index.php?nhk_legacy_archive=' . $type . '&nhk_entity_page=$matches[1]', 'top');
            add_rewrite_rule('^' . preg_quote($type, '#') . '/?$', 'index.php?nhk_legacy_archive=' . $type, 'top');
        }
        foreach ($this->types->all() as $definition) {
            $type = preg_quote($definition->type, '#');
            add_rewrite_rule('^' . $type . '/([^/]+)/?$', 'index.php?nhk_entity_type=' . $definition->type . '&nhk_entity_key=$matches[1]', 'top');
        }
    }

    public function template(string $template): string
    {
        $publicType = (string) get_query_var('nhk_public_entity_type');
        if ($publicType !== '') {
            $segments = array_values(array_filter([(string) get_query_var('nhk_public_entity_a'), (string) get_query_var('nhk_public_entity_b'), (string) get_query_var('nhk_public_entity_c')], static fn (string $value): bool => $value !== ''));
            $entity = $this->query->resolvePublic($publicType, $segments);
            $native = $this->nativeRootObject();
            $decision = self::classifyRootRoute($entity !== null, $native !== null);
            if ($decision === self::ROOT_NATIVE || $decision === self::ROOT_NOT_FOUND) return $template;
            if ($decision === self::ROOT_IDENTITY_CONFLICT) {
                $this->setRouteConflict($publicType, (string) get_query_var('nhk_public_entity_a'), $native);
                return get_404_template();
            }
            $canonicalPath = $this->query->publicPath($entity);
            $requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
            $redirectTarget = self::canonicalRedirectTarget(is_string($requestPath) ? $requestPath : '/', $canonicalPath);
            if ($redirectTarget !== null && !headers_sent()) {
                wp_safe_redirect(home_url($redirectTarget), 301, 'NHK canonical public route');
                exit;
            }
            $this->set200();
            $GLOBALS['nhk_core_entity_context'] = ['mode' => 'detail', 'type' => $publicType, 'entity' => $this->query->detailForEntity($entity), 'archive_url' => $this->query->publicPath($entity)];
            $themeTemplate = locate_template('entity.php');
            return $themeTemplate !== '' ? $themeTemplate : $template;
        }
        $type = (string) get_query_var('nhk_entity_type');
        if ($type === '' || !$this->types->has($type)) return $template;
        $key = (string) get_query_var('nhk_entity_key');
        if ($key !== '') {
            $target = $this->query->publicPathForKey($type, rawurldecode($key));
            if ($target === null) {
                $stableKey = $this->query->stableKeyForPublicSlug($type, rawurldecode($key));
                $target = $stableKey === null ? null : $this->query->publicPathForKey($type, $stableKey);
            }
            if ($target === null) { $this->set404(); return get_404_template(); }
            wp_safe_redirect(home_url($target), 301, 'NHK canonical public route');
            exit;
        } else {
            $page = max(1, (int) get_query_var('nhk_entity_page', 1)); $query = trim((string) get_query_var('nhk_entity_q'));
            $GLOBALS['nhk_core_entity_context'] = ['mode' => 'archive', 'type' => $type, 'archive' => $this->query->archive($type, $page, 24, $query), 'archive_url' => home_url($this->query->archivePath($type) ?? '/')];
        }
        $themeTemplate = locate_template('entity.php');
        return $themeTemplate !== '' ? $themeTemplate : $template;
    }

    /** @return 'entity'|'native_wordpress'|'not_found'|'IDENTITY_CONFLICT' */
    public static function classifyRootRoute(bool $entityResolved, bool $nativeResolved): string
    {
        if ($entityResolved && $nativeResolved) return self::ROOT_IDENTITY_CONFLICT;
        if ($entityResolved) return self::ROOT_ENTITY;
        if ($nativeResolved) return self::ROOT_NATIVE;
        return self::ROOT_NOT_FOUND;
    }

    public static function canonicalRedirectTarget(string $requestPath, ?string $canonicalPath): ?string
    {
        if ($canonicalPath === null || $canonicalPath === '') return null;
        $normalize = static fn (string $path): string => rtrim('/' . trim($path, '/'), '/');
        return $normalize($requestPath) === $normalize($canonicalPath) ? null : $canonicalPath;
    }

    /** @return object|null */
    private function nativeRootObject(): ?object
    {
        global $wp_query;
        if (!isset($wp_query) || !is_object($wp_query) || !method_exists($wp_query, 'is_singular') || !$wp_query->is_singular() || !method_exists($wp_query, 'get_queried_object')) return null;
        $object = $wp_query->get_queried_object();
        return is_object($object) && isset($object->ID, $object->post_type) ? $object : null;
    }

    private function set200(): void
    {
        global $wp_query;
        if (isset($wp_query) && is_object($wp_query)) $wp_query->is_404 = false;
        status_header(200);
    }

    private function set404(): void { global $wp_query; if (isset($wp_query) && is_object($wp_query)) $wp_query->set_404(); status_header(404); nocache_headers(); }

    private function setRouteConflict(string $entityType, string $slug, ?object $native): void
    {
        $GLOBALS['nhk_core_route_diagnostic'] = [
            'code' => self::ROOT_IDENTITY_CONFLICT,
            'entity_type' => $entityType,
            'slug' => $slug,
            'native_post_id' => isset($native->ID) ? (int) $native->ID : null,
            'native_post_type' => isset($native->post_type) ? (string) $native->post_type : null,
        ];
        $this->set404();
    }

    /**
     * The root entity rewrite is deliberately broad so a valid Brand can
     * claim its canonical route. Preserve the native Page query where WP's
     * page resolver needs pagename rather than name.
     *
     * @param array<string,mixed> $queryVars
     * @return array<string,mixed>
     */
    public function preserveNativeRootRoute(array $queryVars): array
    {
        if (($queryVars['nhk_public_entity_type'] ?? '') !== 'brand') return $queryVars;
        $slug = is_scalar($queryVars['nhk_public_entity_a'] ?? null) ? (string) $queryVars['nhk_public_entity_a'] : '';
        if ($slug === '' || !function_exists('get_page_by_path')) return $queryVars;
        $page = get_page_by_path($slug);
        if ($page instanceof \WP_Post && (string) ($page->post_type ?? '') === 'page') {
            unset($queryVars['name']);
            $queryVars['pagename'] = $slug;
        }
        return $queryVars;
    }

    public function legacyArchiveRedirect(): void
    {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || PHP_SAPI === 'cli') return;
        $type = (string) get_query_var('nhk_legacy_archive');
        $namespace = self::CANONICAL_ARCHIVES[$type] ?? null;
        if ($namespace === null) return;
        $page = max(1, (int) get_query_var('nhk_entity_page', 1));
        $target = '/' . $namespace . '/' . ($page > 1 ? 'page/' . $page . '/' : '');
        wp_safe_redirect(home_url($target), 301, 'NHK canonical public archive');
        exit;
    }

    public function legacyIdentityRedirect(): void
    {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || PHP_SAPI === 'cli') return;
        $type = (string) get_query_var('nhk_entity_type'); $key = (string) get_query_var('nhk_entity_key');
        if ($type === '' || $key === '') return;
        $target = $this->query->publicPathForKey($type, rawurldecode($key));
        if ($target === null) {
            $stableKey = $this->query->stableKeyForPublicSlug($type, rawurldecode($key));
            $target = $stableKey === null ? null : $this->query->publicPathForKey($type, $stableKey);
        }
        if ($target === null || $target === '') return;
        $current = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if (is_string($current) && rtrim('/' . trim($current, '/'), '/') === rtrim($target, '/')) return;
        wp_safe_redirect(home_url($target), 301, 'NHK canonical public route');
        exit;
    }

    public function legacyDetailRedirect(): void
    {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || PHP_SAPI === 'cli' || (string) get_query_var('nhk_entity_type') !== '' || (string) get_query_var('nhk_public_entity_type') !== '' || !is_404()) return;
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if (!is_string($path)) return;
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== ''));
        if (count($segments) === 1) {
            $key = $this->query->stableKeyForPublicSlug('brand', rawurldecode($segments[0]));
            if ($key !== null) $this->redirectCanonical('brand', $key);
        }
        if (count($segments) === 2) {
            $parent = $this->query->stableKeyForPublicSlug('brand', rawurldecode($segments[0]));
            $key = $this->query->stableKeyForPublicSlug('model', rawurldecode($segments[1]));
            if ($parent !== null && $key !== null) $this->redirectCanonical('model', $key);
        }
    }

    private function redirectCanonical(string $type, string $stableKey): void
    {
        $target = $this->query->publicPathForKey($type, $stableKey);
        if ($target === null) return;
        wp_safe_redirect(home_url($target), 301, 'NHK canonical public route');
        exit;
    }
}
