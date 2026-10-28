<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';

if (!restore_remembered_login()) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$householdId = selected_household_id();

if ($householdId === null) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'No household selected']);
    exit;
}

try {
    $connection = app_pdo();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

$rawMonth = is_string($_GET['month'] ?? null) ? trim($_GET['month']) : '';
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $rawMonth)) {
    $rawMonth = date('Y-m');
}

$startDate = $rawMonth . '-01';
$endDate = date('Y-m-t', strtotime($startDate));

try {
    // 1. Fetch chore assignments in this month for the household
    $choreStmt = $connection->prepare(
        "SELECT ca.assignment_id, ca.chore_id, c.chore_name, c.frequency, c.assignment_type,
                COALESCE(cat.category_name, 'General') AS category_name,
                ca.due_date, ca.status, ca.completed_at,
                u.user_id, COALESCE(NULLIF(u.full_name, ''), u.username, 'Unassigned') AS assigned_name,
                u.username, u.profile_picture
         FROM chore_assignmentsTb AS ca
         INNER JOIN choresTb AS c ON c.chore_id = ca.chore_id
         LEFT JOIN chore_categoriesTb AS cat
                ON cat.chore_category_id = c.category_id
               AND cat.household_id = c.household_id
         INNER JOIN userTb AS u ON u.user_id = ca.user_id
         WHERE c.household_id = ?
           AND ca.due_date >= ? AND ca.due_date <= ?
         ORDER BY ca.due_date ASC, ca.assignment_id ASC"
    );
    $choreStmt->execute([$householdId, $startDate, $endDate]);
    $choreRows = $choreStmt->fetchAll();

    // 2. Fetch bills due in this month for the household
    $billStmt = $connection->prepare(
        "SELECT b.bill_id, b.bill_name, b.amount, b.due_date, b.status,
                COALESCE(cat.category_name, 'Utility') AS category_name
         FROM billsTb AS b
         LEFT JOIN bill_categoriesTb AS cat
                ON cat.bill_category_id = b.category_id
               AND cat.household_id = b.household_id
         WHERE b.household_id = ?
           AND b.due_date >= ? AND b.due_date <= ?
         ORDER BY b.due_date ASC, b.bill_id ASC"
    );
    $billStmt->execute([$householdId, $startDate, $endDate]);
    $billRows = $billStmt->fetchAll();

    $tasksByDate = [];

    foreach ($choreRows as $c) {
        $date = (string) $c['due_date'];
        $rawStatus = (string) $c['status'];
        $todayStr = date('Y-m-d');

        // Status calculation matching RoomieSync standards
        $displayStatus = 'Pending';
        if (strcasecmp($rawStatus, 'completed') === 0) {
            $displayStatus = 'Completed';
        } elseif ($date < $todayStr) {
            $displayStatus = 'Overdue';
        } elseif ($date <= date('Y-m-d', strtotime('+3 days'))) {
            $displayStatus = 'Due Soon';
        }

        $tasksByDate[$date][] = [
            'type' => 'chore',
            'id' => (int) $c['assignment_id'],
            'chore_id' => (int) $c['chore_id'],
            'title' => (string) $c['chore_name'],
            'assigned_name' => (string) $c['assigned_name'],
            'assigned_user_id' => (int) $c['user_id'],
            'category' => (string) $c['category_name'],
            'frequency' => (string) $c['frequency'],
            'due_date' => $date,
            'status' => $displayStatus,
            'is_completed' => strcasecmp($rawStatus, 'completed') === 0,
            'url' => 'chores.php?search=' . rawurlencode((string) $c['chore_name']),
        ];
    }

    foreach ($billRows as $b) {
        $date = (string) $b['due_date'];
        $rawStatus = (string) $b['status'];
        $todayStr = date('Y-m-d');

        $displayStatus = 'Unpaid';
        if (strcasecmp($rawStatus, 'paid') === 0) {
            $displayStatus = 'Paid';
        } elseif ($date < $todayStr) {
            $displayStatus = 'Overdue';
        }

        $tasksByDate[$date][] = [
            'type' => 'bill',
            'id' => (int) $b['bill_id'],
            'title' => (string) $b['bill_name'],
            'amount' => (float) $b['amount'],
            'amount_formatted' => '₱' . number_format((float) $b['amount'], 2),
            'category' => (string) $b['category_name'],
            'due_date' => $date,
            'status' => $displayStatus,
            'is_completed' => strcasecmp($rawStatus, 'paid') === 0,
            'url' => 'bills.php?view=bills&search=' . rawurlencode((string) $b['bill_name']),
        ];
    }

    echo json_encode([
        'status' => 'success',
        'month' => $rawMonth,
        'today' => date('Y-m-d'),
        'start_date' => $startDate,
        'end_date' => $endDate,
        'tasks_by_date' => $tasksByDate,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Calendar tasks fetch error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to fetch calendar tasks']);
}
