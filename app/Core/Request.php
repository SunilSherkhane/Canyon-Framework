<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Request (Symfony HTTP Foundation Inspired)
 *
 * Wraps PHP superglobals into a clean, immutable request object.
 */
class Request
{
    /** @var string HTTP method */
    protected string $method;

    /** @var string Request URI path */
    protected string $path;

    /** @var string Full URL */
    protected string $url;

    /** @var string|null Client IP */
    protected ?string $clientIp;

    /** @var array Server parameters */
    protected array $server;

    /** @var array Query parameters */
    protected array $query;

    /** @var array Request body parameters */
    protected array $request;

    /** @var array POST files */
    protected array $files;

    /** @var array Cookies */
    protected array $cookies;

    /** @var array Headers */
    protected array $headers;

    /** @var string|null Raw body content */
    protected ?string $content;

    /** @var string Content type */
    protected string $contentType;

    /** @var bool Whether request is AJAX */
    protected bool $isAjax;

    /** @var bool Whether request expects JSON */
    protected bool $wantsJson;

    /** @var string|null Preferred language */
    protected ?string $locale;

    /**
     * Create from PHP globals.
     */
    public static function createFromGlobals(): self
    {
        $request = new self();
        $request->server = $_SERVER;
        $request->query = $_GET;
        $request->request = $_POST;
        $request->files = $_FILES;
        $request->cookies = $_COOKIE;

        $request->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $request->path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $request->url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
            . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
            . ($_SERVER['REQUEST_URI'] ?? '/');
        $request->clientIp = $_SERVER['REMOTE_ADDR'] ?? null;
        $request->content = file_get_contents('php://input') ?: null;
        $request->contentType = $_SERVER['CONTENT_TYPE'] ?? 'text/html';
        $request->isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
        $request->wantsJson = str_contains($request->contentType, 'application/json')
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
        $request->locale = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;

        // Parse headers
        $request->headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
                $request->headers[$header] = $value;
            }
        }

        return $request;
    }

    // -----------------------------------------------------------------------
    // Getters
    // -----------------------------------------------------------------------

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getClientIp(): ?string
    {
        return $this->clientIp;
    }

    public function getContentType(): string
    {
        return $this->contentType;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function getJsonContent(): array
    {
        if ($this->content === null) return [];
        return json_decode($this->content, true) ?? [];
    }

    public function isAjax(): bool
    {
        return $this->isAjax;
    }

    public function wantsJson(): bool
    {
        return $this->wantsJson;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): ?string
    {
        $name = ucwords(strtolower(str_replace('_', '-', $name)), '-');
        return $this->headers[$name] ?? null;
    }

    public function getScheme(): string
    {
        return (isset($this->server['HTTPS']) && $this->server['HTTPS'] === 'on') ? 'https' : 'http';
    }

    public function getHost(): string
    {
        return $this->server['HTTP_HOST'] ?? 'localhost';
    }

    public function getPort(): int
    {
        return (int) ($this->server['SERVER_PORT'] ?? ($this->getScheme() === 'https' ? 443 : 80));
    }

    public function getBaseUrl(): string
    {
        return $this->getScheme() . '://' . $this->getHost();
    }

    public function getUserAgent(): string
    {
        return $this->headers['User-Agent'] ?? '';
    }

    public function getReferer(): ?string
    {
        return $this->headers['Referer'] ?? null;
    }

    // -----------------------------------------------------------------------
    // Parameter Access
    // -----------------------------------------------------------------------

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->request[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->request[$key] ?? $this->query[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->request);
    }

    public function only(array $keys): array
    {
        return array_intersect_key($this->all(), array_flip($keys));
    }

    public function except(array $keys): array
    {
        return array_diff_key($this->all(), array_flip($keys));
    }

    public function has(string $key): bool
    {
        return isset($this->request[$key]) || isset($this->query[$key]);
    }

    public function filled(string $key): bool
    {
        $value = $this->input($key);
        return $value !== null && $value !== '';
    }

    public function hasFile(string $key): bool
    {
        return isset($this->files[$key]) && $this->files[$key]['error'] === UPLOAD_ERR_OK;
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function isGet(): bool
    {
        return $this->method === 'GET';
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function isPut(): bool
    {
        return $this->method === 'PUT';
    }

    public function isPatch(): bool
    {
        return $this->method === 'PATCH';
    }

    public function isDelete(): bool
    {
        return $this->method === 'DELETE';
    }

    public function isSecure(): bool
    {
        return $this->getScheme() === 'https';
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    public function ip(): ?string
    {
        // Check for proxy headers
        $forwarded = $this->getHeader('X-Forwarded-For');
        if ($forwarded) {
            $ips = explode(',', $forwarded);
            return trim($ips[0]);
        }
        return $this->clientIp;
    }

    public function segment(int $index, ?string $default = null): ?string
    {
        $segments = explode('/', trim($this->path, '/'));
        return $segments[$index] ?? $default;
    }

    public function route(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query['_route'] ?? null;
        }
        return $this->query['_route'][$key] ?? $default;
    }

    /**
     * Get bearer token from Authorization header.
     */
    public function bearerToken(): ?string
    {
        $header = $this->getHeader('Authorization');
        if ($header && str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }
        return null;
    }

    /**
     * Validate the request against rules.
     */
    public function validate(array $rules): array
    {
        $validator = new Validation($this->all(), $rules);
        if ($validator->fails()) {
            $this->json(['errors' => $validator->errors()], 422);
        }
        return $validator->validated();
    }
}