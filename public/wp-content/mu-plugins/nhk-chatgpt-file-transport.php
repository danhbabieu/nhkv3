<?php
declare(strict_types=1);

// Staging-only site bootstrap: keep the connector host allowlist outside the
// transport core and permit exactly the observed OpenAI file host.
add_filter('nhk_chatgpt_file_allowed_hosts', static function (mixed $hosts): array {
    $hosts = is_array($hosts) ? $hosts : [];
    if (!function_exists('wp_get_environment_type') || wp_get_environment_type() !== 'staging') return $hosts;
    $hosts[] = 'sdmntpraustraliaeast.oaiusercontent.com';
    return array_values(array_unique($hosts));
}, 10, 1);
