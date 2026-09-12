<?php
declare(strict_types=1);

/**
 * Non-secret bootstrap for the isolated local recovery runtime.
 *
 * The tracked WordPress config selects the database from NHK_WP_TEST_DB. This
 * prepend supplies only the recovery identity before that config is loaded;
 * it contains no credentials and exposes no semantic writer.
 */
if (!defined('NHK_RUNTIME_ENVIRONMENT')) define('NHK_RUNTIME_ENVIRONMENT', 'v3-video-recovery-1309');
if (!defined('NHK_RUNTIME_MODE')) define('NHK_RUNTIME_MODE', 'recovery');
if (!defined('NHK_MIGRATION_RUNTIME')) define('NHK_MIGRATION_RUNTIME', 'recovery');
if (!defined('NHK_AUTHORIZED_MIGRATION_DATABASE')) define('NHK_AUTHORIZED_MIGRATION_DATABASE', 'nhk_v3_video_recovery');
if (!defined('NHK_RECOVERY_ALLOWED_DATABASES')) define('NHK_RECOVERY_ALLOWED_DATABASES', 'nhk_v3_video_recovery');
if (!defined('WP_HOME')) define('WP_HOME', 'http://127.0.0.1:8090');
if (!defined('WP_SITEURL')) define('WP_SITEURL', 'http://127.0.0.1:8090');
putenv('NHK_RECOVERY_ALLOWED_DATABASES=nhk_v3_video_recovery');
