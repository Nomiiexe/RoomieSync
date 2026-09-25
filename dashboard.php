<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if (!restore_remembered_login()) {
    redirect_to('login.php');
}

$connection = database_connection();
$userId = (int) $_SESSION['user_id'];
$householdId = selected_household_id();
if ($householdId === null) {
    redirect_to('households.php');
}
$username = (string) ($_SESSION['username'] ?? '');
$fullName = (string) ($_SESSION['full_name'] ?? $username);
$userEmail = '';
$notice = consume_flash_message();

$userQuery = $connection->prepare(
    'SELECT username, full_name, email FROM userTb WHERE user_id = ? LIMIT 1'
);
$userQuery->bind_param('i', $userId);
$userQuery->execute();
$user = $userQuery->get_result()->fetch_assoc();
if ($user) {
    $username = (string) $user['username'];
    $fullName = (string) $user['full_name'];
    $userEmail = (string) ($user['email'] ?? '');
}

$activeHousehold = null;
$householdQuery = $connection->prepare(
    'SELECT h.household_id, h.household_name, h.description, h.join_code, hm.role
     FROM householdmembersTb AS hm
     INNER JOIN householdTb AS h ON h.household_id = hm.household_id
     WHERE hm.user_id = ? AND hm.household_id = ?
     LIMIT 1'
);
$householdQuery->bind_param('ii', $userId, $householdId);
$householdQuery->execute();
$activeHousehold = $householdQuery->get_result()->fetch_assoc() ?: null;

$householdId = $activeHousehold ? (int) $activeHousehold['household_id'] : 0;
if ($householdId < 1) {
    unset($_SESSION['household_id']);
    redirect_to('households.php');
}
$pendingChores = 0;
$balanceDue = 0.0;
$paidThisMonth = 0.0;
$housemateCount = 0;
$upcomingBill = null;
$chores = [];
$announcement = null;
$activities = [];

