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
         LIMIT 5'
    );
    $choreQuery->bind_param('i', $householdId);
    $choreQuery->execute();
    $chores = $choreQuery->get_result()->fetch_all(MYSQLI_ASSOC);

    $upcomingBills = [];
    $upcomingBillsQuery = $connection->prepare(
        "SELECT b.bill_id, b.bill_name, b.amount, b.due_date, b.status, cat.category_name
         FROM billsTb AS b
         LEFT JOIN bill_categoriesTb AS cat ON cat.bill_category_id = b.category_id AND cat.household_id = b.household_id
         WHERE b.household_id = ? AND LOWER(b.status) <> 'paid'
         ORDER BY b.due_date ASC
         LIMIT 5"
    );
    $upcomingBillsQuery->bind_param('i', $householdId);
    $upcomingBillsQuery->execute();
    $upcomingBills = $upcomingBillsQuery->get_result()->fetch_all(MYSQLI_ASSOC);

    $recentExpenses = [];
    $recentExpensesQuery = $connection->prepare(
        "SELECT e.expense_id, e.title, e.amount, e.expense_date,
                COALESCE(u.full_name, u.username, 'Member') AS payer_name,
                COALESCE(cat.category_name, 'General') AS category_name
         FROM expenseTb AS e
         LEFT JOIN userTb AS u ON u.user_id = e.paid_by
         LEFT JOIN expense_categoriesTb AS cat ON cat.expense_category_id = e.category_id AND cat.household_id = e.household_id
         WHERE e.household_id = ?
         ORDER BY e.expense_date DESC, e.expense_id DESC
         LIMIT 5"
    );
    $recentExpensesQuery->bind_param('i', $householdId);
    $recentExpensesQuery->execute();
    $recentExpenses = $recentExpensesQuery->get_result()->fetch_all(MYSQLI_ASSOC);

    $announcements = [];
    $announcementQuery = $connection->prepare(
        'SELECT a.announcement_id, a.title, a.content, a.created_at, u.username AS created_by_username
         FROM announceTb AS a
         LEFT JOIN userTb AS u ON u.user_id = a.created_by
         WHERE a.household_id = ?
         ORDER BY a.created_at DESC
         LIMIT 3'
    );
    $announcementQuery->bind_param('i', $householdId);
    $announcementQuery->execute();
    $announcements = $announcementQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $announcement = $announcements[0] ?? null;

    try {
        $activityQueries = [
            [
                'sql' => 'SELECT ca.completed_at AS activity_at, u.username, u.full_name, u.profile_picture, c.chore_name AS item, NULL AS amount
                          FROM chore_assignmentsTb AS ca
                          INNER JOIN choresTb AS c ON c.chore_id = ca.chore_id
                          INNER JOIN userTb AS u ON u.user_id = ca.user_id
                          WHERE c.household_id = ? AND LOWER(ca.status) = "completed" AND ca.completed_at IS NOT NULL
                          ORDER BY ca.completed_at DESC LIMIT 5',
                'type' => 'chore_completed',
            ],
            [
                'sql' => 'SELECT c.created_at AS activity_at, u.username, u.full_name, u.profile_picture, c.chore_name AS item, NULL AS amount
                          FROM choresTb AS c
                          LEFT JOIN userTb AS u ON u.user_id = c.created_by
                          WHERE c.household_id = ?
                          ORDER BY c.created_at DESC LIMIT 5',
                'type' => 'chore_created',
            ],
            [
                'sql' => 'SELECT b.created_at AS activity_at, u.username, u.full_name, u.profile_picture, b.bill_name AS item, b.amount
                          FROM billsTb AS b
                          LEFT JOIN userTb AS u ON u.user_id = b.created_by
                          WHERE b.household_id = ?
                          ORDER BY b.created_at DESC LIMIT 5',
                'type' => 'bill_created',
            ],
            [
                'sql' => 'SELECT p.payment_date AS activity_at, u.username, u.full_name, u.profile_picture, b.bill_name AS item, p.amount
                          FROM paymentsTb AS p
                          INNER JOIN bill_sharesTb AS bs ON bs.bill_share_id = p.bill_share_id
                          INNER JOIN billsTb AS b ON b.bill_id = bs.bill_id
                          LEFT JOIN userTb AS u ON u.user_id = bs.user_id
                          WHERE b.household_id = ?
                          ORDER BY p.payment_date DESC, p.payment_id DESC LIMIT 5',
                'type' => 'payment',
            ],
            [
                'sql' => 'SELECT e.created_at AS activity_at, u.username, u.full_name, u.profile_picture, e.title AS item, e.amount
                          FROM expenseTb AS e
                          LEFT JOIN userTb AS u ON u.user_id = e.paid_by
                          WHERE e.household_id = ?
                          ORDER BY e.created_at DESC LIMIT 5',
                'type' => 'expense',
            ],
            [
                'sql' => 'SELECT a.created_at AS activity_at, u.username, u.full_name, u.profile_picture, a.title AS item, NULL AS amount
                          FROM announceTb AS a
                          LEFT JOIN userTb AS u ON u.user_id = a.created_by
                          WHERE a.household_id = ?
                          ORDER BY a.created_at DESC LIMIT 5',
                'type' => 'announcement',
            ],
            [
                'sql' => 'SELECT m.reported_at AS activity_at, u.username, u.full_name, u.profile_picture, m.problem_type AS item, NULL AS amount
                          FROM maintenanceTb AS m
                          LEFT JOIN userTb AS u ON u.user_id = m.reported_by
                          WHERE m.household_id = ?
                          ORDER BY m.reported_at DESC LIMIT 5',
                'type' => 'maintenance',
            ],
        ];

        foreach ($activityQueries as $actQuery) {
            $query = $connection->prepare($actQuery['sql']);
            if ($query) {
                $query->bind_param('i', $householdId);
                if ($query->execute()) {
                    $activityResult = $query->get_result();
                    while ($activity = $activityResult->fetch_assoc()) {
                        $activity['type'] = $actQuery['type'];
                        $activities[] = $activity;
                    }
                    $activityResult->free();
                }
                $query->close();
            }
        }
        usort(
            $activities,
            static fn (array $left, array $right): int =>
                strtotime((string) ($right['activity_at'] ?? '0')) <=> strtotime((string) ($left['activity_at'] ?? '0'))
        );
        $activities = array_slice($activities, 0, 5);
    } catch (Throwable $e) {
        error_log('Dashboard activity fetch error: ' . $e->getMessage());
        $activities = [];
    }

    // Pre-load current month tasks for instant interactive calendar rendering
    $calTasksByDate = [];
    $curMonth = date('Y-m');
    $curMonthStart = $curMonth . '-01';
    $curMonthEnd = date('Y-m-t', strtotime($curMonthStart));

    $calChoreQuery = $connection->prepare(
        "SELECT ca.assignment_id, ca.chore_id, c.chore_name, c.frequency,
                COALESCE(cat.category_name, 'General') AS category_name,
                ca.due_date, ca.status,
                u.user_id, COALESCE(NULLIF(u.full_name, ''), u.username, 'Unassigned') AS assigned_name
         FROM chore_assignmentsTb AS ca
         INNER JOIN choresTb AS c ON c.chore_id = ca.chore_id
         LEFT JOIN chore_categoriesTb AS cat ON cat.chore_category_id = c.category_id AND cat.household_id = c.household_id
         INNER JOIN userTb AS u ON u.user_id = ca.user_id
         WHERE c.household_id = ? AND ca.due_date >= ? AND ca.due_date <= ?
         ORDER BY ca.due_date ASC"
    );
    if ($calChoreQuery) {
        $calChoreQuery->bind_param('iss', $householdId, $curMonthStart, $curMonthEnd);
        if ($calChoreQuery->execute()) {
            $calChoreRows = $calChoreQuery->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($calChoreRows as $cRow) {
                $d = (string) $cRow['due_date'];
                $s = (string) $cRow['status'];
                $dispStatus = 'Pending';
                if (strcasecmp($s, 'completed') === 0) {
                    $dispStatus = 'Completed';
                } elseif ($d < date('Y-m-d')) {
                    $dispStatus = 'Overdue';
                } elseif ($d <= date('Y-m-d', strtotime('+3 days'))) {
                    $dispStatus = 'Due Soon';
                }
                $calTasksByDate[$d][] = [
                    'type' => 'chore',
                    'id' => (int) $cRow['assignment_id'],
                    'chore_id' => (int) $cRow['chore_id'],
                    'title' => (string) $cRow['chore_name'],
                    'assigned_name' => (string) $cRow['assigned_name'],
                    'category' => (string) $cRow['category_name'],
                    'frequency' => (string) $cRow['frequency'],
                    'due_date' => $d,
                    'status' => $dispStatus,
                    'is_completed' => strcasecmp($s, 'completed') === 0,
                    'url' => 'chores.php?search=' . rawurlencode((string) $cRow['chore_name']),
                ];
            }
        }
        $calChoreQuery->close();
    }

    $calBillQuery = $connection->prepare(
        "SELECT b.bill_id, b.bill_name, b.amount, b.due_date, b.status,
                COALESCE(cat.category_name, 'Utility') AS category_name
         FROM billsTb AS b
         LEFT JOIN bill_categoriesTb AS cat ON cat.bill_category_id = b.category_id AND cat.household_id = b.household_id
         WHERE b.household_id = ? AND b.due_date >= ? AND b.due_date <= ?
         ORDER BY b.due_date ASC"
    );
    if ($calBillQuery) {
        $calBillQuery->bind_param('iss', $householdId, $curMonthStart, $curMonthEnd);
        if ($calBillQuery->execute()) {
            $calBillRows = $calBillQuery->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($calBillRows as $bRow) {
                $d = (string) $bRow['due_date'];
                $s = (string) $bRow['status'];
                $dispStatus = (strcasecmp($s, 'paid') === 0) ? 'Paid' : ($d < date('Y-m-d') ? 'Overdue' : 'Unpaid');
                $calTasksByDate[$d][] = [
                    'type' => 'bill',
                    'id' => (int) $bRow['bill_id'],
                    'title' => (string) $bRow['bill_name'],
                    'amount' => (float) $bRow['amount'],
                    'amount_formatted' => '₱' . number_format((float) $bRow['amount'], 2),
                    'category' => (string) $bRow['category_name'],
                    'due_date' => $d,
                    'status' => $dispStatus,
                    'is_completed' => strcasecmp($s, 'paid') === 0,
                    'url' => 'bills.php?view=bills&search=' . rawurlencode((string) $bRow['bill_name']),
                ];
            }
        }
        $calBillQuery->close();
    }
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
        <li><a href="bills.php" class="rs-nav-link"><i class="bi bi-receipt"></i><span>Bills</span></a></li>
        <li><a href="household.php" class="rs-nav-link"><i class="bi bi-house"></i><span>Household</span></a></li>
        <li><a href="reports.php" class="rs-nav-link"><i class="bi bi-bar-chart"></i><span>Reports</span></a></li>
        <?php if (strcasecmp($role, 'Admin') === 0): ?><li><a href="manage.php" class="rs-nav-link"><i class="bi bi-sliders"></i><span>Manage</span></a></li><?php endif; ?>
      </ul>

      <div class="rs-nav-user dropdown">
        <a href="#" class="rs-user-btn dropdown-toggle" role="button" data-bs-toggle="dropdown" aria-expanded="false">
          <?= render_avatar_markup($avatarText, null, $displayName) ?>
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
        <a href="chores.php" class="rs-stat-minimal card-chores h-100" aria-label="Pending Chores - View Chores">
          <div class="rs-stat-minimal-top">
            <span class="rs-stat-minimal-label">Pending Chores</span>
            <div class="rs-stat-minimal-icon"><i class="bi bi-clock-history"></i></div>
          </div>
          <div class="rs-stat-minimal-val"><?= $activeHousehold ? $pendingChores : '—' ?></div>
          <div class="rs-stat-minimal-sub"><?= $activeHousehold ? ($pendingChores === 0 ? 'No pending chores' : 'Current assignments') : 'No household' ?></div>
        </a>
      </div>
      <div class="col-sm-6 col-lg-3">
        <a href="bills.php?view=bills&tab=my" class="rs-stat-minimal card-money h-100" aria-label="Your Balance Due - View My Shares">
          <div class="rs-stat-minimal-top">
            <span class="rs-stat-minimal-label">Your Balance Due</span>
            <div class="rs-stat-minimal-icon"><i class="bi bi-wallet2"></i></div>
          </div>
          <div class="rs-stat-minimal-val"><?= $activeHousehold ? h(dashboard_money($balanceDue)) : '—' ?></div>
          <div class="rs-stat-minimal-sub"><?= $activeHousehold ? h(dashboard_money($paidThisMonth)) . ' paid this month' : 'No household' ?></div>
        </a>
      </div>
      <div class="col-sm-6 col-lg-3">
        <a href="bills.php?view=bills" class="rs-stat-minimal card-money h-100" aria-label="Upcoming Bill - View Bills">
          <div class="rs-stat-minimal-top">
            <span class="rs-stat-minimal-label">Upcoming Bill</span>
            <div class="rs-stat-minimal-icon"><i class="bi bi-receipt"></i></div>
          </div>
          <div class="rs-stat-minimal-val"><?= $upcomingBill ? h(dashboard_money((float) $upcomingBill['amount'])) : '—' ?></div>
          <div class="rs-stat-minimal-sub"><?= $upcomingBill ? h((string) $upcomingBill['bill_name']) . ' · ' . h(dashboard_date((string) $upcomingBill['due_date'])) : 'No data' ?></div>
        </a>
      </div>
      <div class="col-sm-6 col-lg-3">
        <a href="household.php" class="rs-stat-minimal card-household h-100" aria-label="Housemates - View Household">
          <div class="rs-stat-minimal-top">
            <span class="rs-stat-minimal-label">Housemates</span>
            <div class="rs-stat-minimal-icon"><i class="bi bi-people-fill"></i></div>
          </div>
          <div class="rs-stat-minimal-val"><?= $activeHousehold ? $housemateCount : '—' ?></div>
          <div class="rs-stat-minimal-sub"><?= $activeHousehold ? h($householdName) : 'No household' ?></div>
        </a>
      </div>
    </div>

    <!-- Interactive Household Schedule Calendar & Selected Date Task Panel (Main Hero Card) -->
    <div class="row g-4 mb-4">
      <div class="col-lg-7">
        <div class="rs-calendar-card h-100" id="rsDashboardCalendar" data-today="<?= h(date('Y-m-d')) ?>">
          <div class="rs-cal-header">
            <div class="d-flex align-items-center gap-2">
              <div class="rs-stat-icon p-2 bg-warm-secondary rounded-circle text-dark-accent me-1" style="width: 38px; height: 38px; display: flex; align-items: center; justify-content: center;">
                <i class="bi bi-calendar3"></i>
              </div>
              <div>
                <h4 class="rs-cal-month-year mb-0">Calendar</h4>
                <small class="text-secondary-custom">Scheduled household chores &amp; bill due dates</small>
              </div>
            </div>
            <div class="rs-cal-controls">
              <button type="button" class="btn btn-sm btn-rs-outline rs-cal-prev" title="Previous Month" aria-label="Previous Month">
                <i class="bi bi-chevron-left"></i>
              </button>
              <button type="button" class="btn btn-sm btn-rs-primary rs-cal-today">Today</button>
              <button type="button" class="btn btn-sm btn-rs-outline rs-cal-next" title="Next Month" aria-label="Next Month">
                <i class="bi bi-chevron-right"></i>
              </button>
            </div>
          </div>
          <div class="rs-cal-grid">
            <!-- Dynamic Month Grid loaded by calendar.js -->
          </div>
          <div class="d-flex flex-wrap align-items-center justify-content-between pt-3 mt-3 border-top small text-secondary-custom gap-2">
            <div class="d-flex align-items-center gap-3">
              <span class="d-inline-flex align-items-center gap-1"><span class="rs-cal-dot dot-chore"></span> Chore</span>
              <span class="d-inline-flex align-items-center gap-1"><span class="rs-cal-dot dot-bill"></span> Bill Due</span>
            </div>
            <span class="d-inline-flex align-items-center gap-1"><span class="badge border bg-warm-secondary text-dark-accent px-2">Border</span> Today</span>
          </div>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="rs-cal-panel-card" id="rsCalTaskPanel">
          <!-- Dynamic Task details for selected date loaded by calendar.js -->
        </div>
      </div>
    </div>

    <!-- Minimalist Upcoming Chores & Bills Row (Display Limit: 5 items each) -->
    <div class="row g-4 mb-4">
      <!-- Upcoming Chores (limit 5) -->
      <div class="col-lg-6">
        <div class="rs-upcoming-card card-chores h-100">
          <div class="rs-upcoming-header">
            <h5 class="rs-upcoming-title">
              <span class="rs-upcoming-icon"><i class="bi bi-check2-circle"></i></span>
              Upcoming Chores
            </h5>
            <a href="chores.php" class="rs-upcoming-link">
              View all <i class="bi bi-arrow-right-short fs-6"></i>
            </a>
          </div>

          <?php if (!$activeHousehold): ?>
            <div class="p-3 text-center text-secondary-custom small">Join a household to see your chores.</div>
          <?php elseif (!$chores): ?>
            <div class="p-3 text-center text-secondary-custom small">No pending chores in this household.</div>
          <?php else: ?>
            <div class="rs-minimal-list">
              <?php foreach ($chores as $chore): ?>
                <?php
                  $status = (string) $chore['status'];
                  $dueDate = (string) $chore['due_date'];
                  $isToday = ($dueDate === date('Y-m-d'));
                  $isOverdue = ($dueDate < date('Y-m-d'));
                  $statusIconClass = $isOverdue ? 'status-overdue' : ($isToday ? 'status-today' : 'status-upcoming');
                ?>
                <div class="rs-minimal-item">
                  <div class="rs-minimal-item-main">
                    <div class="rs-minimal-status-icon <?= $statusIconClass ?>">
                      <i class="bi <?= $isOverdue ? 'bi-exclamation' : ($isToday ? 'bi-clock' : 'bi-check2') ?>"></i>
                    </div>
                    <div class="rs-minimal-content">
                      <div class="rs-minimal-title" title="<?= h((string) $chore['chore_name']) ?>">
                        <?= h((string) $chore['chore_name']) ?>
                      </div>
                      <div class="rs-minimal-meta">
                        <span><i class="bi bi-person me-1"></i><?= h((string) $chore['assigned_name']) ?></span>
                        <span>·</span>
                        <span><i class="bi bi-calendar3 me-1"></i><?= h(dashboard_date($dueDate)) ?></span>
                      </div>
                    </div>
                  </div>
                  <div class="rs-minimal-right">
                    <?php if ($isOverdue): ?>
                      <span class="rs-minimal-tag tag-overdue">Overdue</span>
                    <?php elseif ($isToday): ?>
                      <span class="rs-minimal-tag tag-today">Today</span>
                    <?php else: ?>
                      <span class="rs-minimal-tag tag-pending">Pending</span>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Upcoming Bills (limit 5) -->
      <div class="col-lg-6">
        <div class="rs-upcoming-card card-money h-100">
          <div class="rs-upcoming-header">
            <h5 class="rs-upcoming-title">
              <span class="rs-upcoming-icon"><i class="bi bi-receipt"></i></span>
              Upcoming Bills
            </h5>
            <a href="bills.php?view=bills" class="rs-upcoming-link">
              View all <i class="bi bi-arrow-right-short fs-6"></i>
            </a>
          </div>

          <?php if (!$activeHousehold): ?>
            <div class="p-3 text-center text-secondary-custom small">Join a household to see bills.</div>
          <?php elseif (!$upcomingBills): ?>
            <div class="p-3 text-center text-secondary-custom small">No unpaid upcoming bills for this household.</div>
          <?php else: ?>
            <div class="rs-minimal-list">
              <?php foreach ($upcomingBills as $uBill): ?>
                <?php
                  $bDueDate = (string) $uBill['due_date'];
                  $bIsToday = ($bDueDate === date('Y-m-d'));
                  $bIsOverdue = ($bDueDate < date('Y-m-d'));
                  $bStatusIconClass = $bIsOverdue ? 'status-overdue' : ($bIsToday ? 'status-today' : 'status-upcoming');
                ?>
                <div class="rs-minimal-item">
                  <div class="rs-minimal-item-main">
                    <div class="rs-minimal-status-icon <?= $bStatusIconClass ?>">
                      <i class="bi <?= $bIsOverdue ? 'bi-exclamation' : 'bi-receipt' ?>"></i>
                    </div>
                    <div class="rs-minimal-content">
                      <div class="rs-minimal-title" title="<?= h((string) $uBill['bill_name']) ?>">
                        <?= h((string) $uBill['bill_name']) ?>
                      </div>
                      <div class="rs-minimal-meta">
                        <span><i class="bi bi-calendar-event me-1"></i>Due <?= h(dashboard_date($bDueDate)) ?></span>
                        <?php if (!empty($uBill['category_name'])): ?>
                          <span>·</span>
                          <span><?= h((string) $uBill['category_name']) ?></span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                  <div class="rs-minimal-right">
                    <div class="rs-minimal-amount"><?= h(dashboard_money((float) $uBill['amount'])) ?></div>
                    <?php if ($bIsOverdue): ?>
                      <span class="rs-minimal-tag tag-overdue mt-1">Overdue</span>
                    <?php elseif ($bIsToday): ?>
                      <span class="rs-minimal-tag tag-today mt-1">Due Today</span>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Minimalist Activity, Notice Board, and Expenses Row -->
    <div class="row g-4">
      <!-- Recent Activity (limit 5) -->
      <div class="col-lg-6">
        <div class="rs-card-minimal card-household h-100">
          <div class="rs-upcoming-header">
            <h5 class="rs-upcoming-title">
              <span class="rs-upcoming-icon"><i class="bi bi-activity"></i></span>
              Recent Activity
            </h5>
            <a href="reports.php?view=activity" class="rs-upcoming-link">
              View all <i class="bi bi-arrow-right-short fs-6"></i>
            </a>
          </div>
          <?php if (!$activities): ?>
            <div class="p-3 text-center text-secondary-custom small">No recent activity recorded for this household.</div>
          <?php else: ?>
            <div class="rs-minimal-list">
              <?php foreach ($activities as $activity): ?>
                <?php
                  $actorName = (string) (!empty($activity['full_name']) ? $activity['full_name'] : (!empty($activity['username']) ? $activity['username'] : 'Roommate'));
                  $actorPic = (string) ($activity['profile_picture'] ?? '');
                  $actType = (string) ($activity['type'] ?? '');
                  $itemName = (string) ($activity['item'] ?? '');
                  $actAmount = isset($activity['amount']) && is_numeric($activity['amount']) ? (float) $activity['amount'] : 0.0;
                ?>
                <div class="rs-minimal-activity-item">
                  <?= render_avatar_markup(dashboard_initials($actorName), $actorPic !== '' ? $actorPic : null, $actorName, 'avatar-sm mt-1') ?>
                  <div class="flex-grow-1 min-w-0">
                    <div class="rs-minimal-activity-body">
                      <?php if ($actType === 'chore_completed'): ?>
                        <strong><?= h($actorName) ?></strong> completed chore <strong><?= h($itemName) ?></strong>.
                      <?php elseif ($actType === 'chore_created'): ?>
                        <strong><?= h($actorName) ?></strong> added new chore <strong><?= h($itemName) ?></strong>.
                      <?php elseif ($actType === 'bill_created'): ?>
                        <strong><?= h($actorName) ?></strong> added bill <strong><?= h($itemName) ?></strong> for <strong><?= h(dashboard_money($actAmount)) ?></strong>.
                      <?php elseif ($actType === 'payment'): ?>
                        <strong><?= h($actorName) ?></strong> paid <strong><?= h(dashboard_money($actAmount)) ?></strong> for <?= h($itemName) ?>.
                      <?php elseif ($actType === 'expense'): ?>
                        <strong><?= h($actorName) ?></strong> logged expense <strong><?= h($itemName) ?></strong> for <strong><?= h(dashboard_money($actAmount)) ?></strong>.
                      <?php elseif ($actType === 'announcement'): ?>
                        <strong><?= h($actorName) ?></strong> posted announcement <strong><?= h($itemName) ?></strong>.
                      <?php elseif ($actType === 'maintenance'): ?>
                        <strong><?= h($actorName) ?></strong> reported issue <strong><?= h($itemName) ?></strong>.
                      <?php else: ?>
                        <strong><?= h($actorName) ?></strong> recorded <strong><?= h($itemName) ?></strong>.
                      <?php endif; ?>
                    </div>
                    <span class="rs-minimal-activity-time"><?= h(dashboard_date_time((string) $activity['activity_at'])) ?></span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="col-lg-6 d-flex flex-column gap-4">
        <!-- Notice Board (limit 3 announcements) -->
        <div class="rs-card-minimal card-announcements">
          <div class="rs-upcoming-header">
            <h5 class="rs-upcoming-title">
              <span class="rs-upcoming-icon"><i class="bi bi-megaphone"></i></span>
              Notice Board
            </h5>
            <?php if (strcasecmp($role, 'Admin') === 0): ?>
              <a href="manage.php?section=announcements" class="rs-upcoming-link">View all <i class="bi bi-arrow-right-short fs-6"></i></a>
            <?php else: ?>
              <span class="rs-minimal-tag tag-pending"><?= count($announcements) ?> Posts</span>
            <?php endif; ?>
          </div>
          <?php if (!$announcements): ?>
            <div class="p-3 text-center text-secondary-custom small">No announcements available.</div>
          <?php else: ?>
            <div class="rs-minimal-list">
              <?php foreach ($announcements as $ann): ?>
                <div class="rs-minimal-notice-item">
                  <div class="d-flex justify-content-between align-items-baseline gap-2 mb-1">
                    <div class="rs-minimal-notice-title">
                      <i class="bi bi-dot text-warning fs-5 p-0 m-0" style="line-height: 0;"></i>
                      <?= h((string) $ann['title']) ?>
                    </div>
                    <span class="rs-minimal-notice-date"><?= h(dashboard_date((string) $ann['created_at'])) ?></span>
                  </div>
                  <p class="rs-minimal-notice-text"><?= h((string) $ann['content']) ?></p>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Recent Expenses (limit 5 expenses) -->
        <div class="rs-card-minimal card-money">
          <div class="rs-upcoming-header">
            <h5 class="rs-upcoming-title">
              <span class="rs-upcoming-icon"><i class="bi bi-wallet2"></i></span>
              Recent Expenses
            </h5>
            <a href="bills.php?view=expenses" class="rs-upcoming-link">View all <i class="bi bi-arrow-right-short fs-6"></i></a>
          </div>
          <?php if (!$recentExpenses): ?>
            <div class="p-3 text-center text-secondary-custom small">No recent expenses logged for this household.</div>
          <?php else: ?>
            <div class="rs-minimal-list">
              <?php foreach ($recentExpenses as $exp): ?>
                <div class="rs-minimal-item">
                  <div class="rs-minimal-item-main">
                    <div class="rs-minimal-status-icon status-upcoming">
                      <i class="bi bi-wallet2"></i>
                    </div>
                    <div class="rs-minimal-content">
                      <div class="rs-minimal-title" title="<?= h((string) $exp['title']) ?>">
                        <?= h((string) $exp['title']) ?>
                      </div>
                      <div class="rs-minimal-meta">
                        <span><i class="bi bi-person me-1"></i><?= h((string) $exp['payer_name']) ?></span>
                        <span>·</span>
                        <span><i class="bi bi-calendar3 me-1"></i><?= h(dashboard_date((string) $exp['expense_date'])) ?></span>
                        <?php if (!empty($exp['category_name'])): ?>
                          <span>·</span>
                          <span><?= h((string) $exp['category_name']) ?></span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                  <div class="rs-minimal-right">
                    <div class="rs-minimal-amount"><?= h(dashboard_money((float) $exp['amount'])) ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
    <!-- Openable Card Modal: View All Tasks for Selected Date (Zero Dashboard Clutter) -->
    <div class="modal fade" id="rsCalAllTasksModal" tabindex="-1" aria-labelledby="rsCalAllTasksModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content rs-modal shadow-lg border">
          <div class="modal-header rs-modal-header d-flex align-items-center justify-content-between">
            <div>
              <div class="d-flex align-items-center gap-2">
                <div class="rs-stat-icon p-2 bg-warm-secondary rounded-circle text-dark-accent" style="width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; font-size: 0.95rem;">
                  <i class="bi bi-calendar3"></i>
                </div>
                <h5 class="modal-title mb-0" id="rsCalAllTasksModalLabel">Tasks Scheduled</h5>
                <span id="rsCalModalTodayBadge"></span>
              </div>
              <small class="text-secondary-custom d-block mt-1" id="rsCalModalSubtitle">Scheduled tasks for this day</small>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body rs-modal-body p-3 p-md-4">
            <div class="rs-cal-modal-task-list d-flex flex-column gap-2" id="rsCalModalTaskList">
              <!-- Dynamically populated by calendar.js -->
            </div>
          </div>
          <div class="modal-footer rs-modal-footer d-flex justify-content-between align-items-center">
            <span class="small text-secondary-custom" id="rsCalModalFooterCount"></span>
            <button type="button" class="btn btn-rs-outline btn-sm px-3" data-bs-dismiss="modal">Close</button>
          </div>
        </div>
      </div>
    </div>
  </main>

  <script>
    window.RS_CALENDAR_DATA = <?= json_encode([
      'status' => 'success',
      'month' => date('Y-m'),
      'today' => date('Y-m-d'),
      'tasks_by_date' => $calTasksByDate,
    ], JSON_UNESCAPED_UNICODE) ?>;
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/main.js"></script>
  <script src="assets/js/calendar.js"></script>
</body>
</html>
