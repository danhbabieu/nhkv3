<?php
declare(strict_types=1);

// Staging-only site bootstrap: keep the connector host allowlist outside the
// transport core and permit only the observed OpenAI file hosts.
add_filter('nhk_chatgpt_file_allowed_hosts', static function (mixed $hosts): array {
    $hosts = is_array($hosts) ? $hosts : [];
    if (!function_exists('wp_get_environment_type') || wp_get_environment_type() !== 'staging') return $hosts;
    $hosts[] = 'oaisdmntpraustraliaeast.blob.core.windows.net';
    $hosts[] = 'sdmntpraustraliaeast.oaiusercontent.com';
    $hosts[] = 'oaisdmntprcentralus.blob.core.windows.net';
    $hosts[] = 'sdmntprcentralus.oaiusercontent.com';
    $hosts[] = 'sdmntprjapaneast.oaiusercontent.com';
    $hosts[] = 'oaisdmntprnznorth.blob.core.windows.net';
    return array_values(array_unique($hosts));
}, 10, 1);
