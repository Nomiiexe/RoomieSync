<?php
$pageTitle = 'Join a Household';
$rootPath = './';
require_once 'includes/header.php';
?>

<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-lg-5 col-md-7">
      <div class="mb-3">
        <a href="households.php" class="text-decoration-none text-secondary-custom small">
          <i class="bi bi-arrow-left me-1"></i> Back to Household Selection
        </a>
      </div>

      <div class="rs-card p-4 p-md-5">
        <div class="text-center mb-4">
          <div class="rs-brand-icon mx-auto mb-3" style="width: 52px; height: 52px; font-size: 1.5rem; background: var(--rs-accent-light);">
            <i class="bi bi-key-fill text-dark-accent"></i>
          </div>
          <h3 class="fw-bold mb-2">Join a Household</h3>
          <p class="text-secondary-custom small mb-0">
            Enter the unique household invite code provided by your household administrator.
          </p>
        </div>

        <form action="join-household.php" method="POST">
          <div class="mb-4">
            <label for="householdCode" class="form-label text-center d-block">Household Invite Code</label>
            <input type="text" class="form-control text-uppercase text-center fw-bold py-3 fs-5" id="householdCode" name="invite_code" required style="letter-spacing: 3px;">
          </div>

          <div class="d-grid gap-2">
            <button type="submit" class="btn btn-rs-primary py-2 justify-content-center">
              <i class="bi bi-door-open"></i> Join Household
            </button>
            <a href="households.php" class="btn btn-rs-outline py-2 justify-content-center">
              Cancel
            </a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once 'includes/footer.php'; ?>
