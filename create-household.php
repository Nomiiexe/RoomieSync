<?php
$pageTitle = 'Create Household';
$rootPath = './';
require_once 'includes/header.php';
?>

<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-lg-7 col-md-9">
      <div class="mb-3">
        <a href="households.php" class="text-decoration-none text-secondary-custom small">
          <i class="bi bi-arrow-left me-1"></i> Back to Household Selection
        </a>
      </div>

      <div class="rs-card p-4 p-md-5">
        <div class="d-flex align-items-center gap-3 mb-4 border-bottom pb-3">
          <div class="rs-stat-icon" style="background-color: var(--rs-accent-light);">
            <i class="bi bi-house-add-fill text-dark-accent"></i>
          </div>
          <div>
            <h3 class="fw-bold mb-1">Create Household</h3>
            <p class="text-secondary-custom small mb-0">Set up a space for your apartment or house</p>
          </div>
        </div>

        <form action="create-household.php" method="POST" enctype="multipart/form-data">
          <div class="mb-3">
            <label for="houseName" class="form-label">Household Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="houseName" name="house_name" required>
          </div>

          <div class="mb-3">
            <label for="houseDescription" class="form-label">Description <span class="text-secondary-custom font-monospace small">(optional)</span></label>
            <textarea class="form-control" id="houseDescription" name="house_description" rows="3"></textarea>
          </div>

          <div class="mb-3">
            <label for="houseAddress" class="form-label">Address <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="houseAddress" name="house_address" required>
          </div>

          <div class="mb-4">
            <label class="form-label">Household Cover Picture</label>
            <div class="p-4 text-center rounded-3 border-dashed" style="border: 2px dashed var(--rs-border-input); background: var(--rs-card-secondary);">
              <div class="rs-brand-icon mx-auto mb-2" style="background: var(--rs-card-primary);">
                <i class="bi bi-image"></i>
              </div>
              <p class="small text-secondary-custom mb-2">Drag and drop a household photo, or browse from device</p>
              <input type="file" class="d-none" id="houseImageInput" name="house_image" accept="image/*">
              <label for="houseImageInput" class="btn btn-sm btn-rs-outline">
                <i class="bi bi-upload me-1"></i> Choose Image
              </label>
              <div class="small text-secondary-custom mt-2" style="font-size: 0.76rem;">PNG, JPG, or WEBP up to 5MB</div>
            </div>
          </div>

          <div class="d-flex justify-content-end gap-2 pt-3 border-top">
            <a href="households.php" class="btn btn-rs-outline">
              Cancel
            </a>
            <button type="submit" class="btn btn-rs-primary">
              <i class="bi bi-check-lg"></i> Create Household
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once 'includes/footer.php'; ?>
