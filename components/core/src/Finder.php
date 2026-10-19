<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Finder (Symfony Finder)
 *
 * File and directory finder with fluent interface.
 */
class Finder
{
    /** @var array Search paths */
    protected array $paths = [];

    /** @var array Filename patterns (include) */
    protected array $namePatterns = [];

    /** @var array Filename patterns (exclude) */
    protected array $notNamePatterns = [];

    /** @var array Path patterns (include) */
    protected array $pathPatterns = [];

    /** @var array Path patterns (exclude) */
    protected array $notPathPatterns = [];

    /** @var int|null Maximum depth */
    protected ?int $depth = null;

    /** @var string|null File type: file|dir */
    protected ?string $type = null;

    /** @var int|null Minimum file size */
    protected ?int $minSize = null;

    /** @var int|null Maximum file size */
    protected ?int $maxSize = null;

    /** @var string|null Date modifier (e.g., "< 2024-01-01") */
    protected ?string $date = null;

    /** @var array Sort callbacks */
    protected array $sortCallbacks = [];

    /** @var bool Whether to ignore dot files */
    protected bool $ignoreDotFiles = true;

    /** @var bool Whether to ignore VCS files */
    protected bool $ignoreVCS = true;

    /** @var array VCS patterns to ignore */
    protected array $vcsPatterns = ['.git', '.svn', '.hg', '.bzr', '_darcs', 'CVS'];

    /** @var array Results */
    protected array $results = [];

    /** @var bool Whether search has been executed */
    protected bool $executed = false;

    /**
     * Create a new Finder instance.
     */
    public static function create(): self
    {
        return new self();
    }

    // -----------------------------------------------------------------------
    // Path Configuration
    // -----------------------------------------------------------------------

    /**
     * Search in a path.
     */
    public function in(string $path): self
    {
        $this->paths[] = rtrim($path, '/');
        return $this;
    }

    /**
     * Search in multiple paths.
     */
    public function inMultiple(array $paths): self
    {
        foreach ($paths as $path) {
            $this->in($path);
        }
        return $this;
    }

    // -----------------------------------------------------------------------
    // Name Filtering
    // -----------------------------------------------------------------------

    /**
     * Filter by filename pattern (glob).
     */
    public function name(string $pattern): self
    {
        $this->namePatterns[] = $pattern;
        return $this;
    }

    /**
     * Filter by multiple filename patterns.
     */
    public function names(array $patterns): self
    {
        foreach ($patterns as $pattern) {
            $this->name($pattern);
        }
        return $this;
    }

    /**
     * Exclude filename pattern.
     */
    public function notName(string $pattern): self
    {
        $this->notNamePatterns[] = $pattern;
        return $this;
    }

    /**
     * Filter by filename regex.
     */
    public function nameRegex(string $pattern): self
    {
        $this->namePatterns[] = $pattern;
        return $this;
    }

    // -----------------------------------------------------------------------
    // Path Filtering
    // -----------------------------------------------------------------------

    /**
     * Filter by path pattern.
     */
    public function path(string $pattern): self
    {
        $this->pathPatterns[] = $pattern;
        return $this;
    }

    /**
     * Exclude path pattern.
     */
    public function notPath(string $pattern): self
    {
        $this->notPathPatterns[] = $pattern;
        return $this;
    }

    /**
     * Exclude directory.
     */
    public function exclude(string $dir): self
    {
        return $this->notPath('#^' . preg_quote($dir, '#') . '#');
    }

    // -----------------------------------------------------------------------
    // File Properties
    // -----------------------------------------------------------------------

    /**
     * Set maximum directory depth.
     */
    public function depth(int $level): self
    {
        $this->depth = $level;
        return $this;
    }

    /**
     * Only files.
     */
    public function files(): self
    {
        $this->type = 'file';
        return $this;
    }

    /**
     * Only directories.
     */
    public function directories(): self
    {
        $this->type = 'dir';
        return $this;
    }

    /**
     * Filter by file size.
     */
    public function size(string $size): self
    {
        if (str_starts_with($size, '>')) {
            $this->minSize = $this->parseSize(substr($size, 1));
        } elseif (str_starts_with($size, '<')) {
            $this->maxSize = $this->parseSize(substr($size, 1));
        } else {
            $exact = $this->parseSize($size);
            $this->minSize = $exact;
            $this->maxSize = $exact;
        }
        return $this;
    }

