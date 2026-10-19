<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Web Application Firewall
 *
 * Request-level protection layer that inspects the raw request before any
 * controller runs: SQL-injection and XSS pattern matching, per-IP and
 * per-session rate limiting, IP allow/deny lists, payload size and request
 * flood guards, and request sanitization for controllers that accept free text.
 *
 * The engine is stateless across processes; persistence (blocked IPs, throttle
 * buckets, counters) lives in Cache files keyed by the threat namespace so the
 * firewall keeps working in CLI queue/cron workers where $_SESSION is absent.
 *
 * Inspired by Symfony's HttpKernel firewall and CodeIgniter's security filters.
 */
class Waf
{
    // -----------------------------------------------------------------------
    // Constants
    // -----------------------------------------------------------------------

    /** Maximum bytes accepted for a single request body. */
    public const MAX_PAYLOAD_BYTES = 10485760; // 10 MB

    /** Maximum request-line (URI) length accepted. */
    public const MAX_URI_LENGTH = 8192;

    /** Default request budget per IP inside the decay window. */
    public const DEFAULT_RATE_LIMIT = 120;

    /** Default decay window in seconds for the IP rate limiter. */
    public const DEFAULT_RATE_DECAY = 60;

    /** Cookie/namespace used to tag WAF-owned cache entries. */
    public const CACHE_NAMESPACE = 'canyon_waf';

    /** Canonical reason strings returned by inspect(). */
    public const REASON_SQL_INJECTION = 'sql_injection';
    public const REASON_XSS = 'xss';
    public const REASON_RATE_LIMIT = 'rate_limit';
    public const REASON_IP_BLOCKED = 'ip_blocked';
    public const REASON_PAYLOAD_TOO_LARGE = 'payload_too_large';
    public const REASON_METHOD_NOT_ALLOWED = 'method_not_allowed';

    /** Default HTTP verbs accepted for state-changing requests. */
    public const ALLOWED_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    // -----------------------------------------------------------------------
    // Configuration
    // -----------------------------------------------------------------------

    /** @var bool Master switch; when false every check short-circuits. */
    protected static bool $enabled = true;

    /** @var bool When true a detected threat aborts with 403 instead of merely being reported. */
    protected static bool $blockOnViolation = true;

    /** @var bool When true pattern hits are reported but never blocked. */
    protected static bool $logOnly = false;

    /** @var bool When true a malicious payload is scrubbed from input instead of rejected. */
    protected static bool $autoSanitize = false;

    /** @var array<int, string> IPv4/IPv6 addresses always denied. */
    protected static array $blockedIps = [];

    /** @var array<int, string> IPv4/IPv6 addresses always allowed (skip rate limiting). */
    protected static array $allowedIps = [];

    /** @var int Requests permitted per IP inside $rateDecay. */
    protected static int $rateLimit = self::DEFAULT_RATE_LIMIT;

    /** @var int Decay window in seconds for the IP rate limiter. */
    protected static int $rateDecay = self::DEFAULT_RATE_DECAY;

    /** @var array<int, string> HTTP methods accepted for this deployment. */
    protected static array $allowedMethods = self::ALLOWED_METHODS;

    /** @var bool Also apply XSS filtering to response bodies. */
    protected static bool $filterOutput = false;

    /** @var int Body size ceiling in bytes. */
    protected static int $maxPayloadBytes = self::MAX_PAYLOAD_BYTES;

    /** @var array<string, string> Additional user-supplied pattern => reason. */
    protected static array $customPatterns = [];

    /** @var array<int, string> Superglobal keys never inspected (e.g. trusted webhooks). */
    protected static array $excludedKeys = [];

    // -----------------------------------------------------------------------
    // SQL injection signatures
    // -----------------------------------------------------------------------

