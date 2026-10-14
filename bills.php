<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bills.php';

if (!restore_remembered_login()) {
    redirect_to('login.php');
}

$userId = (int) $_SESSION['user_id'];
$householdId = selected_household_id();
if ($householdId === null) {
    redirect_to('households.php');
}

try {
    $connection = app_pdo();
} catch (Throwable $error) {
    error_log('RoomieSync bills page connection failed: ' . $error->getMessage());
    http_response_code(500);
    exit('The database is temporarily unavailable.');
}

$context = bill_context($connection, $userId, $householdId);
if ($context === null) {
    unset($_SESSION['household_id']);
    redirect_to('households.php');
}

$householdName = (string) $context['household_name'];
$role = (string) $context['role'];
$isAdmin = strcasecmp($role, 'Admin') === 0;

$userEmailQuery = $connection->prepare('SELECT email, username, full_name FROM userTb WHERE user_id = :user_id LIMIT 1');
$userEmailQuery->execute(['user_id' => $userId]);
$currentUser = $userEmailQuery->fetch() ?: [];
$userEmail = (string) ($currentUser['email'] ?? '');
$username = (string) ($currentUser['username'] ?? ($_SESSION['username'] ?? ''));
$fullName = (string) ($currentUser['full_name'] ?? ($_SESSION['full_name'] ?? ''));
$displayName = $username !== '' ? $username : $fullName;

if (!function_exists('bills_initials')) {
    function bills_initials(string $value): string
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
}

$avatarText = bills_initials($displayName);

// Dynamic categories & active household members
$categories = bill_get_categories($connection, $householdId);
$expenseCategories = expense_get_categories($connection, $householdId);
$members = bill_get_active_members($connection, $householdId);

// Main View Switcher: 'bills' vs 'expenses'
$mainView = is_string($_GET['view'] ?? null) ? strtolower(trim($_GET['view'])) : 'bills';
if (!in_array($mainView, ['bills', 'expenses'], true)) {
    $mainView = 'bills';
}

// ==================================================
// 1. BILLS DATA PROCESSING (When mainView === 'bills')
// ==================================================
$filteredBills = [];
$totalBillsCount = 0;
$unpaidCount = 0;
$dueSoonCount = 0;
$paidCount = 0;
$myTotalShareBalance = 0.0;
$tab = 'all';
$search = '';
$statusFilter = 'all';
$categoryFilter = 0;

