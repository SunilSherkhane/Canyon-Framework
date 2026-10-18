<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Job Queue System
 *
 * Simple file-based job queue inspired by Laravel's queue.
 * Supports dispatching jobs, workers, and delayed jobs.
 */
class Queue
{
    /** @var string Queue directory */
    protected static string $queueDir;

    /** @var string Failed jobs directory */
    protected static string $failedDir;

    /**
     * Initialize queue directories.
     */
    public static function init(): void
    {
        self::$queueDir = CANYON_STORAGE_PATH . '/queue';
        self::$failedDir = CANYON_STORAGE_PATH . '/failed_jobs';
        foreach ([self::$queueDir, self::$failedDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0750, true);
            }
        }
    }

    /**
     * Push a job onto the queue.
     *
     * @param string $jobClass Fully qualified class name implementing JobInterface
     * @param array $payload Job data
     * @param int $delay Delay in seconds before the job is available
     * @return string Job ID
     */
    public static function push(string $jobClass, array $payload = [], int $delay = 0): string
    {
        self::init();
        $id = uniqid('job_', true);
        $job = [
            'id' => $id,
            'class' => $jobClass,
            'payload' => $payload,
            'attempts' => 0,
            'available_at' => time() + $delay,
            'created_at' => time(),
        ];

        $file = self::$queueDir . '/' . $id . '.job';
        file_put_contents($file, serialize($job), LOCK_EX);

        return $id;
    }

    /**
     * Pop the next available job from the queue.
     *
     * @return array|null Job data or null if no jobs available
     */
    public static function pop(): ?array
    {
        self::init();
        $files = glob(self::$queueDir . '/*.job');
        if (empty($files)) {
            return null;
        }

        sort($files);
        $file = $files[0];
        $job = unserialize(file_get_contents($file));

        // Check if job is available yet
        if (time() < $job['available_at']) {
            return null;
        }

        unlink($file);
        return $job;
    }

    /**
     * Process a single job.
     *
     * @return bool True if job was processed successfully
     */
    public static function process(array $job): bool
    {
        $class = $job['class'];

        if (!class_exists($class)) {
            self::fail($job, "Job class {$class} not found");
            return false;
        }

        $instance = new $class();
        $job['attempts']++;

        try {
            if (method_exists($instance, 'handle')) {
                $instance->handle($job['payload']);
            }
            return true;
        } catch (\Throwable $e) {
            $job['error'] = $e->getMessage();
            self::fail($job, $e->getMessage());
            return false;
        }
    }

    /**
     * Run the queue worker (process jobs until queue is empty).
     *
     * @param int $maxJobs Maximum jobs to process (0 = unlimited)
     * @return array Processed jobs
     */
    public static function work(int $maxJobs = 0): array
    {
        self::init();
        $processed = [];
        $count = 0;

        while (true) {
            $job = self::pop();
            if ($job === null) {
                break;
            }

            $success = self::process($job);
            $processed[] = [
                'id' => $job['id'],
                'class' => $job['class'],
                'success' => $success,
            ];

            $count++;
            if ($maxJobs > 0 && $count >= $maxJobs) {
                break;
            }
        }

        return $processed;
    }

    /**
     * Mark a job as failed.
     */
    public static function fail(array $job, string $error): void
    {
        self::init();
        $job['failed_at'] = time();
        $job['error'] = $error;

        $file = self::$failedDir . '/' . $job['id'] . '.failed';
        file_put_contents($file, serialize($job), LOCK_EX);
    }

    /**
     * Get all pending jobs.
     */
    public static function pending(): array
    {
        self::init();
        $files = glob(self::$queueDir . '/*.job') ?: [];
        $jobs = [];
        foreach ($files as $file) {
            $jobs[] = unserialize(file_get_contents($file));
        }
        sort($jobs);
        return $jobs;
    }

    /**
     * Get all failed jobs.
     */
    public static function failed(): array
    {
        self::init();
        $files = glob(self::$failedDir . '/*.failed') ?: [];
        $jobs = [];
        foreach ($files as $file) {
            $jobs[] = unserialize(file_get_contents($file));
        }
        return $jobs;
    }

    /**
     * Clear all pending jobs.
     */
    public static function clear(): void
    {
        self::init();
        foreach (glob(self::$queueDir . '/*.job') ?: [] as $file) {
            unlink($file);
        }
    }

    /**
     * Clear all failed jobs.
     */
    public static function clearFailed(): void
    {
        self::init();
        foreach (glob(self::$failedDir . '/*.failed') ?: [] as $file) {
            unlink($file);
        }
    }
}