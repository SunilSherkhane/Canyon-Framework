<?php

namespace Canyon\Core;

/**
 * Canyon Framework - HMAC Signatures
 *
 * HMAC message authentication for signed URLs and data integrity.
 * Elgg's HMAC implementation.
 */
class Hmac
{
    /** @var string HMAC key */
    protected static string $key;

    /**
     * Initialize HMAC with a key.
     */
    public static function init(): void
    {
        self::$key = $_ENV['APP_KEY'] ?? 'base64:';
        if (str_starts_with(self::$key, 'base64:')) {
            self::$key = base64_decode(substr(self::$key, 7));
        }
        // Ensure key is at least 32 bytes
        self::$key = strlen(self::$key) >= 32 ? self::$key : str_pad(self::$key, 32, "\0");
    }

    /**
     * Generate an HMAC token for data.
     *
     * @param array $data Data to sign
     * @return string HMAC token
     */
    public static function generate(array $data): string
    {
        self::init();
        $serialized = serialize($data);
        return hash_hmac('sha256', $serialized, self::$key);
    }

    /**
     * Validate an HMAC token against data.
     *
     * @param array $data Data to validate
     * @param string $token HMAC token
     * @return bool
     */
    public static function validate(array $data, string $token): bool
    {
        self::init();
        $expected = self::generate($data);
        return hash_equals($expected, $token);
    }

    /**
     * Create a signed URL.
     *
     * @param string $url URL to sign
     * @param array $params Additional parameters
     * @return string Signed URL
     */
    public static function signedUrl(string $url, array $params = []): string
    {
        $params['mac'] = self::generate($params);
        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . http_build_query($params);
    }

    /**
     * Validate a signed URL.
     *
     * @param string $url URL with query parameters
     * @return bool
     */
    public static function validateSignedUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!isset($parts['query'])) {
            return false;
        }

        parse_str($parts['query'], $params);

        if (!isset($params['mac'])) {
            return false;
        }

        $mac = $params['mac'];
        unset($params['mac']);

        return self::validate($params, $mac);
    }

    /**
     * Generate a CSRF token with timestamp.
     *
     * @param int|null $timestamp Custom timestamp
     * @return array ['token' => string, 'ts' => int]
     */
    public static function generateActionToken(?int $timestamp = null): array
    {
        self::init();
        $ts = $timestamp ?? time();
        $token = hash_hmac('sha256', $ts . session_id(), self::$key);
        return ['token' => $token, 'ts' => $ts];
    }

    /**
     * Validate an action token.
     */
    public static function validateActionToken(string $token, int $ts): bool
    {
        self::init();

        // Token expires after 2 hours
        if (time() - $ts > 7200) {
            return false;
        }

        $expected = hash_hmac('sha256', $ts . session_id(), self::$key);
        return hash_equals($expected, $token);
    }

    /**
     * Get the current HMAC key (for JavaScript access).
     */
    public static function getKey(): string
    {
        self::init();
        return self::$key;
    }

    /**
     * Generate a random token.
     */
    public static function randomToken(int $length = 32): string
    {
        return bin2hex(random_bytes($length / 2));
    }
}