<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/pagination.php';

// Ensure user is signed in
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
    error_log('RoomieSync reports page connection failed: ' . $error->getMessage());
    http_response_code(500);
    exit('The database is temporarily unavailable.');
}

// Authoritative Household Verification: User must belong to the active household
$contextQuery = $connection->prepare(
    'SELECT h.household_id, h.household_name, hm.role
     FROM householdmembersTb AS hm
     INNER JOIN householdTb AS h ON h.household_id = hm.household_id
     WHERE hm.household_id = ? AND hm.user_id = ?
     LIMIT 1'
);
$contextQuery->execute([$householdId, $userId]);
$context = $contextQuery->fetch();

if (!is_array($context)) {
    unset($_SESSION['household_id']);
    redirect_to('households.php');
}

$householdName = (string) $context['household_name'];
$role = (string) $context['role'];
$isAdmin = strcasecmp($role, 'Admin') === 0;

// User Profile Details for Navbar
$userQuery = $connection->prepare(
    'SELECT email, username, full_name FROM userTb WHERE user_id = ? LIMIT 1'
);
$userQuery->execute([$userId]);
$currentUser = $userQuery->fetch() ?: [];
$userEmail = (string) ($currentUser['email'] ?? '');
$username = (string) ($currentUser['username'] ?? ($_SESSION['username'] ?? ''));
$fullName = (string) ($currentUser['full_name'] ?? ($_SESSION['full_name'] ?? ''));
$displayName = $username !== '' ? $username : $fullName;

function reports_initials(string $value): string
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

$avatarText = reports_initials($displayName);
$notice = consume_flash_message();

// Date Range Filtering (defaults to current month)
function reports_validate_date(?string $value, string $fallback): string
{
    if ($value === null || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value)) {
        return $fallback;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if ($d === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return $fallback;
    }
    return $d->format('Y-m-d') === $value ? $value : $fallback;
}

$defaultFromDate = date('Y-m-01');
$defaultToDate = date('Y-m-t');

$rawFrom = isset($_GET['from_date']) && is_string($_GET['from_date']) ? trim($_GET['from_date']) : null;
$rawTo = isset($_GET['to_date']) && is_string($_GET['to_date']) ? trim($_GET['to_date']) : null;

$fromDate = reports_validate_date($rawFrom, $defaultFromDate);
$toDate = reports_validate_date($rawTo, $defaultToDate);

if ($fromDate > $toDate) {
    $temp = $fromDate;
    $fromDate = $toDate;
    $toDate = $temp;
}

$hasCustomFilter = ($rawFrom !== null || $rawTo !== null);

// View Selection for Reports (squared cards overview vs openable report pages)
$rawView = isset($_GET['view']) && is_string($_GET['view']) ? strtolower(trim($_GET['view'])) : 'overview';
$validViews = ['overview', 'expenses', 'bills', 'chores', 'maintenance', 'members', 'activity'];
$view = in_array($rawView, $validViews, true) ? $rawView : 'overview';

// ==================================================
// DATABASE QUERIES (Prepared statements, filtered by current household & date range)
// ==================================================

// 1. TOP SUMMARY CARDS
// Total Expenses
$sumExpStmt = $connection->prepare(
    'SELECT COALESCE(SUM(amount), 0) AS total_amount, COUNT(*) AS total_count
     FROM expenseTb
     WHERE household_id = ? AND expense_date >= ? AND expense_date <= ?'
);
$sumExpStmt->execute([$householdId, $fromDate, $toDate]);
$sumExp = $sumExpStmt->fetch() ?: ['total_amount' => 0.0, 'total_count' => 0];
$totalExpensesAmount = (float) $sumExp['total_amount'];
$totalExpensesCount = (int) $sumExp['total_count'];

// Total Bills
$sumBillsStmt = $connection->prepare(
    'SELECT COALESCE(SUM(amount), 0) AS total_amount, COUNT(*) AS total_count,
            COALESCE(SUM(CASE WHEN LOWER(status) = "paid" THEN 1 ELSE 0 END), 0) AS paid_count,
            COALESCE(SUM(CASE WHEN LOWER(status) = "unpaid" AND due_date >= CURDATE() THEN 1 ELSE 0 END), 0) AS unpaid_count,
            COALESCE(SUM(CASE WHEN LOWER(status) <> "paid" AND due_date < CURDATE() THEN 1 ELSE 0 END), 0) AS overdue_count
     FROM billsTb
     WHERE household_id = ? AND due_date >= ? AND due_date <= ?'
);
$sumBillsStmt->execute([$householdId, $fromDate, $toDate]);
$sumBills = $sumBillsStmt->fetch() ?: [
    'total_amount' => 0.0,
    'total_count' => 0,
    'paid_count' => 0,
    'unpaid_count' => 0,
    'overdue_count' => 0,
];
$totalBillsAmount = (float) $sumBills['total_amount'];
$totalBillsCount = (int) $sumBills['total_count'];
$paidBillsCount = (int) $sumBills['paid_count'];
$unpaidBillsCount = (int) $sumBills['unpaid_count'];
$overdueBillsCount = (int) $sumBills['overdue_count'];

// Pending Chores
$sumChoresStmt = $connection->prepare(
    'SELECT COUNT(*) AS total_assignments,
            COALESCE(SUM(CASE WHEN LOWER(ca.status) = "completed" THEN 1 ELSE 0 END), 0) AS completed_count,
            COALESCE(SUM(CASE WHEN LOWER(ca.status) <> "completed" AND ca.due_date >= CURDATE() THEN 1 ELSE 0 END), 0) AS pending_count,
            COALESCE(SUM(CASE WHEN LOWER(ca.status) <> "completed" AND ca.due_date < CURDATE() THEN 1 ELSE 0 END), 0) AS overdue_count
     FROM chore_assignmentsTb AS ca
     INNER JOIN choresTb AS c ON c.chore_id = ca.chore_id
     WHERE c.household_id = ? AND ca.due_date >= ? AND ca.due_date <= ?'
);
$sumChoresStmt->execute([$householdId, $fromDate, $toDate]);
$sumChores = $sumChoresStmt->fetch() ?: [
    'total_assignments' => 0,
    'completed_count' => 0,
    'pending_count' => 0,
    'overdue_count' => 0,
];
$totalChoreAssignments = (int) $sumChores['total_assignments'];
$completedChoresCount = (int) $sumChores['completed_count'];
$pendingChoresCount = (int) $sumChores['pending_count'];
$overdueChoresCount = (int) $sumChores['overdue_count'];
// For top summary card: all uncompleted chores within period
$pendingSummaryCardCount = $pendingChoresCount + $overdueChoresCount;

// Total Chores templates defined in household
$choresDefStmt = $connection->prepare('SELECT COUNT(*) FROM choresTb WHERE household_id = ?');
$choresDefStmt->execute([$householdId]);
$totalHouseholdChoresDefined = (int) $choresDefStmt->fetchColumn();

// Open Maintenance
$sumMaintStmt = $connection->prepare(
    'SELECT COUNT(*) AS total_count,
            COALESCE(SUM(CASE WHEN LOWER(status) = "resolved" THEN 1 ELSE 0 END), 0) AS resolved_count,
            COALESCE(SUM(CASE WHEN LOWER(status) <> "resolved" THEN 1 ELSE 0 END), 0) AS open_count
     FROM maintenanceTb
     WHERE household_id = ? AND DATE(reported_at) >= ? AND DATE(reported_at) <= ?'
);
$sumMaintStmt->execute([$householdId, $fromDate, $toDate]);
$sumMaint = $sumMaintStmt->fetch() ?: [
    'total_count' => 0,
    'resolved_count' => 0,
    'open_count' => 0,
];
$totalMaintenanceCount = (int) $sumMaint['total_count'];
$resolvedMaintenanceCount = (int) $sumMaint['resolved_count'];
$openMaintenanceCount = (int) $sumMaint['open_count'];

// 2. EXPENSE REPORT DETAILS
// Expense Categories Breakdown
$expCatsStmt = $connection->prepare(
    'SELECT COALESCE(ec.category_name, "Uncategorized") AS category_name,
            COUNT(e.expense_id) AS cat_count,
            COALESCE(SUM(e.amount), 0) AS cat_total
     FROM expenseTb AS e
     LEFT JOIN expense_categoriesTb AS ec ON ec.expense_category_id = e.category_id
     WHERE e.household_id = ? AND e.expense_date >= ? AND e.expense_date <= ?
     GROUP BY ec.category_name
     ORDER BY cat_total DESC'
);
$expCatsStmt->execute([$householdId, $fromDate, $toDate]);
$expenseCategories = $expCatsStmt->fetchAll();

// Amount paid by each user
$expUsersStmt = $connection->prepare(
    'SELECT u.user_id,
            COALESCE(NULLIF(u.full_name, ""), u.username, "Unknown") AS payer_name,
            COUNT(e.expense_id) AS exp_count,
            COALESCE(SUM(e.amount), 0) AS total_paid
     FROM expenseTb AS e
     LEFT JOIN userTb AS u ON u.user_id = e.paid_by
     WHERE e.household_id = ? AND e.expense_date >= ? AND e.expense_date <= ?
     GROUP BY u.user_id, payer_name
     ORDER BY total_paid DESC'
);
$expUsersStmt->execute([$householdId, $fromDate, $toDate]);
$expensePayers = $expUsersStmt->fetchAll();

// Expense Table Records: Date | Expense | Category | Amount | Paid By
$expTableStmt = $connection->prepare(
    'SELECT e.expense_id, e.title, e.amount, e.expense_date, e.description,
            COALESCE(ec.category_name, "General") AS category_name,
            COALESCE(NULLIF(u.full_name, ""), u.username, "Not specified") AS paid_by_name
     FROM expenseTb AS e
     LEFT JOIN expense_categoriesTb AS ec ON ec.expense_category_id = e.category_id
     LEFT JOIN userTb AS u ON u.user_id = e.paid_by
     WHERE e.household_id = ? AND e.expense_date >= ? AND e.expense_date <= ?
     ORDER BY e.expense_date DESC, e.expense_id DESC'
);
$expTableStmt->execute([$householdId, $fromDate, $toDate]);
$expenseRecords = $expTableStmt->fetchAll();

// 3. BILL REPORT DETAILS: Bill | Category | Amount | Due Date | Status
$billTableStmt = $connection->prepare(
    'SELECT b.bill_id, b.bill_name, b.amount, b.due_date, b.status,
            COALESCE(bc.category_name, "General") AS category_name
     FROM billsTb AS b
     LEFT JOIN bill_categoriesTb AS bc ON bc.bill_category_id = b.category_id
     WHERE b.household_id = ? AND b.due_date >= ? AND b.due_date <= ?
     ORDER BY b.due_date ASC, b.bill_id DESC'
);
$billTableStmt->execute([$householdId, $fromDate, $toDate]);
$billRecords = $billTableStmt->fetchAll();

