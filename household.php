<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/pagination.php';

if (!restore_remembered_login()) {
    redirect_to('login.php');
}

$userId = (int) $_SESSION['user_id'];
$householdId = selected_household_id();

try {
    $connection = app_pdo();
} catch (Throwable $error) {
    error_log('RoomieSync household page database connection failed: ' . $error->getMessage());
    http_response_code(500);
    exit('The database is temporarily unavailable.');
}

// User Profile Information
$userQuery = $connection->prepare('SELECT email, username, full_name FROM userTb WHERE user_id = :user_id LIMIT 1');
$userQuery->execute(['user_id' => $userId]);
$currentUser = $userQuery->fetch() ?: [];
$userEmail = (string) ($currentUser['email'] ?? '');
$username = (string) ($currentUser['username'] ?? ($_SESSION['username'] ?? ''));
$fullName = (string) ($currentUser['full_name'] ?? ($_SESSION['full_name'] ?? ''));
$displayName = $username !== '' ? $username : $fullName;

function household_initials(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '?';
    }
    $parts = preg_split('/\s+/', $value) ?: [];
    if (count($parts) > 1) {
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts) - 1], 0, 1));
    }
    return strtoupper(substr($value, 0, 2));
}

$avatarText = household_initials($displayName);

// Check if user belongs to the selected household
$currentHousehold = null;
$currentUserRole = '';
$members = [];

if ($householdId !== null) {
    // 1. Verify membership and get current household info
    $hhQuery = $connection->prepare(
        'SELECT h.household_id, h.household_name, h.description, h.address,
                h.household_image, h.join_code, h.created_at, hm.role, hm.joined_at AS user_joined_at
         FROM householdTb AS h
         INNER JOIN householdmembersTb AS hm ON hm.household_id = h.household_id
         WHERE h.household_id = :household_id AND hm.user_id = :user_id
         LIMIT 1'
    );
    $hhQuery->execute(['household_id' => $householdId, 'user_id' => $userId]);
    $currentHousehold = $hhQuery->fetch() ?: null;

    if ($currentHousehold !== null) {
        $currentUserRole = (string) $currentHousehold['role'];

        // 2. Fetch all household members
        $membersQuery = $connection->prepare(
            "SELECT u.user_id, u.full_name, u.username, u.profile_picture, hm.role, hm.joined_at, u.status
             FROM householdmembersTb AS hm
             INNER JOIN userTb AS u ON u.user_id = hm.user_id
             WHERE hm.household_id = :household_id
             ORDER BY
                CASE WHEN LOWER(hm.role) = 'admin' THEN 0 ELSE 1 END,
                hm.joined_at ASC"
        );
        $membersQuery->execute(['household_id' => $householdId]);
        $allMembers = $membersQuery->fetchAll();
        $totalMembersCount = count($allMembers);

        $memberPagination = get_pagination_params($totalMembersCount, 6, [4, 6, 10, 20], 'mem_page', 'mem_per_page');
        $members = array_slice($allMembers, (int) $memberPagination['offset'], (int) $memberPagination['per_page']);

        // 3. Fetch Recent Activities in this household
        $allActivities = [];

        // Activity 1: Payments
        $paymentActQuery = $connection->prepare(
            'SELECT p.payment_date AS activity_at, u.full_name, u.username, b.bill_name AS item, p.amount, \'payment\' AS type
             FROM paymentsTb AS p
             INNER JOIN bill_sharesTb AS bs ON bs.bill_share_id = p.bill_share_id
             INNER JOIN billsTb AS b ON b.bill_id = bs.bill_id
             INNER JOIN userTb AS u ON u.user_id = bs.user_id
             WHERE b.household_id = :household_id
             ORDER BY p.payment_date DESC
             LIMIT 20'
        );
        $paymentActQuery->execute(['household_id' => $householdId]);
        foreach ($paymentActQuery->fetchAll() as $row) {
            $allActivities[] = $row;
        }

        // Activity 2: Expenses
        $expenseActQuery = $connection->prepare(
            'SELECT e.created_at AS activity_at, u.full_name, u.username, e.title AS item, e.amount, \'expense\' AS type
             FROM expenseTb AS e
             LEFT JOIN userTb AS u ON u.user_id = e.paid_by
             WHERE e.household_id = :household_id
             ORDER BY e.created_at DESC
             LIMIT 20'
        );
        $expenseActQuery->execute(['household_id' => $householdId]);
        foreach ($expenseActQuery->fetchAll() as $row) {
            $allActivities[] = $row;
        }

        // Activity 3: Completed Chores
        $choreActQuery = $connection->prepare(
            "SELECT ca.completed_at AS activity_at, u.full_name, u.username, c.chore_name AS item, 0 AS amount, 'chore' AS type
             FROM chore_assignmentsTb AS ca
             INNER JOIN choresTb AS c ON c.chore_id = ca.chore_id
             INNER JOIN userTb AS u ON u.user_id = ca.user_id
             WHERE c.household_id = :household_id
               AND LOWER(ca.status) = 'completed'
               AND ca.completed_at IS NOT NULL
             ORDER BY ca.completed_at DESC
             LIMIT 20"
        );
        $choreActQuery->execute(['household_id' => $householdId]);
        foreach ($choreActQuery->fetchAll() as $row) {
            $allActivities[] = $row;
        }

        // Activity 4: Joined Members
        $joinedActQuery = $connection->prepare(
            "SELECT hm.joined_at AS activity_at, u.full_name, u.username, hm.role AS item, 0 AS amount, 'member' AS type
             FROM householdmembersTb AS hm
             INNER JOIN userTb AS u ON u.user_id = hm.user_id
             WHERE hm.household_id = :household_id
             ORDER BY hm.joined_at DESC
             LIMIT 20"
        );
        $joinedActQuery->execute(['household_id' => $householdId]);
        foreach ($joinedActQuery->fetchAll() as $row) {
            $allActivities[] = $row;
        }

        // Sort all activity chronologically descending
        usort($allActivities, static function (array $left, array $right): int {
            return strtotime((string) $right['activity_at']) <=> strtotime((string) $left['activity_at']);
        });
        $totalActivitiesCount = count($allActivities);

        $actPagination = get_pagination_params($totalActivitiesCount, 6, [5, 6, 10, 20], 'act_page', 'act_per_page');
        $activities = array_slice($allActivities, (int) $actPagination['offset'], (int) $actPagination['per_page']);
    } else {
        // Logged-in user is not a member of the session household ID
        unset($_SESSION['household_id']);
        $householdId = null;
    }
}


