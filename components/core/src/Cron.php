<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Cron System
 *
 * Scheduled task runner with interval-based execution.
 * based on Elgg's cron system.
 */
class Cron
{
    /** @var array Registered cron handlers */
    protected static array $handlers = [];

    /** @var array Cron intervals */
    protected static array $intervals = [
        'minute' => '* * * * *',
        'fiveminute' => '*/5 * * * *',
        'fifteenmin' => '*/15 * * * *',
        'halfhour' => '*/30 * * * *',
        'hourly' => '0 * * * *',
        'daily' => '0 0 * * *',
        'weekly' => '0 0 * * 0',
        'monthly' => '0 0 1 * *',
        'yearly' => '0 0 1 1 *',
    ];

    /** @var string Cron log file */
    protected static string $logFile;

    /**
     * Initialize cron.
     */
    public static function init(): void
    {
        self::$logFile = CANYON_STORAGE_PATH . '/logs/cron.log';
    }

    /**
     * Register a cron handler.
     *
     * @param string $interval Cron interval (minute, hourly, daily, etc.)
     * @param callable $callback Handler function
     * @param string $name Handler name
     */
    public static function register(string $interval, callable $callback, string $name = ''): void
    {
        if (!isset(self::$intervals[$interval])) {
            throw new \InvalidArgumentException("Invalid cron interval: {$interval}");
        }

        self::$handlers[$interval][] = [
            'callback' => $callback,
            'name' => $name,
        ];
    }

    /**
     * Run cron handlers for a specific interval.
     *
     * @param string $interval Cron interval
     * @return array Results
     */
    public static function run(string $interval): array
    {
        self::init();
        $results = [];

        if (!isset(self::$handlers[$interval])) {
            return $results;
        }

        foreach (self::$handlers[$interval] as $handler) {
            $name = $handler['name'] ?? 'unnamed';
            $start = microtime(true);

            try {
                $result = call_user_func($handler['callback']);
                $results[] = [
                    'name' => $name,
                    'status' => 'success',
                    'result' => $result,
                    'duration' => round(microtime(true) - $start, 4),
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'name' => $name,
                    'status' => 'error',
                    'error' => $e->getMessage(),
                    'duration' => round(microtime(true) - $start, 4),
                ];
            }
        }

        // Log results
        self::log($interval, $results);

        return $results;
    }

    /**
     * Run all intervals that are due.
     */
    public static function runDue(): array
    {
        self::init();
        $allResults = [];

        foreach (array_keys(self::$intervals) as $interval) {
            if (self::isDue($interval)) {
                $allResults[$interval] = self::run($interval);
            }
        }

        return $allResults;
    }

    /**
     * Check if a cron interval is due to run.
     */
    public static function isDue(string $interval): bool
    {
        $schedule = self::$intervals[$interval] ?? null;
        if (!$schedule) {
            return false;
        }

        $now = time();
        $parts = explode(' ', $schedule);

        if (count($parts) !== 5) {
            return false;
        }

        [$min, $hour, $day, $month, $weekday] = $parts;

        return self::cronMatch($min, date('i', $now))
            && self::cronMatch($hour, date('H', $now))
            && self::cronMatch($day, date('d', $now))
            && self::cronMatch($month, date('m', $now))
            && self::cronMatch($weekday, date('w', $now));
    }

    /**
     * Match a cron expression part against a value.
     */
    protected static function cronMatch(string $expr, string $value): bool
    {
        if ($expr === '*') {
            return true;
        }

        // Handle */N
        if (str_starts_with($expr, '*/')) {
            $step = (int) substr($expr, 2);
            return ((int) $value) % $step === 0;
        }

        // Handle comma-separated lists
        if (str_contains($expr, ',')) {
            $values = explode(',', $expr);
            return in_array($value, $values, true);
        }

        return $expr === $value;
    }

    /**
     * Add a custom cron interval.
     */
    public static function addInterval(string $name, string $schedule): void
    {
        self::$intervals[$name] = $schedule;
    }

    /**
     * Get all registered intervals.
     */
    public static function getIntervals(): array
    {
        return self::$intervals;
    }

    /**
     * Get all registered handlers.
     */
    public static function getHandlers(): array
    {
        return self::$handlers;
    }

    /**
     * Log cron results.
     */
    protected static function log(string $interval, array $results): void
    {
        $logDir = dirname(self::$logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0750, true);
        }

        $entry = sprintf(
            "[%s] CRON %s | Handlers: %d | Results: %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($interval),
            count($results),
            json_encode($results)
        );

        file_put_contents(self::$logFile, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Clear all registered handlers.
     */
    public static function clear(): void
    {
        self::$handlers = [];
    }
}