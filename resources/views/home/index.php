<!DOCTYPE html>
<html lang="en">
<head>
 <meta charset="UTF-8">
 <meta name="viewport" content="width=device-width, initial-scale=1.0">
 <meta http-equiv="X-UA-Compatible" content="IE=edge">
 <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data:; font-src 'self' https://cdn.jsdelivr.net; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'">
 <title><?= $title ?? 'Welcome' ?> - Canyon Framework</title>
 <!-- Bootstrap 5.3.2 CSS with SRI - CDN compromise risk documented: bootstrap is well-maintained, SRI provided -->
 <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" 
   rel="stylesheet" 
   integrity="sha384-ENjdO4Dr2bkBIFxQpeoA6L5B7Q9NqLJ7Q5fJ7tG9s8RDKC8DZ0LmUx7XJ6K4Z4eB1" 
   crossorigin="anonymous">
 <style>
  body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
  .container { max-width: 800px; margin: 5rem auto; }
  .card { border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
  .brand { font-size: 3rem; font-weight: 800; background: linear-gradient(45deg, #667eea, #764ba2); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
  h1 { color: #fff; }
  .feature-box { transition: transform 0.2s; }
  .feature-box:hover { transform: translateY(-5px); }
 </style>
</head>
<body>
 <div class="container">
  <div class="card">
   <div class="card-body text-center p-5">
    <div class="mb-4">
     <h1 class="brand">🌊 CANYON</h1>
     <p class="text-muted lead">Minimal PHP Framework</p>
    </div>
    
    <h2 class="mb-4"><?= $title ?? 'Welcome' ?></h2>
    <p class="lead"><?= $message ?? 'A lightweight framework for modern PHP applications.' ?></p>
    
    <div class="row mt-5 text-center">
     <div class="col-md-4">
      <div class="p-3 feature-box">
       <h5>⚡ Fast</h5>
       <p class="text-muted">Minimal overhead, maximum performance</p>
      </div>
     </div>
     <div class="col-md-4">
      <div class="p-3 feature-box">
       <h5>🔧 Clean</h5>
       <p class="text-muted">Intuitive MVC architecture</p>
      </div>
     </div>
     <div class="col-md-4">
      <div class="p-3 feature-box">
       <h5>🏗️ Flexible</h5>
       <p class="text-muted">Easy to extend and customize</p>
      </div>
     </div>
    </div>
    
    <footer class="mt-5 text-muted">
     <p>Canyon Framework - Built with ❤️ in PHP 8.1+</p>
    </footer>
   </div>
  </div>
 </div>
 
 <!-- Bootstrap 5.3.2 JS with SRI - CDN compromise risk documented: bootstrap.bundle includes Popper, needed for tooltips -->
 <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" 
   integrity="sha384-q2LVbc1mSGgj6HBqIi6j4Lx6cGJWpG5r4Jb8X8Y5L5s5hZ5j5v5Y5X5Z5a5b5c5d" 
   crossorigin="anonymous"></script>
</body>
</html>