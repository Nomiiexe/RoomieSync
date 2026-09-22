<?php
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
$rootPath = './';
require_once 'includes/header.php';
require_once 'includes/navbar.php';
?>

<div class="container py-4">
  <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-4">
    <div>
      <h2 class="fw-bold mb-1">
        Kumusta<?php if (!empty($currentUser) && $currentUser !== 'User') echo ', ' . htmlspecialchars($currentUser); ?>!
      </h2>
      <p class="text-secondary-custom mb-0">
        Here is what is happening at <strong class="text-dark-accent">Maple Street House</strong> today.
      </p>
    </div>
    <div>
      <span class="badge bg-warm-secondary text-dark-accent border px-3 py-2 rounded-pill fw-semibold">
        <i class="bi bi-calendar-event me-1"></i> <?php echo date('l, M j, Y'); ?>
      </span>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
      <div class="rs-stat-card">
        <div class="rs-stat-icon" style="background-color: var(--rs-status-amber-bg); color: var(--rs-status-amber-text);">
          <i class="bi bi-clock-history"></i>
        </div>
        <div class="rs-stat-content">
          <div class="rs-stat-label">Pending Chores</div>
          <div class="rs-stat-value">3</div>
          <div class="rs-stat-subtext text-warning-emphasis">1 chore assigned to you</div>
        </div>
      </div>
    </div>

    <div class="col-sm-6 col-lg-3">
      <div class="rs-stat-card">
        <div class="rs-stat-icon" style="background-color: var(--rs-accent-light); color: var(--rs-dark-accent);">
          <i class="bi bi-wallet2"></i>
        </div>
        <div class="rs-stat-content">
          <div class="rs-stat-label">Your Balance Due</div>
          <div class="rs-stat-value">₱2,625</div>
          <div class="rs-stat-subtext text-secondary-custom">₱2,000 paid this cycle</div>
        </div>
      </div>
    </div>

    <div class="col-sm-6 col-lg-3">
      <div class="rs-stat-card">
        <div class="rs-stat-icon" style="background-color: var(--rs-card-secondary); color: var(--rs-dark-accent);">
          <i class="bi bi-receipt"></i>
        </div>
        <div class="rs-stat-content">
          <div class="rs-stat-label">Upcoming Bill</div>
          <div class="rs-stat-value">₱1,500</div>
          <div class="rs-stat-subtext text-secondary-custom">Internet &bull; Due Sep 25</div>
        </div>
      </div>
    </div>

    <div class="col-sm-6 col-lg-3">
      <div class="rs-stat-card">
        <div class="rs-stat-icon" style="background-color: var(--rs-accent-light); color: var(--rs-dark-accent);">
          <i class="bi bi-people-fill"></i>
        </div>
        <div class="rs-stat-content">
          <div class="rs-stat-label">Housemates</div>
          <div class="rs-stat-value">4</div>
          <div class="rs-stat-subtext text-secondary-custom">Maple Street House</div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-4">
    <div class="col-lg-7">
      <div class="rs-card h-100">
        <div class="rs-card-header">
          <div>
            <h5 class="rs-card-title">Pending Chores</h5>
            <div class="rs-card-subtitle">Upcoming duties scheduled for your home</div>
          </div>
          <a href="#" class="btn btn-sm btn-rs-outline">
            View All <i class="bi bi-chevron-right"></i>
          </a>
        </div>

        <div class="list-group list-group-flush border rounded-3 overflow-hidden">
          <div class="list-group-item p-3 d-flex justify-content-between align-items-center bg-transparent border-bottom">
            <div class="d-flex align-items-center gap-3">
              <input class="form-check-input mt-0" type="checkbox" id="choreCheck1">
              <div>
                <label class="fw-bold mb-0 text-dark-accent d-block" for="choreCheck1">Take Out Trash</label>
                <small class="text-secondary-custom">
                  <i class="bi bi-calendar3 me-1"></i> Due Today &bull; Kitchen & Porch
                </small>
              </div>
            </div>
            <div class="d-flex align-items-center gap-2">
              <span class="rs-avatar avatar-sm"><i class="bi bi-person"></i></span>
              <span class="rs-badge rs-badge-due-soon">Due Today</span>
            </div>
          </div>

          <div class="list-group-item p-3 d-flex justify-content-between align-items-center bg-transparent border-bottom">
            <div class="d-flex align-items-center gap-3">
              <input class="form-check-input mt-0" type="checkbox" id="choreCheck2">
              <div>
                <label class="fw-bold mb-0 text-dark-accent d-block" for="choreCheck2">Wash Dishes</label>
                <small class="text-secondary-custom">
                  <i class="bi bi-calendar3 me-1"></i> Due Tomorrow &bull; Common Kitchen
                </small>
              </div>
            </div>
            <div class="d-flex align-items-center gap-2">
              <span class="rs-avatar avatar-sm" style="background-color: #8A6246;"><i class="bi bi-person"></i></span>
              <span class="rs-badge rs-badge-pending">Pending</span>
            </div>
          </div>

          <div class="list-group-item p-3 d-flex justify-content-between align-items-center bg-transparent">
            <div class="d-flex align-items-center gap-3">
              <input class="form-check-input mt-0" type="checkbox" id="choreCheck3">
              <div>
                <label class="fw-bold mb-0 text-dark-accent d-block" for="choreCheck3">Clean Bathroom</label>
                <small class="text-secondary-custom">
                  <i class="bi bi-calendar3 me-1"></i> Due Sep 24 &bull; 2nd Floor
                </small>
              </div>
            </div>
            <div class="d-flex align-items-center gap-2">
              <span class="rs-avatar avatar-sm" style="background-color: #B88960;"><i class="bi bi-person"></i></span>
              <span class="rs-badge rs-badge-pending">Upcoming</span>
            </div>
          </div>
        </div>

        <div class="mt-3 text-center">
          <small class="text-secondary-custom">
            <i class="bi bi-info-circle me-1"></i> Chores rotate fairly every Sunday evening among roommates.
          </small>
        </div>
      </div>
    </div>

    <div class="col-lg-5 d-flex flex-column gap-4">
      <div class="rs-card">
        <div class="rs-card-header mb-2">
          <div>
            <h5 class="rs-card-title">Latest Announcement</h5>
            <div class="rs-card-subtitle">From Household Admin &bull; Recent Notice</div>
          </div>
          <span class="badge bg-warm-secondary text-dark-accent border">Notice</span>
        </div>
        <div class="p-3 rounded-3 bg-warm-secondary mb-2">
          <h6 class="fw-bold mb-1 text-dark-accent">
            <i class="bi bi-megaphone-fill me-1 text-dark-accent"></i> Scheduled Wi-Fi Maintenance
          </h6>
          <p class="small mb-0 text-secondary-custom">
            Our internet service provider will be upgrading the fiber connection this Friday between 10:00 PM and 1:00 AM. Please plan heavy school projects accordingly!
          </p>
        </div>
        <div class="text-end">
          <a href="#" class="text-decoration-none small text-dark-accent fw-semibold">
            All Announcements <i class="bi bi-arrow-right"></i>
          </a>
        </div>
      </div>

      <div class="rs-card flex-grow-1">
        <div class="rs-card-header mb-2">
          <div>
            <h5 class="rs-card-title">Recent Activity</h5>
            <div class="rs-card-subtitle">Latest actions in Maple Street House</div>
          </div>
        </div>

        <div class="d-flex flex-column gap-3">
          <div class="d-flex align-items-start gap-2">
            <div class="rs-avatar avatar-sm mt-1" style="background-color: #8A6246;"><i class="bi bi-person"></i></div>
            <div class="flex-grow-1">
              <p class="small mb-0"><strong>Roommate</strong> marked <strong>Internet Bill</strong> (₱375.00) as paid.</p>
              <small class="text-secondary-custom" style="font-size: 0.75rem;">Today at 9:15 AM</small>
            </div>
          </div>

          <div class="d-flex align-items-start gap-2">
            <div class="rs-avatar avatar-sm mt-1"><i class="bi bi-person"></i></div>
            <div class="flex-grow-1">
              <p class="small mb-0"><strong>Household Admin</strong> completed <strong>Vacuum Living Room</strong>.</p>
              <small class="text-secondary-custom" style="font-size: 0.75rem;">Yesterday at 6:40 PM</small>
            </div>
          </div>

          <div class="d-flex align-items-start gap-2">
            <div class="rs-avatar avatar-sm mt-1" style="background-color: #B88960;"><i class="bi bi-person"></i></div>
            <div class="flex-grow-1">
              <p class="small mb-0"><strong>Roommate</strong> added expense <strong>Groceries & Cleaning Supplies</strong> (₱1,240.00).</p>
              <small class="text-secondary-custom" style="font-size: 0.75rem;">Sep 19 at 3:20 PM</small>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once 'includes/footer.php'; ?>

