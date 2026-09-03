# V3 runtime configuration

`application.php` is required by the server `public/wp-config.php` before
WordPress loads. It is tracked in Git and contains no secrets. The server
bootstrap must provide these environment variables first:

`DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_HOST`, `DB_PREFIX`,
`WP_ENVIRONMENT_TYPE`, `WP_HOME`, `WP_SITEURL`, `AUTH_KEY`,
`SECURE_AUTH_KEY`, `LOGGED_IN_KEY`, `NONCE_KEY`, `AUTH_SALT`,
`SECURE_AUTH_SALT`, `LOGGED_IN_SALT`, and `NONCE_SALT`.

`DB_CHARSET` and `DB_COLLATE` are optional and default to `utf8mb4` and an
empty value. `WP_DEBUG` is optional and is enabled only when set to `1`.
Missing required values fail closed before WordPress starts.

## YouTube Data API key

The Video source adapter reads `NHK_YOUTUBE_API_KEY` deterministically:

1. A non-empty PHP constant named `NHK_YOUTUBE_API_KEY`.
2. The `NHK_YOUTUBE_API_KEY` process environment variable.
3. No key (`YOUTUBE_API_NOT_CONFIGURED`); the adapter makes no outbound
   YouTube request and Video completeness remains blocked.

The key is never stored in Git, WordPress options, Post/meta, semantic data,
MCP/REST payloads, diagnostics or logs. The read-only `/nhk/v1/health` response
reports only `youtube_api.configured` and `youtube_api.source`.

If the hosting provider does not pass environment variables to PHP-FPM, use
one of these production-safe configurations outside tracked source:

### A. Untracked local `wp-config.php`

Add this before `wp-settings.php` is loaded:

```php
define('NHK_YOUTUBE_API_KEY', 'YOUR_KEY_HERE');
```

Keep the file outside the deployed Git checkout or in the hosting provider's
untracked configuration layer. Do not replace the placeholder in this
repository.

### B. PHP-FPM pool environment

Add the following to the active PHP-FPM pool configuration, using the real key
only in the server configuration:

```ini
env[NHK_YOUTUBE_API_KEY] = YOUR_KEY_HERE
```

After either change, reload PHP-FPM and clear/reload OPcache if it is enabled.
The exact pool file and service name are hosting-specific; verify the active
pool before editing. This repository does not contain server pool
configuration.

For staging, the expected non-secret values are:

```text
DB_NAME=erourxcg_nhkv3
DB_USER=erourxcg_nhakho_user
DB_HOST=localhost
DB_PREFIX=wp_
WP_ENVIRONMENT_TYPE=staging
WP_HOME=https://demo.1945.vn
WP_SITEURL=https://demo.1945.vn
```
