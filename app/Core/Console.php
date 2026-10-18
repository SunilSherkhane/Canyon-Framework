<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Console (Symfony Console Inspired)
 *
 * CLI command system with arguments, options, and styled output.
 */
class Console
{
    /** @var array Registered commands */
    protected static array $commands = [];

    /** @var array Command output buffer */
    protected array $output = [];

    /** @var int Exit code */
    protected int $exitCode = 0;

    /** @var array Color codes */
    protected array $colors = [
        'black' => '0;30',
        'red' => '0;31',
        'green' => '0;32',
        'yellow' => '1;33',
        'blue' => '0;34',
        'magenta' => '0;35',
        'cyan' => '0;36',
        'white' => '1;37',
    ];

    /** @var array Background colors */
    protected array $bgColors = [
        'black' => '40',
        'red' => '41',
        'green' => '42',
        'yellow' => '43',
        'blue' => '44',
        'magenta' => '45',
        'cyan' => '46',
        'white' => '47',
    ];

    /**
     * Register a command.
     */
    public static function register(string $name, callable $callback, string $description = ''): void
    {
        self::$commands[$name] = [
            'callback' => $callback,
            'description' => $description,
        ];
    }

    /**
     * Run a command.
     */
    public static function run(string $command, array $argv = []): int
    {
        if (!isset(self::$commands[$command])) {
            echo self::error("Command [{$command}] not found.") . PHP_EOL;
            echo "Available commands:" . PHP_EOL;
            foreach (self::$commands as $name => $info) {
                echo "  " . str_pad($name, 25) . $info['description'] . PHP_EOL;
            }
            return 1;
        }

        $instance = new self();
        $result = (self::$commands[$command]['callback'])($instance, $argv);
        return $result ?? 0;
    }

    // -----------------------------------------------------------------------
    // Output Methods
    // -----------------------------------------------------------------------

    /**
     * Write a line to output.
     */
    public function line(string $text = ''): void
    {
        echo $text . PHP_EOL;
    }

    /**
     * Write info message (green).
     */
    public function info(string $text): void
    {
        echo $this->color($text, 'green') . PHP_EOL;
    }

    /**
     * Write error message (red).
     * Named errorOut to avoid collision with PHP's built-in error() function.
     */
    public function errorOut(string $text): void
    {
        echo $this->color($text, 'red') . PHP_EOL;
    }

    /**
     * Write error message (red).
     * Alias of errorOut().
     */
    public function error(string $text): void
    {
        $this->errorOut($text);
    }

    /**
     * Write warning message (yellow).
     */
    public function warn(string $text): void
    {
        echo $this->color($text, 'yellow') . PHP_EOL;
    }

    /**
     * Write comment message (cyan).
     */
    public function comment(string $text): void
    {
        echo $this->color($text, 'cyan') . PHP_EOL;
    }

    /**
     * Write question message (magenta).
     */
    public function question(string $text): void
    {
        echo $this->color($text, 'magenta') . PHP_EOL;
    }

    /**
     * Create a progress bar.
     */
    public function progressBar(int $max = 100): ProgressBar
    {
        return new ProgressBar($max);
    }

    /**
     * Create a table.
     */
    public function table(array $headers, array $rows): void
    {
        $widths = [];
        foreach ($headers as $i => $header) {
            $widths[$i] = strlen($header);
            foreach ($rows as $row) {
                $widths[$i] = max($widths[$i], strlen((string)($row[$i] ?? '')));
            }
        }

        // Header
        $headerLine = ' ';
        foreach ($headers as $i => $header) {
            $headerLine .= $this->color(str_pad($header, $widths[$i] + 2), 'blue');
        }
        echo $headerLine . PHP_EOL;

        // Separator
        echo ' ' . str_repeat('-', array_sum($widths) + count($widths) * 2 - 1) . PHP_EOL;

        // Rows
        foreach ($rows as $row) {
            $rowLine = ' ';
            foreach ($headers as $i => $header) {
                $rowLine .= str_pad((string)($row[$i] ?? ''), $widths[$i] + 2);
            }
            echo $rowLine . PHP_EOL;
        }
    }

    /**
     * Ask for user input.
     */
    public function ask(string $question, string $default = ''): string
    {
        $this->question($question);
        if ($default) {
            $this->line(" [{$default}]: ");
        }
        $input = trim(fgets(STDIN));
        return $input ?: $default;
    }

    /**
     * Confirm action.
     */
    public function confirm(string $question, bool $default = false): bool
    {
        $this->question($question . ' [yes/no]: ');
        $input = strtolower(trim(fgets(STDIN)));
        if ($input === '') return $default;
        return in_array($input, ['y', 'yes', '1', 'true']);
    }

    /**
     * Select from options.
     */
    public function select(string $question, array $options, int $default = 0): int
    {
        $this->question($question);
        foreach ($options as $i => $option) {
            $marker = $i === $default ? '>' : ' ';
            echo "  {$marker} [{$i}] {$option}" . PHP_EOL;
        }
        $input = trim(fgets(STDIN));
        return is_numeric($input) ? (int) $input : $default;
    }

    /**
     * Print success block.
     */
    public function success(string $text): void
    {
        $line = str_repeat(' ', strlen($text) + 4);
        echo $this->color($line, 'white', 'green') . PHP_EOL;
        echo $this->color('  ' . $text . '  ', 'white', 'green') . PHP_EOL;
        echo $this->color($line, 'white', 'green') . PHP_EOL;
    }

    /**
     * Print error block.
     */
    public function failure(string $text): void
    {
        $line = str_repeat(' ', strlen($text) + 4);
        echo $this->color($line, 'white', 'red') . PHP_EOL;
        echo $this->color('  ' . $text . '  ', 'white', 'red') . PHP_EOL;
        echo $this->color($line, 'white', 'red') . PHP_EOL;
    }

    // -----------------------------------------------------------------------
    // Color & Style
    // -----------------------------------------------------------------------

    /**
     * Apply color to text.
     */
    public function color(string $text, string $fg = '', string $bg = ''): string
    {
        $codes = [];
        if (isset($this->colors[$fg])) {
            $codes[] = $this->colors[$fg];
        }
        if (isset($this->bgColors[$bg])) {
            $codes[] = $this->bgColors[$bg];
        }
        if (empty($codes)) return $text;
        return "\033[" . implode(';', $codes) . "m{$text}\033[0m";
    }

    // -----------------------------------------------------------------------
    // Utilities
    // -----------------------------------------------------------------------

    /**
     * Clear the screen.
     */
    public function clear(): void
    {
        echo "\033[2J\033[H";
    }

    /**
     * Sleep for a duration.
     */
    public function sleep(float $seconds): void
    {
        usleep((int)($seconds * 1000000));
    }

    /**
     * Get terminal width.
     */
    public function getWidth(): int
    {
        return (int) exec('tput cols') ?: 80;
    }

    /**
     * Get terminal height.
     */
    public function getHeight(): int
    {
        return (int) exec('tput lines') ?: 24;
    }

    /**
     * List all registered commands.
     */
    public static function listCommands(): array
    {
        return array_map(fn($cmd) => $cmd['description'], self::$commands);
    }
}