if ($householdId > 0) {
    $pendingQuery = $connection->prepare(
        "SELECT COUNT(*) AS total
         FROM chore_assignmentsTb AS ca
         INNER JOIN choresTb AS c ON c.chore_id = ca.chore_id
         WHERE c.household_id = ?
           AND LOWER(ca.status) <> 'completed'
           AND ca.assignment_id = (
               SELECT ca2.assignment_id
               FROM chore_assignmentsTb AS ca2
               WHERE ca2.chore_id = c.chore_id
                 AND LOWER(ca2.status) <> 'completed'
               ORDER BY ca2.assignment_id DESC
               LIMIT 1
           )"
    );
    $pendingQuery->bind_param('i', $householdId);
    $pendingQuery->execute();
    $pendingChores = (int) ($pendingQuery->get_result()->fetch_assoc()['total'] ?? 0);

    $balanceQuery = $connection->prepare(
        "SELECT COALESCE(SUM(bs.share_amount), 0) AS amount
         FROM bill_sharesTb AS bs
         INNER JOIN billsTb AS b ON b.bill_id = bs.bill_id
         WHERE bs.user_id = ? AND b.household_id = ? AND LOWER(bs.payment_status) <> 'paid'"
    );
    $balanceQuery->bind_param('ii', $userId, $householdId);
    $balanceQuery->execute();
    $balanceDue = (float) ($balanceQuery->get_result()->fetch_assoc()['amount'] ?? 0);

    $paidQuery = $connection->prepare(
        "SELECT COALESCE(SUM(p.amount), 0) AS amount
         FROM paymentsTb AS p
         INNER JOIN bill_sharesTb AS bs ON bs.bill_share_id = p.bill_share_id
         INNER JOIN billsTb AS b ON b.bill_id = bs.bill_id
         WHERE bs.user_id = ? AND b.household_id = ?
           AND p.payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
    );
    $paidQuery->bind_param('ii', $userId, $householdId);
    $paidQuery->execute();
    $paidThisMonth = (float) ($paidQuery->get_result()->fetch_assoc()['amount'] ?? 0);

    $membersQuery = $connection->prepare(
        'SELECT COUNT(*) AS total FROM householdmembersTb WHERE household_id = ?'
    );
    $membersQuery->bind_param('i', $householdId);
    $membersQuery->execute();
    $housemateCount = (int) ($membersQuery->get_result()->fetch_assoc()['total'] ?? 0);

    $billQuery = $connection->prepare(
        "SELECT bill_name, amount, due_date, status
         FROM billsTb
         WHERE household_id = ? AND LOWER(status) <> 'paid'
         ORDER BY due_date ASC
         LIMIT 1"
    );
    $billQuery->bind_param('i', $householdId);
    $billQuery->execute();
    $upcomingBill = $billQuery->get_result()->fetch_assoc() ?: null;

    $choreQuery = $connection->prepare(
        'SELECT ca.assignment_id, c.chore_name, ca.due_date, ca.status, u.full_name AS assigned_name
         FROM chore_assignmentsTb AS ca
         INNER JOIN choresTb AS c ON c.chore_id = ca.chore_id
         INNER JOIN userTb AS u ON u.user_id = ca.user_id
         WHERE c.household_id = ?
           AND LOWER(ca.status) <> \'completed\'
           AND ca.assignment_id = (
               SELECT ca2.assignment_id
               FROM chore_assignmentsTb AS ca2
               WHERE ca2.chore_id = c.chore_id
                 AND LOWER(ca2.status) <> \'completed\'
               ORDER BY ca2.assignment_id DESC
               LIMIT 1
           )
         ORDER BY ca.due_date ASC
         LIMIT 8'
    );
    $choreQuery->bind_param('i', $householdId);
    $choreQuery->execute();
    $chores = $choreQuery->get_result()->fetch_all(MYSQLI_ASSOC);

    $announcementQuery = $connection->prepare(
        'SELECT a.title, a.content, a.created_at, u.username AS created_by_username
         FROM announceTb AS a
         LEFT JOIN userTb AS u ON u.user_id = a.created_by
         WHERE a.household_id = ?
         ORDER BY a.created_at DESC
         LIMIT 1'
    );
    $announcementQuery->bind_param('i', $householdId);
    $announcementQuery->execute();
    $announcement = $announcementQuery->get_result()->fetch_assoc() ?: null;

    $activityQueries = [
        [
            'sql' => 'SELECT p.payment_date AS activity_at, u.username, b.bill_name AS item, p.amount
                      FROM paymentsTb AS p
                      INNER JOIN bill_sharesTb AS bs ON bs.bill_share_id = p.bill_share_id
                      INNER JOIN billsTb AS b ON b.bill_id = bs.bill_id
                      INNER JOIN userTb AS u ON u.user_id = bs.user_id
                      WHERE b.household_id = ? ORDER BY p.payment_date DESC LIMIT 5',
            'type' => 'payment',
        ],
        [
            'sql' => 'SELECT e.created_at AS activity_at, u.username, e.title AS item, e.amount
                      FROM expenseTb AS e
                      LEFT JOIN userTb AS u ON u.user_id = e.paid_by
                      WHERE e.household_id = ? ORDER BY e.created_at DESC LIMIT 5',
            'type' => 'expense',
        ],
        [
            'sql' => 'SELECT ca.completed_at AS activity_at, u.username, c.chore_name AS item
                      FROM chore_assignmentsTb AS ca
                      INNER JOIN choresTb AS c ON c.chore_id = ca.chore_id
                      INNER JOIN userTb AS u ON u.user_id = ca.user_id
                      WHERE c.household_id = ? AND LOWER(ca.status) = \'completed\'
                        AND ca.completed_at IS NOT NULL
                      ORDER BY ca.completed_at DESC LIMIT 5',
            'type' => 'chore',
        ],
    ];

    foreach ($activityQueries as $activityQuery) {
        $query = $connection->prepare($activityQuery['sql']);
        $query->bind_param('i', $householdId);
        $query->execute();
        $activityResult = $query->get_result();
        while ($activity = $activityResult->fetch_assoc()) {
            $activity['type'] = $activityQuery['type'];
            $activities[] = $activity;
        }
        $activityResult->free();
        $query->close();
    }
    usort(
        $activities,
        static fn (array $left, array $right): int =>
            strtotime((string) $right['activity_at']) <=> strtotime((string) $left['activity_at'])
    );
    $activities = array_slice($activities, 0, 5);
}

function dashboard_initials(string $value): string
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

