<?php
/**
 * V3 runtime configuration shared by the server wp-config.php bootstrap.
 *
 * Secrets and deployment-specific values must be supplied by the process
 * environment before this file is required. This file intentionally contains
 * no credentials or generated WordPress salts.
 */
declare(strict_types=1);

/** @return non-empty-string */
function nhk_v3_required_environment(string $name): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        throw new RuntimeException("Required environment variable {$name} is missing.");
    }

    return $value;
}

function nhk_v3_define_from_environment(string $constant, string $environment): void
{
    if (!defined($constant)) {
        define($constant, nhk_v3_required_environment($environment));
    }
}

nhk_v3_define_from_environment('DB_NAME', 'DB_NAME');
nhk_v3_define_from_environment('DB_USER', 'DB_USER');
nhk_v3_define_from_environment('DB_PASSWORD', 'DB_PASSWORD');
nhk_v3_define_from_environment('DB_HOST', 'DB_HOST');
nhk_v3_define_from_environment('WP_ENVIRONMENT_TYPE', 'WP_ENVIRONMENT_TYPE');
nhk_v3_define_from_environment('WP_HOME', 'WP_HOME');
nhk_v3_define_from_environment('WP_SITEURL', 'WP_SITEURL');

foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $secret) {
    nhk_v3_define_from_environment($secret, $secret);
}

$prefix = getenv('DB_PREFIX');
if ($prefix === false || $prefix === '') {
    $prefix = 'wp_';
}
if (!preg_match('/^[A-Za-z0-9_]+$/', $prefix)) {
    throw new RuntimeException('DB_PREFIX must contain only letters, numbers, and underscores.');
}
$table_prefix = $prefix;

if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');
}
if (!defined('DB_COLLATE')) {
    define('DB_COLLATE', getenv('DB_COLLATE') ?: '');
}
if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', getenv('WP_DEBUG') === '1');
}