if ($mainView === 'bills') {
    // Tab parameter: 'all' (Household Bills) vs 'my' (My Shares)
    $tab = is_string($_GET['tab'] ?? null) ? strtolower(trim($_GET['tab'])) : ($isAdmin ? 'all' : 'my');
    if (!in_array($tab, ['all', 'my'], true)) {
        $tab = $isAdmin ? 'all' : 'my';
    }

    // Search & Filter parameters
    $search = trim(is_string($_GET['search'] ?? null) ? $_GET['search'] : '');
    $search = mb_substr($search, 0, 100);

    $statusFilter = is_string($_GET['status'] ?? null) ? strtolower(trim($_GET['status'])) : 'all';
    $validStatusFilters = ['all', 'unpaid', 'due soon', 'paid', 'overdue'];
    if (!in_array($statusFilter, $validStatusFilters, true)) {
        $statusFilter = 'all';
    }

    $categoryFilter = is_string($_GET['category'] ?? null) && ctype_digit($_GET['category']) ? (int) $_GET['category'] : 0;

    // Fetch all household bills with category and creator info
    $billsSql = "
        SELECT b.bill_id, b.household_id, b.category_id, b.bill_name, b.amount, b.due_date,
               b.status, b.created_by, b.created_at,
               cat.category_name,
               u_creator.full_name AS creator_name,
               u_creator.username AS creator_username
        FROM billsTb AS b
        LEFT JOIN bill_categoriesTb AS cat
               ON cat.bill_category_id = b.category_id
              AND cat.household_id = b.household_id
        LEFT JOIN userTb AS u_creator
               ON u_creator.user_id = b.created_by
        WHERE b.household_id = :household_id
    ";

    $billsParams = ['household_id' => $householdId];

    if ($search !== '') {
        $billsSql .= " AND (b.bill_name LIKE :search_name OR COALESCE(cat.category_name, '') LIKE :search_cat)";
        $billsParams['search_name'] = '%' . $search . '%';
        $billsParams['search_cat'] = '%' . $search . '%';
    }

    if ($categoryFilter > 0) {
        $billsSql .= " AND b.category_id = :filter_cat";
        $billsParams['filter_cat'] = $categoryFilter;
    }

    $billsSql .= " ORDER BY b.due_date ASC, b.created_at DESC";

    $stmt = $connection->prepare($billsSql);
    $stmt->execute($billsParams);
    $allFetchedBills = $stmt->fetchAll();

    // Pre-load all shares for all bills in this household
    $sharesQuery = $connection->prepare("
        SELECT s.bill_share_id, s.bill_id, s.user_id, s.share_amount, s.payment_status,
               u.full_name, u.username
        FROM bill_sharesTb AS s
        INNER JOIN billsTb AS b ON b.bill_id = s.bill_id
        INNER JOIN userTb AS u ON u.user_id = s.user_id
        WHERE b.household_id = :household_id
        ORDER BY u.full_name ASC
    ");
    $sharesQuery->execute(['household_id' => $householdId]);
    $allShares = $sharesQuery->fetchAll();

    $sharesByBill = [];
    foreach ($allShares as $share) {
        $sharesByBill[(int) $share['bill_id']][] = $share;
    }

    $today = new DateTimeImmutable('today');

    foreach ($allFetchedBills as $bill) {
        $billId = (int) $bill['bill_id'];
        $bill['shares'] = $sharesByBill[$billId] ?? [];

        // Find current user's share
        $myShareAmount = null;
        $myPaymentStatus = null;
        foreach ($bill['shares'] as $sh) {
            if ((int) $sh['user_id'] === $userId) {
                $myShareAmount = (float) $sh['share_amount'];
                $myPaymentStatus = (string) $sh['payment_status'];
                break;
            }
        }
        $bill['my_share_amount'] = $myShareAmount;
        $bill['my_payment_status'] = $myPaymentStatus;

        // Determine derived display status (Unpaid, Paid, Due Soon, Overdue)
        $statusInfo = bill_display_status((string) $bill['status'], (string) $bill['due_date']);
        $derivedStatus = strtolower($statusInfo['label']);
        $bill['derived_status'] = $derivedStatus;
        $bill['status_label'] = $statusInfo['label'];
        $bill['status_badge_class'] = $statusInfo['badge'];
        $bill['due_date_formatted'] = date('M j, Y', strtotime((string) $bill['due_date']));
        $bill['created_at_formatted'] = date('M j, Y g:i A', strtotime((string) $bill['created_at']));

        // If tab is 'my', filter to only bills where user is assigned
        if ($tab === 'my' && $myShareAmount === null) {
            continue;
        }

        // Determine status for counting based on tab:
        $isPaidForUser = ($tab === 'my')
            ? (strcasecmp((string) $myPaymentStatus, 'paid') === 0)
            : ($derivedStatus === 'paid');

        $totalBillsCount++;
        if ($isPaidForUser) {
            $paidCount++;
        } elseif ($derivedStatus === 'due soon') {
            $dueSoonCount++;
            $unpaidCount++;
        } elseif ($derivedStatus === 'overdue') {
            $unpaidCount++;
        } else {
            $unpaidCount++;
        }

        if ($myShareAmount !== null && strcasecmp((string) $myPaymentStatus, 'paid') !== 0) {
            $myTotalShareBalance += $myShareAmount;
        }

        // Apply status filter if set
        if ($statusFilter !== 'all') {
            if ($statusFilter === 'unpaid' && $isPaidForUser) {
                continue;
            }
            if ($statusFilter === 'due soon' && ($derivedStatus !== 'due soon' || $isPaidForUser)) {
                continue;
            }
            if ($statusFilter === 'paid' && !$isPaidForUser) {
                continue;
            }
            if ($statusFilter === 'overdue' && ($derivedStatus !== 'overdue' || $isPaidForUser)) {
                continue;
            }
        }

        $filteredBills[] = $bill;
    }
}

// ==================================================
// 2. EXPENSES DATA PROCESSING (When mainView === 'expenses')
// ==================================================
$filteredExpenses = [];
$totalExpenseAmount = 0.0;
$thisMonthExpenseAmount = 0.0;
$totalExpenseRecords = 0;
$activeCategoriesCount = 0;
$expenseSearch = '';
$expenseCategoryFilter = 0;
$expenseDateFilter = 'all';
$expenseSort = 'date_desc';

if ($mainView === 'expenses') {
    $expenseSearch = trim(is_string($_GET['expense_search'] ?? null) ? $_GET['expense_search'] : '');
    $expenseSearch = mb_substr($expenseSearch, 0, 100);

    $expenseCategoryFilter = is_string($_GET['expense_category'] ?? null) && ctype_digit($_GET['expense_category']) ? (int) $_GET['expense_category'] : 0;

    $expenseDateFilter = is_string($_GET['expense_date_filter'] ?? null) ? strtolower(trim($_GET['expense_date_filter'])) : 'all';
    $validExpenseDateFilters = ['all', 'this_month', 'last_30_days', 'this_year'];
    if (!in_array($expenseDateFilter, $validExpenseDateFilters, true)) {
        $expenseDateFilter = 'all';
    }

    $expenseSort = is_string($_GET['expense_sort'] ?? null) ? strtolower(trim($_GET['expense_sort'])) : 'date_desc';
    $validExpenseSorts = ['date_desc', 'date_asc', 'amount_desc', 'amount_asc', 'title_asc'];
    if (!in_array($expenseSort, $validExpenseSorts, true)) {
        $expenseSort = 'date_desc';
    }

    // Unfiltered Stats for Top Summary Cards
    $allHouseholdExpStmt = $connection->prepare(
        'SELECT amount, expense_date, category_id FROM expenseTb WHERE household_id = :household_id'
    );
    $allHouseholdExpStmt->execute(['household_id' => $householdId]);
    $allHouseholdExp = $allHouseholdExpStmt->fetchAll();

    $currentMonthPrefix = date('Y-m');
    $categoriesUsedMap = [];
    foreach ($allHouseholdExp as $hx) {
        $amt = (float) $hx['amount'];
        $totalExpenseAmount += $amt;
        if (str_starts_with((string) $hx['expense_date'], $currentMonthPrefix)) {
            $thisMonthExpenseAmount += $amt;
        }
        if (!empty($hx['category_id'])) {
            $categoriesUsedMap[(int) $hx['category_id']] = true;
        }
    }
    $totalExpenseRecords = count($allHouseholdExp);
    $activeCategoriesCount = count($categoriesUsedMap);

    // Fetch filtered expenses
    $expensesSql = "
        SELECT e.expense_id, e.household_id, e.category_id, e.title, e.amount,
               e.paid_by, e.expense_date, e.description, e.created_at,
               cat.category_name,
               u_payer.full_name AS payer_name,
               u_payer.username AS payer_username
        FROM expenseTb AS e
        LEFT JOIN expense_categoriesTb AS cat
               ON cat.expense_category_id = e.category_id
              AND cat.household_id = e.household_id
        LEFT JOIN userTb AS u_payer
               ON u_payer.user_id = e.paid_by
        WHERE e.household_id = :household_id
    ";

    $expenseParams = ['household_id' => $householdId];

    if ($expenseSearch !== '') {
        $expensesSql .= " AND (e.title LIKE :exp_search OR COALESCE(cat.category_name, '') LIKE :exp_search_cat OR COALESCE(e.description, '') LIKE :exp_search_desc)";
        $expenseParams['exp_search'] = '%' . $expenseSearch . '%';
        $expenseParams['exp_search_cat'] = '%' . $expenseSearch . '%';
        $expenseParams['exp_search_desc'] = '%' . $expenseSearch . '%';
    }

    if ($expenseCategoryFilter > 0) {
        $expensesSql .= " AND e.category_id = :exp_cat_filter";
        $expenseParams['exp_cat_filter'] = $expenseCategoryFilter;
    }

    if ($expenseDateFilter === 'this_month') {
        $expensesSql .= " AND e.expense_date >= :first_of_month AND e.expense_date <= :last_of_month";
        $expenseParams['first_of_month'] = date('Y-m-01');
        $expenseParams['last_of_month'] = date('Y-m-t');
    } elseif ($expenseDateFilter === 'last_30_days') {
        $expensesSql .= " AND e.expense_date >= :thirty_days_ago";
        $expenseParams['thirty_days_ago'] = date('Y-m-d', strtotime('-30 days'));
    } elseif ($expenseDateFilter === 'this_year') {
        $expensesSql .= " AND e.expense_date >= :first_of_year AND e.expense_date <= :last_of_year";
        $expenseParams['first_of_year'] = date('Y-01-01');
        $expenseParams['last_of_year'] = date('Y-12-31');
    }

    // Sort order
    switch ($expenseSort) {
        case 'date_asc':
            $expensesSql .= " ORDER BY e.expense_date ASC, e.created_at ASC";
            break;
        case 'amount_desc':
            $expensesSql .= " ORDER BY e.amount DESC, e.expense_date DESC";
            break;
        case 'amount_asc':
            $expensesSql .= " ORDER BY e.amount ASC, e.expense_date DESC";
            break;
        case 'title_asc':
            $expensesSql .= " ORDER BY e.title ASC, e.expense_date DESC";
            break;
        case 'date_desc':
        default:
            $expensesSql .= " ORDER BY e.expense_date DESC, e.created_at DESC";
            break;
    }

    $expStmt = $connection->prepare($expensesSql);
    $expStmt->execute($expenseParams);
    $allFetchedExpenses = $expStmt->fetchAll();

    // Pre-load all expense shares for this household
    $expSharesQuery = $connection->prepare("
        SELECT s.share_id, s.expense_id, s.user_id, s.share_amount, s.is_paid,
               u.full_name, u.username
        FROM expenses_sharesTb AS s
        INNER JOIN expenseTb AS e ON e.expense_id = s.expense_id
        INNER JOIN userTb AS u ON u.user_id = s.user_id
        WHERE e.household_id = :household_id
        ORDER BY u.full_name ASC
    ");
    $expSharesQuery->execute(['household_id' => $householdId]);
    $allExpShares = $expSharesQuery->fetchAll();

    $expSharesByExpense = [];
    foreach ($allExpShares as $esh) {
        $expSharesByExpense[(int) $esh['expense_id']][] = $esh;
    }

    foreach ($allFetchedExpenses as $exp) {
        $expId = (int) $exp['expense_id'];
        $exp['shares'] = $expSharesByExpense[$expId] ?? [];
        $exp['expense_date_formatted'] = date('M j, Y', strtotime((string) $exp['expense_date']));
        $exp['created_at_formatted'] = date('M j, Y g:i A', strtotime((string) $exp['created_at']));

        $myExpShare = null;
        $myExpPaid = null;
        foreach ($exp['shares'] as $sh) {
            if ((int) $sh['user_id'] === $userId) {
                $myExpShare = (float) $sh['share_amount'];
                $myExpPaid = (int) $sh['is_paid'] === 1;
                break;
            }
        }
        $exp['my_share_amount'] = $myExpShare;
        $exp['my_is_paid'] = $myExpPaid;

        $filteredExpenses[] = $exp;
    }
}

$notice = consume_flash_message();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $mainView === 'expenses' ? 'Expenses' : 'Bills' ?> | RoomieSync</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="assets/css/bills.css">
</head>
<body class="d-flex flex-column min-vh-100">
  <!-- Navbar matching RoomieSync design system -->
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
        <li><a href="bills.php" class="rs-nav-link active"><i class="bi bi-receipt"></i><span>Bills</span></a></li>
        <li><a href="household.php" class="rs-nav-link"><i class="bi bi-house"></i><span>Household</span></a></li>
        <li><a href="reports.php" class="rs-nav-link"><i class="bi bi-bar-chart"></i><span>Reports</span></a></li>
        <?php if ($isAdmin): ?>
          <li><a href="manage.php" class="rs-nav-link"><i class="bi bi-sliders"></i><span>Manage</span></a></li>
        <?php endif; ?>
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
    <!-- Flash Notice -->
    <?php if ($notice): ?>
      <div class="alert alert-success alert-dismissible fade show mb-4 shadow-sm" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i><?= h((string) $notice['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <!-- Main View Switcher Tabs (Bills vs Expenses) -->
    <div class="rs-main-tabs-wrapper">
      <div class="rs-main-tabs">
        <a href="bills.php?view=bills" class="rs-main-tab <?= $mainView === 'bills' ? 'active' : '' ?>">
          <i class="bi bi-receipt"></i>
          <span>Bills</span>
        </a>
        <a href="bills.php?view=expenses" class="rs-main-tab <?= $mainView === 'expenses' ? 'active' : '' ?>">
          <i class="bi bi-wallet2"></i>
          <span>Expenses</span>
        </a>
      </div>
    </div>

    <?php if ($mainView === 'bills'): ?>
      <!-- ========================================== -->
      <!-- BILLS VIEW SECTION                         -->
      <!-- ========================================== -->

      <!-- Header Section -->
      <header class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
          <span class="rs-eyebrow d-block mb-1">Finances & Utilities</span>
          <h1 class="h2 fw-bold text-main mb-1">Bills</h1>
          <p class="text-secondary-custom mb-0">Track household bills, due dates, shares, and payment status.</p>
        </div>
        <?php if ($isAdmin): ?>
          <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-rs-primary" data-bs-toggle="modal" data-bs-target="#addBillModal">
              <i class="bi bi-plus-lg me-1"></i> Add Bill
            </button>
            <div class="dropdown">
              <button class="btn btn-rs-outline px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Bill Actions">
                <i class="bi bi-three-dots-vertical"></i>
              </button>
              <ul class="dropdown-menu dropdown-menu-end rs-dropdown">
                <li><h6 class="dropdown-header">Household Admin</h6></li>
                <li><a class="dropdown-item rs-dropdown-item" href="#addBillModal" data-bs-toggle="modal"><i class="bi bi-receipt-cutoff"></i> Create New Bill</a></li>
                <li><a class="dropdown-item rs-dropdown-item" href="bills.php?view=bills"><i class="bi bi-arrow-clockwise"></i> Refresh Bills</a></li>
              </ul>
            </div>
          </div>
        <?php endif; ?>
      </header>

      <!-- Bills View Switcher Tabs (Accessible to both Members and Admins) -->
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <ul class="nav bill-tabs">
          <li class="nav-item">
            <a class="nav-link <?= $tab === 'my' ? 'active' : '' ?>" href="bills.php?view=bills&tab=my<?= $statusFilter !== 'all' ? '&status=' . urlencode($statusFilter) : '' ?><?= $categoryFilter > 0 ? '&category=' . $categoryFilter : '' ?><?= $search !== '' ? '&search=' . urlencode($search) : '' ?>">
              <i class="bi bi-person-check me-1"></i> My Shares
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?= $tab === 'all' ? 'active' : '' ?>" href="bills.php?view=bills&tab=all<?= $statusFilter !== 'all' ? '&status=' . urlencode($statusFilter) : '' ?><?= $categoryFilter > 0 ? '&category=' . $categoryFilter : '' ?><?= $search !== '' ? '&search=' . urlencode($search) : '' ?>">
              <i class="bi bi-houses me-1"></i> All Household Bills
            </a>
          </li>
        </ul>
        <?php if ($tab === 'my' && $myTotalShareBalance > 0): ?>
          <div class="small fw-semibold text-secondary-custom">
            Unpaid share balance: <span class="fw-bold text-danger">₱<?= number_format($myTotalShareBalance, 2) ?></span>
          </div>
        <?php endif; ?>
      </div>

      <!-- Bill Summary Cards (No Graphs) -->
      <section class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
          <div class="bill-stat-card card-money">
            <div class="bill-stat-icon total"><i class="bi bi-collection"></i></div>
            <div>
              <div class="bill-stat-val"><?= (int) $totalBillsCount ?></div>
              <div class="bill-stat-lbl">Total Bills</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="bill-stat-card card-announcements">
            <div class="bill-stat-icon unpaid"><i class="bi bi-hourglass-split"></i></div>
            <div>
              <div class="bill-stat-val"><?= (int) $unpaidCount ?></div>
              <div class="bill-stat-lbl">Unpaid</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="bill-stat-card card-maintenance">
            <div class="bill-stat-icon due-soon"><i class="bi bi-alarm"></i></div>
            <div>
              <div class="bill-stat-val"><?= (int) $dueSoonCount ?></div>
              <div class="bill-stat-lbl">Due Soon</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="bill-stat-card card-money">
            <div class="bill-stat-icon paid"><i class="bi bi-check2-circle"></i></div>
            <div>
              <div class="bill-stat-val"><?= (int) $paidCount ?></div>
              <div class="bill-stat-lbl">Paid</div>
            </div>
          </div>
        </div>
      </section>

      <!-- Search & Filter Controls -->
      <section class="rs-card p-3 p-md-4 mb-4">
        <form method="GET" action="bills.php" class="bill-toolbar">
          <input type="hidden" name="view" value="bills">
          <input type="hidden" name="tab" value="<?= h($tab) ?>">
          <div class="input-group bill-search-box">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" class="form-control" name="search" placeholder="Search by bill or category..." value="<?= h($search) ?>">
            <?php if ($search !== ''): ?>
              <a href="bills.php?view=bills&tab=<?= urlencode($tab) ?><?= $statusFilter !== 'all' ? '&status=' . urlencode($statusFilter) : '' ?><?= $categoryFilter > 0 ? '&category=' . $categoryFilter : '' ?>" class="btn btn-outline-secondary" title="Clear Search">
                <i class="bi bi-x-lg"></i>
              </a>
            <?php endif; ?>
          </div>

          <div class="d-flex flex-wrap align-items-center gap-2">
            <!-- Status Filter -->
            <div class="d-flex align-items-center gap-1">
              <label class="text-secondary-custom small fw-bold d-none d-sm-inline">Status:</label>
              <select name="status" class="form-select form-select-sm bill-filter-select" onchange="this.form.submit()">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                <option value="unpaid" <?= $statusFilter === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                <option value="due soon" <?= $statusFilter === 'due soon' ? 'selected' : '' ?>>Due Soon</option>
                <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
                <option value="overdue" <?= $statusFilter === 'overdue' ? 'selected' : '' ?>>Overdue</option>
              </select>
            </div>

            <!-- Category Filter -->
            <div class="d-flex align-items-center gap-1">
              <label class="text-secondary-custom small fw-bold d-none d-sm-inline">Category:</label>
              <select name="category" class="form-select form-select-sm bill-filter-select" onchange="this.form.submit()">
                <option value="0">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                  <option value="<?= (int) $cat['bill_category_id'] ?>" <?= $categoryFilter === (int) $cat['bill_category_id'] ? 'selected' : '' ?>>
                    <?= h((string) $cat['category_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <button type="submit" class="btn btn-rs-outline btn-sm">Filter</button>
            <?php if ($search !== '' || $statusFilter !== 'all' || $categoryFilter > 0): ?>
              <a href="bills.php?view=bills&tab=<?= urlencode($tab) ?>" class="btn btn-link btn-sm text-secondary-custom text-decoration-none">Reset</a>
            <?php endif; ?>
          </div>
        </form>
      </section>

      <!-- Bill List Table/Card Layout -->
      <section class="rs-card p-0 overflow-hidden mb-4">
        <div class="bill-table-wrapper">
          <table class="table bill-table">
            <thead>
              <tr>
                <th>Bill</th>
                <th>Category</th>
                <th>Amount</th>
                <th>Due Date</th>
                <th>Status</th>
                <th>My Share</th>
                <th>Payment Status</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($filteredBills)): ?>
                <tr>
                  <td colspan="8" class="text-center py-5 text-secondary-custom">
                    <div class="py-4">
                      <i class="bi bi-receipt-cutoff fs-1 d-block mb-2 text-muted"></i>
                      <div class="fw-bold mb-1">
                        <?= $tab === 'my' ? 'No personal bill shares found' : 'No household bills found' ?>
                      </div>
                      <div class="small">
                        <?php if ($search !== '' || $statusFilter !== 'all' || $categoryFilter > 0): ?>
                          Try clearing your filters or search query.
                        <?php elseif ($tab === 'my'): ?>
                          You do not have any bills assigned to your account right now. Check "All Household Bills" to see other home utilities.
                        <?php else: ?>
                          <?= $isAdmin ? 'Click "+ Add Bill" above to track your first household utility.' : 'There are currently no bills logged for your household.' ?>
                        <?php endif; ?>
                      </div>
                    </div>
                  </td>
                </tr>

              <?php else: ?>
                <?php foreach ($filteredBills as $bill): ?>
                  <?php
                    $jsonPayload = json_encode([
                        'bill_id' => (int) $bill['bill_id'],
                        'category_id' => (int) $bill['category_id'],
                        'category_name' => (string) ($bill['category_name'] ?? 'Uncategorized'),
                        'bill_name' => (string) $bill['bill_name'],
                        'amount' => (float) $bill['amount'],
                        'due_date' => (string) $bill['due_date'],
                        'due_date_formatted' => $bill['due_date_formatted'],
                        'status' => (string) $bill['status'],
                        'status_label' => $bill['status_label'],
                        'status_badge_class' => $bill['status_badge_class'],
                        'creator_name' => (string) ($bill['creator_name'] ?? ($bill['creator_username'] ?? 'Household Admin')),
                        'created_at' => (string) $bill['created_at'],
                        'created_at_formatted' => $bill['created_at_formatted'],
                        'my_share_amount' => $bill['my_share_amount'],
                        'my_payment_status' => $bill['my_payment_status'],
                        'shares' => $bill['shares'],
                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                  ?>

                  <tr>
                    <td>
                      <div class="fw-bold text-main"><?= h((string) $bill['bill_name']) ?></div>
                      <div class="text-secondary-custom small d-md-none">
                        <?= h((string) ($bill['category_name'] ?? 'General')) ?>
                      </div>
                    </td>
                    <td>
                      <span class="bill-category-tag">
                        <i class="bi bi-tag-fill"></i> <?= h((string) ($bill['category_name'] ?? 'Uncategorized')) ?>
                      </span>
                    </td>
                    <td>
                      <span class="fw-bold text-main">₱<?= number_format((float) $bill['amount'], 2) ?></span>
                    </td>
                    <td>
                      <div class="small fw-semibold"><?= h($bill['due_date_formatted']) ?></div>
                    </td>
                    <td>
                      <span class="rs-bill-status <?= h($bill['status_badge_class']) ?>">
                        <span class="status-dot"></span> <?= h($bill['status_label']) ?>
                      </span>
                    </td>
                    <td>
                      <?php if ($bill['my_share_amount'] !== null): ?>
                        <span class="fw-bold text-main">₱<?= number_format((float) $bill['my_share_amount'], 2) ?></span>
                      <?php else: ?>
                        <span class="text-secondary-custom small">Not assigned</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($bill['my_payment_status'] !== null): ?>
                        <?php $isMyPaid = strcasecmp((string) $bill['my_payment_status'], 'paid') === 0; ?>
                        <span class="rs-bill-status <?= $isMyPaid ? 'rs-bill-status-paid' : 'rs-bill-status-unpaid' ?>">
                          <span class="status-dot"></span> <?= $isMyPaid ? 'Paid' : 'Unpaid' ?>
                        </span>
                      <?php else: ?>
                        <span class="text-secondary-custom small">—</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end">
                      <div class="d-inline-flex align-items-center gap-1">
                        <!-- View Button (All Users) -->
                        <button type="button" class="btn btn-rs-outline btn-sm py-1 px-2 view-bill-btn"
                                data-bs-toggle="modal" data-bs-target="#viewBillModal"
                                data-bill='<?= $jsonPayload ?>'
                                title="View Details">
                          <i class="bi bi-eye"></i> <span class="d-none d-xl-inline">View</span>
                        </button>

                        <!-- Admin Actions -->
                        <?php if ($isAdmin): ?>
                          <div class="dropdown">
                            <button class="btn btn-rs-outline btn-sm py-1 px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Manage Bill">
                              <i class="bi bi-three-dots-vertical"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end rs-dropdown">
                              <?php $isBillPaid = strcasecmp((string) $bill['status'], 'Paid') === 0; ?>
                              <li>
                                <form action="actions/bill-status.php" method="POST" class="m-0">
                                  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                  <input type="hidden" name="bill_id" value="<?= (int) $bill['bill_id'] ?>">
                                  <input type="hidden" name="status" value="<?= $isBillPaid ? 'Unpaid' : 'Paid' ?>">
                                  <button type="submit" class="dropdown-item rs-dropdown-item <?= $isBillPaid ? 'text-secondary' : 'text-success' ?>">
                                    <i class="bi <?= $isBillPaid ? 'bi-arrow-counterclockwise text-secondary' : 'bi-check2-circle text-success' ?> me-1"></i>
                                    <?= $isBillPaid ? 'Mark as Unpaid' : 'Mark as Paid' ?>
                                  </button>
                                </form>
                              </li>
                              <li><hr class="dropdown-divider rs-dropdown-divider"></li>
                              <li>
                                <button type="button" class="dropdown-item rs-dropdown-item edit-bill-btn"
                                        data-bs-toggle="modal" data-bs-target="#editBillModal"
                                        data-bill='<?= $jsonPayload ?>'>
                                  <i class="bi bi-pencil me-1"></i> Edit Bill
                                </button>
                              </li>
                              <li><hr class="dropdown-divider rs-dropdown-divider"></li>
                              <li>
                                <button type="button" class="dropdown-item rs-dropdown-item text-danger delete-bill-btn"
                                        data-bs-toggle="modal" data-bs-target="#deleteBillModal"
                                        data-bill-id="<?= (int) $bill['bill_id'] ?>"
                                        data-bill-name="<?= h((string) $bill['bill_name']) ?>">
                                  <i class="bi bi-trash3 me-1"></i> Delete Bill
                                </button>
                              </li>
                            </ul>
                          </div>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

    <?php else: ?>
      <!-- ========================================== -->
      <!-- EXPENSES VIEW SECTION                      -->
      <!-- ========================================== -->

      <!-- Header Section -->
      <header class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
          <span class="rs-eyebrow d-block mb-1">Household Spending</span>
          <h1 class="h2 fw-bold text-main mb-1">Expenses</h1>
          <p class="text-secondary-custom mb-0">Record and track day-to-day household spending, purchases, and shared costs.</p>
        </div>
        <?php if ($isAdmin): ?>
          <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-rs-primary" data-bs-toggle="modal" data-bs-target="#addExpenseModal">
              <i class="bi bi-plus-lg me-1"></i> Add Expense
            </button>
            <div class="dropdown">
              <button class="btn btn-rs-outline px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Expense Actions">
                <i class="bi bi-three-dots-vertical"></i>
              </button>
              <ul class="dropdown-menu dropdown-menu-end rs-dropdown">
                <li><h6 class="dropdown-header">Household Admin</h6></li>
                <li><a class="dropdown-item rs-dropdown-item" href="#addExpenseModal" data-bs-toggle="modal"><i class="bi bi-wallet2"></i> Record New Expense</a></li>
                <li><a class="dropdown-item rs-dropdown-item" href="bills.php?view=expenses"><i class="bi bi-arrow-clockwise"></i> Refresh Expenses</a></li>
                <li><hr class="dropdown-divider rs-dropdown-divider"></li>
                <li><a class="dropdown-item rs-dropdown-item" href="manage.php#categoriesSection"><i class="bi bi-tags"></i> Manage Categories</a></li>
              </ul>
            </div>
          </div>
        <?php endif; ?>
      </header>

      <!-- Expense Summary Cards (No Graphs) -->
      <section class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
          <div class="bill-stat-card card-money">
            <div class="bill-stat-icon expense-total"><i class="bi bi-wallet2"></i></div>
            <div>
              <div class="bill-stat-val">₱<?= number_format($totalExpenseAmount, 2) ?></div>
              <div class="bill-stat-lbl">Total Spent</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="bill-stat-card card-money">
            <div class="bill-stat-icon expense-month"><i class="bi bi-calendar-check"></i></div>
            <div>
              <div class="bill-stat-val">₱<?= number_format($thisMonthExpenseAmount, 2) ?></div>
              <div class="bill-stat-lbl">This Month</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="bill-stat-card card-household">
            <div class="bill-stat-icon expense-count"><i class="bi bi-receipt-cutoff"></i></div>
            <div>
              <div class="bill-stat-val"><?= (int) $totalExpenseRecords ?></div>
              <div class="bill-stat-lbl">Total Records</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="bill-stat-card card-chores">
            <div class="bill-stat-icon expense-categories"><i class="bi bi-tags"></i></div>
            <div>
              <div class="bill-stat-val"><?= (int) $activeCategoriesCount ?></div>
              <div class="bill-stat-lbl">Active Categories</div>
            </div>
          </div>
        </div>
      </section>

      <!-- Search & Filter Controls -->
      <section class="rs-card p-3 p-md-4 mb-4">
        <form method="GET" action="bills.php" class="bill-toolbar">
          <input type="hidden" name="view" value="expenses">
          <div class="input-group bill-search-box">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" class="form-control" name="expense_search" placeholder="Search by expense, category, or note..." value="<?= h($expenseSearch) ?>">
            <?php if ($expenseSearch !== ''): ?>
              <a href="bills.php?view=expenses<?= $expenseCategoryFilter > 0 ? '&expense_category=' . $expenseCategoryFilter : '' ?><?= $expenseDateFilter !== 'all' ? '&expense_date_filter=' . urlencode($expenseDateFilter) : '' ?><?= $expenseSort !== 'date_desc' ? '&expense_sort=' . urlencode($expenseSort) : '' ?>" class="btn btn-outline-secondary" title="Clear Search">
                <i class="bi bi-x-lg"></i>
              </a>
            <?php endif; ?>
          </div>

          <div class="d-flex flex-wrap align-items-center gap-2">
            <!-- Category Filter -->
            <div class="d-flex align-items-center gap-1">
              <label class="text-secondary-custom small fw-bold d-none d-sm-inline">Category:</label>
              <select name="expense_category" class="form-select form-select-sm bill-filter-select" onchange="this.form.submit()">
                <option value="0">All Categories</option>
                <?php foreach ($expenseCategories as $ecat): ?>
                  <option value="<?= (int) $ecat['expense_category_id'] ?>" <?= $expenseCategoryFilter === (int) $ecat['expense_category_id'] ? 'selected' : '' ?>>
                    <?= h((string) $ecat['category_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Date Filter -->
            <div class="d-flex align-items-center gap-1">
              <label class="text-secondary-custom small fw-bold d-none d-sm-inline">Date:</label>
              <select name="expense_date_filter" class="form-select form-select-sm bill-filter-select" onchange="this.form.submit()">
                <option value="all" <?= $expenseDateFilter === 'all' ? 'selected' : '' ?>>All Time</option>
                <option value="this_month" <?= $expenseDateFilter === 'this_month' ? 'selected' : '' ?>>This Month</option>
                <option value="last_30_days" <?= $expenseDateFilter === 'last_30_days' ? 'selected' : '' ?>>Last 30 Days</option>
                <option value="this_year" <?= $expenseDateFilter === 'this_year' ? 'selected' : '' ?>>This Year</option>
              </select>
            </div>

            <!-- Sort Filter -->
            <div class="d-flex align-items-center gap-1">
              <label class="text-secondary-custom small fw-bold d-none d-sm-inline">Sort:</label>
              <select name="expense_sort" class="form-select form-select-sm bill-filter-select" onchange="this.form.submit()">
                <option value="date_desc" <?= $expenseSort === 'date_desc' ? 'selected' : '' ?>>Newest Date</option>
                <option value="date_asc" <?= $expenseSort === 'date_asc' ? 'selected' : '' ?>>Oldest Date</option>
                <option value="amount_desc" <?= $expenseSort === 'amount_desc' ? 'selected' : '' ?>>Highest Amount</option>
                <option value="amount_asc" <?= $expenseSort === 'amount_asc' ? 'selected' : '' ?>>Lowest Amount</option>
                <option value="title_asc" <?= $expenseSort === 'title_asc' ? 'selected' : '' ?>>Title (A-Z)</option>
              </select>
            </div>

            <button type="submit" class="btn btn-rs-outline btn-sm">Filter</button>
            <?php if ($expenseSearch !== '' || $expenseCategoryFilter > 0 || $expenseDateFilter !== 'all' || $expenseSort !== 'date_desc'): ?>
              <a href="bills.php?view=expenses" class="btn btn-link btn-sm text-secondary-custom text-decoration-none">Reset</a>
            <?php endif; ?>
          </div>
        </form>
      </section>

      <!-- Expense List Table Layout -->
      <section class="rs-card p-0 overflow-hidden mb-4">
        <div class="bill-table-wrapper">
          <table class="table bill-table">
            <thead>
              <tr>
                <th>Expense</th>
                <th>Category</th>
                <th>Amount</th>
                <th>Paid By</th>
                <th>Expense Date</th>
                <th>Payment Status</th>
                <th>Shared With</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($filteredExpenses)): ?>
                <tr>
                  <td colspan="8" class="text-center py-5 text-secondary-custom">
                    <div class="py-4">
                      <i class="bi bi-wallet2 fs-1 d-block mb-2 text-muted"></i>
                      <div class="fw-bold mb-1">No household expenses found</div>
                      <div class="small">
                        <?php if ($expenseSearch !== '' || $expenseCategoryFilter > 0 || $expenseDateFilter !== 'all'): ?>
                          Try clearing your filters or search query.
                        <?php else: ?>
                          <?= $isAdmin ? 'Click "+ Add Expense" above to record your household’s first spending.' : 'No expenses have been recorded for your household yet.' ?>
                        <?php endif; ?>
                      </div>
                    </div>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($filteredExpenses as $exp): ?>
                  <?php
                    $jsonExpPayload = json_encode([
                        'expense_id' => (int) $exp['expense_id'],
                        'category_id' => (int) ($exp['category_id'] ?? 0),
                        'category_name' => (string) ($exp['category_name'] ?? 'Uncategorized'),
                        'title' => (string) $exp['title'],
                        'amount' => (float) $exp['amount'],
                        'paid_by' => (int) ($exp['paid_by'] ?? 0),
                        'payer_name' => (string) ($exp['payer_name'] ?? 'Member'),
                        'payer_username' => (string) ($exp['payer_username'] ?? ''),
                        'expense_date' => (string) $exp['expense_date'],
                        'expense_date_formatted' => $exp['expense_date_formatted'],
                        'description' => (string) ($exp['description'] ?? ''),
                        'created_at' => (string) $exp['created_at'],
                        'created_at_formatted' => $exp['created_at_formatted'],
                        'shares' => $exp['shares'],
                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

                    $payerAvatar = bills_initials((string) ($exp['payer_name'] ?: $exp['payer_username'] ?: '?'));
                    $shareCount = count($exp['shares']);

                    $allExpPaid = true;
                    if (!empty($exp['shares'])) {
                        foreach ($exp['shares'] as $esh) {
                            if ((int) $esh['is_paid'] !== 1) {
                                $allExpPaid = false;
                                break;
                            }
                        }
                    }
                  ?>
                  <tr>
                    <td>
                      <div class="fw-bold text-main"><?= h((string) $exp['title']) ?></div>
                      <?php if (!empty($exp['description'])): ?>
                        <div class="text-secondary-custom small text-truncate" style="max-width: 280px;">
                          <?= h((string) $exp['description']) ?>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="bill-category-tag">
                        <i class="bi bi-tag-fill"></i> <?= h((string) ($exp['category_name'] ?? 'Uncategorized')) ?>
                      </span>
                    </td>
                    <td>
                      <span class="fw-bold text-main">₱<?= number_format((float) $exp['amount'], 2) ?></span>
                    </td>
                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <div class="rs-avatar" style="width: 28px; height: 28px; font-size: 0.72rem;">
                          <?= h($payerAvatar) ?>
                        </div>
                        <div>
                          <div class="small fw-semibold text-main"><?= h((string) ($exp['payer_name'] ?? $exp['payer_username'] ?? 'Member')) ?></div>
                          <?php if (!empty($exp['payer_username'])): ?>
                            <div class="text-secondary-custom" style="font-size: 0.72rem;">@<?= h((string) $exp['payer_username']) ?></div>
                          <?php endif; ?>
                        </div>
                      </div>
                    </td>
                    <td>
                      <div class="small fw-semibold"><?= h($exp['expense_date_formatted']) ?></div>
                    </td>
                    <td>
                      <span class="rs-bill-status <?= $allExpPaid ? 'rs-bill-status-paid' : 'rs-bill-status-unpaid' ?>">
                        <span class="status-dot"></span> <?= $allExpPaid ? 'Paid' : 'Unpaid' ?>
                      </span>
                    </td>
                    <td>
                      <?php if ($shareCount > 0): ?>
                        <span class="badge bg-warm-secondary text-dark-accent border" title="Shared among <?= $shareCount ?> members">
                          <i class="bi bi-people me-1"></i> <?= $shareCount ?> <?= $shareCount === 1 ? 'member' : 'members' ?>
                        </span>
                      <?php else: ?>
                        <span class="text-secondary-custom small">Household</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end">
                      <div class="d-inline-flex align-items-center gap-1">
                        <!-- View Button (All Users) -->
                        <button type="button" class="btn btn-rs-outline btn-sm py-1 px-2 view-expense-btn"
                                data-bs-toggle="modal" data-bs-target="#viewExpenseModal"
                                data-expense='<?= $jsonExpPayload ?>'
                                title="View Details">
                          <i class="bi bi-eye"></i> <span class="d-none d-xl-inline">View</span>
                        </button>

                        <!-- Admin Actions -->
                        <?php if ($isAdmin): ?>
                          <div class="dropdown">
                            <button class="btn btn-rs-outline btn-sm py-1 px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Manage Expense">
                              <i class="bi bi-three-dots-vertical"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end rs-dropdown">
                              <li>
                                <form action="actions/expense-status.php" method="POST" class="m-0">
                                  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                  <input type="hidden" name="expense_id" value="<?= (int) $exp['expense_id'] ?>">
                                  <input type="hidden" name="is_paid" value="<?= $allExpPaid ? '0' : '1' ?>">
                                  <button type="submit" class="dropdown-item rs-dropdown-item <?= $allExpPaid ? 'text-secondary' : 'text-success' ?>">
                                    <i class="bi <?= $allExpPaid ? 'bi-arrow-counterclockwise text-secondary' : 'bi-check2-circle text-success' ?> me-1"></i>
                                    <?= $allExpPaid ? 'Mark as Unpaid' : 'Mark as Paid' ?>
                                  </button>
                                </form>
                              </li>
                              <li><hr class="dropdown-divider rs-dropdown-divider"></li>
                              <li>
                                <button type="button" class="dropdown-item rs-dropdown-item edit-expense-btn"
                                        data-bs-toggle="modal" data-bs-target="#editExpenseModal"
                                        data-expense='<?= $jsonExpPayload ?>'>
                                  <i class="bi bi-pencil me-1"></i> Edit Expense
                                </button>
                              </li>
                              <li><hr class="dropdown-divider rs-dropdown-divider"></li>
                              <li>
                                <button type="button" class="dropdown-item rs-dropdown-item text-danger delete-expense-btn"
                                        data-bs-toggle="modal" data-bs-target="#deleteExpenseModal"
                                        data-expense-id="<?= (int) $exp['expense_id'] ?>"
                                        data-expense-title="<?= h((string) $exp['title']) ?>">
                                  <i class="bi bi-trash3 me-1"></i> Delete Expense
                                </button>
                              </li>
                            </ul>
                          </div>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

    <?php endif; ?>
  </main>

  <?php if ($mainView === 'bills'): ?>
    <!-- ========================================== -->
    <!-- BILLS MODALS                               -->
    <!-- ========================================== -->

    <!-- View Bill Details Modal (For All Household Members) -->
    <div class="modal fade" id="viewBillModal" tabindex="-1" aria-labelledby="viewBillModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rs-card p-0 border-0 shadow-lg">
          <div class="modal-header border-bottom p-4">
            <div>
              <span class="rs-eyebrow d-block mb-1">Bill Information</span>
              <h5 class="modal-title fw-bold text-main" id="viewBillName">Bill Details</h5>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body p-4">
            <!-- Summary info grid -->
            <div class="row g-3 mb-4">
              <div class="col-6">
                <span class="text-secondary-custom small d-block">Category</span>
                <span class="fw-bold text-main" id="viewCategory">—</span>
              </div>
              <div class="col-6">
                <span class="text-secondary-custom small d-block">Total Amount</span>
                <span class="fw-bold text-main fs-5" id="viewAmount">—</span>
              </div>
              <div class="col-6">
                <span class="text-secondary-custom small d-block">Due Date</span>
                <span class="fw-bold text-main" id="viewDueDate">—</span>
              </div>
              <div class="col-6">
                <span class="text-secondary-custom small d-block">Overall Status</span>
                <span id="viewStatusBadge" class="rs-bill-status rs-bill-status-unpaid">
                  <span class="status-dot"></span> Unpaid
                </span>
              </div>
              <div class="col-6">
                <span class="text-secondary-custom small d-block">Created By</span>
                <span class="small fw-semibold text-main" id="viewCreatedBy">—</span>
              </div>
              <div class="col-6">
                <span class="text-secondary-custom small d-block">Created Date</span>
                <span class="small fw-semibold text-main" id="viewCreatedAt">—</span>
              </div>
            </div>

            <!-- Personal Share Highlight Box -->
            <div id="viewMyShareBanner" class="p-3 mb-3 rounded bg-warm-secondary border d-flex justify-content-between align-items-center">
              <div>
                <span class="text-secondary-custom small d-block">Your Share</span>
                <span class="fw-bold text-main fs-5" id="viewMyShareAmount">—</span>
              </div>
              <div id="viewMyShareStatus"></div>
            </div>

            <?php if ($isAdmin): ?>
              <!-- Admin Quick Bill Status Action -->
              <div class="mb-3 p-3 rounded bg-warm-secondary border d-flex justify-content-between align-items-center flex-wrap gap-2" id="viewBillAdminStatusWrap">
                <div>
                  <span class="small fw-bold text-main d-block"><i class="bi bi-shield-check text-primary me-1"></i>Admin Status Control</span>
                  <span class="small text-secondary-custom">Quickly mark this entire bill and its member shares as Paid or Unpaid</span>
                </div>
                <form action="actions/bill-status.php" method="POST" class="m-0" id="viewBillStatusForm">
                  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="bill_id" id="viewBillStatusId" value="">
                  <input type="hidden" name="status" id="viewBillStatusTarget" value="Paid">
                  <button type="submit" class="btn btn-sm btn-success" id="viewBillStatusBtn">
                    <i class="bi bi-check2-circle me-1"></i> Mark as Paid
                  </button>
                </form>
              </div>
            <?php endif; ?>

            <!-- Individual User Shares Breakdown -->
            <div>
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="fw-bold text-main mb-0">Household Member Shares</h6>
                <span class="text-secondary-custom small">Payment Status</span>
              </div>
              <div class="border rounded bg-white overflow-hidden" id="viewSharesList"></div>
            </div>
          </div>
          <div class="modal-footer border-top p-3">
            <button type="button" class="btn btn-rs-outline w-100" data-bs-dismiss="modal">Close</button>
          </div>
        </div>
      </div>
    </div>

    <?php if ($isAdmin): ?>
      <!-- Add Bill Modal (Admin Only) -->
      <div class="modal fade" id="addBillModal" tabindex="-1" aria-labelledby="addBillModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
          <div class="modal-content rs-card p-0 border-0 shadow-lg">
            <div class="modal-header border-bottom p-4">
              <div>
                <span class="rs-eyebrow d-block mb-1">New Obligation</span>
                <h5 class="modal-title fw-bold text-main" id="addBillModalLabel">Add Household Bill</h5>
              </div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <form action="actions/bill-create.php" method="POST" id="addBillForm">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <div class="modal-body p-4">
                <!-- Bill Core Info -->
                <div class="row g-3 mb-4">
                  <div class="col-12 col-md-6">
                    <label for="addBillName" class="form-label fw-bold text-main small">Bill Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="addBillName" name="bill_name" placeholder="e.g. Meralco Electricity, PLDT Fiber" maxlength="100" required>
                  </div>
                  <div class="col-12 col-md-6">
                    <label for="addCategoryId" class="form-label fw-bold text-main small">Category <span class="text-danger">*</span></label>
                    <select class="form-select" id="addCategoryId" name="category_id" required>
                      <option value="">Choose a category...</option>
                      <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int) $cat['bill_category_id'] ?>">
                          <?= h((string) $cat['category_name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-12 col-md-4">
                    <label for="addAmount" class="form-label fw-bold text-main small">Total Amount (₱) <span class="text-danger">*</span></label>
                    <div class="input-group">
                      <span class="input-group-text">₱</span>
                      <input type="number" class="form-control" id="addAmount" name="amount" step="0.01" min="0.01" placeholder="0.00" required>
                    </div>
                  </div>
                  <div class="col-12 col-md-4">
                    <label for="addDueDate" class="form-label fw-bold text-main small">Due Date <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="addDueDate" name="due_date" required>
                  </div>
                  <div class="col-12 col-md-4">
                    <label for="addStatus" class="form-label fw-bold text-main small">Bill Status</label>
                    <select class="form-select" id="addStatus" name="status">
                      <option value="Unpaid" selected>Unpaid</option>
                      <option value="Paid">Paid</option>
                      <option value="Due Soon">Due Soon</option>
                      <option value="Overdue">Overdue</option>
                    </select>
                  </div>
                </div>

                <!-- Bill Shares Section -->
                <div class="border-top pt-3">
                  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                    <div>
                      <h6 class="fw-bold text-main mb-0">Assign Member Shares</h6>
                      <small class="text-secondary-custom">Select members and assign their portion of the bill.</small>
                    </div>
                    <button type="button" class="btn btn-rs-outline btn-sm add-split-equally">
                      <i class="bi bi-calculator me-1"></i> Split Equally
                    </button>
                  </div>

                  <div class="mb-3">
                    <?php foreach ($members as $m): ?>
                      <div class="share-member-row is-selected">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                          <div class="form-check d-flex align-items-center gap-2 mb-0">
                            <input class="form-check-input add-member-checkbox" type="checkbox"
                                   name="selected_members[]" value="<?= (int) $m['user_id'] ?>" id="addMemberCheck<?= (int) $m['user_id'] ?>" checked>
                            <label class="form-check-label fw-semibold text-main small cursor-pointer" for="addMemberCheck<?= (int) $m['user_id'] ?>">
                              <?= h((string) $m['full_name']) ?> <span class="text-secondary-custom">(@<?= h((string) $m['username']) ?>)</span>
                            </label>
                          </div>
                          <div class="input-group input-group-sm share-input-group" style="max-width: 170px;">
                            <span class="input-group-text">₱</span>
                            <input type="number" step="0.01" min="0.01" class="form-control add-share-input"
                                   name="shares[<?= (int) $m['user_id'] ?>]" placeholder="0.00" value="0.00" required>
                          </div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>

                  <!-- Live calculation helper bar -->
                  <div class="p-3 bg-warm-secondary rounded d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                      <span class="text-secondary-custom small d-block">Assigned Shares Total:</span>
                      <span class="fw-bold text-main add-shares-total">₱0.00</span>
                    </div>
                    <div class="add-shares-difference text-end">
                      <span class="small text-secondary-custom">Enter total amount & click Split Equally</span>
                    </div>
                  </div>
                </div>
              </div>

              <div class="modal-footer border-top p-3">
                <button type="button" class="btn btn-rs-outline" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-rs-primary">Create Bill</button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Edit Bill Modal (Admin Only) -->
      <div class="modal fade" id="editBillModal" tabindex="-1" aria-labelledby="editBillModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
          <div class="modal-content rs-card p-0 border-0 shadow-lg">
            <div class="modal-header border-bottom p-4">
              <div>
                <span class="rs-eyebrow d-block mb-1">Edit Obligation</span>
                <h5 class="modal-title fw-bold text-main" id="editBillModalLabel">Edit Household Bill</h5>
              </div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <form action="actions/bill-update.php" method="POST" id="editBillForm">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="bill_id" value="">

              <div class="modal-body p-4">
                <!-- Bill Core Info -->
                <div class="row g-3 mb-4">
                  <div class="col-12 col-md-6">
                    <label for="editBillName" class="form-label fw-bold text-main small">Bill Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="editBillName" name="bill_name" maxlength="100" required>
                  </div>
                  <div class="col-12 col-md-6">
                    <label for="editCategoryId" class="form-label fw-bold text-main small">Category <span class="text-danger">*</span></label>
                    <select class="form-select" id="editCategoryId" name="category_id" required>
                      <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int) $cat['bill_category_id'] ?>">
                          <?= h((string) $cat['category_name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-12 col-md-4">
                    <label for="editAmount" class="form-label fw-bold text-main small">Total Amount (₱) <span class="text-danger">*</span></label>
                    <div class="input-group">
                      <span class="input-group-text">₱</span>
                      <input type="number" class="form-control" id="editAmount" name="amount" step="0.01" min="0.01" required>
                    </div>
                  </div>
                  <div class="col-12 col-md-4">
                    <label for="editDueDate" class="form-label fw-bold text-main small">Due Date <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="editDueDate" name="due_date" required>
                  </div>
                  <div class="col-12 col-md-4">
                    <label for="editStatus" class="form-label fw-bold text-main small">Bill Status</label>
                    <select class="form-select" id="editStatus" name="status">
                      <option value="Unpaid">Unpaid</option>
                      <option value="Paid">Paid</option>
                      <option value="Due Soon">Due Soon</option>
                      <option value="Overdue">Overdue</option>
                    </select>
                  </div>
                </div>

                <!-- Bill Shares Section -->
                <div class="border-top pt-3">
                  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                    <div>
                      <h6 class="fw-bold text-main mb-0">Update Member Shares</h6>
                      <small class="text-secondary-custom">Preserves existing payment records while adjusting shares.</small>
                    </div>
                    <button type="button" class="btn btn-rs-outline btn-sm edit-split-equally">
                      <i class="bi bi-calculator me-1"></i> Split Equally
                    </button>
                  </div>

                  <div class="mb-3">
                    <?php foreach ($members as $m): ?>
                      <div class="share-member-row">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                          <div class="form-check d-flex align-items-center gap-2 mb-0">
                            <input class="form-check-input edit-member-checkbox" type="checkbox"
                                   name="selected_members[]" value="<?= (int) $m['user_id'] ?>" id="editMemberCheck<?= (int) $m['user_id'] ?>">
                            <label class="form-check-label fw-semibold text-main small cursor-pointer" for="editMemberCheck<?= (int) $m['user_id'] ?>">
                              <?= h((string) $m['full_name']) ?> <span class="text-secondary-custom">(@<?= h((string) $m['username']) ?>)</span>
                            </label>
                          </div>
                          <div class="input-group input-group-sm share-input-group" style="max-width: 170px;">
                            <span class="input-group-text">₱</span>
                            <input type="number" step="0.01" min="0.01" class="form-control edit-share-input"
                                   name="shares[<?= (int) $m['user_id'] ?>]" placeholder="0.00" value="0.00">
                          </div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>

                  <!-- Live calculation helper bar -->
                  <div class="p-3 bg-warm-secondary rounded d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                      <span class="text-secondary-custom small d-block">Assigned Shares Total:</span>
                      <span class="fw-bold text-main edit-shares-total">₱0.00</span>
                    </div>
                    <div class="edit-shares-difference text-end">
                      <span class="small text-secondary-custom">—</span>
                    </div>
                  </div>
                </div>
              </div>

              <div class="modal-footer border-top p-3">
                <button type="button" class="btn btn-rs-outline" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-rs-primary">Save Changes</button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Delete Confirmation Modal (Admin Only) -->
      <div class="modal fade" id="deleteBillModal" tabindex="-1" aria-labelledby="deleteBillModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content rs-card p-0 border-0 shadow-lg">
            <div class="modal-header border-bottom p-4">
              <h5 class="modal-title fw-bold text-danger" id="deleteBillModalLabel">
                <i class="bi bi-exclamation-triangle me-2"></i>Delete Bill
              </h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="actions/bill-delete.php" method="POST">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="bill_id" id="deleteBillId" value="">

              <div class="modal-body p-4">
                <p class="mb-2">Are you sure you want to delete <strong id="deleteBillName" class="text-main">this bill</strong>?</p>
                <p class="text-secondary-custom small mb-0">This will remove the bill record and all associated member share entries from the household.</p>
              </div>
              <div class="modal-footer border-top p-3">
                <button type="button" class="btn btn-rs-outline" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete Bill</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    <?php endif; ?>

  <?php else: ?>
    <!-- ========================================== -->
    <!-- EXPENSES MODALS                            -->
    <!-- ========================================== -->

    <!-- View Expense Details Modal (For All Household Members) -->
    <div class="modal fade" id="viewExpenseModal" tabindex="-1" aria-labelledby="viewExpenseModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rs-card p-0 border-0 shadow-lg">
          <div class="modal-header border-bottom p-4">
            <div>
              <span class="rs-eyebrow d-block mb-1">Expense Information</span>
              <h5 class="modal-title fw-bold text-main" id="viewExpenseTitle">Expense Details</h5>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body p-4">
            <!-- Summary info grid -->
            <div class="row g-3 mb-4">
              <div class="col-6">
                <span class="text-secondary-custom small d-block">Category</span>
                <span class="fw-bold text-main" id="viewExpenseCategory">—</span>
              </div>
              <div class="col-6">
                <span class="text-secondary-custom small d-block">Total Amount</span>
                <span class="fw-bold text-main fs-5" id="viewExpenseAmount">—</span>
              </div>
              <div class="col-6">
                <span class="text-secondary-custom small d-block">Paid By</span>
                <span class="small fw-bold text-main" id="viewExpensePaidBy">—</span>
              </div>
              <div class="col-6">
                <span class="text-secondary-custom small d-block">Expense Date</span>
                <span class="fw-bold text-main" id="viewExpenseDate">—</span>
              </div>
              <div class="col-12">
                <span class="text-secondary-custom small d-block">Recorded On</span>
                <span class="small text-secondary-custom" id="viewExpenseCreatedAt">—</span>
              </div>
            </div>

            <!-- Description / Notes Box -->
            <div class="mb-4">
              <span class="text-secondary-custom small d-block mb-1 fw-bold">Description / Notes</span>
              <div class="p-3 rounded bg-warm-secondary border small text-main" id="viewExpenseDescription">
                No additional description provided.
              </div>
            </div>

            <?php if ($isAdmin): ?>
              <!-- Admin Quick Expense Status Action -->
              <div class="mb-3 p-3 rounded bg-warm-secondary border d-flex justify-content-between align-items-center flex-wrap gap-2" id="viewExpenseAdminStatusWrap">
                <div>
                  <span class="small fw-bold text-main d-block"><i class="bi bi-shield-check text-primary me-1"></i>Admin Status Control</span>
                  <span class="small text-secondary-custom">Quickly mark all member shares for this expense as Paid or Unpaid</span>
                </div>
                <form action="actions/expense-status.php" method="POST" class="m-0" id="viewExpenseStatusForm">
                  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="expense_id" id="viewExpenseStatusId" value="">
                  <input type="hidden" name="is_paid" id="viewExpenseStatusTarget" value="1">
                  <button type="submit" class="btn btn-sm btn-success" id="viewExpenseStatusBtn">
                    <i class="bi bi-check2-circle me-1"></i> Mark All as Paid
                  </button>
                </form>
              </div>
            <?php endif; ?>

            <!-- Household Member Shares Breakdown -->
            <div>
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="fw-bold text-main mb-0">Household Member Shares</h6>
                <span class="text-secondary-custom small">Status</span>
              </div>
              <div class="border rounded bg-white overflow-hidden" id="viewExpenseSharesList">
                <!-- Dynamically populated by JavaScript -->
              </div>
            </div>
          </div>
          <div class="modal-footer border-top p-3">
            <button type="button" class="btn btn-rs-outline w-100" data-bs-dismiss="modal">Close</button>
          </div>
        </div>
      </div>
    </div>

    <?php if ($isAdmin): ?>
      <!-- Add Expense Modal (Admin Only) -->
      <div class="modal fade" id="addExpenseModal" tabindex="-1" aria-labelledby="addExpenseModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
          <div class="modal-content rs-card p-0 border-0 shadow-lg">
            <div class="modal-header border-bottom p-4">
              <div>
                <span class="rs-eyebrow d-block mb-1">New Household Spending</span>
                <h5 class="modal-title fw-bold text-main" id="addExpenseModalLabel">Add Household Expense</h5>
              </div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <form action="actions/expense-create.php" method="POST" id="addExpenseForm" data-optional-shares="true">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <div class="modal-body p-4">
                <!-- Core Info -->
                <div class="row g-3 mb-4">
                  <div class="col-12 col-md-6">
                    <label for="addExpenseTitle" class="form-label fw-bold text-main small">Expense Title <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="addExpenseTitle" name="title" placeholder="e.g. Weekly Grocery Run, Dishwashing Liquid" maxlength="100" required>
                  </div>
                  <div class="col-12 col-md-6">
                    <div class="d-flex justify-content-between align-items-center">
                      <label for="addExpenseCategoryId" class="form-label fw-bold text-main small mb-1">Category <span class="text-danger">*</span></label>
                      <a href="manage.php#categoriesSection" class="small text-secondary-custom text-decoration-none" title="Manage Categories in Admin Settings">
                        <i class="bi bi-gear-fill"></i> Manage
                      </a>
                    </div>
                    <select class="form-select" id="addExpenseCategoryId" name="category_id" required>
                      <option value="">Choose a category...</option>
                      <?php foreach ($expenseCategories as $ecat): ?>
                        <option value="<?= (int) $ecat['expense_category_id'] ?>">
                          <?= h((string) $ecat['category_name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-12 col-md-4">
                    <label for="addExpenseAmount" class="form-label fw-bold text-main small">Total Amount (₱) <span class="text-danger">*</span></label>
                    <div class="input-group">
                      <span class="input-group-text">₱</span>
                      <input type="number" class="form-control" id="addExpenseAmount" name="amount" step="0.01" min="0.01" placeholder="0.00" required>
                    </div>
                  </div>
                  <div class="col-12 col-md-4">
                    <label for="addExpensePaidBy" class="form-label fw-bold text-main small">Paid By <span class="text-danger">*</span></label>
                    <select class="form-select" id="addExpensePaidBy" name="paid_by" required>
                      <?php foreach ($members as $m): ?>
                        <option value="<?= (int) $m['user_id'] ?>" <?= (int) $m['user_id'] === $userId ? 'selected' : '' ?>>
                          <?= h((string) $m['full_name']) ?> (@<?= h((string) $m['username']) ?>)
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-12 col-md-4">
                    <label for="addExpenseDate" class="form-label fw-bold text-main small">Expense Date <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="addExpenseDate" name="expense_date" value="<?= date('Y-m-d') ?>" required>
                  </div>
                  <div class="col-12">
                    <label for="addExpenseDescription" class="form-label fw-bold text-main small">Description / Notes</label>
                    <textarea class="form-control" id="addExpenseDescription" name="description" rows="2" placeholder="Optional notes, receipt details, or store name..."></textarea>
                  </div>
                </div>

                <!-- Expense Shares / Split Section -->
                <div class="border-top pt-3">
                  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                    <div>
                      <h6 class="fw-bold text-main mb-0">Split Expense Among Members (Optional)</h6>
                      <small class="text-secondary-custom">Check members to divide the cost. Leave unchecked for general household expenses.</small>
                    </div>
                    <button type="button" class="btn btn-rs-outline btn-sm add-expense-split-equally">
                      <i class="bi bi-calculator me-1"></i> Split Equally
                    </button>
                  </div>

                  <div class="mb-3">
                    <?php foreach ($members as $m): ?>
                      <div class="share-member-row">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                          <div class="form-check d-flex align-items-center gap-2 mb-0">
                            <input class="form-check-input add-expense-member-checkbox" type="checkbox"
                                   name="selected_members[]" value="<?= (int) $m['user_id'] ?>" id="addExpMemberCheck<?= (int) $m['user_id'] ?>">
                            <label class="form-check-label fw-semibold text-main small cursor-pointer" for="addExpMemberCheck<?= (int) $m['user_id'] ?>">
                              <?= h((string) $m['full_name']) ?> <span class="text-secondary-custom">(@<?= h((string) $m['username']) ?>)</span>
                            </label>
                          </div>
                          <div class="input-group input-group-sm share-input-group" style="max-width: 170px;">
                            <span class="input-group-text">₱</span>
                            <input type="number" step="0.01" min="0.01" class="form-control add-expense-share-input"
                                   name="shares[<?= (int) $m['user_id'] ?>]" placeholder="0.00" value="0.00" disabled>
                          </div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>

                  <!-- Live calculation helper bar -->
                  <div class="p-3 bg-warm-secondary rounded d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                      <span class="text-secondary-custom small d-block">Assigned Shares Total:</span>
                      <span class="fw-bold text-main add-expense-shares-total">₱0.00</span>
                    </div>
                    <div class="add-expense-shares-difference text-end">
                      <span class="small text-secondary-custom">Optional: check members to divide cost</span>
                    </div>
                  </div>
                </div>
              </div>

              <div class="modal-footer border-top p-3">
                <button type="button" class="btn btn-rs-outline" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-rs-primary">Record Expense</button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Edit Expense Modal (Admin Only) -->
      <div class="modal fade" id="editExpenseModal" tabindex="-1" aria-labelledby="editExpenseModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
          <div class="modal-content rs-card p-0 border-0 shadow-lg">
            <div class="modal-header border-bottom p-4">
              <div>
                <span class="rs-eyebrow d-block mb-1">Modify Record</span>
                <h5 class="modal-title fw-bold text-main" id="editExpenseModalLabel">Edit Household Expense</h5>
              </div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <form action="actions/expense-update.php" method="POST" id="editExpenseForm" data-optional-shares="true">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="expense_id" value="">

              <div class="modal-body p-4">
                <!-- Core Info -->
                <div class="row g-3 mb-4">
                  <div class="col-12 col-md-6">
                    <label for="editExpenseTitle" class="form-label fw-bold text-main small">Expense Title <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="editExpenseTitle" name="title" maxlength="100" required>
                  </div>
                  <div class="col-12 col-md-6">
                    <label for="editExpenseCategoryId" class="form-label fw-bold text-main small">Category <span class="text-danger">*</span></label>
                    <select class="form-select" id="editExpenseCategoryId" name="category_id" required>
                      <?php foreach ($expenseCategories as $ecat): ?>
                        <option value="<?= (int) $ecat['expense_category_id'] ?>">
                          <?= h((string) $ecat['category_name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-12 col-md-4">
                    <label for="editExpenseAmount" class="form-label fw-bold text-main small">Total Amount (₱) <span class="text-danger">*</span></label>
                    <div class="input-group">
                      <span class="input-group-text">₱</span>
                      <input type="number" class="form-control" id="editExpenseAmount" name="amount" step="0.01" min="0.01" required>
                    </div>
                  </div>
                  <div class="col-12 col-md-4">
                    <label for="editExpensePaidBy" class="form-label fw-bold text-main small">Paid By <span class="text-danger">*</span></label>
                    <select class="form-select" id="editExpensePaidBy" name="paid_by" required>
                      <?php foreach ($members as $m): ?>
                        <option value="<?= (int) $m['user_id'] ?>">
                          <?= h((string) $m['full_name']) ?> (@<?= h((string) $m['username']) ?>)
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-12 col-md-4">
                    <label for="editExpenseDate" class="form-label fw-bold text-main small">Expense Date <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="editExpenseDate" name="expense_date" required>
                  </div>
                  <div class="col-12">
                    <label for="editExpenseDescription" class="form-label fw-bold text-main small">Description / Notes</label>
                    <textarea class="form-control" id="editExpenseDescription" name="description" rows="2"></textarea>
                  </div>
                </div>

                <!-- Expense Shares / Split Section -->
                <div class="border-top pt-3">
                  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                    <div>
                      <h6 class="fw-bold text-main mb-0">Update Member Shares (Optional)</h6>
                      <small class="text-secondary-custom">Preserves existing payment records while adjusting split amounts.</small>
                    </div>
                    <button type="button" class="btn btn-rs-outline btn-sm edit-expense-split-equally">
                      <i class="bi bi-calculator me-1"></i> Split Equally
                    </button>
                  </div>

                  <div class="mb-3">
                    <?php foreach ($members as $m): ?>
                      <div class="share-member-row">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                          <div class="form-check d-flex align-items-center gap-2 mb-0">
                            <input class="form-check-input edit-expense-member-checkbox" type="checkbox"
                                   name="selected_members[]" value="<?= (int) $m['user_id'] ?>" id="editExpMemberCheck<?= (int) $m['user_id'] ?>">
                            <label class="form-check-label fw-semibold text-main small cursor-pointer" for="editExpMemberCheck<?= (int) $m['user_id'] ?>">
                              <?= h((string) $m['full_name']) ?> <span class="text-secondary-custom">(@<?= h((string) $m['username']) ?>)</span>
                            </label>
                          </div>
                          <div class="input-group input-group-sm share-input-group" style="max-width: 170px;">
                            <span class="input-group-text">₱</span>
                            <input type="number" step="0.01" min="0.01" class="form-control edit-expense-share-input"
                                   name="shares[<?= (int) $m['user_id'] ?>]" placeholder="0.00" value="0.00">
                          </div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>

                  <!-- Live calculation helper bar -->
                  <div class="p-3 bg-warm-secondary rounded d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                      <span class="text-secondary-custom small d-block">Assigned Shares Total:</span>
                      <span class="fw-bold text-main edit-expense-shares-total">₱0.00</span>
                    </div>
                    <div class="edit-expense-shares-difference text-end">
                      <span class="small text-secondary-custom">—</span>
                    </div>
                  </div>
                </div>
              </div>

              <div class="modal-footer border-top p-3">
                <button type="button" class="btn btn-rs-outline" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-rs-primary">Save Changes</button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Delete Expense Confirmation Modal (Admin Only) -->
      <div class="modal fade" id="deleteExpenseModal" tabindex="-1" aria-labelledby="deleteExpenseModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content rs-card p-0 border-0 shadow-lg">
            <div class="modal-header border-bottom p-4">
              <h5 class="modal-title fw-bold text-danger" id="deleteExpenseModalLabel">
                <i class="bi bi-exclamation-triangle me-2"></i>Delete Expense
              </h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="actions/expense-delete.php" method="POST">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="expense_id" id="deleteExpenseId" value="">

              <div class="modal-body p-4">
                <p class="mb-2">Are you sure you want to delete <strong id="deleteExpenseTitle" class="text-main">this expense</strong>?</p>
                <p class="text-secondary-custom small mb-0">This will remove the expense record and all associated member share entries from the household.</p>
              </div>
              <div class="modal-footer border-top p-3">
                <button type="button" class="btn btn-rs-outline" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete Expense</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    <?php endif; ?>

  <?php endif; ?>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/bills.js"></script>
</body>
</html>
