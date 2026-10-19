<!DOCTYPE html>
<html lang="en">
<head>
 <meta charset="UTF-8">
 <meta name="viewport" content="width=device-width, initial-scale=1.0">
 <meta http-equiv="X-UA-Compatible" content="IE=edge">
 <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net;">
 <title><?= $title ?? 'Test' ?> - Canyon Framework</title>
 <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
 <style>
  body { background: #f8f9fa; }
  .container { max-width: 900px; margin: 3rem auto; }
  .card { border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
  .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.82rem; }
  .check-row { border-bottom: 1px solid #eee; padding: 0.7rem 0; }
  .check-row:last-child { border-bottom: none; }
  .escaped-box { background: #f4f4f5; border-radius: 8px; padding: 0.9rem; word-break: break-all; }
 </style>
</head>
<body>
 <div class="container">
  <div class="card">
   <div class="card-body p-4 p-md-5">

    <div class="d-flex justify-content-between align-items-start mb-3">
     <div>
      <h1 class="mb-1">🧪 Security Diagnostics</h1>
      <p class="text-muted mb-0">Canyon Framework &mdash; developer test page</p>
     </div>
     <a href="/" class="btn btn-outline-secondary btn-sm">&larr; Home</a>
    </div>

    <div class="alert alert-warning small py-2">
     <strong>Development only.</strong> This page reports framework internals and is served
     solely while <code>APP_DEBUG</code> is enabled. In production it returns a normal 404.
    </div>

    <div class="row">
     <div class="col-md-6">
      <h2 class="h5 mb-3">Security Controls</h2>
      <?php foreach ($checks as $label => $ok): ?>
       <div class="check-row d-flex justify-content-between align-items-center">
        <span class="small"><?= $label ?></span>
        <?php if ($ok): ?>
         <span class="badge bg-success">PASS</span>
        <?php else: ?>
         <span class="badge bg-danger">FAIL</span>
        <?php endif; ?>
       </div>
      <?php endforeach; ?>
     </div>

     <div class="col-md-6">
      <h2 class="h5 mb-3">CSRF</h2>
      <div class="check-row d-flex justify-content-between">
       <span class="text-muted small">Token field name</span>
       <span class="mono"><?= $csrf_token_name ?></span>
      </div>
      <div class="check-row d-flex justify-content-between">
       <span class="text-muted small">Token length</span>
       <span class="mono"><?= $csrf_token_len ?> chars</span>
      </div>
      <div class="check-row d-flex justify-content-between">
       <span class="text-muted small">Verification</span>
       <span class="badge bg-success">hash_equals()</span>
      </div>
      <div class="check-row d-flex justify-content-between">
       <span class="text-muted small">PHP version</span>
       <span class="mono"><?= $php_version ?></span>
      </div>
     </div>
    </div>

    <hr class="my-4">

    <h2 class="h5 mb-3">Output Escaping Demo</h2>
    <p class="small text-muted">The payload below is escaped automatically before it reaches the browser.</p>
    <div class="escaped-box mono"><?= $raw_payload ?></div>

    <hr class="my-4">

    <div class="row">
     <div class="col-md-6">
      <h2 class="h5 mb-3">Redirect Sanitisation</h2>
      <p class="small text-muted">Only same-site relative URLs survive validation.</p>
      <ul class="list-unstyled small mb-0">
       <?php foreach ($redirect_cases as $case): ?>
        <li class="d-flex justify-content-between border-bottom py-1">
         <span class="mono"><?= $case['input'] ?></span>
         <?php if ($case['allowed']): ?>
          <span class="badge bg-success">allowed</span>
         <?php else: ?>
          <span class="badge bg-danger">rejected</span>
         <?php endif; ?>
        </li>
       <?php endforeach; ?>
      </ul>
     </div>

     <div class="col-md-6">
      <h2 class="h5 mb-3">Input Sanitisation</h2>
      <p class="small text-muted">Strict validation rules applied server-side.</p>
      <ul class="list-unstyled small mb-0">
       <li class="border-bottom py-1 d-flex justify-content-between">
        <span class="text-muted">age (int)</span>
        <span class="mono"><?= $sanitized['age'] ?></span>
       </li>
       <li class="border-bottom py-1 d-flex justify-content-between">
        <span class="text-muted">note (trimmed)</span>
        <span class="mono"><?= $sanitized['note'] ?></span>
       </li>
       <li class="py-1 d-flex justify-content-between">
        <span class="text-muted">email (invalid &rarr; empty)</span>
        <span class="mono"><?= $sanitized['email'] === '' ? '(empty)' : $sanitized['email'] ?></span>
       </li>
      </ul>
     </div>
    </div>

   </div>
  </div>
 </div>
 <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
   integrity="sha384-q2LVbc1mSGgj6HBqIi6j4Lx6cGJWpG5r4Jb8X8Y5L5s5hZ5j5v5Y5X5Z5a5b5c5d"
   crossorigin="anonymous"></script>
</body>
</html>