// 4. CHORE REPORT DETAILS: Chore | Assigned To | Due Date | Status
// Uses chore_assignmentsTb and choresTb (choresTb.assigned_to is NOT used per requirements)
$choreTableStmt = $connection->prepare(
    'SELECT ca.assignment_id, ca.due_date, ca.status, ca.completed_at,
            c.chore_name, c.frequency,
            COALESCE(cc.category_name, "General") AS category_name,
            COALESCE(NULLIF(u.full_name, ""), u.username, "Unassigned") AS assigned_to_name
     FROM chore_assignmentsTb AS ca
     INNER JOIN choresTb AS c ON c.chore_id = ca.chore_id
     LEFT JOIN chore_categoriesTb AS cc ON cc.chore_category_id = c.category_id
     LEFT JOIN userTb AS u ON u.user_id = ca.user_id
     WHERE c.household_id = ? AND ca.due_date >= ? AND ca.due_date <= ?
     ORDER BY ca.due_date DESC, ca.assignment_id DESC'
);
$choreTableStmt->execute([$householdId, $fromDate, $toDate]);
$choreRecords = $choreTableStmt->fetchAll();

// 5. MAINTENANCE REPORT DETAILS: Problem | Type | Reported By | Date | Status
$maintTableStmt = $connection->prepare(
    'SELECT m.problem_id, m.problem_type, m.problem_desc, m.reported_at, m.status,
            COALESCE(NULLIF(u.full_name, ""), u.username, "Anonymous") AS reported_by_name
     FROM maintenanceTb AS m
     LEFT JOIN userTb AS u ON u.user_id = m.reported_by
     WHERE m.household_id = ? AND DATE(m.reported_at) >= ? AND DATE(m.reported_at) <= ?
     ORDER BY m.reported_at DESC, m.problem_id DESC'
);
$maintTableStmt->execute([$householdId, $fromDate, $toDate]);
$maintenanceRecords = $maintTableStmt->fetchAll();

// 6. HOUSEHOLD ACTIVITY TOTALS
$actMembersStmt = $connection->prepare('SELECT COUNT(*) FROM householdmembersTb WHERE household_id = ?');
$actMembersStmt->execute([$householdId]);
$activityMembersCount = (int) $actMembersStmt->fetchColumn();

$actAnnounceStmt = $connection->prepare(
    'SELECT COUNT(*) FROM announceTb
     WHERE household_id = ? AND DATE(created_at) >= ? AND DATE(created_at) <= ?'
);
$actAnnounceStmt->execute([$householdId, $fromDate, $toDate]);
$activityAnnounceCount = (int) $actAnnounceStmt->fetchColumn();

$actExpensesCount = $totalExpensesCount;
$actBillsCount = $totalBillsCount;
$actChoresCreatedCount = $totalHouseholdChoresDefined;
$actMaintenanceCount = $totalMaintenanceCount;

// 7. MEMBER SUMMARY: Member | Expenses Paid | Chores Completed | Bill Shares | Maintenance Reports
$membersStmt = $connection->prepare(
    'SELECT hm.user_id, COALESCE(NULLIF(u.full_name, ""), u.username) AS member_name, hm.role
     FROM householdmembersTb AS hm
     INNER JOIN userTb AS u ON u.user_id = hm.user_id
     WHERE hm.household_id = ?
     ORDER BY member_name ASC'
);
$membersStmt->execute([$householdId]);
$householdMemberList = $membersStmt->fetchAll();

// Keyed map of expenses paid per member
$memExpStmt = $connection->prepare(
    'SELECT paid_by, COALESCE(SUM(amount), 0) AS total
     FROM expenseTb
     WHERE household_id = ? AND expense_date >= ? AND expense_date <= ? AND paid_by IS NOT NULL
     GROUP BY paid_by'
);
$memExpStmt->execute([$householdId, $fromDate, $toDate]);
$memExpMap = $memExpStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Keyed map of chores completed per member
$memChoresStmt = $connection->prepare(
    'SELECT ca.user_id, COUNT(*) AS total
     FROM chore_assignmentsTb AS ca
     INNER JOIN choresTb AS c ON c.chore_id = ca.chore_id
     WHERE c.household_id = ? AND LOWER(ca.status) = "completed" AND ca.due_date >= ? AND ca.due_date <= ?
     GROUP BY ca.user_id'
);
$memChoresStmt->execute([$householdId, $fromDate, $toDate]);
$memChoresMap = $memChoresStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Keyed map of bill shares per member
$memSharesStmt = $connection->prepare(
    'SELECT bs.user_id, COALESCE(SUM(bs.share_amount), 0) AS total
     FROM bill_sharesTb AS bs
     INNER JOIN billsTb AS b ON b.bill_id = bs.bill_id
     WHERE b.household_id = ? AND b.due_date >= ? AND b.due_date <= ?
     GROUP BY bs.user_id'
);
$memSharesStmt->execute([$householdId, $fromDate, $toDate]);
$memSharesMap = $memSharesStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Keyed map of maintenance reports per member
$memMaintStmt = $connection->prepare(
    'SELECT reported_by, COUNT(*) AS total
     FROM maintenanceTb
     WHERE household_id = ? AND reported_by IS NOT NULL AND DATE(reported_at) >= ? AND DATE(reported_at) <= ?
     GROUP BY reported_by'
);
$memMaintStmt->execute([$householdId, $fromDate, $toDate]);
$memMaintMap = $memMaintStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$memberSummaryList = [];
foreach ($householdMemberList as $m) {
    $uid = (int) $m['user_id'];
    $memberSummaryList[] = [
        'user_id' => $uid,
        'member_name' => (string) $m['member_name'],
        'role' => (string) $m['role'],
        'expenses_paid' => (float) ($memExpMap[$uid] ?? 0.0),
        'chores_completed' => (int) ($memChoresMap[$uid] ?? 0),
        'bill_shares' => (float) ($memSharesMap[$uid] ?? 0.0),
        'maintenance_reports' => (int) ($memMaintMap[$uid] ?? 0),
    ];
}

// Helpers for formatted date display
$formattedFromDate = date('M j, Y', strtotime($fromDate));
$formattedToDate = date('M j, Y', strtotime($toDate));

// =========================================================================
// MS EXCEL (.XLS) & CSV EXPORT HANDLERS
// =========================================================================
$exportFormat = isset($_GET['export']) ? strtolower(trim((string) $_GET['export'])) : null;

