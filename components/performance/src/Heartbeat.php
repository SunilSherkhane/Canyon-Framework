<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Heartbeat Control
 *
 * Manages WordPress-like heartbeat functionality for periodic requests.
 * QUIC.cloud heartbeat control.
 */
class Heartbeat
{
    /** @var array Heartbeat settings */
    protected static array $settings = [
        'enabled' => true,
        'interval' => 15,        // seconds
        'min_interval' => 10,    // minimum interval
        'max_interval' => 60,    // maximum interval
        'admin_interval' => 30,  // admin area interval
        'frontend_interval' => 60, // frontend interval
    ];

    /** @var array Heartbeat log */
    protected static array $log = [];

    /** @var string Last heartbeat time file */
    protected static string $lastBeatFile;

    /**
     * Initialize heartbeat.
     */
    public static function init(): void
    {
        self::$lastBeatFile = CANYON_STORAGE_PATH . '/heartbeat/last_beat';
    }

    /**
     * Check if a heartbeat request should be processed.
     */
    public static function shouldProcess(string $context = 'frontend'): bool
    {
        if (!self::$settings['enabled']) {
            return false;
        }

        $interval = self::getInterval($context);
        $lastBeat = self::getLastBeat();

        if ($lastBeat === 0) {
            return true;
        }

        return (time() - $lastBeat) >= $interval;
    }

    /**
     * Process a heartbeat request.
     */
    public static function process(string $context = 'frontend'): array
    {
        self::init();

        if (!self::shouldProcess($context)) {
            return ['processed' => false, 'reason' => 'throttled'];
        }

        $results = [];

        // Run cleanup tasks
        $results['cleanup'] = self::runCleanup();

        // Run scheduled events
        $results['cron'] = self::runCron($context);

        // Update last beat
        self::updateLastBeat();

        // Log
        self::$log[] = [
            'context' => $context,
            'time' => time(),
            'results' => $results,
        ];

        return ['processed' => true, 'results' => $results];
    }

    /**
     * Get the heartbeat interval for a context.
     */
    public static function getInterval(string $context): int
    {
        return match ($context) {
            'admin' => self::$settings['admin_interval'],
            'frontend' => self::$settings['frontend_interval'],
            default => self::$settings['interval'],
        };
    }

    /**
     * Set heartbeat interval.
     */
    public static function setInterval(int $seconds, string $context = 'all'): void
    {
        $seconds = max(self::$settings['min_interval'], min(self::$settings['max_interval'], $seconds));

        if ($context === 'all') {
            self::$settings['interval'] = $seconds;
        } else {
            self::$settings[$context . '_interval'] = $seconds;
        }
    }

    /**
     * Enable or disable heartbeat.
     */
    public static function setEnabled(bool $enabled): void
    {
        self::$settings['enabled'] = $enabled;
    }

    /**
     * Check if heartbeat is enabled.
     */
    public static function isEnabled(): bool
    {
        return self::$settings['enabled'];
    }

    /**
     * Get the last heartbeat time.
     */
    public static function getLastBeat(): int
    {
        self::init();
        if (!file_exists(self::$lastBeatFile)) {
            return 0;
        }
        return (int) file_get_contents(self::$lastBeatFile);
    }

    /**
     * Update the last heartbeat time.
     */
    protected static function updateLastBeat(): void
    {
        self::init();
        $dir = dirname(self::$lastBeatFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents(self::$lastBeatFile, (string) time());
    }

    /**
     * Run cleanup tasks during heartbeat.
     */
    protected static function runCleanup(): array
    {
        $results = [];

        // Clean expired cache
        $cache = new Cache();
        // Cache cleanup is handled automatically

        // Clean old sessions
        $optimizer = new DatabaseOptimizer();
        $results['sessions'] = $optimizer->cleanSessions();
        $results['logs'] = $optimizer->cleanLogs(7);

        return $results;
    }

    /**
     * Run cron tasks during heartbeat.
     */
    protected static function runCron(string $context): array
    {
        $cron = new Cron();
        $results = [];

        // Run minute cron if due
        if ($cron->isDue('minute')) {
            $results['minute'] = $cron->run('minute');
        }

        return $results;
    }

    /**
     * Get heartbeat settings.
     */
    public static function getSettings(): array
    {
        return self::$settings;
    }

    /**
     * Set multiple settings.
     */
    public static function configure(array $settings): void
    {
        self::$settings = array_merge(self::$settings, $settings);
    }

    /**
     * Get heartbeat log.
     */
    public static function getLog(): array
    {
        return self::$log;
    }

    /**
     * Clear heartbeat log.
     */
    public static function clearLog(): void
    {
        self::$log = [];
    }

    /**
     * Get time until next heartbeat.
     */
    public static function timeUntilNext(string $context = 'frontend'): int
    {
        $lastBeat = self::getLastBeat();
        $interval = self::getInterval($context);
        $elapsed = time() - $lastBeat;
        return max(0, $interval - $elapsed);
    }

    /**
     * Get heartbeat status.
     */
    public static function status(string $context = 'frontend'): array
    {
        return [
            'enabled' => self::$settings['enabled'],
            'interval' => self::getInterval($context),
            'last_beat' => self::getLastBeat(),
            'time_until_next' => self::timeUntilNext($context),
            'should_process' => self::shouldProcess($context),
        ];
    }
}