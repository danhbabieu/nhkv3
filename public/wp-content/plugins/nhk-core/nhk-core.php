<?php
/**
 * Plugin Name: NHK Core (v3)
 * Description: Clean Architecture foundation for the new NHK project.
 * Version: 0.1.0
 * Requires PHP: 8.1
 * Requires at least: 6.8
 * Text Domain: nhk-core
 */
declare(strict_types=1);

namespace NHK\Core;

use NHK\Core\Infrastructure\Dictionary\DictionaryBootstrap;
use NHK\Core\Infrastructure\Frontend\FrontendSemanticBootstrap;
use NHK\Core\Infrastructure\PublicIdentity\WordPressPublicSlugBridge;

if (! defined('ABSPATH')) { exit; }
define('NHK_CORE_VERSION', '0.1.0');
define('NHK_CORE_API_VERSION', 'v1');

$autoload = __DIR__ . '/vendor/autoload.php';
if (! is_readable($autoload)) { $autoload = __DIR__ . '/../../../../vendor/autoload.php'; }
if (is_readable($autoload)) { require_once $autoload; }
else {
    spl_autoload_register(static function (string $class): void {
        $prefix = __NAMESPACE__ . '\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) { return; }
        $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_readable($path)) { require_once $path; }
    });
}
Plugin::boot(__FILE__);
(new WordPressPublicSlugBridge())->register();
DictionaryBootstrap::boot();
FrontendSemanticBootstrap::boot();
register_activation_hook(__FILE__, [Plugin::class, 'activate']);
register_activation_hook(__FILE__, [DictionaryBootstrap::class, 'activate']);
register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);
