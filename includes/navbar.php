<?php
$currentScript = basename($_SERVER['PHP_SELF']);
$userDisplayName = !empty($currentUser) && $currentUser !== 'User' ? htmlspecialchars($currentUser) : 'Account';
$userInitials = !empty($currentUser) && $currentUser !== 'User' ? strtoupper(substr($currentUser, 0, 2)) : '';
?>

<div class="container rs-navbar-wrapper">
  <nav class="rs-pill-nav">
    <a href="<?php echo $rootPath; ?>dashboard.php" class="rs-brand">
      <div class="rs-brand-icon">
        <i class="bi bi-house-heart-fill"></i>
      </div>
      <span>RoomieSync</span>
    </a>

    <button class="btn btn-rs-outline rs-mobile-toggle d-lg-none py-1 px-2" type="button" id="mobileNavToggle" aria-label="Toggle navigation">
      <i class="bi bi-list fs-5"></i>
    </button>

    <ul class="rs-nav-links" id="navLinksContainer">
      <li>
        <a href="<?php echo $rootPath; ?>dashboard.php" 
           class="rs-nav-link <?php echo ($activeNav === 'dashboard') ? 'active' : ''; ?>">
          <i class="bi bi-grid-1x2-fill"></i>
          <span>Dashboard</span>
        </a>
      </li>
      <li>
        <a href="#" class="rs-nav-link <?php echo ($activeNav === 'chores') ? 'active' : ''; ?>">
          <i class="bi bi-check2-square"></i>
          <span>Chores</span>
        </a>
      </li>
      <li>
        <a href="#" class="rs-nav-link <?php echo ($activeNav === 'expenses') ? 'active' : ''; ?>">
          <i class="bi bi-wallet2"></i>
          <span>Expenses</span>
        </a>
      </li>
      <li>
        <a href="#" class="rs-nav-link <?php echo ($activeNav === 'bills') ? 'active' : ''; ?>">
          <i class="bi bi-receipt"></i>
          <span>Bills</span>
        </a>
      </li>
      <li>
        <a href="#" class="rs-nav-link <?php echo ($activeNav === 'payments') ? 'active' : ''; ?>">
          <i class="bi bi-credit-card"></i>
          <span>Payments</span>
        </a>
      </li>
      <li>
        <a href="#" class="rs-nav-link <?php echo ($activeNav === 'household') ? 'active' : ''; ?>">
          <i class="bi bi-people-fill"></i>
          <span>Household</span>
        </a>
      </li>
      <li>
        <a href="#" class="rs-nav-link <?php echo ($activeNav === 'reports') ? 'active' : ''; ?>">
          <i class="bi bi-file-earmark-bar-graph"></i>
          <span>Reports</span>
        </a>
      </li>

      <?php if ($isAdmin): ?>
      <li>
        <a href="#" class="rs-nav-link <?php echo ($activeNav === 'admin') ? 'active' : ''; ?>">
          <i class="bi bi-gear-fill"></i>
          <span>Admin</span>
        </a>
      </li>
      <?php endif; ?>
    </ul>

    <div class="rs-nav-user dropdown">
      <a href="#" class="rs-user-btn dropdown-toggle" role="button" data-bs-toggle="dropdown" aria-expanded="false">
        <div class="rs-avatar">
          <?php echo !empty($userInitials) ? $userInitials : '<i class="bi bi-person-fill"></i>'; ?>
        </div>
        <span class="d-none d-md-inline"><?php echo $userDisplayName; ?></span>
        <span class="role-badge <?php echo $isAdmin ? 'admin' : 'roommate'; ?> d-none d-xl-inline">
          <?php echo $isAdmin ? 'Admin' : 'Member'; ?>
        </span>
      </a>
      <ul class="dropdown-menu dropdown-menu-end rs-dropdown">
        <li class="px-3 py-2 border-bottom mb-1">
          <div class="fw-bold text-dark-accent"><?php echo $userDisplayName; ?></div>
          <?php if (!empty($currentEmail)): ?>
            <small class="text-secondary-custom"><?php echo htmlspecialchars($currentEmail); ?></small>
          <?php endif; ?>
          <div class="mt-1">
            <span class="role-badge <?php echo $isAdmin ? 'admin' : 'roommate'; ?>">
              <?php echo $isAdmin ? 'Household Admin' : 'Household Member'; ?>
            </span>
          </div>
        </li>
        <li>
          <a class="dropdown-item rs-dropdown-item" href="#">
            <i class="bi bi-person-circle"></i> My Profile
          </a>
        </li>
        <li>
          <a class="dropdown-item rs-dropdown-item" href="<?php echo $rootPath; ?>households.php">
            <i class="bi bi-arrow-repeat"></i> Switch Household
          </a>
        </li>
        <li><hr class="dropdown-divider rs-dropdown-divider"></li>
        <li>
          <a class="dropdown-item rs-dropdown-item text-danger" href="<?php echo $rootPath; ?>login.php">
            <i class="bi bi-box-arrow-right"></i> Sign Out
          </a>
        </li>
      </ul>
    </div>
  </nav>
</div>
