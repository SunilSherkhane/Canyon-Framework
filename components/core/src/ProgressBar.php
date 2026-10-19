<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Progress Bar (Symfony Console)
 */
class ProgressBar
{
    /** @var int Maximum steps */
    protected int $max;

    /** @var int Current step */
    protected int $current = 0;

    /** @var string Bar character */
    protected string $barChar = '=';

    /** @var string Empty bar character */
    protected string $emptyChar = ' ';

    /** @var int Bar width */
    protected int $width = 50;

    /** @var bool Whether to show percentage */
    protected bool $showPercent = true;

    /** @var bool Whether to show step count */
    protected bool $showSteps = true;

    /** @var float Start time */
    protected float $startTime;

    /** @var string|null Current message */
    protected ?string $message = null;

    public function __construct(int $max = 100)
    {
        $this->max = $max;
        $this->startTime = microtime(true);
    }

    /**
     * Start the progress bar.
     */
    public function start(): void
    {
        $this->current = 0;
        $this->startTime = microtime(true);
        $this->display();
    }

    /**
     * Advance the progress bar.
     */
    public function advance(int $step = 1): void
    {
        $this->current = min($this->current + $step, $this->max);
        $this->display();
    }

    /**
     * Set current progress.
     */
    public function setProgress(int $step): void
    {
        $this->current = max(0, min($step, $this->max));
        $this->display();
    }

    /**
     * Set the message.
     */
    public function setMessage(string $message): void
    {
        $this->message = $message;
        $this->display();
    }

    /**
     * Finish the progress bar.
     */
    public function finish(): void
    {
        $this->current = $this->max;
        $this->display();
        echo PHP_EOL;
    }

    /**
     * Display the progress bar.
     */
    protected function display(): void
    {
        $percent = $this->max > 0 ? round($this->current / $this->max * 100) : 0;
        $filled = (int) round($this->current / $this->max * $this->width);
        $empty = $this->width - $filled;

        $bar = str_repeat($this->barChar, $filled) . str_repeat($this->emptyChar, $empty);

        $output = "\r[{$bar}]";

        if ($this->showPercent) {
            $output .= " {$percent}%";
        }

        if ($this->showSteps) {
            $output .= " ({$this->current}/{$this->max})";
        }

        if ($this->message) {
            $output .= " {$this->message}";
        }

        // Clear remaining characters
        $output .= str_repeat(' ', 10);

        echo $output;
        flush();
    }

    /**
     * Get elapsed time.
     */
    public function getElapsed(): float
    {
        return microtime(true) - $this->startTime;
    }

    /**
     * Get estimated time remaining.
     */
    public function getRemaining(): float
    {
        if ($this->current === 0) return 0;
        $elapsed = $this->getElapsed();
        return ($elapsed / $this->current) * ($this->max - $this->current);
    }

    /**
     * Format time in human-readable format.
     */
    public static function formatTime(float $seconds): string
    {
        if ($seconds < 60) {
            return round($seconds, 2) . 's';
        }
        if ($seconds < 3600) {
            return round($seconds / 60, 2) . 'm';
        }
        return round($seconds / 3600, 2) . 'h';
    }
}