<?php
/**
 * gkbs-core Singleton-Loader.
 *
 * Coexistence-Pattern: when two plugins both vendor gkbs/core, the loader
 * with the highest version wins. The other plugin's vendored copy detects
 * via the GKBS_CORE_LOADED_VERSION constant and bails out.
 *
 * Each distribution must call Loader::register(__FILE__, self::VERSION)
 * from its plugin bootstrap on the `plugins_loaded` hook with priority 0.
 */

declare(strict_types=1);

namespace GKBS\Core;

final class Loader
{
    public const VERSION = '0.1.0-alpha.0';

    private static ?Plugin $instance = null;

    public static function register(string $pluginFile, string $coreVersionInVendor): bool
    {
        if (defined('GKBS_CORE_LOADED_VERSION')) {
            $loaded = (string) constant('GKBS_CORE_LOADED_VERSION');
            if (version_compare($loaded, $coreVersionInVendor, '>=')) {
                return false;
            }
        }

        if (! defined('GKBS_CORE_LOADED_VERSION')) {
            define('GKBS_CORE_LOADED_VERSION', $coreVersionInVendor);
        }
        if (! defined('GKBS_CORE_LOADED_FROM')) {
            define('GKBS_CORE_LOADED_FROM', $pluginFile);
        }

        return true;
    }

    public static function plugin(): Plugin
    {
        if (self::$instance === null) {
            self::$instance = new Plugin();
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