    /**
     * Filter by date.
     */
    public function date(string $date): self
    {
        $this->date = $date;
        return $this;
    }

    /**
     * Modified after date.
     */
    public function modifiedAfter(string $date): self
    {
        $this->date = '> ' . $date;
        return $this;
    }

    /**
     * Modified before date.
     */
    public function modifiedBefore(string $date): self
    {
        $this->date = '< ' . $date;
        return $this;
    }

    // -----------------------------------------------------------------------
    // Sorting
    // -----------------------------------------------------------------------

    /**
     * Sort by name.
     */
    public function sortByName(bool $reverse = false): self
    {
        $this->sortCallbacks[] = function ($a, $b) use ($reverse) {
            $result = strnatcasecmp($a['name'], $b['name']);
            return $reverse ? -$result : $result;
        };
        return $this;
    }

    /**
     * Sort by size.
     */
    public function sortBySize(bool $reverse = false): self
    {
        $this->sortCallbacks[] = function ($a, $b) use ($reverse) {
            $result = $a['size'] <=> $b['size'];
            return $reverse ? -$result : $result;
        };
        return $this;
    }

    /**
     * Sort by modified time.
     */
    public function sortByModifiedTime(bool $reverse = false): self
    {
        $this->sortCallbacks[] = function ($a, $b) use ($reverse) {
            $result = $a['mtime'] <=> $b['mtime'];
            return $reverse ? -$result : $result;
        };
        return $this;
    }

    /**
     * Sort by type.
     */
    public function sortByType(): self
    {
        $this->sortCallbacks[] = fn($a, $b) => $a['type'] <=> $b['type'];
        return $this;
    }

    /**
     * Custom sort.
     */
    public function sort(callable $callback): self
    {
        $this->sortCallbacks[] = $callback;
        return $this;
    }

    // -----------------------------------------------------------------------
    // Ignoring
    // -----------------------------------------------------------------------

    /**
     * Ignore dot files.
     */
    public function ignoreDotFiles(bool $ignore = true): self
    {
        $this->ignoreDotFiles = $ignore;
        return $this;
    }

    /**
     * Ignore VCS files.
     */
    public function ignoreVCS(bool $ignore = true): self
    {
        $this->ignoreVCS = $ignore;
        return $this;
    }

    /**
     * Add VCS pattern.
     */
    public function addVCSPattern(string $pattern): self
    {
        $this->vcsPatterns[] = $pattern;
        return $this;
    }

    /**
     * Ignore unreadable directories.
     */
    public function ignoreUnreadableDirs(bool $ignore = true): self
    {
        // Implementation would handle permission errors gracefully
        return $this;
    }

    // -----------------------------------------------------------------------
    // Execution
    // -----------------------------------------------------------------------

    /**
     * Execute the search and return results.
     */
    public function getResults(): array
    {
        if ($this->executed) {
            return $this->results;
        }

        $this->executed = true;
        $this->results = [];

        foreach ($this->paths as $path) {
            $this->searchPath($path, 0);
        }

        // Apply sorting
        foreach ($this->sortCallbacks as $callback) {
            usort($this->results, $callback);
        }

        return $this->results;
    }

    /**
     * Execute and return first result.
     */
    public function first(): ?array
    {
        $results = $this->getResults();
        return $results[0] ?? null;
    }

    /**
     * Execute and return count.
     */
    public function count(): int
    {
        return count($this->getResults());
    }

