<!DOCTYPE html>
<html lang="en">
<head>
 <meta charset="UTF-8">
 <meta name="viewport" content="width=device-width, initial-scale=1.0">
 <title>Register</title>
 <style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
   font-family: -apple-system, BlinkSystemFont, 'Segoe UI', Roboto, sans-serif;
   background: #f0f2f5; color: #333; line-height: 1.6;
  }
  .container { max-width: 420px; margin: 60px auto; padding: 20px; }
  .card {
   background: #fff; border-radius: 8px; padding: 30px;
   box-shadow: 0 2px 10px rgba(0,0,0,0.1);
  }
  h1 { color: #2c3e50; margin-bottom: 20px; font-size: 22px; }
  .form-group { margin-bottom: 18px; }
  label {
   display: block; margin-bottom: 6px; font-weight: 600; font-size: 14px;
  }
  input[type="text"], input[type="email"], input[type="password"] {
   width: 100%; padding: 12px; border: 1px solid #ddd;
   border-radius: 4px; font-size: 14px;
  }
  input:focus { outline: none; border-color: #3498db; }
  .btn {
   display: inline-block; padding: 12px 24px; background: #27ae60;
   color: #fff; border: none; border-radius: 4px; font-size: 15px;
   cursor: pointer; width: 100%;
  }
  .btn:hover { background: #219a52; }
  .alert {
   padding: 12px 16px; border-radius: 4px; margin-bottom: 16px; font-size: 14px;
   display: none;
  }
  .alert-error { background: #fee; color: #c33; border: 1px solid #fcc; }
  .alert-success { background: #efe; color: #3c3; border: 1px solid #cfc; }
  .links { margin-top: 18px; text-align: center; font-size: 14px; }
  .links a { color: #3498db; text-decoration: none; }
  .links a:hover { text-decoration: underline; }
 </style>
</head>
<body>
 <div class="container">
  <div class="card">
   <h1>Create Account</h1>

   <div id="alert" class="alert"></div>

   <form id="registerForm">
    <?= $csrf_token ?? '' ?>

    <div class="form-group">
     <label for="name">Full Name</label>
     <input type="text" id="name" name="name" required autofocus>
    </div>

    <div class="form-group">
     <label for="email">Email</label>
     <input type="email" id="email" name="email" required>
    </div>

    <div class="form-group">
     <label for="password">Password (min 8 characters)</label>
     <input type="password" id="password" name="password" required minlength="8">
    </div>

    <div class="form-group">
     <label for="password_confirmation">Confirm Password</label>
     <input type="password" id="password_confirmation" name="password_confirmation" required minlength="8">
    </div>

    <button type="submit" class="btn">Create Account</button>
   </form>

   <div class="links">
    Already have an account? <a href="/login">Login</a>
   </div>
  </div>
 </div>

 <script>
  (function() {
   const form = document.getElementById('registerForm');
   const alert = document.getElementById('alert');

   function showAlert(message, isError) {
    alert.textContent = message;
    alert.className = 'alert ' + (isError ? 'alert-error' : 'alert-success');
    alert.style.display = 'block';
   }

   form.addEventListener('submit', async function(e) {
    e.preventDefault();
    alert.style.display = 'none';

    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());

    try {
     const res = await fetch('/register', {
      method: 'POST',
      headers: {
       'Content-Type': 'application/json',
       'X-CSRF-Token': document.querySelector('input[name="_canyon_csrf_token"]')?.value || '',
      },
      body: JSON.stringify(data),
     });

     const result = await res.json();

     if (result.success) {
      showAlert(result.message || 'Account created!', false);
      setTimeout(() => { window.location.href = '/'; }, 800);
     } else {
      showAlert(result.message || 'Error.', true);
     }
    } catch (err) {
     showAlert('Network error. Please try again.', true);
    }
   });
  })();
 </script>
</body>
</html>
