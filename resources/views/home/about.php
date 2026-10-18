<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data:; font-src 'self' https://cdn.jsdelivr.net; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'">
    <title>About - Canyon Framework</title>
    <!-- Bootstrap 5.3.2 CSS with SRI -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" 
          rel="stylesheet" 
          integrity="sha384-ENjdO4Dr2bkBIFxQpeoA6L5B7Q9NqLJ7Q5fJ7tG9s8RDKC8DZ0LmUx7XJ6K4Z4eB1" 
          crossorigin="anonymous">
    <style>
        body { background: #f8f9fa; }
        .container { max-width: 800px; }
        .card { border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .card-header { background: linear-gradient(45deg, #667eea, #764ba2); }
    </style>
</head>
<body>
    <div class="container py-5">
        <div class="card shadow">
            <div class="card-header text-white">
                <h1 class="mb-0">About Canyon</h1>
            </div>
            <div class="card-body">
                <h2>Built for Modern PHP</h2>
                <p>Canyon is a lightweight PHP framework inspired by Laravel's elegance and CodeIgniter's simplicity. It provides minimal yet powerful tools for building web applications.</p>
                
                <h3>Features</h3>
                <ul class="list-unstyled">
                    <li class="mb-2">✅ Minimalist architecture</li>
                    <li class="mb-2">✅ PSR-4 autoloading</li>
                    <li class="mb-2">✅ Powerful routing system</li>
                    <li class="mb-2">✅ Template-based views with XSS protection</li>
                    <li class="mb-2">✅ Configuration management</li>
                    <li class="mb-2">✅ CSRF protection</li>
                    <li class="mb-2">✅ Security headers</li>
                </ul>
                
                <h3>Security Features</h3>
                <ul class="list-unstyled">
                    <li class="mb-2">🔒 XSS protection via output escaping</li>
                    <li class="mb-2">🛡️ CSRF token validation</li>
                    <li class="mb-2">🚫 Open redirect prevention</li>
                    <li class="mb-2">🕸️ Content Security Policy</li>
                    <li class="mb-2">🌐 Host header injection protection</li>
                </ul>
                
                <a href="/" class="btn btn-primary mt-3">← Back to Home</a>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" 
            integrity="sha384-q2LVbc1mSGgj6HBqIi6j4Lx6cGJWpG5r4Jb8X8Y5L5s5hZ5j5v5Y5X5Z5a5b5c5d" 
            crossorigin="anonymous"></script>
</body>
</html>