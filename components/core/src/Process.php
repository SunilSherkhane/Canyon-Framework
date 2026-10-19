<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Process (Symfony Process)
 *
 * Execute system commands with timeout, environment variables, and async support.
 */
class Process
{
    /** @var string Command to execute */
    protected string $command;

    /** @var string|null Working directory */
    protected ?string $cwd;

    /** @var array Environment variables */
    protected array $env;

    /** @var string|null Input */
    protected ?string $input;

    /** @var int Timeout in seconds */
    protected int $timeout;

    /** @var array Command output */
    protected array $output = [];

    /** @var array Error output */
    protected array $errorOutput = [];

    /** @var int|null Exit code */
    protected ?int $exitCode = null;

    /** @var float Start time */
    protected float $startTime;

    /** @var float|null End time */
    protected ?float $endTime = null;

    /** @var array Running processes */
    protected static array $running = [];

    /** @var resource|null Process resource */
    protected $process = null;

    /** @var array Process pipes */
    protected array $pipes = [];

    /**
     * Constructor.
     */
    public function __construct(string $command, ?string $cwd = null, ?array $env = null, ?string $input = null, int $timeout = 60)
    {
        $this->command = $command;
        $this->cwd = $cwd;
        $this->env = $env ?? $_ENV;
        $this->input = $input;
        $this->timeout = $timeout;
    }

    /**
     * Create a new process.
     */
    public static function create(string $command, ?string $cwd = null, ?array $env = null, ?string $input = null, int $timeout = 60): self
    {
        return new self($command, $cwd, $env, $input, $timeout);
    }

    /**
     * Create from command array.
     */
    public static function fromCommandArray(array $command, ?string $cwd = null, ?array $env = null, ?string $input = null, int $timeout = 60): self
    {
        $escaped = array_map(fn($arg) => escapeshellarg($arg), $command);
        return new self(implode(' ', $escaped), $cwd, $env, $input, $timeout);
    }

    // -----------------------------------------------------------------------
    // Execution
    // -----------------------------------------------------------------------

    /**
     * Run the process synchronously.
     */
    public function run(?callable $callback = null): int
    {
        $this->startTime = microtime(true);

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $this->process = proc_open(
            $this->command,
            $descriptors,
            $this->pipes,
            $this->cwd,
            $this->env
        );

        if (!is_resource($this->process)) {
            throw new \RuntimeException("Failed to start process: {$this->command}");
        }

        // Set streams to non-blocking
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);

        // Write input
        if ($this->input !== null) {
            fwrite($this->pipes[0], $this->input);
        }
        fclose($this->pipes[0]);

        // Read output
        $this->output = [];
        $this->errorOutput = [];

        while (true) {
            $status = proc_get_status($this->process);

            // Read stdout
            $stdout = stream_get_contents($this->pipes[1]);
            if ($stdout !== false && $stdout !== '') {
                $lines = explode("\n", rtrim($stdout, "\n"));
                foreach ($lines as $line) {
                    $this->output[] = $line;
                    if ($callback) {
                        $callback('out', $line);
                    }
                }
            }

            // Read stderr
            $stderr = stream_get_contents($this->pipes[2]);
            if ($stderr !== false && $stderr !== '') {
                $lines = explode("\n", rtrim($stderr, "\n"));
                foreach ($lines as $line) {
                    $this->errorOutput[] = $line;
                    if ($callback) {
                        $callback('err', $line);
                    }
                }
            }

            if (!$status['running']) {
                break;
            }

            // Check timeout
            if (microtime(true) - $this->startTime > $this->timeout) {
                $this->stop(0);
                throw new \RuntimeException("Process timed out after {$this->timeout} seconds");
            }

            usleep(10000); // 10ms
        }

        // Close pipes
        fclose($this->pipes[1]);
        fclose($this->pipes[2]);

        $this->exitCode = proc_close($this->process);
        $this->endTime = microtime(true);
        $this->process = null;