    /**
     * SQL injection signatures. Ordered by specificity - statement terminators
     * and UNION-based extraction first, then comment/truncation and tautology
     * tricks. The patterns run against the lowercased payload so obfuscation by
     * case is not an escape.
     *
     * @var array<int, string>
     */
    protected static array $sqlPatterns = [
        // UNION-based data extraction
        '/union\s+(all\s+)?select\b/i',
        // Stacked / terminator attacks
        '/;\s*(drop|delete|insert|update|alter|create|truncate|exec(ute)?|call)\b/i',
        '/\bdrop\s+(table|database|schema|view|procedure)\b/i',
        '/\btruncate\s+table\b/i',
        '/\binsert\s+into\b.+\bvalues\b/i',
        // Comment-based truncation of the original query
        '/(\%27|\'|\%22|"|\))\s*(--|#|\/\*)/i',
        '/\b(or|and)\s+\'?[\w\d\%]+\'?(\s*=\s*\'?[\w\d\%]+\'?|\s+like\s+\'?)/i',
        '/\b(or|and)\s+1\s*=\s*1\b/i',
        '/\bsleep\s*\(/i',
        '/\bbenchmark\s*\(/i',
        '/\bwaitfor\s+delay\b/i',
        '/\bload_file\s*\(/i',
        '/\binto\s+(outfile|dumpfile)\b/i',
        '/\binformation_schema\b/i',
        '/\bconcat\s*\(/i',
        '/\bchar\s*\(\s*\d+\s*\)/i',
        '/\b0x[0-9a-f]{8,}\b/i',
    ];

    // -----------------------------------------------------------------------
    // XSS signatures
    // -----------------------------------------------------------------------

    /**
     * XSS signatures covering script injection, event handlers, javascript:
     * URLs, and encoding tricks used to slip past naive filters.
     *
     * @var array<int, string>
     */
    protected static array $xssPatterns = [
        '/<\s*script\b/i',
        '/<\s*\/\s*script\s*>/i',
        '/<\s*iframe\b/i',
        '/<\s*object\b/i',
        '/<\s*embed\b/i',
        '/<\s*(img|svg|video|audio|body|details|marquee)\b[^>]*\bon[a-z]+\s*=/i',
        '/\bon(error|load|click|mouseover|mousedown|focus|blur|submit|change|animationstart|toggle)\s*=/i',
        '/(javascript|vbscript|data)\s*:/i',
        '/expression\s*\(/i',
        '/<\s*link\b[^>]*\brel\s*=\s*[\'"]?stylesheet/i',
        '/<\s*meta\b[^>]*\bhttp-equiv/i',
        '/(document|window|eval|alert|prompt|confirm)\s*[\.\(]/i',
        '/%3c\s*script/i',
        '/\\x3c\s*script/i',
        '/<\s*img\b[^>]*\bsrc\s*=\s*[\'"]?data:/i',
    ];

    // -----------------------------------------------------------------------
    // Configuration
    // -----------------------------------------------------------------------

