<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

final class PublicEditorialRoutes
{
    public function register(): void
    {
        add_action('init', [$this, 'rewrite']);
    }

    public function rewrite(): void
    {
        foreach (['tri-thuc', 'goc-chia-se'] as $slug) {
            add_rewrite_rule('^' . $slug . '/page/([1-9][0-9]*)/?$', 'index.php?category_name=' . $slug . '&paged=$matches[1]', 'top');
            add_rewrite_rule('^' . $slug . '/?$', 'index.php?category_name=' . $slug, 'top');
        }
    }
}
