<?php
$pageTitle = 'Sign In';
$rootPath = './';
require_once 'includes/header.php';
?>

<div class="container d-flex flex-column align-items-center justify-content-center min-vh-100 py-5">
  <div class="rs-card p-4 p-md-5" style="max-width: 440px; width: 100%;">
    <div class="text-center mb-4">
      <div class="rs-brand-icon mx-auto mb-3" style="width: 54px; height: 54px; font-size: 1.6rem;">
        <i class="bi bi-house-heart-fill"></i>
      </div>
      <h3 class="fw-bold mb-1">Welcome Back</h3>
      <p class="text-secondary-custom small mb-0">Sign in to manage your household with RoomieSync</p>
    </div>

    <form action="login.php" method="POST">
      <div class="mb-3">
        <label for="loginEmail" class="form-label">Username or Email</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-person"></i></span>
          <input type="text" class="form-control" id="loginEmail" name="username" required>
        </div>
      </div>

      <div class="mb-3">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <label for="loginPassword" class="form-label mb-0">Password</label>
          <a href="#" class="small text-secondary-custom text-decoration-none">
            Forgot Password?
          </a>
        </div>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-key"></i></span>
          <input type="password" class="form-control" id="loginPassword" name="password" required>
          <button class="btn btn-rs-outline toggle-password-btn" type="button" data-target="loginPassword" title="Show/Hide Password">
            <i class="bi bi-eye"></i>
          </button>
        </div>
      </div>

      <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="rememberMe" name="remember_me">
          <label class="form-check-label small text-secondary-custom" for="rememberMe">
            Remember Me
          </label>
        </div>
      </div>

      <button type="submit" class="btn btn-rs-primary w-100 py-2 justify-content-center mb-3">
        <i class="bi bi-box-arrow-in-right"></i> Sign In
      </button>
    </form>

    <div class="text-center pt-3 border-top">
      <p class="small text-secondary-custom mb-0">
        Don't have an account? <a href="register.php" class="fw-bold text-dark-accent text-decoration-none">Create Account</a>
      </p>
    </div>
  </div>
</div>

<?php require_once 'includes/footer.php'; ?>

