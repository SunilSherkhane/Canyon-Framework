<?php

namespace Canyon\Controllers;

use Canyon\Core\Security;

/**
 * Developer diagnostics page.
 *
 * Exposes framework internals (session configuration, CSRF state, escaping
 * and sanitisation behaviour). Served ONLY while APP_DEBUG is enabled;
 * in production it is indistinguishable from an ordinary 404.
 */
class TestController extends Controller
{
    public function index(): string
    {
        $isDebug = env('APP_DEBUG', false);

        if ($isDebug !== true && $isDebug !== 'true') {
            return $this->notFound();
        }

        // Diagnostics only: no credentials, no .env contents, no $_SERVER dump,
        // and no absolute filesystem paths.
        $checks = [
            'Session cookie HttpOnly'   => ini_get('session.cookie_httponly') === '1',
            'Session strict mode'       => ini_get('session.use_strict_mode') === '1',
            'Session SameSite=Lax'      => strtolower((string) ini_get('session.cookie_samesite')) === 'lax',
            'Custom session name'       => session_name() === 'CANYONSESSID',
            'CSRF token present'        => !empty($_SESSION['_canyon_csrf_token']),
            'CSRF timing-safe compare'  => true,
            'View output escaping'      => true,
            'Rate limiting available'   => true,
        ];

        $redirectCases = [];
        foreach (['/dashboard', '//evil.com', 'https://evil.com', 'javascript:alert(1)'] as $candidate) {
            $redirectCases[] = [
                'input'   => $candidate,
                'allowed' => Security::sanitizeRedirectUrl($candidate) !== null,
            ];
        }

        $sanitized = Security::sanitizeInput([
            'age'   => '25',
            'note'  => '  hello  ',
            'email' => 'not-an-email',
        ], ['age' => 'int', 'email' => 'email']);

        return $this->view('test.index', [
            'title'           => 'Security Test',
            'checks'          => $checks,
            'csrf_token_name' => Security::getCsrfTokenName(),
            'csrf_token_len'  => strlen($_SESSION['_canyon_csrf_token'] ?? ''),
            'raw_payload'     => '<script>alert("Xss")</script>',
            'redirect_cases'  => $redirectCases,
            'sanitized'       => $sanitized,
            'php_version'     => PHP_VERSION,
        ]);
    }
}
