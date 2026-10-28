<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';

if (!restore_remembered_login()) {
    redirect_to('../login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('../manage.php');
}

$userId = (int) $_SESSION['user_id'];
$householdId = selected_household_id();
if ($householdId === null) {
    redirect_to('../households.php');
}

if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
    $_SESSION['flash_message'] = ['message' => 'This form expired. Please try again.', 'type' => 'danger'];
    redirect_to('../manage.php');
}

try {
    $connection = app_pdo();
} catch (Throwable $e) {
    error_log('Manage bulk connection error: ' . $e->getMessage());
    $_SESSION['flash_message'] = ['message' => 'Database connection failed.', 'type' => 'danger'];
    redirect_to('../manage.php');
}

// Admin Verification
$roleQuery = $connection->prepare(
    'SELECT role FROM householdmembersTb WHERE household_id = ? AND user_id = ? LIMIT 1'
);
$roleQuery->execute([$householdId, $userId]);
$role = (string) $roleQuery->fetchColumn();
if (strcasecmp($role, 'Admin') !== 0) {
    $_SESSION['flash_message'] = ['message' => 'You do not have permission to perform bulk management actions.', 'type' => 'danger'];
    redirect_to('../manage.php');
}

$action = is_string($_POST['bulk_action'] ?? null) ? trim($_POST['bulk_action']) : '';
$rawIds = $_POST['selected_ids'] ?? [];
$selectedIds = [];
if (is_array($rawIds)) {
    foreach ($rawIds as $id) {
        if ((is_int($id) || is_string($id)) && ctype_digit((string) $id) && (int) $id > 0) {
            $selectedIds[] = (int) $id;
        }
    }
}
$selectedIds = array_values(array_unique($selectedIds));

if ($selectedIds === []) {
    $_SESSION['flash_message'] = ['message' => 'Please select at least one record.', 'type' => 'danger'];
    redirect_to('../manage.php');
}

if ($action === 'delete_announcements') {
    try {
        $connection->beginTransaction();
        $inPlaceholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $stmt = $connection->prepare(
            "DELETE FROM announceTb
             WHERE announcement_id IN ({$inPlaceholders})
               AND household_id = ?"
        );
        $params = array_merge($selectedIds, [$householdId]);
        $stmt->execute($params);
        $deleted = $stmt->rowCount();
        $connection->commit();

        $word = $deleted === 1 ? 'announcement' : 'announcements';
        $_SESSION['flash_message'] = ['message' => "{$deleted} {$word} deleted successfully.", 'type' => 'success'];
        redirect_to('../manage.php?section=announcements');
    } catch (Throwable $e) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        error_log('Announcement bulk delete error: ' . $e->getMessage());
        $_SESSION['flash_message'] = ['message' => 'Failed to delete selected announcements.', 'type' => 'danger'];
        redirect_to('../manage.php?section=announcements');
    }
} elseif ($action === 'delete_chore_categories') {
    try {
        $connection->beginTransaction();
        $inPlaceholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $stmt = $connection->prepare(
            "DELETE FROM chore_categoriesTb
             WHERE chore_category_id IN ({$inPlaceholders})
               AND household_id = ?"
        );
        $params = array_merge($selectedIds, [$householdId]);
        $stmt->execute($params);
        $deleted = $stmt->rowCount();
        $connection->commit();

        $word = $deleted === 1 ? 'chore category' : 'chore categories';
        $_SESSION['flash_message'] = ['message' => "{$deleted} {$word} deleted successfully.", 'type' => 'success'];
        redirect_to('../manage.php?section=categories');
    } catch (Throwable $e) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        error_log('Chore category bulk delete error: ' . $e->getMessage());
        $_SESSION['flash_message'] = ['message' => 'Failed to delete selected chore categories.', 'type' => 'danger'];
        redirect_to('../manage.php?section=categories');
    }
} elseif ($action === 'delete_expense_categories') {
    try {
        $connection->beginTransaction();
        $inPlaceholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $stmt = $connection->prepare(
            "DELETE FROM expense_categoriesTb
             WHERE expense_category_id IN ({$inPlaceholders})
               AND household_id = ?"
        );
        $params = array_merge($selectedIds, [$householdId]);
        $stmt->execute($params);
        $deleted = $stmt->rowCount();
        $connection->commit();

        $word = $deleted === 1 ? 'expense category' : 'expense categories';
        $_SESSION['flash_message'] = ['message' => "{$deleted} {$word} deleted successfully.", 'type' => 'success'];
        redirect_to('../manage.php?section=categories');
    } catch (Throwable $e) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        error_log('Expense category bulk delete error: ' . $e->getMessage());
        $_SESSION['flash_message'] = ['message' => 'Failed to delete selected expense categories.', 'type' => 'danger'];
        redirect_to('../manage.php?section=categories');
    }
} elseif ($action === 'delete_bill_categories') {
    try {
        $connection->beginTransaction();
        $inPlaceholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $stmt = $connection->prepare(
            "DELETE FROM bill_categoriesTb
             WHERE bill_category_id IN ({$inPlaceholders})
               AND household_id = ?"
        );
        $params = array_merge($selectedIds, [$householdId]);
        $stmt->execute($params);
        $deleted = $stmt->rowCount();
        $connection->commit();

        $word = $deleted === 1 ? 'bill category' : 'bill categories';
        $_SESSION['flash_message'] = ['message' => "{$deleted} {$word} deleted successfully.", 'type' => 'success'];
        redirect_to('../manage.php?section=categories');
    } catch (Throwable $e) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        error_log('Bill category bulk delete error: ' . $e->getMessage());
        $_SESSION['flash_message'] = ['message' => 'Failed to delete selected bill categories.', 'type' => 'danger'];
        redirect_to('../manage.php?section=categories');
    }
} else {
    $_SESSION['flash_message'] = ['message' => 'Unknown bulk action specified.', 'type' => 'danger'];
    redirect_to('../manage.php');
}
