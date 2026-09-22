<?php
$pageTitle = 'Create Account';
$rootPath = './';
require_once 'includes/header.php';
?>

<div class="container d-flex flex-column align-items-center justify-content-center min-vh-100 py-5">
  <div class="rs-card p-4 p-md-5" style="max-width: 480px; width: 100%;">
    <div class="text-center mb-4">
      <div class="rs-brand-icon mx-auto mb-3" style="width: 54px; height: 54px; font-size: 1.6rem;">
        <i class="bi bi-house-heart-fill"></i>
      </div>
      <h3 class="fw-bold mb-1">Create an Account</h3>
      <p class="text-secondary-custom small mb-0">Join your housemates and synchronize daily living</p>
    </div>

    <form action="register.php" method="POST">
      <div class="mb-3">
        <label for="regFullName" class="form-label">Full Name</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-card-text"></i></span>
          <input type="text" class="form-control" id="regFullName" name="full_name" required>
        </div>
      </div>

      <div class="mb-3">
        <label for="regUsername" class="form-label">Username</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-person"></i></span>
          <input type="text" class="form-control" id="regUsername" name="username" required>
        </div>
      </div>

      <div class="mb-3">
        <label for="regEmail" class="form-label">Email Address</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-envelope"></i></span>
          <input type="email" class="form-control" id="regEmail" name="email" required>
        </div>
      </div>

      <div class="row g-2 mb-4">
        <div class="col-sm-6">
          <label for="regPassword" class="form-label">Password</label>
          <div class="input-group">
            <input type="password" class="form-control" id="regPassword" name="password" required>
            <button class="btn btn-rs-outline toggle-password-btn px-2" type="button" data-target="regPassword">
              <i class="bi bi-eye"></i>
            </button>
          </div>
        </div>
        <div class="col-sm-6">
          <label for="regConfirmPassword" class="form-label">Confirm Password</label>
          <div class="input-group">
            <input type="password" class="form-control" id="regConfirmPassword" name="confirm_password" required>
            <button class="btn btn-rs-outline toggle-password-btn px-2" type="button" data-target="regConfirmPassword">
              <i class="bi bi-eye"></i>
            </button>
          </div>
        </div>
      </div>

      <button type="submit" class="btn btn-rs-primary w-100 py-2 justify-content-center mb-3">
        <i class="bi bi-person-check"></i> Create Account
      </button>
    </form>

    <div class="text-center pt-3 border-top">
      <p class="small text-secondary-custom mb-0">
        Already have an account? <a href="login.php" class="fw-bold text-dark-accent text-decoration-none">Sign In</a>
      </p>
    </div>
  </div>
</div>

<?php require_once 'includes/footer.php'; ?>