    /**
     * Check if any results exist.
     */
    public function hasResults(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Iterate over results.
     */
    public function each(callable $callback): void
    {
        foreach ($this->getResults() as $result) {
            $callback($result);
        }
    }

    /**
     * Map over results.
     */
    public function map(callable $callback): array
    {
        return array_map($callback, $this->getResults());
    }

    /**
     * Filter results.
     */
    public function filter(callable $callback): array
    {
        return array_filter($this->getResults(), $callback);
    }

    // -----------------------------------------------------------------------
    // Internal
    // -----------------------------------------------------------------------

    /**
     * Recursively search a path.
     */
    protected function searchPath(string $path, int $currentDepth): void
    {
        if ($this->depth !== null && $currentDepth > $this->depth) {
            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $iterator = new \DirectoryIterator($path);

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDot()) {
                continue;
            }

            $filename = $fileInfo->getFilename();
            $fullPath = $fileInfo->getPathname();

            // Ignore dot files
            if ($this->ignoreDotFiles && str_starts_with($filename, '.')) {
                continue;
            }

            // Ignore VCS
            if ($this->ignoreVCS && $this->isVCS($filename)) {
                continue;
            }

            // Check name patterns
            if (!$this->matchesName($filename)) {
                continue;
            }

            // Check path patterns
            if (!$this->matchesPath($fullPath)) {
                continue;
            }

            // Check file type
            $isDir = $fileInfo->isDir();
            if ($this->type === 'file' && $isDir) {
                // Still recurse into directories
                $this->searchPath($fullPath, $currentDepth + 1);
                continue;
            }
            if ($this->type === 'dir' && !$isDir) {
                continue;
            }

            // Check size (only for files)
            if (!$isDir) {
                $size = $fileInfo->getSize();
                if ($this->minSize !== null && $size < $this->minSize) {
                    continue;
                }
                if ($this->maxSize !== null && $size > $this->maxSize) {
                    continue;
                }

                // Check date
                if ($this->date !== null && !$this->matchesDate($fileInfo->getMTime())) {
                    continue;
                }
            }

            // Add to results
            $this->results[] = [
                'name' => $filename,
                'path' => $fullPath,
                'relative_path' => str_replace($path . '/', '', $fullPath),
                'type' => $isDir ? 'dir' : 'file',
                'size' => $isDir ? 0 : $fileInfo->getSize(),
                'mtime' => $fileInfo->getMTime(),
                'atime' => $fileInfo->getATime(),
                'extension' => $isDir ? '' : $fileInfo->getExtension(),
                'is_readable' => $fileInfo->isReadable(),
                'is_writable' => $fileInfo->isWritable(),
            ];

            // Recurse into directories
            if ($isDir) {
                $this->searchPath($fullPath, $currentDepth + 1);
            }
        }
    }

    /**
     * Check if filename matches name patterns.
     */
    protected function matchesName(string $filename): bool
    {
        // Include patterns
        if (!empty($this->namePatterns)) {
            $matched = false;
            foreach ($this->namePatterns as $pattern) {
                if (fnmatch($pattern, $filename) || preg_match($pattern, $filename)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) return false;
        }

        // Exclude patterns
        foreach ($this->notNamePatterns as $pattern) {
            if (fnmatch($pattern, $filename) || preg_match($pattern, $filename)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if path matches path patterns.
     */
    protected function matchesPath(string $path): bool
    {
        // Include patterns
        if (!empty($this->pathPatterns)) {
            $matched = false;
            foreach ($this->pathPatterns as $pattern) {
                if (fnmatch($pattern, $path) || preg_match($pattern, $path)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) return false;
        }

        // Exclude patterns
        foreach ($this->notPathPatterns as $pattern) {
            if (fnmatch($pattern, $path) || preg_match($pattern, $path)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if file matches date constraint.
     */
    protected function matchesDate(int $mtime): bool
    {
        if ($this->date === null) return true;

        if (str_starts_with($this->date, '>')) {
            $date = strtotime(trim(substr($this->date, 1)));
            return $mtime > $date;
        }
        if (str_starts_with($this->date, '<')) {
            $date = strtotime(trim(substr($this->date, 1)));
            return $mtime < $date;
        }

        return true;
    }

    /**
     * Check if filename is a VCS directory.
     */
    protected function isVCS(string $filename): bool
    {
        return in_array($filename, $this->vcsPatterns);
    }

    /**
     * Parse size string to bytes.
     */
    protected function parseSize(string $size): int
    {
        $size = trim(strtolower($size));
        $unit = substr($size, -1);
        $value = (int) $size;

        return match ($unit) {
            'k' => $value * 1024,
            'm' => $value * 1024 * 1024,
            'g' => $value * 1024 * 1024 * 1024,
            default => $value,
        };
    }

    /**
     * Reset the finder.
     */
    public function reset(): self
    {
        $this->paths = [];
        $this->namePatterns = [];
        $this->notNamePatterns = [];
        $this->pathPatterns = [];
        $this->notPathPatterns = [];
        $this->depth = null;
        $this->type = null;
        $this->minSize = null;
        $this->maxSize = null;
        $this->date = null;
        $this->sortCallbacks = [];
        $this->ignoreDotFiles = true;
        $this->ignoreVCS = true;
        $this->results = [];
        $this->executed = false;
        return $this;
    }
}