// Primary: MS Excel (.xls SpreadsheetML) Export
if ($exportFormat === 'excel' || $exportFormat === 'xls') {
    $scope = isset($_GET['scope']) ? strtolower(trim((string) $_GET['scope'])) : 'all';
    $sanitizedHousehold = preg_replace('/[^A-Za-z0-9_-]/', '_', $householdName) ?: 'Household';
    $scopeLabel = ucfirst($scope);
    $filename = sprintf('RoomieSync_%s_%s_%s_to_%s.xls', $sanitizedHousehold, $scopeLabel, $fromDate, $toDate);

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0, no-cache, must-revalidate, proxy-revalidate');
    header('Pragma: public');
    header('Expires: 0');

    $xEsc = static function ($value): string {
        return htmlspecialchars((string) ($value ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    };

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
    ?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 <DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">
  <Author><?= $xEsc($displayName) ?></Author>
  <LastAuthor>RoomieSync System</LastAuthor>
  <Company>RoomieSync</Company>
  <Created><?= date('Y-m-d\TH:i:s\Z') ?></Created>
 </DocumentProperties>
 <Styles>
  <Style ss:ID="Default" ss:Name="Normal">
   <Alignment ss:Vertical="Center"/>
   <Font ss:FontName="Segoe UI" ss:Size="10" ss:Color="#3B2922"/>
  </Style>
  <Style ss:ID="TitleStyle">
   <Font ss:FontName="Segoe UI" ss:Size="14" ss:Bold="1" ss:Color="#3B2922"/>
   <Alignment ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="SubTitleStyle">
   <Font ss:FontName="Segoe UI" ss:Size="9" ss:Italic="1" ss:Color="#715F54"/>
   <Alignment ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="SectionHeader">
   <Font ss:FontName="Segoe UI" ss:Size="11" ss:Bold="1" ss:Color="#3B2922"/>
   <Alignment ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="HeaderStyle">
   <Font ss:FontName="Segoe UI" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/>
   <Interior ss:Color="#8A6246" ss:Pattern="Solid"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#5F432F"/>
   </Borders>
  </Style>
  <Style ss:ID="HeaderGreen">
   <Font ss:FontName="Segoe UI" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/>
   <Interior ss:Color="#10B981" ss:Pattern="Solid"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#059669"/>
   </Borders>
  </Style>
  <Style ss:ID="HeaderBlue">
   <Font ss:FontName="Segoe UI" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/>
   <Interior ss:Color="#3B82F6" ss:Pattern="Solid"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#2563EB"/>
   </Borders>
  </Style>
  <Style ss:ID="HeaderRed">
   <Font ss:FontName="Segoe UI" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/>
   <Interior ss:Color="#EF4444" ss:Pattern="Solid"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#DC2626"/>
   </Borders>
  </Style>
  <Style ss:ID="HeaderPurple">
   <Font ss:FontName="Segoe UI" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/>
   <Interior ss:Color="#8B5CF6" ss:Pattern="Solid"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#7C3AED"/>
   </Borders>
  </Style>
  <Style ss:ID="CurrencyStyle">
   <NumberFormat ss:Format="₱#,##0.00"/>
   <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="NumberStyle">
   <NumberFormat ss:Format="#,##0"/>
   <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="DateStyle">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="StatusPaid">
   <Font ss:FontName="Segoe UI" ss:Size="9" ss:Bold="1" ss:Color="#065F46"/>
   <Interior ss:Color="#ECFDF5" ss:Pattern="Solid"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="StatusOverdue">
   <Font ss:FontName="Segoe UI" ss:Size="9" ss:Bold="1" ss:Color="#991B1B"/>
   <Interior ss:Color="#FEE2E2" ss:Pattern="Solid"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="StatusPending">
   <Font ss:FontName="Segoe UI" ss:Size="9" ss:Bold="1" ss:Color="#92400E"/>
   <Interior ss:Color="#FEF3C7" ss:Pattern="Solid"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="StatusInProgress">
   <Font ss:FontName="Segoe UI" ss:Size="9" ss:Bold="1" ss:Color="#1E40AF"/>
   <Interior ss:Color="#DBEAFE" ss:Pattern="Solid"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="TotalLabel">
   <Font ss:FontName="Segoe UI" ss:Size="10" ss:Bold="1" ss:Color="#3B2922"/>
   <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#8A6246"/>
    <Border ss:Position="Bottom" ss:LineStyle="Double" ss:Weight="3" ss:Color="#8A6246"/>
   </Borders>
  </Style>
  <Style ss:ID="TotalCurrency">
   <Font ss:FontName="Segoe UI" ss:Size="10" ss:Bold="1" ss:Color="#3B2922"/>
   <NumberFormat ss:Format="₱#,##0.00"/>
   <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#8A6246"/>
    <Border ss:Position="Bottom" ss:LineStyle="Double" ss:Weight="3" ss:Color="#8A6246"/>
   </Borders>
  </Style>
 </Styles>

<?php if ($scope === 'all' || $scope === 'overview'): ?>
 <!-- ==================== SHEET: EXECUTIVE SUMMARY ==================== -->
 <Worksheet ss:Name="Executive Summary">
  <Table ss:DefaultRowHeight="20">
   <Column ss:Width="200"/>
   <Column ss:Width="150"/>
   <Column ss:Width="320"/>
   <Row ss:Height="28">
    <Cell ss:MergeAcross="2" ss:StyleID="TitleStyle"><Data ss:Type="String">RoomieSync Household Report - Executive Summary</Data></Cell>
   </Row>
   <Row ss:Height="18">
    <Cell ss:MergeAcross="2" ss:StyleID="SubTitleStyle"><Data ss:Type="String">Household: <?= $xEsc($householdName) ?> | Period: <?= $xEsc($formattedFromDate . ' - ' . $formattedToDate) ?> | Generated: <?= date('M j, Y g:i A') ?> by <?= $xEsc($displayName . ' (' . $role . ')') ?></Data></Cell>
   </Row>
   <Row ss:Height="10"/>
   <Row ss:Height="22">
    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Metric</Data></Cell>
    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Value</Data></Cell>
    <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Details</Data></Cell>
   </Row>
   <Row>
    <Cell><Data ss:Type="String">Total Expenses</Data></Cell>
    <Cell ss:StyleID="CurrencyStyle"><Data ss:Type="Number"><?= (float) $totalExpensesAmount ?></Data></Cell>
    <Cell><Data ss:Type="String"><?= $totalExpensesCount ?> expense transactions recorded</Data></Cell>
   </Row>
   <Row>
    <Cell><Data ss:Type="String">Total Bills</Data></Cell>
    <Cell ss:StyleID="CurrencyStyle"><Data ss:Type="Number"><?= (float) $totalBillsAmount ?></Data></Cell>
    <Cell><Data ss:Type="String"><?= $totalBillsCount ?> bills (<?= $paidBillsCount ?> paid, <?= $unpaidBillsCount ?> unpaid, <?= $overdueBillsCount ?> overdue)</Data></Cell>
   </Row>
   <Row>
    <Cell><Data ss:Type="String">Pending Chores</Data></Cell>
    <Cell ss:StyleID="NumberStyle"><Data ss:Type="Number"><?= $pendingSummaryCardCount ?></Data></Cell>
    <Cell><Data ss:Type="String"><?= $completedChoresCount ?> chores completed in period</Data></Cell>
   </Row>
   <Row>
    <Cell><Data ss:Type="String">Maintenance Issues</Data></Cell>
    <Cell ss:StyleID="NumberStyle"><Data ss:Type="Number"><?= $openMaintenanceCount ?></Data></Cell>
    <Cell><Data ss:Type="String"><?= $openMaintenanceCount ?> open, <?= $resolvedMaintenanceCount ?> resolved of <?= $totalMaintenanceCount ?> total reported</Data></Cell>
   </Row>
   <Row>
    <Cell><Data ss:Type="String">Active Household Members</Data></Cell>
    <Cell ss:StyleID="NumberStyle"><Data ss:Type="Number"><?= $activityMembersCount ?></Data></Cell>
    <Cell><Data ss:Type="String">Registered members participating</Data></Cell>
   </Row>
   <?php if (!empty($expenseCategories)): ?>
   <Row ss:Height="16"/>
   <Row ss:Height="22">
    <Cell ss:MergeAcross="2" ss:StyleID="SectionHeader"><Data ss:Type="String">Expense Breakdown by Category</Data></Cell>
   </Row>
   <Row ss:Height="22">
    <Cell ss:StyleID="HeaderGreen"><Data ss:Type="String">Category</Data></Cell>
    <Cell ss:StyleID="HeaderGreen"><Data ss:Type="String">Total (PHP)</Data></Cell>
    <Cell ss:StyleID="HeaderGreen"><Data ss:Type="String">Transactions</Data></Cell>
   </Row>
   <?php foreach ($expenseCategories as $cat): ?>
   <Row>
    <Cell><Data ss:Type="String"><?= $xEsc((string) $cat['category_name']) ?></Data></Cell>
    <Cell ss:StyleID="CurrencyStyle"><Data ss:Type="Number"><?= (float) $cat['cat_total'] ?></Data></Cell>
    <Cell ss:StyleID="NumberStyle"><Data ss:Type="Number"><?= (int) $cat['cat_count'] ?></Data></Cell>
   </Row>
   <?php endforeach; ?>
   <?php endif; ?>
  </Table>
 </Worksheet>
<?php endif; ?>

<?php if ($scope === 'all' || $scope === 'expenses'): ?>
 <!-- ==================== SHEET: EXPENSES ==================== -->
 <Worksheet ss:Name="Expenses">
  <Table ss:DefaultRowHeight="20">
   <Column ss:Width="100"/>
   <Column ss:Width="190"/>
   <Column ss:Width="130"/>
   <Column ss:Width="130"/>
   <Column ss:Width="150"/>
   <Column ss:Width="250"/>
   <Row ss:Height="28">
    <Cell ss:MergeAcross="5" ss:StyleID="TitleStyle"><Data ss:Type="String">RoomieSync - Expense Records</Data></Cell>
   </Row>
   <Row ss:Height="18">
    <Cell ss:MergeAcross="5" ss:StyleID="SubTitleStyle"><Data ss:Type="String">Household: <?= $xEsc($householdName) ?> | Period: <?= $xEsc($formattedFromDate . ' - ' . $formattedToDate) ?></Data></Cell>
   </Row>
   <Row ss:Height="10"/>
   <Row ss:Height="22">
    <Cell ss:StyleID="HeaderGreen"><Data ss:Type="String">Date</Data></Cell>
    <Cell ss:StyleID="HeaderGreen"><Data ss:Type="String">Title</Data></Cell>
    <Cell ss:StyleID="HeaderGreen"><Data ss:Type="String">Category</Data></Cell>
    <Cell ss:StyleID="HeaderGreen"><Data ss:Type="String">Amount (PHP)</Data></Cell>
    <Cell ss:StyleID="HeaderGreen"><Data ss:Type="String">Paid By</Data></Cell>
    <Cell ss:StyleID="HeaderGreen"><Data ss:Type="String">Description</Data></Cell>
   </Row>
   <?php if (empty($expenseRecords)): ?>
   <Row>
    <Cell ss:MergeAcross="5"><Data ss:Type="String">No expense records found for this period.</Data></Cell>
   </Row>
   <?php else: ?>
     <?php foreach ($expenseRecords as $exp): ?>
     <Row>
      <Cell ss:StyleID="DateStyle"><Data ss:Type="String"><?= $xEsc(date('Y-m-d', strtotime((string) $exp['expense_date']))) ?></Data></Cell>
      <Cell><Data ss:Type="String"><?= $xEsc((string) $exp['title']) ?></Data></Cell>
      <Cell><Data ss:Type="String"><?= $xEsc((string) ($exp['category_name'] ?: 'General')) ?></Data></Cell>
      <Cell ss:StyleID="CurrencyStyle"><Data ss:Type="Number"><?= (float) $exp['amount'] ?></Data></Cell>
      <Cell><Data ss:Type="String"><?= $xEsc((string) $exp['paid_by_name']) ?></Data></Cell>
      <Cell><Data ss:Type="String"><?= $xEsc((string) ($exp['description'] ?? '')) ?></Data></Cell>
     </Row>
     <?php endforeach; ?>
     <Row ss:Height="22">
      <Cell ss:MergeAcross="2" ss:StyleID="TotalLabel"><Data ss:Type="String">Total Expenses:</Data></Cell>
      <Cell ss:StyleID="TotalCurrency"><Data ss:Type="Number"><?= (float) $totalExpensesAmount ?></Data></Cell>
      <Cell ss:MergeAcross="1"/>
     </Row>
   <?php endif; ?>
  </Table>
 </Worksheet>
<?php endif; ?>

<?php if ($scope === 'all' || $scope === 'bills'): ?>
 <!-- ==================== SHEET: BILLS ==================== -->
 <Worksheet ss:Name="Bills">
  <Table ss:DefaultRowHeight="20">
   <Column ss:Width="200"/>
   <Column ss:Width="140"/>
   <Column ss:Width="130"/>
   <Column ss:Width="110"/>
   <Column ss:Width="110"/>
   <Row ss:Height="28">
    <Cell ss:MergeAcross="4" ss:StyleID="TitleStyle"><Data ss:Type="String">RoomieSync - Bill Records</Data></Cell>
   </Row>
   <Row ss:Height="18">
    <Cell ss:MergeAcross="4" ss:StyleID="SubTitleStyle"><Data ss:Type="String">Household: <?= $xEsc($householdName) ?> | Period: <?= $xEsc($formattedFromDate . ' - ' . $formattedToDate) ?></Data></Cell>
   </Row>
   <Row ss:Height="10"/>
   <Row ss:Height="22">
    <Cell ss:StyleID="HeaderGreen"><Data ss:Type="String">Bill Name</Data></Cell>
    <Cell ss:StyleID="HeaderGreen"><Data ss:Type="String">Category</Data></Cell>
    <Cell ss:StyleID="HeaderGreen"><Data ss:Type="String">Amount (PHP)</Data></Cell>
    <Cell ss:StyleID="HeaderGreen"><Data ss:Type="String">Due Date</Data></Cell>
    <Cell ss:StyleID="HeaderGreen"><Data ss:Type="String">Status</Data></Cell>
   </Row>
   <?php if (empty($billRecords)): ?>
   <Row>
    <Cell ss:MergeAcross="4"><Data ss:Type="String">No bills found with due dates in this period.</Data></Cell>
   </Row>
   <?php else: ?>
     <?php foreach ($billRecords as $bill): ?>
       <?php
         $bStatus = strtolower((string) $bill['status']);
         $bDueDate = strtotime((string) $bill['due_date']);
         $isOverdue = ($bStatus !== 'paid' && $bDueDate < strtotime('today'));
         $billStatusStyle = $bStatus === 'paid' ? 'StatusPaid' : ($isOverdue ? 'StatusOverdue' : 'StatusPending');
         $billStatusLabel = $bStatus === 'paid' ? 'Paid' : ($isOverdue ? 'Overdue' : ucfirst($bStatus));
       ?>
       <Row>
        <Cell><Data ss:Type="String"><?= $xEsc((string) $bill['bill_name']) ?></Data></Cell>
        <Cell><Data ss:Type="String"><?= $xEsc((string) ($bill['category_name'] ?: 'Utilities')) ?></Data></Cell>
        <Cell ss:StyleID="CurrencyStyle"><Data ss:Type="Number"><?= (float) $bill['amount'] ?></Data></Cell>
        <Cell ss:StyleID="DateStyle"><Data ss:Type="String"><?= $xEsc(date('Y-m-d', $bDueDate)) ?></Data></Cell>
        <Cell ss:StyleID="<?= $billStatusStyle ?>"><Data ss:Type="String"><?= $xEsc($billStatusLabel) ?></Data></Cell>
       </Row>
     <?php endforeach; ?>
     <Row ss:Height="22">
      <Cell ss:MergeAcross="1" ss:StyleID="TotalLabel"><Data ss:Type="String">Total Bills:</Data></Cell>
      <Cell ss:StyleID="TotalCurrency"><Data ss:Type="Number"><?= (float) $totalBillsAmount ?></Data></Cell>
      <Cell ss:MergeAcross="1"/>
     </Row>
   <?php endif; ?>
  </Table>
 </Worksheet>
<?php endif; ?>

<?php if ($scope === 'all' || $scope === 'chores'): ?>
 <!-- ==================== SHEET: CHORES ==================== -->
 <Worksheet ss:Name="Chores">
  <Table ss:DefaultRowHeight="20">
   <Column ss:Width="200"/>
   <Column ss:Width="130"/>
   <Column ss:Width="110"/>
   <Column ss:Width="160"/>
   <Column ss:Width="110"/>
   <Column ss:Width="110"/>
   <Row ss:Height="28">
    <Cell ss:MergeAcross="5" ss:StyleID="TitleStyle"><Data ss:Type="String">RoomieSync - Chore Assignments</Data></Cell>
   </Row>
   <Row ss:Height="18">
    <Cell ss:MergeAcross="5" ss:StyleID="SubTitleStyle"><Data ss:Type="String">Household: <?= $xEsc($householdName) ?> | Period: <?= $xEsc($formattedFromDate . ' - ' . $formattedToDate) ?></Data></Cell>
   </Row>
   <Row ss:Height="10"/>
   <Row ss:Height="22">
    <Cell ss:StyleID="HeaderBlue"><Data ss:Type="String">Chore Name</Data></Cell>
    <Cell ss:StyleID="HeaderBlue"><Data ss:Type="String">Category</Data></Cell>
    <Cell ss:StyleID="HeaderBlue"><Data ss:Type="String">Frequency</Data></Cell>
    <Cell ss:StyleID="HeaderBlue"><Data ss:Type="String">Assigned To</Data></Cell>
    <Cell ss:StyleID="HeaderBlue"><Data ss:Type="String">Due Date</Data></Cell>
    <Cell ss:StyleID="HeaderBlue"><Data ss:Type="String">Status</Data></Cell>
   </Row>
   <?php if (empty($choreRecords)): ?>
   <Row>
    <Cell ss:MergeAcross="5"><Data ss:Type="String">No chore assignments found due within this period.</Data></Cell>
   </Row>
   <?php else: ?>
     <?php foreach ($choreRecords as $chore): ?>
       <?php
         $cStatus = strtolower((string) $chore['status']);
         $cDueDate = strtotime((string) $chore['due_date']);
         $isOverdue = ($cStatus !== 'completed' && $cDueDate < strtotime('today'));
         $choreStatusStyle = $cStatus === 'completed' ? 'StatusPaid' : ($isOverdue ? 'StatusOverdue' : 'StatusPending');
         $choreStatusLabel = $cStatus === 'completed' ? 'Completed' : ($isOverdue ? 'Overdue' : ucfirst($cStatus));
       ?>
       <Row>
        <Cell><Data ss:Type="String"><?= $xEsc((string) $chore['chore_name']) ?></Data></Cell>
        <Cell><Data ss:Type="String"><?= $xEsc((string) ($chore['category_name'] ?: 'Household')) ?></Data></Cell>
        <Cell><Data ss:Type="String"><?= $xEsc((string) ($chore['frequency'] ?: 'Routine')) ?></Data></Cell>
        <Cell><Data ss:Type="String"><?= $xEsc((string) ($chore['assigned_to_name'] ?: 'Unassigned')) ?></Data></Cell>
        <Cell ss:StyleID="DateStyle"><Data ss:Type="String"><?= $xEsc(date('Y-m-d', $cDueDate)) ?></Data></Cell>
        <Cell ss:StyleID="<?= $choreStatusStyle ?>"><Data ss:Type="String"><?= $xEsc($choreStatusLabel) ?></Data></Cell>
       </Row>
     <?php endforeach; ?>
   <?php endif; ?>
  </Table>
 </Worksheet>
<?php endif; ?>

<?php if ($scope === 'all' || $scope === 'maintenance'): ?>
 <!-- ==================== SHEET: MAINTENANCE ==================== -->
 <Worksheet ss:Name="Maintenance">
  <Table ss:DefaultRowHeight="20">
   <Column ss:Width="160"/>
   <Column ss:Width="280"/>
   <Column ss:Width="160"/>
   <Column ss:Width="140"/>
   <Column ss:Width="110"/>
   <Row ss:Height="28">
    <Cell ss:MergeAcross="4" ss:StyleID="TitleStyle"><Data ss:Type="String">RoomieSync - Maintenance Issues</Data></Cell>
   </Row>
   <Row ss:Height="18">
    <Cell ss:MergeAcross="4" ss:StyleID="SubTitleStyle"><Data ss:Type="String">Household: <?= $xEsc($householdName) ?> | Period: <?= $xEsc($formattedFromDate . ' - ' . $formattedToDate) ?></Data></Cell>
   </Row>
   <Row ss:Height="10"/>
   <Row ss:Height="22">
    <Cell ss:StyleID="HeaderRed"><Data ss:Type="String">Problem Type</Data></Cell>
    <Cell ss:StyleID="HeaderRed"><Data ss:Type="String">Description</Data></Cell>
    <Cell ss:StyleID="HeaderRed"><Data ss:Type="String">Reported By</Data></Cell>
    <Cell ss:StyleID="HeaderRed"><Data ss:Type="String">Date Reported</Data></Cell>
    <Cell ss:StyleID="HeaderRed"><Data ss:Type="String">Status</Data></Cell>
   </Row>
   <?php if (empty($maintenanceRecords)): ?>
   <Row>
    <Cell ss:MergeAcross="4"><Data ss:Type="String">No maintenance issues reported for this period.</Data></Cell>
   </Row>
   <?php else: ?>
     <?php foreach ($maintenanceRecords as $maint): ?>
       <?php
         $mStatus = strtolower((string) $maint['status']);
         $maintStyle = ($mStatus === 'resolved') ? 'StatusPaid' : (($mStatus === 'in_progress' || $mStatus === 'in progress') ? 'StatusInProgress' : 'StatusPending');
         $maintLabel = ($mStatus === 'in_progress' || $mStatus === 'in progress') ? 'In Progress' : ucfirst($mStatus);
       ?>
       <Row>
        <Cell><Data ss:Type="String"><?= $xEsc((string) $maint['problem_type']) ?></Data></Cell>
        <Cell><Data ss:Type="String"><?= $xEsc((string) ($maint['problem_desc'] ?? '')) ?></Data></Cell>
        <Cell><Data ss:Type="String"><?= $xEsc((string) ($maint['reported_by_name'] ?: 'Anonymous')) ?></Data></Cell>
        <Cell ss:StyleID="DateStyle"><Data ss:Type="String"><?= $xEsc(date('Y-m-d H:i', strtotime((string) $maint['reported_at']))) ?></Data></Cell>
        <Cell ss:StyleID="<?= $maintStyle ?>"><Data ss:Type="String"><?= $xEsc($maintLabel) ?></Data></Cell>
       </Row>
     <?php endforeach; ?>
   <?php endif; ?>
  </Table>
 </Worksheet>
<?php endif; ?>

<?php if ($scope === 'all' && !empty($memberSummaryList)): ?>
 <!-- ==================== SHEET: MEMBER SUMMARY ==================== -->
 <Worksheet ss:Name="Member Summary">
  <Table ss:DefaultRowHeight="20">
   <Column ss:Width="180"/>
   <Column ss:Width="110"/>
   <Column ss:Width="140"/>
   <Column ss:Width="130"/>
   <Column ss:Width="140"/>
   <Column ss:Width="150"/>
   <Row ss:Height="28">
    <Cell ss:MergeAcross="5" ss:StyleID="TitleStyle"><Data ss:Type="String">RoomieSync - Member Participation Summary</Data></Cell>
   </Row>
   <Row ss:Height="18">
    <Cell ss:MergeAcross="5" ss:StyleID="SubTitleStyle"><Data ss:Type="String">Household: <?= $xEsc($householdName) ?> | Period: <?= $xEsc($formattedFromDate . ' - ' . $formattedToDate) ?></Data></Cell>
   </Row>
   <Row ss:Height="10"/>
   <Row ss:Height="22">
    <Cell ss:StyleID="HeaderPurple"><Data ss:Type="String">Member Name</Data></Cell>
    <Cell ss:StyleID="HeaderPurple"><Data ss:Type="String">Role</Data></Cell>
    <Cell ss:StyleID="HeaderPurple"><Data ss:Type="String">Expenses Paid (PHP)</Data></Cell>
    <Cell ss:StyleID="HeaderPurple"><Data ss:Type="String">Chores Done</Data></Cell>
    <Cell ss:StyleID="HeaderPurple"><Data ss:Type="String">Bill Shares (PHP)</Data></Cell>
    <Cell ss:StyleID="HeaderPurple"><Data ss:Type="String">Maintenance Reported</Data></Cell>
   </Row>
   <?php foreach ($memberSummaryList as $mem): ?>
   <Row>
    <Cell><Data ss:Type="String"><?= $xEsc((string) $mem['member_name']) ?></Data></Cell>
    <Cell><Data ss:Type="String"><?= $xEsc(ucfirst((string) $mem['role'])) ?></Data></Cell>
    <Cell ss:StyleID="CurrencyStyle"><Data ss:Type="Number"><?= (float) $mem['expenses_paid'] ?></Data></Cell>
    <Cell ss:StyleID="NumberStyle"><Data ss:Type="Number"><?= (int) $mem['chores_completed'] ?></Data></Cell>
    <Cell ss:StyleID="CurrencyStyle"><Data ss:Type="Number"><?= (float) $mem['bill_shares'] ?></Data></Cell>
    <Cell ss:StyleID="NumberStyle"><Data ss:Type="Number"><?= (int) $mem['maintenance_reports'] ?></Data></Cell>
   </Row>
   <?php endforeach; ?>
  </Table>
 </Worksheet>
<?php endif; ?>
</Workbook>
    <?php
    exit;
}

// Fallback / Alternative: Plain CSV Export
if ($exportFormat === 'csv') {
    $scope = isset($_GET['scope']) ? strtolower(trim((string) $_GET['scope'])) : 'all';
    $sanitizedHousehold = preg_replace('/[^A-Za-z0-9_-]/', '_', $householdName) ?: 'Household';
    $scopeLabel = ucfirst($scope);
    $filename = sprintf('RoomieSync_%s_%s_%s_to_%s.csv', $sanitizedHousehold, $scopeLabel, $fromDate, $toDate);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    if ($out) {
        // Output UTF-8 Byte Order Mark for Excel compatibility
        fputs($out, "\xEF\xBB\xBF");

        $writeSectionHeader = static function (string $title) use ($out): void {
            fputcsv($out, []);
            fputcsv($out, ['=== ' . strtoupper($title) . ' ===']);
            fputcsv($out, []);
        };

        // Header Metadata
        fputcsv($out, ['ROOMIESYNC HOUSEHOLD REPORT']);
        fputcsv($out, ['Household:', $householdName]);
        fputcsv($out, ['Period:', $formattedFromDate . ' - ' . $formattedToDate]);
        fputcsv($out, ['Generated On:', date('F j, Y, g:i a')]);
        fputcsv($out, ['Generated By:', $displayName . ' (' . $role . ')']);
        fputcsv($out, ['Export Scope:', $scopeLabel]);
        fputcsv($out, []);

        if ($scope === 'all' || $scope === 'overview') {
            $writeSectionHeader('EXECUTIVE SUMMARY');
            fputcsv($out, ['Metric', 'Value', 'Details']);
            fputcsv($out, ['Total Expenses', 'PHP ' . number_format($totalExpensesAmount, 2), $totalExpensesCount . ' expenses recorded']);
            fputcsv($out, ['Total Bills', 'PHP ' . number_format($totalBillsAmount, 2), $totalBillsCount . ' bills (' . $paidBillsCount . ' paid, ' . $unpaidBillsCount . ' unpaid, ' . $overdueBillsCount . ' overdue)']);
            fputcsv($out, ['Pending Chores', $pendingSummaryCardCount, $completedChoresCount . ' chores completed in period']);
            fputcsv($out, ['Maintenance Issues', $openMaintenanceCount . ' Open', $resolvedMaintenanceCount . ' resolved, ' . $totalMaintenanceCount . ' total reported']);
            fputcsv($out, ['Active Household Members', $activityMembersCount]);
        }

        if ($scope === 'all' || $scope === 'expenses') {
            $writeSectionHeader('EXPENSE RECORDS');
            fputcsv($out, ['Date', 'Title', 'Category', 'Amount (PHP)', 'Paid By', 'Description']);
            if (empty($expenseRecords)) {
                fputcsv($out, ['No expense records found for this date range.']);
            } else {
                foreach ($expenseRecords as $exp) {
                    fputcsv($out, [
                        date('Y-m-d', strtotime((string) $exp['expense_date'])),
                        (string) $exp['title'],
                        (string) ($exp['category_name'] ?: 'Uncategorized'),
                        number_format((float) $exp['amount'], 2, '.', ''),
                        (string) $exp['paid_by_name'],
                        (string) ($exp['description'] ?? '')
                    ]);
                }
            }

            if (!empty($expenseCategories)) {
                fputcsv($out, []);
                fputcsv($out, ['Category Breakdown:']);
                fputcsv($out, ['Category', 'Total Amount (PHP)', 'Transaction Count']);
                foreach ($expenseCategories as $cat) {
                    fputcsv($out, [
                        (string) $cat['category_name'],
                        number_format((float) $cat['cat_total'], 2, '.', ''),
                        (int) $cat['cat_count']
                    ]);
                }
            }
        }

        if ($scope === 'all' || $scope === 'bills') {
            $writeSectionHeader('BILL RECORDS');
            fputcsv($out, ['Bill Name', 'Category', 'Amount (PHP)', 'Due Date', 'Status']);
            if (empty($billRecords)) {
                fputcsv($out, ['No bills found with due dates in this date range.']);
            } else {
                foreach ($billRecords as $bill) {
                    $bStatus = strtolower((string) $bill['status']);
                    $dueDateObj = strtotime((string) $bill['due_date']);
                    $statusLabel = ucfirst($bStatus);
                    if ($bStatus !== 'paid' && $dueDateObj < strtotime('today')) {
                        $statusLabel = 'Overdue';
                    }
                    fputcsv($out, [
                        (string) $bill['bill_name'],
                        (string) ($bill['category_name'] ?: 'Utilities'),
                        number_format((float) $bill['amount'], 2, '.', ''),
                        date('Y-m-d', $dueDateObj),
                        $statusLabel
                    ]);
                }
            }
        }

        if ($scope === 'all' || $scope === 'chores') {
            $writeSectionHeader('CHORE ASSIGNMENTS');
            fputcsv($out, ['Chore Name', 'Category', 'Frequency', 'Assigned To', 'Due Date', 'Status']);
            if (empty($choreRecords)) {
                fputcsv($out, ['No chore assignments found due within this period.']);
            } else {
                foreach ($choreRecords as $chore) {
                    $cStatus = strtolower((string) $chore['status']);
                    $cDueDateObj = strtotime((string) $chore['due_date']);
                    $statusLabel = ucfirst($cStatus);
                    if ($cStatus !== 'completed' && $cDueDateObj < strtotime('today')) {
                        $statusLabel = 'Overdue';
                    }
                    fputcsv($out, [
                        (string) $chore['chore_name'],
                        (string) ($chore['category_name'] ?? 'Household'),
                        (string) ($chore['frequency'] ?: 'Routine'),
                        (string) ($chore['assigned_to_name'] ?: 'Unassigned'),
                        date('Y-m-d', $cDueDateObj),
                        $statusLabel
                    ]);
                }
            }
        }

        if ($scope === 'all' || $scope === 'maintenance') {
            $writeSectionHeader('MAINTENANCE ISSUES');
            fputcsv($out, ['Problem Type', 'Description', 'Reported By', 'Date Reported', 'Status']);
            if (empty($maintenanceRecords)) {
                fputcsv($out, ['No maintenance issues reported for this period.']);
            } else {
                foreach ($maintenanceRecords as $maint) {
                    fputcsv($out, [
                        (string) $maint['problem_type'],
                        (string) ($maint['problem_desc'] ?? ''),
                        (string) ($maint['reported_by_name'] ?: 'Anonymous'),
                        date('Y-m-d H:i', strtotime((string) $maint['reported_at'])),
                        ucfirst(strtolower((string) $maint['status']))
                    ]);
                }
            }
        }

        if ($scope === 'all' && !empty($memberSummaryList)) {
            $writeSectionHeader('MEMBER PARTICIPATION SUMMARY');
            fputcsv($out, ['Member Name', 'Role', 'Expenses Paid (PHP)', 'Chores Completed', 'Bill Shares (PHP)', 'Maintenance Reported']);
            foreach ($memberSummaryList as $mem) {
                fputcsv($out, [
                    (string) $mem['member_name'],
                    (string) $mem['role'],
                    number_format((float) $mem['expenses_paid'], 2, '.', ''),
                    (int) $mem['chores_completed'],
                    number_format((float) $mem['bill_shares'], 2, '.', ''),
                    (int) $mem['maintenance_reports']
                ]);
            }
        }

        fclose($out);
    }
    exit;
}

// Calculate Pagination for Report Tables
$expReportPagination = get_pagination_params(count($expenseRecords), 10, [5, 10, 20, 50], 'exp_page', 'exp_per_page');
$pagedExpenseRecords = array_slice($expenseRecords, $expReportPagination['offset'], $expReportPagination['per_page']);

$billReportPagination = get_pagination_params(count($billRecords), 10, [5, 10, 20, 50], 'bill_page', 'bill_per_page');
$pagedBillRecords = array_slice($billRecords, $billReportPagination['offset'], $billReportPagination['per_page']);

$choreReportPagination = get_pagination_params(count($choreRecords), 10, [5, 10, 20, 50], 'chore_page', 'chore_per_page');
$pagedChoreRecords = array_slice($choreRecords, $choreReportPagination['offset'], $choreReportPagination['per_page']);

$maintReportPagination = get_pagination_params(count($maintenanceRecords), 10, [5, 10, 20, 50], 'maint_page', 'maint_per_page');
$pagedMaintenanceRecords = array_slice($maintenanceRecords, $maintReportPagination['offset'], $maintReportPagination['per_page']);

$memReportPagination = get_pagination_params(count($memberSummaryList), 10, [5, 10, 20, 50], 'mem_page', 'mem_per_page');
$pagedMemberSummaryList = array_slice($memberSummaryList, $memReportPagination['offset'], $memReportPagination['per_page']);

$viewTitles = [
    'overview' => 'Reports',
    'expenses' => 'Expenses Report',
    'bills' => 'Bills & Utilities Report',
    'chores' => 'Chores & Tasks Report',
    'maintenance' => 'Maintenance & Repairs Report',
    'members' => 'Member Participation Matrix',
    'activity' => 'Household Activity Summary',
];
$currentReportTitle = $viewTitles[$view] ?? 'Reports';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($currentReportTitle) ?> | RoomieSync</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="assets/css/reports.css">
</head>
<body class="d-flex flex-column min-vh-100">

  <!-- Printable Header (Visible Only on Paper / Print View) -->
  <div class="report-print-header">
    <div class="d-flex justify-content-between align-items-center">
      <div>
        <h1 class="h3 fw-bold mb-1">RoomieSync &ndash; <?= h($currentReportTitle) ?></h1>
        <p class="print-meta mb-0">
          <strong>Household:</strong> <?= h($householdName) ?> &bull; 
          <strong>Date Range:</strong> <?= h($formattedFromDate) ?> &ndash; <?= h($formattedToDate) ?>
          <?php if ($view !== 'overview'): ?> &bull; <strong>Scope:</strong> <?= h(ucfirst($view)) ?><?php endif; ?>
        </p>
      </div>
      <div class="text-end print-meta">
        <div><strong>Generated on:</strong> <?= date('F j, Y, g:i a') ?></div>
        <div><strong>Generated by:</strong> <?= h($displayName) ?> (<?= h($role) ?>)</div>
      </div>
    </div>
  </div>

  <!-- Standard RoomieSync Navbar -->
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
        <li><a href="household.php" class="rs-nav-link"><i class="bi bi-house"></i><span>Household</span></a></li>
        <li><a href="reports.php" class="rs-nav-link active"><i class="bi bi-bar-chart"></i><span>Reports</span></a></li>
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

    <!-- Report Header -->
    <header class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
      <div>
        <span class="rs-eyebrow d-block mb-1">
          <?php if ($view === 'overview'): ?>
            Household Overview &amp; Records
          <?php else: ?>
            Household Report &bull; <?= h(ucfirst($view)) ?>
          <?php endif; ?>
        </span>
        <h1 class="h2 fw-bold text-main mb-1"><?= h($currentReportTitle) ?></h1>
        <p class="text-secondary-custom mb-0">
          <?php if ($view === 'overview'): ?>
            Select any squared report card below to view detailed records, breakdown summaries, and export data.
          <?php elseif ($view === 'expenses'): ?>
            Individual household expenses, category breakdowns, and payer contribution totals.
          <?php elseif ($view === 'bills'): ?>
            Household recurring utilities, due dates, paid vs unpaid amounts, and settlement status.
          <?php elseif ($view === 'chores'): ?>
            Roommate assignment schedule, completion history, and task accountability.
          <?php elseif ($view === 'maintenance'): ?>
            Household maintenance tickets, repair requests, and current resolution statuses.
          <?php elseif ($view === 'members'): ?>
            Matrix of member contributions across expenses, chores completed, bill shares, and tickets.
          <?php elseif ($view === 'activity'): ?>
            Aggregate operational event totals across all household modules.
          <?php endif; ?>
        </p>
      </div>
      <div class="d-flex align-items-center gap-2">
        <div class="btn-group">
          <?php
            $exportScope = ($view !== 'overview') ? $view : 'all';
            $exportBtnLabel = ($view !== 'overview') ? 'Export ' . ucfirst($view) . ' (.xls)' : 'Export to Excel';
          ?>
          <a href="reports.php?export=excel&scope=<?= urlencode($exportScope) ?>&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>" class="btn btn-rs-primary" id="btnExportExcel" title="Export to MS Excel (.xls)">
            <i class="bi bi-file-earmark-excel me-1"></i> <?= h($exportBtnLabel) ?>
          </a>
          <button type="button" class="btn btn-rs-primary dropdown-toggle dropdown-toggle-split px-2" data-bs-toggle="dropdown" aria-expanded="false" title="Export options">
            <span class="visually-hidden">Toggle Dropdown</span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end rs-dropdown shadow-sm">
            <li><h6 class="dropdown-header">MS Excel Workbooks (.xls)</h6></li>
            <li>
              <a class="dropdown-item rs-dropdown-item fw-semibold" href="reports.php?export=excel&scope=all&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>">
                <i class="bi bi-file-earmark-spreadsheet me-2 text-dark-accent"></i> All Sections Workbook (.xls)
              </a>
            </li>
            <li><hr class="dropdown-divider rs-dropdown-divider"></li>
            <li>
              <a class="dropdown-item rs-dropdown-item <?= $view === 'expenses' ? 'active' : '' ?>" href="reports.php?export=excel&scope=expenses&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>">
                <i class="bi bi-wallet2 me-2" style="color: #10B981;"></i> Expenses Sheet (.xls)
              </a>
            </li>
            <li>
              <a class="dropdown-item rs-dropdown-item <?= $view === 'bills' ? 'active' : '' ?>" href="reports.php?export=excel&scope=bills&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>">
                <i class="bi bi-receipt me-2" style="color: #10B981;"></i> Bills Sheet (.xls)
              </a>
            </li>
            <li>
              <a class="dropdown-item rs-dropdown-item <?= $view === 'chores' ? 'active' : '' ?>" href="reports.php?export=excel&scope=chores&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>">
                <i class="bi bi-check2-square me-2" style="color: #3B82F6;"></i> Chores Sheet (.xls)
              </a>
            </li>
            <li>
              <a class="dropdown-item rs-dropdown-item <?= $view === 'maintenance' ? 'active' : '' ?>" href="reports.php?export=excel&scope=maintenance&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>">
                <i class="bi bi-tools me-2" style="color: #EF4444;"></i> Maintenance Sheet (.xls)
              </a>
            </li>
            <li><hr class="dropdown-divider rs-dropdown-divider"></li>
            <li><h6 class="dropdown-header">Other Formats</h6></li>
            <li>
              <a class="dropdown-item rs-dropdown-item text-secondary-custom" href="reports.php?export=csv&scope=<?= urlencode($exportScope) ?>&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>">
                <i class="bi bi-filetype-csv me-2"></i> Export as Plain CSV (.csv)
              </a>
            </li>
          </ul>
        </div>
      </div>
    </header>

    <?php if ($view !== 'overview'): ?>
      <!-- Section Back Navigation & Quick Switcher -->
      <div class="rs-view-toolbar">
        <a href="reports.php?from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>" class="rs-btn-back">
          <i class="bi bi-arrow-left"></i> Back to Reports Hub
        </a>
        <div class="rs-view-pills">
          <a href="reports.php?view=expenses&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>" class="rs-view-pill-btn <?= $view === 'expenses' ? 'active' : '' ?>">
            <i class="bi bi-wallet2"></i> Expenses
          </a>
          <a href="reports.php?view=bills&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>" class="rs-view-pill-btn <?= $view === 'bills' ? 'active' : '' ?>">
            <i class="bi bi-receipt"></i> Bills
          </a>
          <a href="reports.php?view=chores&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>" class="rs-view-pill-btn <?= $view === 'chores' ? 'active' : '' ?>">
            <i class="bi bi-check2-square"></i> Chores
          </a>
          <a href="reports.php?view=maintenance&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>" class="rs-view-pill-btn <?= $view === 'maintenance' ? 'active' : '' ?>">
            <i class="bi bi-tools"></i> Maintenance
          </a>
          <a href="reports.php?view=members&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>" class="rs-view-pill-btn <?= $view === 'members' ? 'active' : '' ?>">
            <i class="bi bi-people-fill"></i> Members
          </a>
          <a href="reports.php?view=activity&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>" class="rs-view-pill-btn <?= $view === 'activity' ? 'active' : '' ?>">
            <i class="bi bi-activity"></i> Activity
          </a>
        </div>
      </div>
    <?php endif; ?>

    <!-- Date Filter Card -->
    <section class="report-filter-card">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-calendar-range text-dark-accent fs-5"></i>
          <span class="fw-bold text-main">Filter Report Period</span>
          <span class="badge bg-warm-secondary text-dark-accent border ms-2 py-1 px-2">
            <?= h($formattedFromDate) ?> &ndash; <?= h($formattedToDate) ?>
          </span>
        </div>
        <!-- Quick Presets -->
        <div class="d-flex flex-wrap align-items-center gap-1">
          <span class="small text-secondary-custom me-1 d-none d-sm-inline">Quick:</span>
          <button type="button" class="report-preset-btn" data-preset="this-month">This Month</button>
          <button type="button" class="report-preset-btn" data-preset="last-month">Last Month</button>
          <button type="button" class="report-preset-btn" data-preset="last-30">Last 30 Days</button>
          <button type="button" class="report-preset-btn" data-preset="this-year">This Year</button>
        </div>
      </div>

      <form method="GET" action="reports.php" id="reportFilterForm" class="row g-3 align-items-end">
        <?php if ($view !== 'overview'): ?>
          <input type="hidden" name="view" value="<?= h($view) ?>">
        <?php endif; ?>
        <div class="col-12 col-sm-6 col-md-4 col-lg-3">
          <label for="from_date" class="report-filter-label">From Date</label>
          <input type="date" name="from_date" id="from_date" class="form-control report-date-input" value="<?= h($fromDate) ?>" required>
        </div>
        <div class="col-12 col-sm-6 col-md-4 col-lg-3">
          <label for="to_date" class="report-filter-label">To Date</label>
          <input type="date" name="to_date" id="to_date" class="form-control report-date-input" value="<?= h($toDate) ?>" required>
        </div>
        <div class="col-12 col-md-4 col-lg-6 d-flex gap-2">
          <button type="submit" class="btn btn-rs-primary px-3">
            <i class="bi bi-filter me-1"></i> Apply Filter
          </button>
          <a href="reports.php<?= $view !== 'overview' ? '?view=' . urlencode($view) : '' ?>" class="btn btn-rs-outline px-3" title="Reset date filter to current month">
            <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
          </a>
        </div>
      </form>
    </section>

    <!-- ==================================================
         VIEW 1: OVERVIEW HUB (SQUARED OPENABLE REPORT CARDS)
         ================================================== -->
    <?php if ($view === 'overview'): ?>
      <div class="rs-hub-grid">
        <!-- 1. Expenses Report Card -->
        <a href="reports.php?view=expenses&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>" class="rs-hub-card card-green">
          <div>
            <div class="rs-hub-top">
              <div class="rs-hub-icon green">
                <i class="bi bi-wallet2"></i>
              </div>
              <span class="rs-hub-pill"><?= $totalExpensesCount ?> Expense<?= $totalExpensesCount === 1 ? '' : 's' ?></span>
            </div>
            <div class="rs-hub-main">
              <h2 class="rs-hub-title">Expenses Report</h2>
              <div class="rs-hub-metric" style="color: #047857 !important;">₱<?= number_format($totalExpensesAmount, 2) ?></div>
              <p class="rs-hub-desc">Track household purchases, spending breakdown by category, and individual member contribution logs.</p>
            </div>
          </div>
          <div class="rs-hub-footer">
            <span>Open Expenses Report</span>
            <i class="bi bi-arrow-right"></i>
          </div>
        </a>

        <!-- 2. Bills & Utilities Card -->
        <a href="reports.php?view=bills&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>" class="rs-hub-card card-amber">
          <div>
            <div class="rs-hub-top">
              <div class="rs-hub-icon amber">
                <i class="bi bi-receipt"></i>
              </div>
              <span class="rs-hub-pill"><?= $totalBillsCount ?> Bill<?= $totalBillsCount === 1 ? '' : 's' ?> Due</span>
            </div>
            <div class="rs-hub-main">
              <h2 class="rs-hub-title">Bills &amp; Utilities</h2>
              <div class="rs-hub-metric" style="color: #B45309 !important;">₱<?= number_format($totalBillsAmount, 2) ?></div>
              <p class="rs-hub-desc">Review recurring household utility charges, payment deadlines, settlement statuses, and overdue accounts.</p>
            </div>
          </div>
          <div class="rs-hub-footer">
            <span>Open Bills Report</span>
            <i class="bi bi-arrow-right"></i>
          </div>
        </a>

        <!-- 3. Chores & Tasks Card -->
        <a href="reports.php?view=chores&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>" class="rs-hub-card card-blue">
          <div>
            <div class="rs-hub-top">
              <div class="rs-hub-icon blue">
                <i class="bi bi-check2-square"></i>
              </div>
              <span class="rs-hub-pill"><?= $totalChoreAssignments ?> Scheduled</span>
            </div>
            <div class="rs-hub-main">
              <h2 class="rs-hub-title">Chores &amp; Tasks</h2>
              <div class="rs-hub-metric text-primary"><?= $completedChoresCount ?> Done &bull; <?= $pendingSummaryCardCount ?> Pending</div>
              <p class="rs-hub-desc">Monitor roommate task assignments, on-time completion rates, pending duties, and overdue chores.</p>
            </div>
          </div>
          <div class="rs-hub-footer">
            <span>Open Chores Report</span>
            <i class="bi bi-arrow-right"></i>
          </div>
        </a>

        <!-- 4. Maintenance & Repairs Card -->
        <a href="reports.php?view=maintenance&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>" class="rs-hub-card card-rose">
          <div>
            <div class="rs-hub-top">
              <div class="rs-hub-icon rose">
                <i class="bi bi-tools"></i>
              </div>
              <span class="rs-hub-pill"><?= $totalMaintenanceCount ?> Total Ticket<?= $totalMaintenanceCount === 1 ? '' : 's' ?></span>
            </div>
            <div class="rs-hub-main">
              <h2 class="rs-hub-title">Maintenance &amp; Repairs</h2>
              <div class="rs-hub-metric" style="color: #BE123C !important;"><?= $openMaintenanceCount ?> Open &bull; <?= $resolvedMaintenanceCount ?> Resolved</div>
              <p class="rs-hub-desc">Inspect reported damages, repairs, plumber/electrician requests, and live resolution progress.</p>
            </div>
          </div>
          <div class="rs-hub-footer">
            <span>Open Maintenance Report</span>
            <i class="bi bi-arrow-right"></i>
          </div>
        </a>

        <!-- 5. Member Participation Matrix Card -->
        <a href="reports.php?view=members&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>" class="rs-hub-card card-purple">
          <div>
            <div class="rs-hub-top">
              <div class="rs-hub-icon purple">
                <i class="bi bi-person-badge"></i>
              </div>
              <span class="rs-hub-pill"><?= count($memberSummaryList) ?> Members</span>
            </div>
            <div class="rs-hub-main">
              <h2 class="rs-hub-title">Member Participation</h2>
              <div class="rs-hub-metric" style="color: #6D28D9 !important;"><?= count($memberSummaryList) ?> Active Roommates</div>
              <p class="rs-hub-desc">Comprehensive matrix showing each member's paid expenses, completed chores, bill shares, and tickets.</p>
            </div>
          </div>
          <div class="rs-hub-footer">
            <span>Open Member Matrix</span>
            <i class="bi bi-arrow-right"></i>
          </div>
        </a>

        <!-- 6. Household Activity Summary Card -->
        <a href="reports.php?view=activity&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>" class="rs-hub-card card-green">
          <div>
            <div class="rs-hub-top">
              <div class="rs-hub-icon green">
                <i class="bi bi-activity"></i>
              </div>
              <span class="rs-hub-pill"><?= $activityMembersCount ?> In Household</span>
            </div>
            <div class="rs-hub-main">
              <h2 class="rs-hub-title">Household Activity</h2>
              <div class="rs-hub-metric" style="color: #047857 !important;"><?= $activityAnnounceCount + $actExpensesCount + $actBillsCount + $actChoresCreatedCount + $actMaintenanceCount ?> Total Events</div>
              <p class="rs-hub-desc">Overall operational activity metrics across announcements, chore definitions, bills, and tickets.</p>
            </div>
          </div>
          <div class="rs-hub-footer">
            <span>Open Activity Summary</span>
            <i class="bi bi-arrow-right"></i>
          </div>
        </a>
      </div>

      <!-- Quick Executive Summary Banner (clean, uncluttered high-level snapshot) -->
      <section class="p-4 rounded-4 bg-white border shadow-sm">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3 pb-3 border-bottom">
          <div>
            <h3 class="h6 fw-bold text-main mb-1"><i class="bi bi-lightning-charge-fill text-warning me-2"></i>Household Health at a Glance</h3>
            <p class="small text-secondary-custom mb-0">Summary performance for <?= h($householdName) ?> from <?= h($formattedFromDate) ?> to <?= h($formattedToDate) ?>.</p>
          </div>
          <div class="d-flex gap-2">
            <a href="reports.php?export=excel&scope=all&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>" class="btn btn-rs-outline btn-sm">
              <i class="bi bi-download me-1"></i> Full Workbook (.xls)
            </a>
          </div>
        </div>
        <div class="row g-3 text-center">
          <div class="col-6 col-md-3">
            <div class="p-2 rounded bg-warm-secondary">
              <small class="text-secondary-custom text-uppercase fw-bold" style="font-size: 0.7rem;">Settled Expenses</small>
              <div class="fw-bold text-main fs-6 mt-1">₱<?= number_format($totalExpensesAmount, 2) ?></div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="p-2 rounded bg-warm-secondary">
              <small class="text-secondary-custom text-uppercase fw-bold" style="font-size: 0.7rem;">Paid Bills</small>
              <div class="fw-bold text-main fs-6 mt-1"><?= $paidBillsCount ?> / <?= $totalBillsCount ?> Paid</div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="p-2 rounded bg-warm-secondary">
              <small class="text-secondary-custom text-uppercase fw-bold" style="font-size: 0.7rem;">Chore Completion</small>
              <div class="fw-bold text-main fs-6 mt-1">
                <?= $totalChoreAssignments > 0 ? round(($completedChoresCount / $totalChoreAssignments) * 100) : 100 ?>%
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="p-2 rounded bg-warm-secondary">
              <small class="text-secondary-custom text-uppercase fw-bold" style="font-size: 0.7rem;">Open Tickets</small>
              <div class="fw-bold text-main fs-6 mt-1"><?= $openMaintenanceCount ?> Open</div>
            </div>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <!-- ==================================================
         VIEW 2: EXPENSES REPORT
         ================================================== -->
    <?php if ($view === 'expenses'): ?>
      <section class="report-section-card card-money">
        <div class="report-section-header">
          <div class="report-section-title-wrap">
            <div class="report-section-icon expenses"><i class="bi bi-wallet2"></i></div>
            <div>
              <h2 class="h5 fw-bold text-main mb-0">Expense Summary</h2>
              <small class="text-secondary-custom">Individual household expenses and member contributions</small>
            </div>
          </div>
          <div class="d-flex align-items-center gap-2">
            <span class="report-tag">
              <i class="bi bi-hash"></i> Total: <strong><?= $totalExpensesCount ?></strong>
            </span>
            <span class="report-tag">
              <i class="bi bi-cash-stack"></i> Amount: <strong>₱<?= number_format($totalExpensesAmount, 2) ?></strong>
            </span>
          </div>
        </div>

        <!-- Expense Categories & Payers Breakdown -->
        <?php if (!empty($expenseCategories) || !empty($expensePayers)): ?>
          <div class="row g-3 mb-4">
            <?php if (!empty($expenseCategories)): ?>
              <div class="col-12 col-md-6">
                <div class="p-3 rounded bg-warm-secondary border">
                  <span class="report-filter-label mb-2"><i class="bi bi-tag me-1"></i> Expense Categories</span>
                  <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($expenseCategories as $cat): ?>
                      <span class="report-tag bg-white">
                        <span><?= h((string) $cat['category_name']) ?>:</span>
                        <strong>₱<?= number_format((float) $cat['cat_total'], 2) ?></strong>
                        <small class="text-secondary-custom">(<?= (int) $cat['cat_count'] ?>)</small>
                      </span>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            <?php endif; ?>

            <?php if (!empty($expensePayers)): ?>
              <div class="col-12 col-md-6">
                <div class="p-3 rounded bg-warm-secondary border">
                  <span class="report-filter-label mb-2"><i class="bi bi-people me-1"></i> Paid by Members</span>
                  <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($expensePayers as $payer): ?>
                      <span class="report-tag bg-white">
                        <span><?= h((string) $payer['payer_name']) ?>:</span>
                        <strong>₱<?= number_format((float) $payer['total_paid'], 2) ?></strong>
                      </span>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <!-- Individual Expense Records Table: Date | Expense | Category | Amount | Paid By -->
        <div class="report-table-wrapper">
          <table class="table report-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Expense</th>
                <th>Category</th>
                <th class="text-end">Amount</th>
                <th>Paid By</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($expenseRecords)): ?>
                <tr>
                  <td colspan="5" class="report-empty-state">
                    <i class="bi bi-inbox"></i>
                    <p class="mb-0">No expense records found for this date range.</p>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($pagedExpenseRecords as $exp): ?>
                  <tr>
                    <td class="text-nowrap"><?= h(date('M j, Y', strtotime((string) $exp['expense_date']))) ?></td>
                    <td>
                      <div class="fw-bold text-main"><?= h((string) $exp['title']) ?></div>
                      <?php if (!empty($exp['description'])): ?>
                        <small class="text-secondary-custom"><?= h((string) $exp['description']) ?></small>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="report-tag"><?= h((string) $exp['category_name']) ?></span>
                    </td>
                    <td class="text-end fw-bold text-main">
                      ₱<?= number_format((float) $exp['amount'], 2) ?>
                    </td>
                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <div class="member-avatar-pill"><?= h(reports_initials((string) $exp['paid_by_name'])) ?></div>
                        <span class="fw-semibold"><?= h((string) $exp['paid_by_name']) ?></span>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?= $expReportPagination ? render_pagination($expReportPagination, ['view' => 'expenses', 'from_date' => $fromDate, 'to_date' => $toDate]) : '' ?>
      </section>
    <?php endif; ?>

    <!-- ==================================================
         VIEW 3: BILLS & UTILITIES REPORT
         ================================================== -->
    <?php if ($view === 'bills'): ?>
      <section class="report-section-card card-money">
        <div class="report-section-header">
          <div class="report-section-title-wrap">
            <div class="report-section-icon bills"><i class="bi bi-receipt"></i></div>
            <div>
              <h2 class="h5 fw-bold text-main mb-0">Bill Summary</h2>
              <small class="text-secondary-custom">Household recurring utilities, due dates, and settlement status</small>
            </div>
          </div>
          <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="report-tag"><i class="bi bi-collection"></i> Total Bills: <strong><?= $totalBillsCount ?></strong></span>
            <span class="report-tag"><i class="bi bi-cash"></i> Amount: <strong>₱<?= number_format($totalBillsAmount, 2) ?></strong></span>
            <span class="rs-badge rs-badge-paid"><span class="badge-dot"></span> Paid: <?= $paidBillsCount ?></span>
            <span class="rs-badge rs-badge-unpaid"><span class="badge-dot"></span> Unpaid: <?= $unpaidBillsCount ?></span>
            <?php if ($overdueBillsCount > 0): ?>
              <span class="rs-badge rs-badge-overdue"><span class="badge-dot"></span> Overdue: <?= $overdueBillsCount ?></span>
            <?php endif; ?>
          </div>
        </div>

        <!-- Bill Records Table: Bill | Category | Amount | Due Date | Status -->
        <div class="report-table-wrapper">
          <table class="table report-table">
            <thead>
              <tr>
                <th>Bill</th>
                <th>Category</th>
                <th class="text-end">Amount</th>
                <th>Due Date</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($billRecords)): ?>
                <tr>
                  <td colspan="5" class="report-empty-state">
                    <i class="bi bi-inbox"></i>
                    <p class="mb-0">No bills found with due dates in this date range.</p>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($pagedBillRecords as $bill): ?>
                  <?php
                    $billStatus = strtolower((string) $bill['status']);
                    $dueDateObj = strtotime((string) $bill['due_date']);
                    $isOverdue = ($billStatus !== 'paid' && $dueDateObj < strtotime('today'));
                    
                    $badgeClass = 'rs-badge-unpaid';
                    $statusLabel = ucfirst($billStatus);
                    if ($billStatus === 'paid') {
                        $badgeClass = 'rs-badge-paid';
                        $statusLabel = 'Paid';
                    } elseif ($isOverdue) {
                        $badgeClass = 'rs-badge-overdue';
                        $statusLabel = 'Overdue';
                    } elseif ($billStatus === 'unpaid') {
                        $badgeClass = 'rs-badge-unpaid';
                        $statusLabel = 'Unpaid';
                    }
                  ?>
                  <tr>
                    <td>
                      <div class="fw-bold text-main"><?= h((string) $bill['bill_name']) ?></div>
                    </td>
                    <td>
                      <span class="report-tag"><?= h((string) $bill['category_name']) ?></span>
                    </td>
                    <td class="text-end fw-bold text-main">
                      ₱<?= number_format((float) $bill['amount'], 2) ?>
                    </td>
                    <td class="text-nowrap">
                      <?= h(date('M j, Y', $dueDateObj)) ?>
                    </td>
                    <td>
                      <span class="rs-badge <?= $badgeClass ?>">
                        <span class="badge-dot"></span> <?= h($statusLabel) ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?= $billReportPagination ? render_pagination($billReportPagination, ['view' => 'bills', 'from_date' => $fromDate, 'to_date' => $toDate]) : '' ?>
      </section>
    <?php endif; ?>

    <!-- ==================================================
         VIEW 4: CHORES & TASKS REPORT
         ================================================== -->
    <?php if ($view === 'chores'): ?>
      <section class="report-section-card card-chores">
        <div class="report-section-header">
          <div class="report-section-title-wrap">
            <div class="report-section-icon chores"><i class="bi bi-check2-square"></i></div>
            <div>
              <h2 class="h5 fw-bold text-main mb-0">Chore Summary</h2>
              <small class="text-secondary-custom">Assignments schedule and completion history</small>
            </div>
          </div>
          <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="report-tag"><i class="bi bi-list-check"></i> Household Chores: <strong><?= $totalHouseholdChoresDefined ?></strong></span>
            <span class="report-tag"><i class="bi bi-calendar3"></i> Scheduled: <strong><?= $totalChoreAssignments ?></strong></span>
            <span class="rs-badge rs-badge-completed"><span class="badge-dot"></span> Completed: <?= $completedChoresCount ?></span>
            <span class="rs-badge rs-badge-pending"><span class="badge-dot"></span> Pending: <?= $pendingChoresCount ?></span>
            <?php if ($overdueChoresCount > 0): ?>
              <span class="rs-badge rs-badge-overdue"><span class="badge-dot"></span> Overdue: <?= $overdueChoresCount ?></span>
            <?php endif; ?>
          </div>
        </div>

        <!-- Chore Records Table: Chore | Assigned To | Due Date | Status -->
        <div class="report-table-wrapper">
          <table class="table report-table">
            <thead>
              <tr>
                <th>Chore</th>
                <th>Assigned To</th>
                <th>Due Date</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($choreRecords)): ?>
                <tr>
                  <td colspan="4" class="report-empty-state">
                    <i class="bi bi-inbox"></i>
                    <p class="mb-0">No chore assignments found due within this period.</p>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($pagedChoreRecords as $chore): ?>
                  <?php
                    $cStatus = strtolower((string) $chore['status']);
                    $cDueDateObj = strtotime((string) $chore['due_date']);
                    $isChoreOverdue = ($cStatus !== 'completed' && $cDueDateObj < strtotime('today'));

                    $badgeClass = 'rs-badge-pending';
                    $choreStatusLabel = ucfirst($cStatus);
                    if ($cStatus === 'completed') {
                        $badgeClass = 'rs-badge-completed';
                        $choreStatusLabel = 'Completed';
                    } elseif ($isChoreOverdue) {
                        $badgeClass = 'rs-badge-overdue';
                        $choreStatusLabel = 'Overdue';
                    } else {
                        $badgeClass = 'rs-badge-pending';
                        $choreStatusLabel = 'Pending';
                    }
                  ?>
                  <tr>
                    <td>
                      <div class="fw-bold text-main"><?= h((string) $chore['chore_name']) ?></div>
                      <?php if (!empty($chore['frequency'])): ?>
                        <small class="text-secondary-custom"><?= h((string) $chore['frequency']) ?></small>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <div class="member-avatar-pill"><?= h(reports_initials((string) $chore['assigned_to_name'])) ?></div>
                        <span class="fw-semibold"><?= h((string) $chore['assigned_to_name']) ?></span>
                      </div>
                    </td>
                    <td class="text-nowrap">
                      <?= h(date('M j, Y', $cDueDateObj)) ?>
                    </td>
                    <td>
                      <span class="rs-badge <?= $badgeClass ?>">
                        <span class="badge-dot"></span> <?= h($choreStatusLabel) ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?= $choreReportPagination ? render_pagination($choreReportPagination, ['view' => 'chores', 'from_date' => $fromDate, 'to_date' => $toDate]) : '' ?>
      </section>
    <?php endif; ?>

    <!-- ==================================================
         VIEW 5: MAINTENANCE & REPAIRS REPORT
         ================================================== -->
    <?php if ($view === 'maintenance'): ?>
      <section class="report-section-card card-maintenance">
        <div class="report-section-header">
          <div class="report-section-title-wrap">
            <div class="report-section-icon maintenance"><i class="bi bi-tools"></i></div>
            <div>
              <h2 class="h5 fw-bold text-main mb-0">Maintenance Summary</h2>
              <small class="text-secondary-custom">Track reported household issues, repairs, and current resolution status</small>
            </div>
          </div>
          <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="report-tag"><i class="bi bi-exclamation-diamond"></i> Total Reported: <strong><?= $totalMaintenanceCount ?></strong></span>
            <span class="rs-badge rs-badge-open"><span class="badge-dot"></span> Open: <?= $openMaintenanceCount ?></span>
            <span class="rs-badge rs-badge-resolved"><span class="badge-dot"></span> Resolved: <?= $resolvedMaintenanceCount ?></span>
          </div>
        </div>

        <!-- Maintenance Records Table: Problem | Type | Reported By | Date | Status -->
        <div class="report-table-wrapper">
          <table class="table report-table">
            <thead>
              <tr>
                <th>Problem</th>
                <th>Type</th>
                <th>Reported By</th>
                <th>Date</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($maintenanceRecords)): ?>
                <tr>
                  <td colspan="5" class="report-empty-state">
                    <i class="bi bi-inbox"></i>
                    <p class="mb-0">No maintenance issues reported for this period.</p>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($pagedMaintenanceRecords as $maint): ?>
                  <?php
                    $mStatus = strtolower((string) $maint['status']);
                    $mBadgeClass = 'rs-badge-open';
                    $mLabel = 'Open';
                    if ($mStatus === 'resolved') {
                        $mBadgeClass = 'rs-badge-resolved';
                        $mLabel = 'Resolved';
                    } elseif ($mStatus === 'in progress' || $mStatus === 'in_progress') {
                        $mBadgeClass = 'rs-badge-in-progress';
                        $mLabel = 'In Progress';
                    } else {
                        $mBadgeClass = 'rs-badge-open';
                        $mLabel = ucfirst($mStatus);
                    }
                  ?>
                  <tr>
                    <td>
                      <div class="fw-bold text-main"><?= h((string) $maint['problem_type']) ?></div>
                      <?php if (!empty($maint['problem_desc'])): ?>
                        <small class="text-secondary-custom"><?= h((string) $maint['problem_desc']) ?></small>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="report-tag"><?= h((string) $maint['problem_type']) ?></span>
                    </td>
                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <div class="member-avatar-pill"><?= h(reports_initials((string) $maint['reported_by_name'])) ?></div>
                        <span class="fw-semibold"><?= h((string) $maint['reported_by_name']) ?></span>
                      </div>
                    </td>
                    <td class="text-nowrap">
                      <?= h(date('M j, Y', strtotime((string) $maint['reported_at']))) ?>
                    </td>
                    <td>
                      <span class="rs-badge <?= $mBadgeClass ?>">
                        <span class="badge-dot"></span> <?= h($mLabel) ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?= $maintReportPagination ? render_pagination($maintReportPagination, ['view' => 'maintenance', 'from_date' => $fromDate, 'to_date' => $toDate]) : '' ?>
      </section>
    <?php endif; ?>

    <!-- ==================================================
         VIEW 6: MEMBER PARTICIPATION MATRIX
         ================================================== -->
    <?php if ($view === 'members'): ?>
      <section class="report-section-card mb-5">
        <div class="report-section-header">
          <div class="report-section-title-wrap">
            <div class="report-section-icon members"><i class="bi bi-person-badge"></i></div>
            <div>
              <h2 class="h5 fw-bold text-main mb-0">Member Participation Matrix</h2>
              <small class="text-secondary-custom">Individual member contributions, chores completed, and share totals</small>
            </div>
          </div>
          <div>
            <span class="report-tag">
              <i class="bi bi-people"></i> <?= count($memberSummaryList) ?> Active Member<?= count($memberSummaryList) === 1 ? '' : 's' ?>
            </span>
          </div>
        </div>

        <!-- Member Summary Table: Member | Expenses Paid | Chores Completed | Bill Shares | Maintenance Reports -->
        <div class="report-table-wrapper">
          <table class="table report-table">
            <thead>
              <tr>
                <th>Member</th>
                <th class="text-end">Expenses Paid</th>
                <th class="text-center">Chores Completed</th>
                <th class="text-end">Bill Shares</th>
                <th class="text-center">Maintenance Reports</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($memberSummaryList)): ?>
                <tr>
                  <td colspan="5" class="report-empty-state">
                    <i class="bi bi-people"></i>
                    <p class="mb-0">No household members found.</p>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($pagedMemberSummaryList as $mem): ?>
                  <tr>
                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <div class="member-avatar-pill"><?= h(reports_initials((string) $mem['member_name'])) ?></div>
                        <div>
                          <div class="fw-bold text-main"><?= h((string) $mem['member_name']) ?></div>
                          <span class="role-badge roommate"><?= h((string) $mem['role']) ?></span>
                        </div>
                      </div>
                    </td>
                    <td class="text-end fw-bold text-main">
                      ₱<?= number_format((float) $mem['expenses_paid'], 2) ?>
                    </td>
                    <td class="text-center">
                      <span class="badge bg-warm-secondary text-dark-accent border px-2 py-1">
                        <?= (int) $mem['chores_completed'] ?>
                      </span>
                    </td>
                    <td class="text-end fw-bold text-main">
                      ₱<?= number_format((float) $mem['bill_shares'], 2) ?>
                    </td>
                    <td class="text-center">
                      <span class="badge bg-warm-secondary text-dark-accent border px-2 py-1">
                        <?= (int) $mem['maintenance_reports'] ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?= $memReportPagination ? render_pagination($memReportPagination, ['view' => 'members', 'from_date' => $fromDate, 'to_date' => $toDate]) : '' ?>
      </section>
    <?php endif; ?>

    <!-- ==================================================
         VIEW 7: HOUSEHOLD ACTIVITY SUMMARY
         ================================================== -->
    <?php if ($view === 'activity'): ?>
      <section class="report-section-card card-household mb-5">
        <div class="report-section-header">
          <div class="report-section-title-wrap">
            <div class="report-section-icon activity"><i class="bi bi-activity"></i></div>
            <div>
              <h2 class="h5 fw-bold text-main mb-0">Household Activity Summary</h2>
              <small class="text-secondary-custom">Aggregate activity counts across all household operational modules</small>
            </div>
          </div>
          <div>
            <span class="report-tag">
              <i class="bi bi-calendar-event"></i> <?= h($formattedFromDate) ?> &ndash; <?= h($formattedToDate) ?>
            </span>
          </div>
        </div>

        <div class="row g-3 mb-4">
          <div class="col-6 col-md-4 col-lg-2">
            <div class="activity-box">
              <div class="activity-box-icon"><i class="bi bi-people-fill"></i></div>
              <div>
                <div class="activity-box-val"><?= $activityMembersCount ?></div>
                <div class="activity-box-lbl">Members</div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-4 col-lg-2">
            <div class="activity-box">
              <div class="activity-box-icon"><i class="bi bi-megaphone-fill"></i></div>
              <div>
                <div class="activity-box-val"><?= $activityAnnounceCount ?></div>
                <div class="activity-box-lbl">Announcements</div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-4 col-lg-2">
            <div class="activity-box">
              <div class="activity-box-icon"><i class="bi bi-wallet-fill"></i></div>
              <div>
                <div class="activity-box-val"><?= $actExpensesCount ?></div>
                <div class="activity-box-lbl">Expenses</div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-4 col-lg-2">
            <div class="activity-box">
              <div class="activity-box-icon"><i class="bi bi-receipt-cutoff"></i></div>
              <div>
                <div class="activity-box-val"><?= $actBillsCount ?></div>
                <div class="activity-box-lbl">Bills</div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-4 col-lg-2">
            <div class="activity-box">
              <div class="activity-box-icon"><i class="bi bi-card-checklist"></i></div>
              <div>
                <div class="activity-box-val"><?= $actChoresCreatedCount ?></div>
                <div class="activity-box-lbl">Chores Created</div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-4 col-lg-2">
            <div class="activity-box">
              <div class="activity-box-icon"><i class="bi bi-wrench-adjustable"></i></div>
              <div>
                <div class="activity-box-val"><?= $actMaintenanceCount ?></div>
                <div class="activity-box-lbl">Maintenance</div>
              </div>
            </div>
          </div>
        </div>

        <div class="p-3 rounded bg-warm-secondary border">
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
              <span class="fw-bold text-main d-block">Quick Report Links</span>
              <small class="text-secondary-custom">Jump to dedicated records for any category above</small>
            </div>
            <div class="d-flex flex-wrap gap-2">
              <a href="reports.php?view=expenses&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>" class="btn btn-rs-outline btn-sm">
                <i class="bi bi-wallet2 me-1"></i> Expenses Report
              </a>
              <a href="reports.php?view=bills&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>" class="btn btn-rs-outline btn-sm">
                <i class="bi bi-receipt me-1"></i> Bills Report
              </a>
              <a href="reports.php?view=chores&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>" class="btn btn-rs-outline btn-sm">
                <i class="bi bi-check2-square me-1"></i> Chores Report
              </a>
              <a href="reports.php?view=maintenance&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>" class="btn btn-rs-outline btn-sm">
                <i class="bi bi-tools me-1"></i> Maintenance Report
              </a>
            </div>
          </div>
        </div>
      </section>
    <?php endif; ?>

  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/main.js"></script>
  <script src="assets/js/reports.js"></script>
</body>
</html>