        return $this->exitCode;
    }

    /**
     * Start the process asynchronously.
     */
    public function start(?callable $callback = null): self
    {
        $this->startTime = microtime(true);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $this->process = proc_open(
            $this->command,
            $descriptors,
            $this->pipes,
            $this->cwd,
            $this->env
        );

        if (!is_resource($this->process)) {
            throw new \RuntimeException("Failed to start process: {$this->command}");
        }

        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);

        // Write input
        if ($this->input !== null) {
            fwrite($this->pipes[0], $this->input);
        }
        fclose($this->pipes[0]);

        self::$running[spl_object_id($this)] = $this;

        // Start monitoring
        $this->monitorAsync($callback);

        return $this;
    }

    /**
     * Wait for the process to finish.
     */
    public function wait(?callable $callback = null): int
    {
        if ($this->process === null) {
            return $this->exitCode ?? 0;
        }

        return $this->run($callback);
    }

    /**
     * Stop the process.
     */
    public function stop(float $timeout = 10, ?int $signal = null): void
    {
        if ($this->process === null) {
            return;
        }

        $signal = $signal ?? 15; // SIGTERM

        proc_terminate($this->process, $signal);

        // Wait for process to exit
        $start = microtime(true);
        while (microtime(true) - $start < $timeout) {
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                break;
            }
            usleep(10000);
        }

        // Force kill if still running
        $status = proc_get_status($this->process);
        if ($status['running']) {
            proc_terminate($this->process, 9); // SIGKILL
        }

        // Close pipes
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        proc_close($this->process);
        $this->process = null;
        unset(self::$running[spl_object_id($this)]);
    }

    /**
     * Send signal to process.
     */
    public function signal(int $signal): bool
    {
        if ($this->process === null) {
            return false;
        }
        return proc_terminate($this->process, $signal);
    }

    // -----------------------------------------------------------------------
    // Monitoring
    // -----------------------------------------------------------------------

    /**
     * Monitor process asynchronously.
     */
    protected function monitorAsync(?callable $callback): void
    {
        while (true) {
            $status = proc_get_status($this->process);

            // Read available output
            $stdout = stream_get_contents($this->pipes[1]);
            if ($stdout !== false && $stdout !== '') {
                $lines = explode("\n", rtrim($stdout, "\n"));
                foreach ($lines as $line) {
                    $this->output[] = $line;
                    if ($callback) {
                        $callback('out', $line);
                    }
                }
            }

            $stderr = stream_get_contents($this->pipes[2]);
            if ($stderr !== false && $stderr !== '') {
                $lines = explode("\n", rtrim($stderr, "\n"));
                foreach ($lines as $line) {
                    $this->errorOutput[] = $line;
                    if ($callback) {
                        $callback('err', $line);
                    }
                }
            }

            if (!$status['running']) {
                // Read any remaining output
                $stdout = stream_get_contents($this->pipes[1]);
                if ($stdout !== false && $stdout !== '') {
                    $lines = explode("\n", rtrim($stdout, "\n"));
                    foreach ($lines as $line) {
                        $this->output[] = $line;
                        if ($callback) {
                            $callback('out', $line);
                        }
                    }
                }

                $stderr = stream_get_contents($this->pipes[2]);
                if ($stderr !== false && $stderr !== '') {
                    $lines = explode("\n", rtrim($stderr, "\n"));
                    foreach ($lines as $line) {
                        $this->errorOutput[] = $line;
                        if ($callback) {
                            $callback('err', $line);
                        }
                    }
                }

                fclose($this->pipes[1]);
                fclose($this->pipes[2]);

                $this->exitCode = $status['exitcode'];
                $this->endTime = microtime(true);
                $this->process = null;
                unset(self::$running[spl_object_id($this)]);
                break;
            }

            usleep(10000);
        }
    }

    // -----------------------------------------------------------------------
    // Getters
    // -----------------------------------------------------------------------

    /**
     * Get command output.
     */
    public function getOutput(): string
    {
        return implode("\n", $this->output);
    }

    /**
     * Get output lines.
     */
    public function getOutputLines(): array
    {
        return $this->output;
    }

    /**
     * Get error output.
     */
    public function getErrorOutput(): string
    {
        return implode("\n", $this->errorOutput);
    }

    /**
     * Get error output lines.
     */
    public function getErrorOutputLines(): array
    {
        return $this->errorOutput;
    }

    /**
     * Get exit code.
     */
    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    /**
     * Check if process succeeded.
     */
    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * Check if process failed.
     */
    public function hasFailed(): bool
    {
        return $this->exitCode !== null && $this->exitCode !== 0;
    }

    /**
     * Check if process is running.
     */
    public function isRunning(): bool
    {
        if ($this->process === null) {
            return false;
        }
        $status = proc_get_status($this->process);
        return $status['running'];
    }

    /**
     * Get execution time.
     */
    public function getExecutionTime(): float
    {
        $end = $this->endTime ?? microtime(true);
        return $end - $this->startTime;
    }

    // -----------------------------------------------------------------------
    // Output Helpers
    // -----------------------------------------------------------------------

    /**
     * Get output with ANSI colors stripped.
     */
    public function getCleanOutput(): string
    {
        return preg_replace('/\033\[[0-9;]*m/', '', $this->getOutput());
    }

    /**
     * Get output as array (trimmed).
     */
    public function getOutputAsArray(): array
    {
        return array_map('trim', $this->output);
    }

    /**
     * Get JSON output.
     */
    public function getJsonOutput(): ?array
    {
        $output = $this->getOutput();
        if (empty($output)) return null;
        return json_decode($output, true);
    }

    // -----------------------------------------------------------------------
    // Static Methods
    // -----------------------------------------------------------------------

    /**
     * Get all running processes.
     */
    public static function getRunningProcesses(): array
    {
        return self::$running;
    }

    /**
     * Kill all running processes.
     */
    public static function killAll(): void
    {
        foreach (self::$running as $process) {
            $process->stop(0);
        }
        self::$running = [];
    }
}