// User's all accessible households for easy switching if multiple exist
$allUserHouseholdsQuery = $connection->prepare(
    'SELECT h.household_id, h.household_name, hm.role
     FROM householdmembersTb AS hm
     INNER JOIN householdTb AS h ON h.household_id = hm.household_id
     WHERE hm.user_id = :user_id
     ORDER BY h.household_name ASC'
);
$allUserHouseholdsQuery->execute(['user_id' => $userId]);
$userHouseholds = $allUserHouseholdsQuery->fetchAll();

$isAdmin = strcasecmp($currentUserRole, 'Admin') === 0;
$notice = consume_flash_message();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Household | RoomieSync</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="assets/css/household.css">
</head>
<body class="d-flex flex-column min-vh-100">
  <!-- Pill Navbar matching RoomieSync design system -->
  <div class="container rs-navbar-wrapper">
    <nav class="rs-pill-nav">
      <a href="dashboard.php" class="rs-brand">
        <div class="rs-brand-icon"><i class="bi bi-house-heart-fill"></i></div>
        <span>RoomieSync</span>
      </a>
      <button class="btn btn-rs-outline rs-mobile-toggle d-lg-none py-1 px-2" type="button" id="mobileNavToggle" aria-label="Toggle navigation">
        <i class="bi bi-list fs-5"></i>
      </button>
      <ul class="rs-nav-links" id="navLinksContainer">
        <li><a href="dashboard.php" class="rs-nav-link"><i class="bi bi-grid-1x2-fill"></i><span>Dashboard</span></a></li>
        <li><a href="chores.php" class="rs-nav-link"><i class="bi bi-check2-square"></i><span>Chores</span></a></li>
        <li><a href="bills.php" class="rs-nav-link"><i class="bi bi-receipt"></i><span>Bills</span></a></li>
        <li><a href="household.php" class="rs-nav-link active"><i class="bi bi-house"></i><span>Household</span></a></li>
        <li><a href="reports.php" class="rs-nav-link"><i class="bi bi-bar-chart"></i><span>Reports</span></a></li>
        <?php if ($isAdmin): ?>
          <li><a href="manage.php" class="rs-nav-link"><i class="bi bi-sliders"></i><span>Manage</span></a></li>
        <?php endif; ?>
      </ul>
      <div class="rs-nav-user dropdown">
        <a href="#" class="rs-user-btn dropdown-toggle" role="button" data-bs-toggle="dropdown" aria-expanded="false">
          <?= render_current_user_avatar($avatarText, $displayName) ?>
          <span class="d-none d-md-inline"><?= h($displayName) ?></span>
          <?php if ($currentUserRole !== ''): ?><span class="role-badge roommate d-none d-xl-inline"><?= h($currentUserRole) ?></span><?php endif; ?>
        </a>
        <ul class="dropdown-menu dropdown-menu-end rs-dropdown">
          <li class="px-3 py-2 border-bottom mb-1">
            <div class="fw-bold text-dark-accent"><?= h($displayName) ?></div>
            <?php if ($userEmail !== ''): ?>
              <small class="text-secondary-custom"><?= h($userEmail) ?></small>
            <?php else: ?>
              <small class="text-secondary-custom">No email on profile</small>
            <?php endif; ?>
            <?php if ($currentUserRole !== ''): ?><div class="mt-1"><span class="role-badge roommate"><?= h($currentUserRole) ?></span></div><?php endif; ?>
          </li>
          <li><a class="dropdown-item rs-dropdown-item" href="profile.php"><i class="bi bi-person-circle"></i> Profile</a></li>
          <li><a class="dropdown-item rs-dropdown-item" href="households.php"><i class="bi bi-arrow-left-right"></i> Switch Household</a></li>
          <li><a class="dropdown-item rs-dropdown-item" href="#joinHouseholdModal" data-bs-toggle="modal"><i class="bi bi-door-open"></i> Join Another Home</a></li>
          <li><hr class="dropdown-divider rs-dropdown-divider"></li>
          <li><a class="dropdown-item rs-dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a></li>
        </ul>
      </div>
    </nav>
  </div>

  <main class="container py-4 flex-grow-1">
    <!-- Flash Notice -->
    <?php if ($notice): ?>
      <?php
        $noticeClass = 'alert-success';
        if (isset($notice['type'])) {
            $noticeClass = $notice['type'] === 'danger' ? 'alert-danger' : ($notice['type'] === 'info' ? 'alert-info' : 'alert-success');
        }
      ?>
      <div class="alert <?= h($noticeClass) ?> alert-dismissible fade show mb-4 shadow-sm" role="alert">
        <i class="bi bi-info-circle-fill me-2"></i><?= h((string) $notice['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <!-- Page Header -->
    <header class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
      <div>
        <span class="rs-eyebrow d-block mb-1">Your Living Space</span>
        <h1 class="h2 fw-bold text-main mb-1">Household</h1>
        <p class="text-secondary-custom mb-0">View your household information and members.</p>
      </div>
      <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn btn-rs-outline" data-bs-toggle="modal" data-bs-target="#joinHouseholdModal">
          <i class="bi bi-door-open me-1"></i> Join a Household
        </button>
        <?php if (count($userHouseholds) > 1): ?>
          <a href="households.php" class="btn btn-rs-outline" title="Switch between your registered households">
            <i class="bi bi-arrow-left-right me-1"></i> Switch Home
          </a>
        <?php endif; ?>
      </div>
    </header>

    <?php if ($currentHousehold === null): ?>
      <!-- Empty State: User is not currently part of an active household -->
      <section class="rs-card text-center py-5 my-4">
        <div class="py-4">
          <div class="rs-brand-icon mx-auto mb-3" style="width: 64px; height: 64px; font-size: 2rem;">
            <i class="bi bi-house-door"></i>
          </div>
          <h2 class="h4 fw-bold text-main mb-2">You are not currently part of a household</h2>
          <p class="text-secondary-custom mb-4" style="max-width: 480px; margin-inline: auto;">
            Connect with your roomies by joining an existing home using their invite code, or create a brand new household.
          </p>
          <div class="d-flex justify-content-center gap-2 flex-wrap">
            <a href="households.php" class="btn btn-rs-primary">
              <i class="bi bi-plus-lg me-1"></i> Create Household
            </a>
            <button type="button" class="btn btn-rs-outline" data-bs-toggle="modal" data-bs-target="#joinHouseholdModal">
              <i class="bi bi-door-open me-1"></i> Join Household
            </button>
          </div>
        </div>
      </section>

    <?php else: ?>
      <div class="row g-4">
        <!-- Household Overview Card -->
        <div class="col-lg-5 col-xl-4">
          <div class="household-hero-card card-household">
            <!-- Household Image / Placeholder -->
            <?php if (!empty($currentHousehold['household_image'])): ?>
              <img src="<?= h((string) $currentHousehold['household_image']) ?>" alt="<?= h((string) $currentHousehold['household_name']) ?>" class="household-banner-img">
            <?php else: ?>
              <div class="household-banner-placeholder">
                <i class="bi bi-house-heart-fill mb-1"></i>
                <span class="small fw-bold opacity-75">RoomieSync Home</span>
              </div>
            <?php endif; ?>

            <div class="p-4">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <h2 class="h4 fw-bold text-main mb-0"><?= h((string) $currentHousehold['household_name']) ?></h2>
                <?php
                  $roleClass = (strcasecmp($currentUserRole, 'Admin') === 0) ? 'admin' : 'roommate';
                ?>
                <span class="role-badge <?= h($roleClass) ?>">
                  You · <?= h($currentUserRole) ?>
                </span>
              </div>

              <!-- Description -->
              <p class="text-secondary-custom small mb-4">
                <?= !empty($currentHousehold['description']) ? nl2br(h((string) $currentHousehold['description'])) : 'No description provided for this household.' ?>
              </p>

              <hr class="border-secondary opacity-25 my-3">

              <!-- Meta Information Details -->
              <div class="mb-4">
                <!-- Address -->
                <div class="household-meta-item">
                  <div class="household-meta-icon"><i class="bi bi-geo-alt"></i></div>
                  <div>
                    <span class="text-secondary-custom small d-block">Address</span>
                    <span class="fw-semibold text-main small">
                      <?= !empty($currentHousehold['address']) ? h((string) $currentHousehold['address']) : 'No address set' ?>
                    </span>
                  </div>
                </div>

                <!-- Created Date -->
                <div class="household-meta-item">
                  <div class="household-meta-icon"><i class="bi bi-calendar3"></i></div>
                  <div>
                    <span class="text-secondary-custom small d-block">Created Date</span>
                    <span class="fw-semibold text-main small">
                      <?= date('F j, Y', strtotime((string) $currentHousehold['created_at'])) ?>
                    </span>
                  </div>
                </div>

                <!-- Total Members count -->
                <div class="household-meta-item">
                  <div class="household-meta-icon"><i class="bi bi-people"></i></div>
                  <div>
                    <span class="text-secondary-custom small d-block">Total Members</span>
                    <span class="fw-semibold text-main small">
                      <?= count($members) ?> <?= count($members) === 1 ? 'member' : 'members' ?>
                    </span>
                  </div>
                </div>
              </div>

              <!-- Join Code Section -->
              <div class="pt-2">
                <label class="form-label text-secondary-custom small fw-bold text-uppercase letter-spacing-1 mb-2">Household Join Code</label>
                <div class="join-code-box w-100 justify-content-between">
                  <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-key-fill text-dark-accent"></i>
                    <span class="join-code-val" id="householdJoinCodeText"><?= h((string) ($currentHousehold['join_code'] ?? 'N/A')) ?></span>
                  </div>
                  <?php if (!empty($currentHousehold['join_code'])): ?>
                    <button type="button" class="btn btn-rs-outline btn-sm copy-btn-feedback" id="copyJoinCodeBtn" title="Copy code to clipboard">
                      <i class="bi bi-clipboard me-1"></i> Copy
                    </button>
                  <?php endif; ?>
                </div>
                <small class="text-secondary-custom d-block mt-2" style="font-size: 0.76rem;">
                  Share this code with roommates to invite them to join this household.
                </small>
              </div>
            </div>
          </div>
        </div>

        <!-- Right Column: Household Members Card + Recent Activity Below -->
        <div class="col-lg-7 col-xl-8 d-flex flex-column gap-4">
          <!-- 1. Household Members Card -->
          <section class="rs-card p-0 overflow-hidden">
            <div class="p-4 border-bottom bg-warm-secondary d-flex flex-wrap justify-content-between align-items-center gap-2">
              <div>
                <h2 class="h5 fw-bold text-main mb-0">Household Members</h2>
                <small class="text-secondary-custom">List of everyone living in this household</small>
              </div>
              <span class="badge bg-white text-dark-accent border px-2 py-1 small fw-bold">
                <?= $totalMembersCount ?> <?= $totalMembersCount === 1 ? 'Member' : 'Members' ?>
              </span>
            </div>

            <!-- Desktop View: Table Layout -->
            <div class="d-none d-md-block overflow-x-auto">
              <table class="table member-table">
                <thead>
                  <tr>
                    <th>Member</th>
                    <th>Role</th>
                    <th>Joined Date</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($members as $m): ?>
                    <?php
                      $isYou = ((int) $m['user_id'] === $userId);
                      $mRole = (string) $m['role'];
                      $isMemberAdmin = strcasecmp($mRole, 'Admin') === 0;
                      $roleBadgeClass = $isMemberAdmin ? 'admin' : 'roommate';
                      $mDisplayName = (string) $m['full_name'];
                      $mUsername = (string) $m['username'];
                      $mAvatar = household_initials($mDisplayName !== '' ? $mDisplayName : $mUsername);
                    ?>
                    <tr class="<?= $isYou ? 'is-you' : '' ?>">
                      <td>
                        <div class="d-flex align-items-center gap-3">
                          <?= render_avatar_markup($mAvatar, (string) ($m['profile_picture'] ?? ''), $mDisplayName, '', 'width: 40px; height: 40px; font-size: 0.95rem;') ?>
                          <div>
                            <div class="fw-bold text-main d-flex align-items-center gap-2">
                              <?= h($mDisplayName) ?>
                              <?php if ($isYou): ?>
                                <span class="badge-you">You</span>
                              <?php endif; ?>
                            </div>
                            <small class="text-secondary-custom">@<?= h($mUsername) ?></small>
                          </div>
                        </div>
                      </td>
                      <td>
                        <span class="role-badge <?= h($roleBadgeClass) ?>">
                          <?= h($isMemberAdmin ? 'Admin' : 'Roommate') ?>
                        </span>
                      </td>
                      <td>
                        <span class="small fw-semibold text-secondary-custom">
                          <?= date('M j, Y', strtotime((string) $m['joined_at'])) ?>
                        </span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <!-- Mobile View: Card-based list -->
            <div class="d-md-none p-3">
              <?php foreach ($members as $m): ?>
                <?php
                  $isYou = ((int) $m['user_id'] === $userId);
                  $mRole = (string) $m['role'];
                  $isMemberAdmin = strcasecmp($mRole, 'Admin') === 0;
                  $roleBadgeClass = $isMemberAdmin ? 'admin' : 'roommate';
                  $mDisplayName = (string) $m['full_name'];
                  $mUsername = (string) $m['username'];
                  $mAvatar = household_initials($mDisplayName !== '' ? $mDisplayName : $mUsername);
                ?>
                <div class="member-mobile-card <?= $isYou ? 'is-you' : '' ?>">
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="d-flex align-items-center gap-2">
                      <?= render_avatar_markup($mAvatar, (string) ($m['profile_picture'] ?? ''), $mDisplayName, '', 'width: 36px; height: 36px; font-size: 0.85rem;') ?>
                      <div>
                        <div class="fw-bold text-main small d-flex align-items-center gap-1">
                          <?= h($mDisplayName) ?>
                          <?php if ($isYou): ?>
                            <span class="badge-you" style="font-size: 0.65rem;">You</span>
                          <?php endif; ?>
                        </div>
                        <small class="text-secondary-custom" style="font-size: 0.75rem;">@<?= h($mUsername) ?></small>
                      </div>
                    </div>
                    <span class="role-badge <?= h($roleBadgeClass) ?>" style="font-size: 0.68rem;">
                      <?= h($isMemberAdmin ? 'Admin' : 'Roommate') ?>
                    </span>
                  </div>
                  <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                    <small class="text-secondary-custom" style="font-size: 0.75rem;">Joined</small>
                    <small class="fw-semibold text-main" style="font-size: 0.75rem;">
                      <?= date('M j, Y', strtotime((string) $m['joined_at'])) ?>
                    </small>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <!-- Members Pagination -->
            <div class="px-4 pb-2">
              <?= render_pagination($memberPagination, [], 'householdMembers') ?>
            </div>
          </section>

          <!-- 2. Recent Activity Card (Positioned below Members) -->
          <section class="rs-card p-0 overflow-hidden" id="recentActivity">
            <div class="p-4 border-bottom bg-warm-secondary d-flex flex-wrap justify-content-between align-items-center gap-2">
              <div>
                <h2 class="h5 fw-bold text-main mb-0">
                  <i class="bi bi-clock-history me-1 text-dark-accent"></i> Recent Activity
                </h2>
                <small class="text-secondary-custom">Latest updates recorded across your household</small>
              </div>
              <?php if (!empty($totalActivitiesCount)): ?>
                <span class="badge bg-white text-dark-accent border px-2 py-1 small fw-bold">
                  <?= $totalActivitiesCount ?> Updates
                </span>
              <?php endif; ?>
            </div>

            <?php if (empty($activities)): ?>
              <div class="p-5 text-center text-secondary-custom">
                <i class="bi bi-clock-history fs-1 d-block mb-2 text-muted"></i>
                <div class="fw-bold mb-1">No recent activity yet</div>
                <div class="small">Household payments, completed chores, and member updates will appear here.</div>
              </div>
            <?php else: ?>
              <div class="activity-timeline-list">
                <?php foreach ($activities as $act): ?>
                  <?php
                    $actType = (string) $act['type'];
                    $actIcon = 'bi-activity';
                    $actTitle = '';
                    $actBadgeClass = $actType;

                    if ($actType === 'payment') {
                        $actIcon = 'bi-credit-card-2-front-fill';
                        $actTitle = '<strong>' . h((string) ($act['full_name'] ?: $act['username'])) . '</strong> paid <strong>₱' . number_format((float) $act['amount'], 2) . '</strong> for ' . h((string) $act['item']) . '.';
                    } elseif ($actType === 'expense') {
                        $actIcon = 'bi-receipt-cutoff';
                        $actTitle = '<strong>' . h((string) ($act['full_name'] ?: ($act['username'] ?: 'A member'))) . '</strong> logged expense ' . h((string) $act['item']) . ' (₱' . number_format((float) $act['amount'], 2) . ').';
                    } elseif ($actType === 'chore') {
                        $actIcon = 'bi-check2-circle';
                        $actTitle = '<strong>' . h((string) ($act['full_name'] ?: $act['username'])) . '</strong> completed chore <strong>' . h((string) $act['item']) . '</strong>.';
                    } elseif ($actType === 'member') {
                        $actIcon = 'bi-person-plus-fill';
                        $actTitle = '<strong>' . h((string) ($act['full_name'] ?: $act['username'])) . '</strong> joined as ' . h((string) $act['item']) . '.';
                    }
                  ?>
                  <div class="activity-item">
                    <div class="activity-icon-badge <?= h($actBadgeClass) ?>">
                      <i class="bi <?= h($actIcon) ?>"></i>
                    </div>
                    <div class="flex-grow-1">
                      <p class="small text-main mb-1"><?= $actTitle ?></p>
                      <small class="text-secondary-custom" style="font-size: 0.75rem;">
                        <i class="bi bi-clock me-1"></i><?= date('M j, Y · g:i A', strtotime((string) $act['activity_at'])) ?>
                      </small>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              <!-- Activity Pagination -->
              <div class="px-4 pb-2">
                <?= render_pagination($actPagination, [], 'recentActivity') ?>
              </div>
            <?php endif; ?>
          </section>
        </div>


      </div>
    <?php endif; ?>
  </main>

  <!-- Join Household Modal -->
  <div class="modal fade" id="joinHouseholdModal" tabindex="-1" aria-labelledby="joinHouseholdModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content rs-card p-0 border-0 shadow-lg">
        <div class="modal-header border-bottom p-4">
          <div>
            <span class="rs-eyebrow d-block mb-1">Enter Invite Code</span>
            <h5 class="modal-title fw-bold text-main" id="joinHouseholdModalLabel">Join a Household</h5>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <form action="actions/household-join.php" method="POST" id="joinHouseholdForm">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <div class="modal-body p-4">
            <p class="text-secondary-custom small mb-3">
              Enter the unique invite code provided by your household admin or roommate to join their home as a Roommate.
            </p>

            <div class="mb-3">
              <label for="joinHouseholdInput" class="form-label fw-bold text-main small">Household Invite Code <span class="text-danger">*</span></label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-key"></i></span>
                <input type="text" class="form-control" id="joinHouseholdInput" name="invite_code"
                       placeholder="e.g. ROOMIE123" maxlength="32" autocomplete="off" required>
              </div>
            </div>
          </div>

          <div class="modal-footer border-top p-3">
            <button type="button" class="btn btn-rs-outline" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-rs-primary">Join Household</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/household.js"></script>
</body>
</html>