    /**
     * Enable or disable the firewall. Disabled = every inspection passes.
     */
    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
    }

    /**
     * Whether the firewall is active.
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Set the IPs that are always denied.
     *
     * @param array<int, string> $ips
     */
    public static function setBlockedIps(array $ips): void
    {
        self::$blockedIps = array_values(array_unique(array_filter(array_map('trim', $ips))));
    }

    /**
     * Set the IPs that bypass rate limiting.
     *
     * @param array<int, string> $ips
     */
    public static function setAllowedIps(array $ips): void
    {
        self::$allowedIps = array_values(array_unique(array_filter(array_map('trim', $ips))));
    }

    /**
     * Configure the per-IP rate limiter.
     */
    public static function setRateLimit(int $maxAttempts = self::DEFAULT_RATE_LIMIT, int $decaySeconds = self::DEFAULT_RATE_DECAY): void
    {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('Rate limit must be at least 1 attempt.');
        }
        if ($decaySeconds < 1) {
            throw new \InvalidArgumentException('Rate decay must be at least 1 second.');
        }
        self::$rateLimit = $maxAttempts;
        self::$rateDecay = $decaySeconds;
    }

    /**
     * Restrict the accepted HTTP verbs.
     *
     * @param array<int, string> $methods
     */
    public static function setAllowedMethods(array $methods): void
    {
        self::$allowedMethods = array_map('strtoupper', $methods);
    }

    /**
     * Register an extra detection pattern.
     *
     * @param string $reason Canonical reason string, e.g. 'command_injection'
     * @param string $pattern PCRE pattern (without delimiters)
     */
    public static function addPattern(string $reason, string $pattern): void
    {
        if (@preg_match('/' . str_replace('/', '\/', $pattern) . '/i', '') === false) {
            throw new \InvalidArgumentException("Invalid WAF pattern: {$pattern}");
        }
        self::$customPatterns[$reason] = $pattern;
    }

    /**
     * Register input keys that must never be inspected.
     *
     * @param array<int, string> $keys
     */
    public static function excludeKeys(array $keys): void
    {
        self::$excludedKeys = array_values(array_unique($keys));
    }

    /**
     * Apply configuration from the WAF_* environment variables.
     */
    public static function configureFromEnvironment(): void
    {
        if (array_key_exists('WAF_ENABLED', $_ENV)) {
            self::$enabled = env('WAF_ENABLED', true);
        }
        if (array_key_exists('WAF_RATE_LIMIT', $_ENV)) {
            self::$rateLimit = (int) env('WAF_RATE_LIMIT', self::DEFAULT_RATE_LIMIT);
        }
        if (array_key_exists('WAF_RATE_DECAY', $_ENV)) {
            self::$rateDecay = (int) env('WAF_RATE_DECAY', self::DEFAULT_RATE_DECAY);
        }
        if (array_key_exists('WAF_MAX_PAYLOAD', $_ENV)) {
            self::$maxPayloadBytes = (int) env('WAF_MAX_PAYLOAD', self::MAX_PAYLOAD_BYTES);
        }

        $blocked = env('WAF_BLOCKED_IPS', '');
        if (is_string($blocked) && $blocked !== '') {
            self::setBlockedIps(explode(',', $blocked));
        }

        $allowed = env('WAF_ALLOWED_IPS', '');
        if (is_string($allowed) && $allowed !== '') {
            self::setAllowedIps(explode(',', $allowed));
        }
    }

    // -----------------------------------------------------------------------
    // Inspection
    // -----------------------------------------------------------------------

    /**
     * Inspect a request (defaults to the current PHP request) and decide whether
     * it may proceed.
     *
     * @param array<string, mixed>|null $request Optional request bag; uses superglobals when null
     * @return array{
     *     allowed: bool,
     *     reason: string|null,
     *     threats: array<int, array{type: string, matched: string, field: string}>
     * }
     */
    public static function inspect(?array $request = null): array
    {
        $request = $request ?? self::currentRequest();
        $threats = [];

        // The firewall is a no-op when disabled or when nothing is configured.
        if (!self::$enabled) {
            return ['allowed' => true, 'reason' => null, 'threats' => []];
        }

        $method = strtoupper((string) ($request['method'] ?? $_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, self::$allowedMethods, true)) {
            $threats[] = ['type' => 'method', 'matched' => $method, 'field' => 'REQUEST_METHOD'];
            return self::verdict(false, self::REASON_METHOD_NOT_ALLOWED, $threats);
        }

        $ip = self::clientIp();
        if (self::isIpBlocked($ip)) {
            $threats[] = ['type' => 'ip_blocked', 'matched' => $ip, 'field' => 'REMOTE_ADDR'];
            return self::verdict(false, self::REASON_IP_BLOCKED, $threats);
        }

        if (!self::isIpAllowed($ip) && !self::checkRateLimit($ip)) {
            $threats[] = ['type' => 'rate_limit', 'matched' => (string) self::requestCount($ip), 'field' => 'REMOTE_ADDR'];
            return self::verdict(false, self::REASON_RATE_LIMIT, $threats);
        }

        $payload = self::collectPayload($request);
        if (strlen($payload) > self::$maxPayloadBytes) {
            $threats[] = ['type' => 'payload', 'matched' => (string) strlen($payload), 'field' => 'BODY'];
            return self::verdict(false, self::REASON_PAYLOAD_TOO_LARGE, $threats);
        }

        $uri = (string) ($request['uri'] ?? $_SERVER['REQUEST_URI'] ?? '/');
        if (strlen($uri) > self::MAX_URI_LENGTH) {
            $threats[] = ['type' => 'uri_length', 'matched' => (string) strlen($uri), 'field' => 'REQUEST_URI'];
            return self::verdict(false, self::REASON_PAYLOAD_TOO_LARGE, $threats);
        }

        $found = self::scan($payload);
        foreach ($found as $hit) {
            $threats[] = ['type' => $hit['type'], 'matched' => $hit['matched'], 'field' => $hit['field']];
        }

        if (!empty($threats)) {
            self::recordThreat($ip, $threats);
            if (!self::$logOnly) {
                return self::verdict(false, $threats[0]['type'], $threats);
            }
        }

        return self::verdict(true, null, $threats);
    }

    /**
     * Inspect the current request and terminate with 403 when it is rejected.
     */
    public static function guard(): void
    {
        $result = self::inspect();

        if ($result['allowed']) {
            return;
        }

        Security::logEvent('waf.blocked', [
            'reason' => $result['reason'],
            'ip' => self::clientIp(),
            'uri' => $_SERVER['REQUEST_URI'] ?? '/',
        ], 'warning');

        if (!self::$logOnly) {
            self::forbiddenResponse($result['reason']);
        }
    }

    /**
     * Inspect a single value for injection/XSS content without blocking.
     *
     * @return array{clean: bool, threats: array<int, array{type: string, matched: string}>}
     */
    public static function check(string $value): array
    {
        $hits = [];
        foreach (self::scan((string) $value) as $hit) {
            $hits[] = ['type' => $hit['type'], 'matched' => $hit['matched']];
        }

        return ['clean' => empty($hits), 'threats' => $hits];
    }

    /**
     * Whether a value contains SQL injection or XSS content.
     */
    public static function isMalicious(string $value): bool
    {
        return !empty(self::scan($value));
    }

    // -----------------------------------------------------------------------
    // Signature scanning
    // -----------------------------------------------------------------------

    /**
     * Scan every configured signature against a payload.
     *
     * @return array<int, array{type: string, matched: string, field: string}>
     */
    protected static function scan(string $payload): array
    {
        if ($payload === '') {
            return [];
        }

        $hits = [];

        foreach (self::$sqlPatterns as $pattern) {
            if (($matched = self::matchPattern($pattern, $payload)) !== null) {
                $hits[] = ['type' => self::REASON_SQL_INJECTION, 'matched' => $matched, 'field' => 'payload'];
            }
        }

        foreach (self::$xssPatterns as $pattern) {
            if (($matched = self::matchPattern($pattern, $payload)) !== null) {
                $hits[] = ['type' => self::REASON_XSS, 'matched' => $matched, 'field' => 'payload'];
            }
        }

        foreach (self::$customPatterns as $reason => $pattern) {
            if (($matched = self::matchPattern($pattern, $payload)) !== null) {
                $hits[] = ['type' => $reason, 'matched' => $matched, 'field' => 'payload'];
            }
        }

        return $hits;
    }

    /**
     * Run a single pattern and return a short, safe snippet of what matched.
     */
    protected static function matchPattern(string $pattern, string $payload): ?string
    {
        if (@preg_match($pattern, $payload, $matches) !== 1) {
            return null;
        }

        $snippet = $matches[0] ?? '';
        // Keep logs readable and bounded; never log more than the first 60 bytes.
        return substr($snippet, 0, 60);
    }

    // -----------------------------------------------------------------------
    // Request assembly
    // -----------------------------------------------------------------------

    /**
     * Flatten the current request into the request bag used by inspect().
     *
     * @return array<string, mixed>
     */
    protected static function currentRequest(): array
    {
        return [
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'uri' => $_SERVER['REQUEST_URI'] ?? '/',
            'ip' => self::clientIp(),
            'headers' => self::requestHeaders(),
            'query' => $_GET,
            'body' => $_POST,
            'raw' => file_get_contents('php://input') ?: '',
        ];
    }

    /**
     * Collect the inspectable text of a request into one string.
     */
    protected static function collectPayload(array $request): string
    {
        $parts = [];

        foreach (['query', 'body'] as $bag) {
            if (!empty($request[$bag]) && is_array($request[$bag])) {
                $parts[] = self::flatten($request[$bag]);
            }
        }

        if (!empty($request['raw']) && is_string($request['raw'])) {
            $parts[] = $request['raw'];
        }

        if (!empty($request['headers']) && is_array($request['headers'])) {
            $parts[] = self::flatten($request['headers']);
        }

        // The URI is inspected for path-based injection attempts too.
        if (!empty($request['uri']) && is_string($request['uri'])) {
            $parts[] = $request['uri'];
        }

        return implode("\n", array_filter($parts, fn ($p) => $p !== ''));
    }

    /**
     * Recursively flatten an input bag to a single inspectable string,
     * skipping keys registered via excludeKeys().
     */
    protected static function flatten(mixed $value, string $path = ''): string
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $childPath = $path === '' ? (string) $key : $path . '.' . $key;
                if (in_array($childPath, self::$excludedKeys, true)) {
                    continue;
                }
                $out[] = self::flatten($item, $childPath);
            }
            return implode("\n", $out);
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Normalise the request headers from $_SERVER into a name => value map.
     *
     * @return array<string, string>
     */
    protected static function requestHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || !is_string($value) || !str_starts_with($key, 'HTTP_')) {
                continue;
            }
            $name = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
            $headers[$name] = $value;
        }
        return $headers;
    }

    /**
     * Best-effort client IP.
     *
     * X-Forwarded-For is only honoured when the deployment has explicitly
     * configured trusted proxies (WAF_TRUSTED_PROXIES). Reading it
     * unconditionally lets any client spoof their address and bypass IP-based
     * blocking and rate limiting.
     */
    public static function clientIp(): string
    {
        $direct = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if (self::isTrustedProxy($direct)) {
            $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if (is_string($forwarded) && $forwarded !== '') {
                // The left-most entry is the original client; walk back while
                // the immediate peer is itself a trusted proxy.
                $chain = array_map('trim', explode(',', $forwarded));
                foreach ($chain as $candidate) {
                    if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                        continue;
                    }
                    if (!self::isTrustedProxy($candidate)) {
                        return $candidate;
                    }
                }
            }
        }

        return $direct;
    }

    /**
     * Whether an address belongs to the configured trusted-proxy list.
     */
    public static function isTrustedProxy(string $ip): bool
    {
        $configured = env('WAF_TRUSTED_PROXIES', '');
        if (!is_string($configured) || trim($configured) === '') {
            return false;
        }

        foreach (explode(',', $configured) as $range) {
            $range = trim($range);
            if ($range === '') {
                continue;
            }
            if (self::ipInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an IP falls inside a single address or CIDR range.
     */
    public static function ipInRange(string $ip, string $range): bool
    {
        if (!str_contains($range, '/')) {
            return $ip === $range;
        }

        [$subnet, $bits] = explode('/', $range, 2);
        $bits = (int) $bits;

        $ipLong = @ip2long($ip);
        $subnetLong = @ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        if ($bits < 0 || $bits > 32) {
            return false;
        }

        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    // -----------------------------------------------------------------------
    // IP blocking
    // -----------------------------------------------------------------------

    /**
     * Whether the given IP (or the current client) is on the deny list.
     */
    public static function isIpBlocked(?string $ip = null): bool
    {
        $ip = $ip ?? self::clientIp();
        if (in_array($ip, self::$blockedIps, true)) {
            return true;
        }

        // Persisted blocks survive across requests.
        $key = self::cacheKey('blocked', $ip);
        if (Cache::has($key)) {
            return true;
        }

        return false;
    }

    /**
     * Permanently deny an IP for $seconds (0 = forever).
     */
    public static function blockIp(string $ip, int $seconds = 0, string $reason = ''): void
    {
        if (!in_array($ip, self::$blockedIps, true)) {
            self::$blockedIps[] = $ip;
        }

        Cache::put(self::cacheKey('blocked', $ip), [
            'ip' => $ip,
            'reason' => $reason,
            'blocked_at' => time(),
            'expires_at' => $seconds > 0 ? time() + $seconds : null,
        ], $seconds > 0 ? $seconds : null);
    }

    /**
     * Remove an IP from the deny list.
     */
    public static function unblockIp(string $ip): void
    {
        self::$blockedIps = array_values(array_filter(
            self::$blockedIps,
            fn ($blocked) => $blocked !== $ip
        ));
        Cache::forget(self::cacheKey('blocked', $ip));
    }

    /**
     * Whether the IP is exempt from rate limiting.
     */
    public static function isIpAllowed(?string $ip = null): bool
    {
        return in_array($ip ?? self::clientIp(), self::$allowedIps, true);
    }

    // -----------------------------------------------------------------------
    // Rate limiting
    // -----------------------------------------------------------------------

    /**
     * Consume one request against the per-IP budget.
     *
     * @return bool True when the request is within budget.
     */
    public static function checkRateLimit(?string $ip = null): bool
    {
        $ip = $ip ?? self::clientIp();
        $key = self::cacheKey('throttle', $ip);

        $bucket = Cache::get($key);
        $now = time();

        if (!is_array($bucket) || ($bucket['reset'] ?? 0) < $now) {
            $bucket = ['count' => 0, 'reset' => $now + self::$rateDecay];
        }

        $bucket['count']++;

        Cache::put($key, $bucket, self::$rateDecay);

        return $bucket['count'] <= self::$rateLimit;
    }

    /**
     * How many requests the IP has used inside the current window.
     */
    public static function requestCount(?string $ip = null): int
    {
        $bucket = Cache::get(self::cacheKey('throttle', $ip ?? self::clientIp()));
        if (!is_array($bucket) || ($bucket['reset'] ?? 0) < time()) {
            return 0;
        }
        return (int) ($bucket['count'] ?? 0);
    }

    /**
     * Seconds until the current rate window resets.
     */
    public static function retryAfter(?string $ip = null): int
    {
        $bucket = Cache::get(self::cacheKey('throttle', $ip ?? self::clientIp()));
        if (!is_array($bucket)) {
            return 0;
        }
        return max(0, (int) ($bucket['reset'] ?? 0) - time());
    }

    /**
     * Reset the rate window for an IP.
     */
    public static function resetRateLimit(?string $ip = null): void
    {
        Cache::forget(self::cacheKey('throttle', $ip ?? self::clientIp()));
    }

    // -----------------------------------------------------------------------
    // Sanitization
    // -----------------------------------------------------------------------

    /**
     * Sanitize a request bag: strip control characters, null bytes, and neutralise
     * tags and quote-based escapes in every scalar value.
     *
     * The result is a *safe-for-output* copy; it is never written back to
     * $_GET/$_POST automatically (use applySanitizedInput() for that).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function sanitize(array $input): array
    {
        $sanitized = [];

        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = self::sanitize($value);
                continue;
            }

            if (!is_scalar($value)) {
                $sanitized[$key] = $value;
                continue;
            }

            $sanitized[$key] = self::sanitizeValue((string) $value);
        }

        return $sanitized;
    }

    /**
     * Sanitize a single scalar value.
     *
     * Markup is removed rather than escaped so free text never renders as HTML;
     * only whitespace and control characters are trimmed.
     */
    public static function sanitizeValue(string $value): string
    {
        // Drop null bytes and C0 control characters; keep tabs and newlines.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';

        // Strip HTML/XML/PHP tags entirely - never trust free text to be markup.
        $value = strip_tags($value);

        // Neutralise stray closing brackets so a truncated tag cannot recombine.
        $value = str_replace(['<', '>'], ['&lt;', '&gt;'], $value);

        return trim($value);
    }

    /**
     * Remove SQL injection and XSS signatures from a value, preserving the rest
     * of the content. Use sanitizeValue() when the value is pure text.
     */
    public static function stripMalicious(string $value): string
    {
        foreach (array_merge(self::$sqlPatterns, self::$xssPatterns, array_values(self::$customPatterns)) as $pattern) {
            $value = @preg_replace($pattern, '', $value);
        }

        return self::sanitizeValue($value ?? '');
    }

    /**
     * Sanitize the live superglobals in place.
     *
     * @param array<int, string> $keys Limit to these keys; empty = everything
     * @return array<string, mixed> The values that were replaced
     */
    public static function applySanitizedInput(array $keys = []): array
    {
        $replaced = [];

        foreach (['GET' => &$_GET, 'POST' => &$_POST, 'REQUEST' => &$_REQUEST] as $name => &$bag) {
            if (!is_array($bag)) {
                continue;
            }

            foreach ($bag as $key => $value) {
                if (!empty($keys) && !in_array($key, $keys, true)) {
                    continue;
                }
                if (!is_string($value)) {
                    continue;
                }

                $clean = self::sanitizeValue($value);
                if ($clean !== $value) {
                    $bag[$key] = $clean;
                    $replaced[$name][$key] = $clean;
                }
            }
        }
        unset($bag);

        return $replaced;
    }

    /**
     * Escape a value for HTML output.
     */
    public static function escapeHtml(string $value): string
    {
        return Security::escape($value);
    }

    // -----------------------------------------------------------------------
    // Response helpers
    // -----------------------------------------------------------------------

    /**
     * Build the 403 body for a rejected request.
     */
    public static function forbiddenResponse(?string $reason = null): void
    {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('Retry-After: ' . self::retryAfter());

        echo 'Forbidden' . ($reason ? ': ' . $reason : '');
        exit;
    }

    /**
     * Build a 429 body for a rate-limited request.
     */
    public static function tooManyRequestsResponse(int $retryAfter): void
    {
        http_response_code(429);
        header('Content-Type: application/json; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('Retry-After: ' . max(1, $retryAfter));

        echo json_encode([
            'success' => false,
            'message' => 'Too many requests. Please try again later.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -----------------------------------------------------------------------
    // Verdict & bookkeeping
    // -----------------------------------------------------------------------

    /**
     * Turn a detection result into the standard verdict array.
     *
     * @param array<int, array{type: string, matched: string, field: string}> $threats
     * @return array{allowed: bool, reason: string|null, threats: array<int, array{type: string, matched: string, field: string}>}
     */
    protected static function verdict(bool $allowed, ?string $reason, array $threats): array
    {
        return [
            'allowed' => $allowed,
            'reason' => $reason,
            'threats' => $threats,
        ];
    }

    /**
     * Record a threat: bump the auto-block counter and write to the security log.
     *
     * @param array<int, array{type: string, matched: string, field: string}> $threats
     */
    protected static function recordThreat(string $ip, array $threats): void
    {
        $types = array_values(array_unique(array_map(fn ($t) => $t['type'], $threats)));

        Security::logEvent('waf.threat', [
            'ip' => $ip,
            'types' => $types,
            'uri' => $_SERVER['REQUEST_URI'] ?? '/',
            'count' => count($threats),
        ], 'warning');

        // Repeat offenders are denied for an escalating window.
        $strikeKey = self::cacheKey('strikes', $ip);
        $strikes = (int) Cache::get($strikeKey, 0);
        $strikes++;
        Cache::put($strikeKey, $strikes, 3600);

        if ($strikes >= 5) {
            self::blockIp($ip, min(86400, 3600 * $strikes), 'repeated_waf_violations');
        }
    }

    /**
     * Cache key for a namespaced WAF entry.
     */
    protected static function cacheKey(string $kind, string $subject): string
    {
        return self::CACHE_NAMESPACE . '_' . $kind . '_' . md5($subject);
    }

    // -----------------------------------------------------------------------
    // Diagnostics
    // -----------------------------------------------------------------------

    /**
     * Current firewall configuration, for health/debug pages.
     *
     * @return array<string, mixed>
     */
    public static function getStatus(): array
    {
        return [
            'enabled' => self::$enabled,
            'block_on_violation' => self::$blockOnViolation,
            'log_only' => self::$logOnly,
            'auto_sanitize' => self::$autoSanitize,
            'rate_limit' => self::$rateLimit,
            'rate_decay' => self::$rateDecay,
            'max_payload_bytes' => self::$maxPayloadBytes,
            'blocked_ips' => self::$blockedIps,
            'allowed_ips' => self::$allowedIps,
            'sql_patterns' => count(self::$sqlPatterns),
            'xss_patterns' => count(self::$xssPatterns),
            'custom_patterns' => count(self::$customPatterns),
            'current_ip' => self::clientIp(),
            'current_ip_blocked' => self::isIpBlocked(),
            'requests_this_window' => self::requestCount(),
            'retry_after' => self::retryAfter(),
        ];
    }
}