function dashboard_money(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

function dashboard_date(?string $value): string
{
    return $value ? date('M j, Y', strtotime($value)) : 'Date unavailable';
}

function dashboard_date_time(?string $value): string
{
    return $value ? date('M j, Y g:i A', strtotime($value)) : 'Time unavailable';
}

function dashboard_status_class(string $status): string
{
    return strtolower($status) === 'completed' ? 'rs-badge-completed' : 'rs-badge-pending';
}

$displayName = $username !== '' ? $username : $fullName;
$avatarText = dashboard_initials($username !== '' ? $username : $fullName);
$householdName = $activeHousehold ? (string) $activeHousehold['household_name'] : '';
$role = $activeHousehold ? (string) $activeHousehold['role'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard | RoomieSync</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="d-flex flex-column min-vh-100">
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
        <li><a href="dashboard.php" class="rs-nav-link active"><i class="bi bi-grid-1x2-fill"></i><span>Dashboard</span></a></li>
        <li><a href="chores.php" class="rs-nav-link"><i class="bi bi-check2-square"></i><span>Chores</span></a></li>
        <li><a href="#" class="rs-nav-link" aria-disabled="true" title="Bills page coming soon"><i class="bi bi-receipt"></i><span>Bills</span></a></li>
        <li><a href="#" class="rs-nav-link" aria-disabled="true" title="Household page coming soon"><i class="bi bi-house"></i><span>Household</span></a></li>
        <li><a href="#" class="rs-nav-link" aria-disabled="true" title="Reports page coming soon"><i class="bi bi-bar-chart"></i><span>Reports</span></a></li>
        <?php if (strcasecmp($role, 'Admin') === 0): ?><li><a href="#" class="rs-nav-link" aria-disabled="true" title="Manage page coming soon"><i class="bi bi-sliders"></i><span>Manage</span></a></li><?php endif; ?>
      </ul>

      <div class="rs-nav-user dropdown">
        <a href="#" class="rs-user-btn dropdown-toggle" role="button" data-bs-toggle="dropdown" aria-expanded="false">
          <div class="rs-avatar"><?= h($avatarText) ?></div>
          <span class="d-none d-md-inline"><?= h($displayName) ?></span>
          <?php if ($role !== ''): ?><span class="role-badge roommate d-none d-xl-inline"><?= h($role) ?></span><?php endif; ?>
        </a>
        <ul class="dropdown-menu dropdown-menu-end rs-dropdown">
          <li class="px-3 py-2 border-bottom mb-1">
            <div class="fw-bold text-dark-accent"><?= h($displayName) ?></div>
            <?php if ($userEmail !== ''): ?>
              <small class="text-secondary-custom"><?= h($userEmail) ?></small>
            <?php else: ?>
              <small class="text-secondary-custom">No email on profile</small>
            <?php endif; ?>
            <?php if ($role !== ''): ?><div class="mt-1"><span class="role-badge roommate"><?= h($role) ?></span></div><?php endif; ?>
          </li>
          <li><a class="dropdown-item rs-dropdown-item" href="profile.php"><i class="bi bi-person-circle"></i> Profile</a></li>
          <li><a class="dropdown-item rs-dropdown-item" href="households.php"><i class="bi bi-arrow-left-right"></i> Switch Household</a></li>
          <li><a class="dropdown-item rs-dropdown-item" href="join-household.php"><i class="bi bi-door-open"></i> Join Another Home</a></li>
          <li><hr class="dropdown-divider rs-dropdown-divider"></li>
          <li><a class="dropdown-item rs-dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a></li>
        </ul>
      </div>
    </nav>
  </div>

  <main class="container py-4 flex-grow-1">
    <?php if ($notice): ?><div class="alert alert-success" role="status"><?= h((string) ($notice['message'] ?? '')) ?></div><?php endif; ?>
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-4">
      <div>
        <h2 class="fw-bold mb-1">Kamusta, <?= h($displayName) ?>!</h2>
        <p class="text-secondary-custom mb-0">
          <?php if ($activeHousehold): ?>
            Here is what is happening at <strong class="text-dark-accent"><?= h($householdName) ?></strong> today.
          <?php else: ?>
            You are not connected to a household yet.
          <?php endif; ?>
        </p>
      </div>
      <div><span class="badge bg-warm-secondary text-dark-accent border px-3 py-2 rounded-pill fw-semibold"><i class="bi bi-calendar-event me-1"></i> <?= h(date('M j, Y')) ?></span></div>
    </div>

    <div class="row g-3 mb-4">
      <div class="col-sm-6 col-lg-3">
        <div class="rs-stat-card">
          <div class="rs-stat-icon" style="background-color: var(--rs-status-amber-bg); color: var(--rs-status-amber-text);"><i class="bi bi-clock-history"></i></div>
          <div class="rs-stat-content">
            <div class="rs-stat-label">Pending Chores</div>
            <div class="rs-stat-value"><?= $activeHousehold ? $pendingChores : '—' ?></div>
            <div class="rs-stat-subtext text-secondary-custom"><?= $activeHousehold ? ($pendingChores === 0 ? 'No pending chores' : 'Current household assignments') : 'No household selected' ?></div>
          </div>
        </div>
      </div>
      <div class="col-sm-6 col-lg-3">
        <div class="rs-stat-card">
          <div class="rs-stat-icon" style="background-color: var(--rs-accent-light); color: var(--rs-dark-accent);"><i class="bi bi-wallet2"></i></div>
          <div class="rs-stat-content">
            <div class="rs-stat-label">Your Balance Due</div>
            <div class="rs-stat-value"><?= $activeHousehold ? h(dashboard_money($balanceDue)) : '—' ?></div>
            <div class="rs-stat-subtext text-secondary-custom"><?= $activeHousehold ? h(dashboard_money($paidThisMonth)) . ' paid this month' : 'No household selected' ?></div>
          </div>
        </div>
      </div>
      <div class="col-sm-6 col-lg-3">
        <div class="rs-stat-card">
          <div class="rs-stat-icon" style="background-color: var(--rs-card-secondary); color: var(--rs-dark-accent);"><i class="bi bi-receipt"></i></div>
          <div class="rs-stat-content">
            <div class="rs-stat-label">Upcoming Bill</div>
            <div class="rs-stat-value"><?= $upcomingBill ? h(dashboard_money((float) $upcomingBill['amount'])) : '—' ?></div>
            <div class="rs-stat-subtext text-secondary-custom"><?= $upcomingBill ? h((string) $upcomingBill['bill_name']) . ' · Due ' . h(dashboard_date((string) $upcomingBill['due_date'])) : 'No data available' ?></div>
          </div>
        </div>
      </div>
      <div class="col-sm-6 col-lg-3">
        <div class="rs-stat-card">
          <div class="rs-stat-icon" style="background-color: var(--rs-accent-light); color: var(--rs-dark-accent);"><i class="bi bi-people-fill"></i></div>
          <div class="rs-stat-content">
            <div class="rs-stat-label">Housemates</div>
            <div class="rs-stat-value"><?= $activeHousehold ? $housemateCount : '—' ?></div>
            <div class="rs-stat-subtext text-secondary-custom"><?= $activeHousehold ? h($householdName) : 'No household selected' ?></div>
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
              <div class="rs-card-subtitle">Current assignments in your household</div>
            </div>
            <div class="d-flex align-items-center gap-2">
              <?php if ($activeHousehold): ?><span class="badge bg-warm-secondary text-dark-accent border px-2 py-1"><?= count($chores) ?> shown</span><?php endif; ?>
              <a href="chores.php" class="btn btn-sm btn-rs-outline">View all</a>
            </div>
          </div>

          <?php if (!$activeHousehold): ?>
            <div class="p-4 text-center bg-warm-secondary rounded-3">
              <i class="bi bi-house-slash fs-3 text-dark-accent"></i>
              <p class="mb-0 mt-2 text-secondary-custom">Join a household to see your chores.</p>
            </div>
          <?php elseif (!$chores): ?>
            <div class="p-4 text-center bg-warm-secondary rounded-3">
              <i class="bi bi-check2-circle fs-3 text-dark-accent"></i>
              <p class="mb-0 mt-2 text-secondary-custom">No pending chores in this household.</p>
            </div>
          <?php else: ?>
            <div class="list-group list-group-flush border rounded-3 overflow-hidden">
              <?php foreach ($chores as $index => $chore): ?>
                <?php $status = (string) $chore['status']; ?>
                <div class="list-group-item p-3 d-flex justify-content-between align-items-center bg-transparent <?= $index < count($chores) - 1 ? 'border-bottom' : '' ?> rs-chore-item" data-status="<?= h(strtolower($status)) ?>">
                  <div class="d-flex align-items-center gap-3">
                    <div>
                      <div class="fw-bold text-dark-accent rs-chore-title"><?= h((string) $chore['chore_name']) ?></div>
                      <small class="text-secondary-custom"><i class="bi bi-person me-1"></i><?= h((string) $chore['assigned_name']) ?> <span class="mx-1">·</span> <i class="bi bi-calendar3 me-1"></i><?= h(dashboard_date((string) $chore['due_date'])) ?></small>
                    </div>
                  </div>
                  <span class="rs-badge <?= h(dashboard_status_class($status)) ?> rs-chore-badge"><span class="rs-badge-dot"></span><?= h($status) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="col-lg-5 d-flex flex-column gap-4">
        <div class="rs-card">
          <div class="rs-card-header mb-2">
            <div>
              <h5 class="rs-card-title">Notice Board</h5>
              <div class="rs-card-subtitle"><?= $announcement ? 'Latest household announcement' : 'No announcements yet' ?></div>
            </div>
            <?php if ($announcement): ?><span class="badge bg-warm-secondary text-dark-accent border">Notice</span><?php endif; ?>
          </div>
          <?php if ($announcement): ?>
            <div class="p-3 rounded-3 bg-warm-secondary mb-2">
              <h6 class="fw-bold mb-1 text-dark-accent"><i class="bi bi-megaphone-fill me-1 text-dark-accent"></i><?= h((string) $announcement['title']) ?></h6>
              <p class="small mb-0 text-secondary-custom"><?= nl2br(h((string) $announcement['content'])) ?></p>
            </div>
            <div class="text-end"><small class="text-secondary-custom">Posted <?= h(dashboard_date_time((string) $announcement['created_at'])) ?><?= $announcement['created_by_username'] ? ' by ' . h((string) $announcement['created_by_username']) : '' ?></small></div>
          <?php else: ?>
            <div class="p-4 text-center bg-warm-secondary rounded-3"><i class="bi bi-megaphone fs-3 text-dark-accent"></i><p class="mb-0 mt-2 text-secondary-custom">No announcements available.</p></div>
          <?php endif; ?>
        </div>

        <div class="rs-card flex-grow-1">
          <div class="rs-card-header mb-2">
            <div>
              <h5 class="rs-card-title">Recent Activity</h5>
              <div class="rs-card-subtitle">Activity recorded in your household</div>
            </div>
          </div>
          <?php if (!$activities): ?>
            <div class="p-4 text-center bg-warm-secondary rounded-3"><i class="bi bi-activity fs-3 text-dark-accent"></i><p class="mb-0 mt-2 text-secondary-custom">No recent activity.</p></div>
          <?php else: ?>
            <div class="d-flex flex-column gap-3">
              <?php foreach ($activities as $activity): ?>
                <div class="d-flex align-items-start gap-2">
                  <div class="rs-avatar avatar-sm mt-1"><?= h(dashboard_initials((string) ($activity['username'] ?? ''))) ?></div>
                  <div class="flex-grow-1">
                    <?php if ($activity['type'] === 'payment'): ?>
                      <p class="small mb-0"><strong><?= h((string) $activity['username']) ?></strong> paid <strong><?= h(dashboard_money((float) $activity['amount'])) ?></strong> for <?= h((string) $activity['item']) ?>.</p>
                    <?php elseif ($activity['type'] === 'expense'): ?>
                      <p class="small mb-0"><strong><?= h((string) ($activity['username'] ?? 'Unknown user')) ?></strong> logged <?= h((string) $activity['item']) ?> for <strong><?= h(dashboard_money((float) $activity['amount'])) ?></strong>.</p>
                    <?php else: ?>
                      <p class="small mb-0"><strong><?= h((string) $activity['username']) ?></strong> completed <strong><?= h((string) $activity['item']) ?></strong>.</p>
                    <?php endif; ?>
                    <small class="text-secondary-custom" style="font-size: 0.75rem;"><?= h(dashboard_date_time((string) $activity['activity_at'])) ?></small>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/main.js"></script>
</body>
</html>
