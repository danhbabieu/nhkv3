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
