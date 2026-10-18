<?php

namespace Canyon\Core;

/**
 * Canyon Framework - OPcache Manager
 *
 * OPcache monitoring and management.
 * Inspired by QUIC.cloud OPcache support.
 */
class Opcache
{
    /** @var bool Whether OPcache is enabled */
    protected static bool $enabled = false;

    /**
     * Initialize and check OPcache status.
     */
    public static function init(): void
    {
        self::$enabled = extension_loaded('Zend OPcache') && function_exists('opcache_get_status');
    }

    /**
     * Check if OPcache is enabled.
     */
    public static function isEnabled(): bool
    {
        self::init();
        return self::$enabled;
    }

    /**
     * Get OPcache status.
     */
    public static function getStatus(): array
    {
        if (!self::isEnabled()) {
            return ['enabled' => false, 'message' => 'OPcache is not available'];
        }

        $status = opcache_get_status(false);
        if (!$status) {
            return ['enabled' => false, 'message' => 'OPcache status unavailable'];
        }

        $memory = $status['memory_usage'] ?? [];
        $statistics = $status['opcache_statistics'] ?? [];

        return [
            'enabled' => $status['opcache_enabled'] ?? false,
            'full' => $status['cache_full'] ?? false,
            'restart_pending' => $status['restart_pending'] ?? false,
            'restart_in_progress' => $status['restart_in_progress'] ?? false,
            'memory' => [
                'used_mb' => round(($memory['used_memory'] ?? 0) / 1048576, 2),
                'free_mb' => round(($memory['free_memory'] ?? 0) / 1048576, 2),
                'wasted_mb' => round(($memory['wasted_memory'] ?? 0) / 1048576, 2),
                'current_used_mb' => round(($memory['current_used_memory'] ?? 0) / 1048576, 2),
                'current_wasted_mb' => round(($memory['current_wasted_memory'] ?? 0) / 1048576, 2),
            ],
            'statistics' => [
                'num_cached_scripts' => $statistics['num_cached_scripts'] ?? 0,
                'num_cached_keys' => $statistics['num_cached_keys'] ?? 0,
                'max_cached_keys' => $statistics['max_cached_keys'] ?? 0,
                'hits' => $statistics['hits'] ?? 0,
                'misses' => $statistics['misses'] ?? 0,
                'oom_restarts' => $statistics['oom_restarts'] ?? 0,
                'hash_restarts' => $statistics['hash_restarts'] ?? 0,
                'manual_restarts' => $statistics['manual_restarts'] ?? 0,
                'hit_rate' => self::calculateHitRate($statistics),
            ],
            'interned_strings' => $status['interned_strings_usage'] ?? [],
        ];
    }

    /**
     * Calculate OPcache hit rate.
     */
    protected static function calculateHitRate(array $statistics): float
    {
        $hits = $statistics['hits'] ?? 0;
        $misses = $statistics['misses'] ?? 0;
        $total = $hits + $misses;
        if ($total === 0) {
            return 0.0;
        }
        return round(($hits / $total) * 100, 2);
    }

    /**
     * Get OPcache configuration.
     */
    public static function getConfig(): array
    {
        if (!self::isEnabled()) {
            return ['enabled' => false];
        }

        $config = opcache_get_configuration();
        return [
            'enabled' => true,
            'directives' => $config['directives'] ?? [],
            'version' => $config['version'] ?? [],
            'blacklist' => $config['blacklist'] ?? [],
        ];
    }

    /**
     * Clear OPcache.
     */
    public static function clear(): bool
    {
        if (!self::isEnabled()) {
            return false;
        }

        return opcache_reset();
    }

    /**
     * Invalidate a specific file.
     */
    public static function invalidate(string $file): bool
    {
        if (!self::isEnabled() || !file_exists($file)) {
            return false;
        }

        return opcache_invalidate($file, true);
    }

    /**
     * Invalidate multiple files.
     */
    public static function invalidateFiles(array $files): array
    {
        $results = [];
        foreach ($files as $file) {
            $results[$file] = self::invalidate($file);
        }
        return $results;
    }

    /**
     * Precompile a file.
     */
    public static function precompile(string $file): bool
    {
        if (!self::isEnabled() || !file_exists($file)) {
            return false;
        }

        return opcache_compile_file($file);
    }

    /**
     * Precompile all PHP files in a directory.
     */
    public static function precompileDirectory(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $results = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $results[$file->getPathname()] = self::precompile($file->getPathname());
            }
        }

        return $results;
    }

    /**
     * Get OPcache memory usage percentage.
     */
    public static function getMemoryUsagePercent(): float
    {
        $status = self::getStatus();
        $memory = $status['memory'] ?? [];
        $used = $memory['used_mb'] ?? 0;
        $total = $used + ($memory['free_mb'] ?? 0);
        if ($total === 0) {
            return 0.0;
        }
        return round(($used / $total) * 100, 2);
    }

    /**
     * Check if OPcache needs restart.
     */
    public static function needsRestart(): bool
    {
        $status = self::getStatus();
        return $status['restart_pending'] ?? false;
    }

    /**
     * Get OPcache health report.
     */
    public static function healthReport(): array
    {
        $status = self::getStatus();
        $memoryUsage = self::getMemoryUsagePercent();

        return [
            'status' => $status,
            'memory_usage_percent' => $memoryUsage,
            'needs_restart' => self::needsRestart(),
            'recommendations' => self::getRecommendations($status, $memoryUsage),
        ];
    }

    /**
     * Get recommendations based on OPcache status.
     */
    protected static function getRecommendations(array $status, float $memoryUsage): array
    {
        $recommendations = [];

        if (!$status['enabled']) {
            $recommendations[] = 'Enable OPcache in php.ini for better performance';
        }

        if ($memoryUsage > 90) {
            $recommendations[] = 'OPcache memory usage is high. Consider increasing opcache.memory_consumption';
        }

        if ($status['cache_full'] ?? false) {
            $recommendations[] = 'OPcache is full. Increase memory limit or clear cache';
        }

        if ($status['oom_restarts'] ?? 0 > 0) {
            $recommendations[] = 'OPcache has had OOM restarts. Increase memory limit';
        }

        if ($status['statistics']['hit_rate'] ?? 100 < 50) {
            $recommendations[] = 'Low hit rate. Check if files are being cached properly';
        }

        if (empty($recommendations)) {
            $recommendations[] = 'OPcache is healthy';
        }

        return $recommendations;
    }
}