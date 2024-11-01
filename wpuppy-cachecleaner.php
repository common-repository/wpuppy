<?php
namespace CacheCleaner;

/**
 * LIST OF KNOWN AND SUPPORTED CACHE PLUGINS
 * WP Super Cache (2+ million downloads)
 * W3 Total Cache (>1+ million downloads)
 * WP Fastest Cache (500.000+ downloads)
 *
 * KNOWN, BUT NOT (YET) SUPPORTED CACHE PLUGINS
 * WP Rocket (390,297 downloads, paid plugin)
 * LiteSpeed Cache (200.000+ downloads)
 * Comet Cache (60.000+ downloads)
 * Cache Enabler - Word Press Cache (40.000+ downloads)
 * Hyper Cache (40.000+ downloads)
 * Hummingbird (10.000+ downloads)
 * Simple Cache (7.000+ downloads)
 * Super Static Cache (2.000+ downloads)
 */

abstract class CacheCleaner
{
    public static $cachePlugins = array(
        "wp-super-cache" => "WPSuperCache",
        "w3-total-cache" => "W3TotalCache",
        "wp-fastest-cache" => "WPFastestCache",

        // unsupported at the moment
        "wp-rocket" => "WPRocket",
        "litespeed-cache" => "LiteSpeedCache",
        "comet-cache" => "CometCache",
        "cache-enabler" => "CacheEnabler",
        "hyper-cache" => "HyperCache",
        "wp-hummingbird" => "HummingbirdPro",
        "simple-cache" => "SimpleCache",
        "super-static-cache" => "SuperStaticCache"
    );

    public static function isValid($slug)
    {
        return array_key_exists($slug, static::$cachePlugins);
    }
}

abstract class CacheCleanerFactory
{
    public static function create($slug)
    {
        $method = __NAMESPACE__ . "\\CacheCleaner" . CacheCleaner::$cachePlugins[$slug];
        if (!CacheCleaner::isValid($slug)) {
            return false;
        }

        if (!class_exists($method)) {
            throw new \Exception($slug . " is not a supported cache plugin.");
        }

        return new $method;
    }
}

class CacheCleanerWPSuperCache extends CacheCleanerFactory
{
    public function clean()
    {
        if (!function_exists("wp_cache_clean_cache")) {
            throw new \Exception("Failed to clean WP Super Cache's cache.");
        }

        global $file_prefix;
        wp_cache_clean_cache($file_prefix, true); // boolean arg means clear entire cache

        return "Succesfully cleaned WP Super Cache's cache.";
    }
}

class CacheCleanerW3TotalCache extends CacheCleanerFactory
{
    public function clean()
    {
        if (!function_exists("w3tc_flush_all")) {
            throw new \Exception("Failed to clean W3 Total Cache's cache.");
        }

        w3tc_flush_all();

        return "Succesfully cleaned W3 Total Cache's cache.";
    }
}

class CacheCleanerWPFastestCache extends CacheCleanerFactory
{
    public function clean()
    {
        if (!class_exists("WpFastestCache")) {
            throw new \Exception("Failed to clean WP Fastest Cache's cache.");
        }

        $wpfc = new WpFastestCache();
        $wpfc->deleteCache(true); // this arg deletes minified css/js too

        return "Succesfully cleaned WP Fastest Cache's cache.";
    }
}
