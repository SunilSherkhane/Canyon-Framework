<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Response (Symfony HTTP Foundation Inspired)
 *
 * Clean response object with chainable methods for JSON, views, redirects.
 */
class Response
{
    /** @var string Response content */
    protected string $content;

    /** @var int HTTP status code */
    protected int $statusCode;

    /** @var array Response headers */
    protected array $headers;

    /** @var string Content type */
    protected string $contentType;

    /** @var array Cookies to set */
    protected array $cookies = [];

    /**
     * Constructor.
     */
    public function __construct(string $content = '', int $statusCode = 200, array $headers = [])
    {
        $this->content = $content;
        $this->statusCode = $statusCode;
        $this->headers = $headers;
        $this->contentType = $headers['Content-Type'] ?? 'text/html; charset=UTF-8';
    }

    // -----------------------------------------------------------------------
    // Factory Methods
    // -----------------------------------------------------------------------

    public static function make(string $content = '', int $statusCode = 200, array $headers = []): self
    {
        return new self($content, $statusCode, $headers);
    }

    public static function json(array $data, int $statusCode = 200, array $headers = []): self
    {
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        $content = json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
        return new self($content, $statusCode, $headers);
    }

    public static function view(string $view, array $data = [], int $statusCode = 200, array $headers = []): self
    {
        $content = View::render($view, $data);
        $headers['Content-Type'] = 'text/html; charset=utf-8';
        return new self($content, $statusCode, $headers);
    }

    public static function redirect(string $url, int $statusCode = 302, array $headers = []): self
    {
        $headers['Location'] = $url;
        return new self('', $statusCode, $headers);
    }

    public static function back(int $statusCode = 302): self
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '/';
        return self::redirect($referer, $statusCode);
    }

    public static function download(string $filePath, ?string $filename = null, array $headers = []): self
    {
        if (!file_exists($filePath)) {
            return self::json(['error' => 'File not found'], 404);
        }

        $filename = $filename ?? basename($filePath);
        $mimeType = mime_content_type($filePath) ?? 'application/octet-stream';

        $headers['Content-Type'] = $mimeType;
        $headers['Content-Length'] = (string) filesize($filePath);
        $headers['Content-Disposition'] = 'attachment; filename="' . $filename . '"';
        $headers['Cache-Control'] = 'no-cache, must-revalidate';

        return new self(file_get_contents($filePath), 200, $headers);
    }

    public static function file(string $filePath, ?string $filename = null, array $headers = []): self
    {
        if (!file_exists($filePath)) {
            return self::json(['error' => 'File not found'], 404);
        }

        $filename = $filename ?? basename($filePath);
        $mimeType = mime_content_type($filePath) ?? 'application/octet-stream';

        $headers['Content-Type'] = $mimeType;
        $headers['Content-Length'] = (string) filesize($filePath);
        $headers['Content-Disposition'] = 'inline; filename="' . $filename . '"';

        return new self(file_get_contents($filePath), 200, $headers);
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    public static function error(int $statusCode, string $message = ''): self
    {
        $defaultMessages = [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
        ];

        $message = $message ?: ($defaultMessages[$statusCode] ?? 'Error');

        return self::json([
            'error' => [
                'code' => $statusCode,
                'message' => $message,
            ],
        ], $statusCode);
    }

    // -----------------------------------------------------------------------
    // Chainable Methods
    // -----------------------------------------------------------------------

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function withHeaders(array $headers): self
    {
        $this->headers = array_merge($this->headers, $headers);
        return $this;
    }

    public function cookie(string $name, string $value, int $expires = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httpOnly = true, string $sameSite = 'Lax'): self
    {
        $this->cookies[] = compact('name', 'value', 'expires', 'path', 'domain', 'secure', 'httpOnly', 'sameSite');
        return $this;
    }

    public function withoutCookie(string $name, string $path = '/', string $domain = ''): self
    {
        return $this->cookie($name, '', time() - 3600, $path, $domain);
    }

    public function status(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    // -----------------------------------------------------------------------
    // Getters
    // -----------------------------------------------------------------------

    public function getContent(): string
    {
        return $this->content;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getCookies(): array
    {
        return $this->cookies;
    }

    // -----------------------------------------------------------------------
    // Send
    // -----------------------------------------------------------------------

    /**
     * Send the response to the client.
     */
    public function send(): void
    {
        // Send status code
        http_response_code($this->statusCode);

        // Send headers
        foreach ($this->headers as $name => $value) {
            if ($name === 'Content-Type') {
                header("Content-Type: $value");
            } else {
                header("$name: $value");
            }
        }

        // Send cookies
        foreach ($this->cookies as $cookie) {
            setcookie(
                $cookie['name'],
                $cookie['value'],
                [
                    'expires' => $cookie['expires'],
                    'path' => $cookie['path'],
                    'domain' => $cookie['domain'],
                    'secure' => $cookie['secure'],
                    'httponly' => $cookie['httpOnly'],
                    'samesite' => $cookie['sameSite'],
                ]
            );
        }

        // Send content
        echo $this->content;
    }
}