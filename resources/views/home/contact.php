<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Contact - Canyon Framework</title>
    <!-- Security: CSP meta tag as fallback -->
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net;">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .card { border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .btn-primary { background: linear-gradient(45deg, #667eea, #764ba2); border: none; }
    </style>
</head>
<body>
    <div class="container py-5">
        <div class="card shadow" style="max-width: 600px; margin: 0 auto;">
            <div class="card-header bg-primary text-white">
                <h1 class="mb-0">Contact Us</h1>
            </div>
            <div class="card-body">
                <form method="POST" action="/api/contact" class="contact-form">
                    <!-- CSRF Protection: generated in-view so it is never escaped as view data -->
                    <?= \Canyon\Core\Security::csrfField() ?>
                    <input type="hidden" name="token" value="<?= $csrf_token_name ?? '_canyon_csrf_token' ?>">
                    
                    <div class="mb-3">
                        <label for="name" class="form-label">Name</label>
                        <input type="text" class="form-control" id="name" name="name" 
                               placeholder="Your name" required 
                               maxlength="100"
                               pattern="[a-zA-Z\s'-]+"
                               title="Only letters, spaces, hyphens and apostrophes allowed">
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" 
                               placeholder="your@email.com" required 
                               maxlength="255"
                               pattern="[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$"
                               title="Please enter a valid email address">
                    </div>
                    <div class="mb-3">
                        <label for="message" class="form-label">Message</label>
                        <textarea class="form-control" id="message" name="message" rows="4" 
                                  placeholder="Your message..." required 
                                  maxlength="2000"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Send Message</button>
                    <a href="/" class="btn btn-outline-secondary mt-2 w-100">Cancel</a>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>