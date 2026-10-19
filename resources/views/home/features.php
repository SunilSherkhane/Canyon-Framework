<!DOCTYPE html>
<html lang="en">
<head>
 <meta charset="UTF-8">
 <meta name="viewport" content="width=device-width, initial-scale=1.0">
 <meta http-equiv="X-UA-Compatible" content="IE=edge">
 <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net;">
 <title><?= $title ?? 'Features' ?> - Canyon Framework</title>
 <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
 <style>
  body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
  .container { max-width: 900px; margin: 4rem auto; }
  .card { border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
  .feature-row { border-bottom: 1px solid #eee; padding: 0.9rem 0; }
  .feature-row:last-child { border-bottom: none; }
  .feature-icon { font-size: 1.4rem; width: 2rem; text-align: center; }
  code { background: #f4f4f5; padding: 0.15rem 0.4rem; border-radius: 4px; font-size: 0.85rem; }
  .brand { font-size: 2.2rem; font-weight: 800; }
 </style>
</head>
<body>
 <div class="container">
  <div class="card">
   <div class="card-body p-4 p-md-5">
    <div class="d-flex justify-content-between align-items-start mb-4">
     <div>
      <h1 class="brand mb-1">🌊 CANYON</h1>
      <p class="text-muted mb-0">Minimal PHP Framework &mdash; Feature Set</p>
     </div>
     <a href="/" class="btn btn-outline-secondary btn-sm">&larr; Home</a>
    </div>

    <h2 class="h4 mb-3">Core Framework</h2>
    <div class="feature-row d-flex">
     <span class="feature-icon">🛣️</span>
     <div><strong>Router</strong><p class="text-muted mb-0 small">HTTP method allow-list, URI sanitisation (null bytes, control chars, length limits), <code>{param}</code> patterns, catch-all support and route names.</p></div>
    </div>
    <div class="feature-row d-flex">
     <span class="feature-icon">🎮</span>
     <div><strong>Controllers</strong><p class="text-muted mb-0 small">Base controller with view rendering, JSON responses, validated redirects and a consistent error-response API.</p></div>
    </div>
    <div class="feature-row d-flex">
     <span class="feature-icon">🧩</span>
     <div><strong>Views</strong><p class="text-muted mb-0 small">PHP templates with automatic output escaping, path-traversal protection and an explicit <code>View::raw()</code> escape hatch for trusted HTML.</p></div>
    </div>
    <div class="feature-row d-flex">
     <span class="feature-icon">⚙️</span>
     <div><strong>Configuration</strong><p class="text-muted mb-0 small">Environment-driven config via <code>.env</code> with a safe <code>env()</code> helper and typed coercion.</p></div>
    </div>
    <div class="feature-row d-flex">
     <span class="feature-icon">🧱</span>
     <div><strong>Middleware</strong><p class="text-muted mb-0 small">Abstract middleware contract with route groups for composing cross-cutting concerns.</p></div>
    </div>

    <hr class="my-4">

    <h2 class="h4 mb-3">Security Controls</h2>
    <div class="feature-row d-flex">
     <span class="feature-icon">🛡️</span>
     <div><strong>CSRF Protection</strong><p class="text-muted mb-0 small">Per-session tokens, timing-safe <code>hash_equals()</code> verification and one-hour expiry.</p></div>
    </div>
    <div class="feature-row d-flex">
     <span class="feature-icon">✖️</span>
     <div><strong>XSS Prevention</strong><p class="text-muted mb-0 small">Automatic <code>htmlspecialchars()</code> escaping on all view data, plus <code>JSON_HEX_*</code> flags on JSON output.</p></div>
    </div>
    <div class="feature-row d-flex">
     <span class="feature-icon">↪️</span>
     <div><strong>Open Redirect Prevention</strong><p class="text-muted mb-0 small">Redirect URLs are restricted to same-site relative paths; protocol-relative and absolute URLs are rejected.</p></div>
    </div>
    <div class="feature-row d-flex">
     <span class="feature-icon">🌐</span>
     <div><strong>Host Header Validation</strong><p class="text-muted mb-0 small">Configurable allow-list with wildcard subdomains, port stripping and rejection of suspicious hosts.</p></div>
    </div>
    <div class="feature-row d-flex">
     <span class="feature-icon">🔒</span>
     <div><strong>Security Headers</strong><p class="text-muted mb-0 small">CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy and HSTS.</p></div>
    </div>
    <div class="feature-row d-flex">
     <span class="feature-icon">⏱️</span>
     <div><strong>Rate Limiting</strong><p class="text-muted mb-0 small">Per-session throttling with configurable attempts and decay windows.</p></div>
    </div>
    <div class="feature-row d-flex">
     <span class="feature-icon">🧼</span>
     <div><strong>Input Sanitisation</strong><p class="text-muted mb-0 small">Strict validation rules using <code>FILTER_VALIDATE_*</code> &mdash; no deprecated filters.</p></div>
    </div>
    <div class="feature-row d-flex">
     <span class="feature-icon">📝</span>
     <div><strong>Security Logging</strong><p class="text-muted mb-0 small">Structured event logging for CSRF failures, rate-limit breaches and form submissions.</p></div>
    </div>
    <div class="feature-row d-flex">
     <span class="feature-icon">🍪</span>
     <div><strong>Session Hardening</strong><p class="text-muted mb-0 small">HttpOnly, SameSite=Lax, strict mode, custom session name and storage outside the web root.</p></div>
    </div>

    <div class="alert alert-info mt-4 mb-0 small">
     <strong>Developer test page:</strong> available at <code>/test</code> while <code>APP_DEBUG</code> is enabled.
     It reports security diagnostics and is unreachable in production.
    </div>
   </div>
  </div>
 </div>
 <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
   integrity="sha384-q2LVbc1mSGgj6HBqIi6j4Lx6cGJWpG5r4Jb8X8Y5L5s5hZ5j5v5Y5X5Z5a5b5c5d"
   crossorigin="anonymous"></script>
</body>
</html>