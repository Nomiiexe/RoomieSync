<?php
$pageTitle = 'Select Household';
$rootPath = './';
require_once 'includes/header.php';
?>

<div class="container py-5">
  <div class="text-center mb-5">
    <div class="rs-brand-icon mx-auto mb-3" style="width: 58px; height: 58px; font-size: 1.75rem;">
      <i class="bi bi-house-heart-fill"></i>
    </div>
    <h2 class="fw-bold mb-2">Welcome back<?php if (!empty($currentUser) && $currentUser !== 'User') echo ', ' . htmlspecialchars($currentUser); ?></h2>
    <p class="text-secondary-custom fs-6 mb-0">Select your active household or connect with a new home</p>
  </div>

  <div class="row justify-content-center g-4">
    <div class="col-md-6 col-lg-5">
      <div class="rs-card rs-card-hover h-100 p-0 overflow-hidden d-flex flex-column">
        <div class="p-4 text-center" style="background: linear-gradient(135deg, #F3E8DD 0%, #EAD8C7 100%); min-height: 150px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
          <div class="rs-stat-icon mb-2" style="width: 64px; height: 64px; font-size: 2rem; background: rgba(255,255,255,0.85);">
            <i class="bi bi-building text-dark-accent"></i>
          </div>
          <span class="badge bg-warm-secondary text-dark-accent px-3 py-1 rounded-pill fw-semibold border" style="font-size: 0.8rem;">
            <i class="bi bi-geo-alt me-1"></i> Primary Residence
          </span>
        </div>

        <div class="p-4 d-flex flex-column flex-grow-1">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <div>
              <h4 class="fw-bold mb-1">Maple Street House</h4>
              <p class="text-secondary-custom small mb-0">Shared 4-bedroom townhouse</p>
            </div>
            <span class="rs-badge rs-badge-active">
              <span class="rs-badge-dot"></span> Active
            </span>
          </div>

          <div class="my-3 py-2 border-top border-bottom d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
              <div class="d-flex" style="margin-left: 0.5rem;">
                <div class="rs-avatar avatar-sm border border-2 border-white" style="margin-left: -0.5rem;"><i class="bi bi-person-fill"></i></div>
                <div class="rs-avatar avatar-sm border border-2 border-white" style="margin-left: -0.5rem; background: #8A6246;"><i class="bi bi-person-fill"></i></div>
                <div class="rs-avatar avatar-sm border border-2 border-white" style="margin-left: -0.5rem; background: #B88960;"><i class="bi bi-person-fill"></i></div>
                <div class="rs-avatar avatar-sm border border-2 border-white" style="margin-left: -0.5rem; background: #5F554F;"><i class="bi bi-person-fill"></i></div>
              </div>
              <span class="small fw-semibold text-secondary-custom">4 Members</span>
            </div>
            <span class="small text-secondary-custom">Active Household</span>
          </div>

          <div class="mt-auto pt-2">
            <a href="dashboard.php" class="btn btn-rs-primary w-100 justify-content-center py-2">
              <span>Enter Household</span>
              <i class="bi bi-arrow-right"></i>
            </a>
          </div>
        </div>
      </div>
    </div>

    <div class="col-md-6 col-lg-5 d-flex flex-column gap-3">
      <div class="rs-card rs-card-hover p-4 flex-fill d-flex flex-column justify-content-between">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="rs-stat-icon" style="background-color: var(--rs-card-secondary);">
            <i class="bi bi-plus-circle text-dark-accent"></i>
          </div>
          <div>
            <h5 class="fw-bold mb-1">Create a Household</h5>
            <p class="text-secondary-custom small mb-0">Set up a new space and invite your roommates to join.</p>
          </div>
        </div>
        <a href="create-household.php" class="btn btn-rs-secondary w-100 justify-content-center">
          <i class="bi bi-house-add"></i> Create Household
        </a>
      </div>

      <div class="rs-card rs-card-hover p-4 flex-fill d-flex flex-column justify-content-between">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="rs-stat-icon" style="background-color: var(--rs-accent-light);">
            <i class="bi bi-key text-dark-accent"></i>
          </div>
          <div>
            <h5 class="fw-bold mb-1">Join with Invite Code</h5>
            <p class="text-secondary-custom small mb-0">Have an invite code from your house administrator?</p>
          </div>
        </div>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-rs-primary flex-grow-1 justify-content-center" data-bs-toggle="modal" data-bs-target="#joinHouseholdModal">
            <i class="bi bi-box-arrow-in-right"></i> Enter Code
          </button>
          <a href="join-household.php" class="btn btn-rs-outline justify-content-center">
            Join Page <i class="bi bi-chevron-right"></i>
          </a>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="joinHouseholdModal" tabindex="-1" aria-labelledby="joinHouseholdModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content rs-modal">
      <div class="modal-header rs-modal-header">
        <h5 class="modal-title" id="joinHouseholdModalLabel">
          <i class="bi bi-key-fill text-dark-accent me-2"></i> Join a Household
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form action="join-household.php" method="POST">
        <div class="modal-body rs-modal-body">
          <p class="text-secondary-custom small mb-3">
            Enter the unique household invite code provided by your household administrator to link your account.
          </p>
          <div class="mb-3">
            <label for="modalJoinCode" class="form-label">Household Code</label>
            <input type="text" class="form-control text-uppercase fw-bold text-center letter-spacing-1" id="modalJoinCode" name="invite_code" required style="letter-spacing: 2px;">
          </div>
        </div>
        <div class="modal-footer rs-modal-footer">
          <button type="button" class="btn btn-rs-outline" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-rs-primary">
            <i class="bi bi-door-open"></i> Join Household
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once 'includes/footer.php'; ?>

