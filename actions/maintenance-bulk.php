<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';

if (!restore_remembered_login()) {
    redirect_to('../login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('../reports.php?view=maintenance');
}

$userId = (int) $_SESSION['user_id'];
$householdId = selected_household_id();
if ($householdId === null) {
    redirect_to('../households.php');
}

$fromDate = is_string($_POST['from_date'] ?? null) ? trim($_POST['from_date']) : '';
$toDate = is_string($_POST['to_date'] ?? null) ? trim($_POST['to_date']) : '';
$redirectUrl = '../reports.php?view=maintenance';
if ($fromDate !== '' && $toDate !== '') {
    $redirectUrl .= '&from_date=' . rawurlencode($fromDate) . '&to_date=' . rawurlencode($toDate);
}

if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
    $_SESSION['flash_message'] = ['message' => 'This form expired. Please try again.', 'type' => 'danger'];
    redirect_to($redirectUrl);
}

try {
    $connection = app_pdo();
} catch (Throwable $e) {
    error_log('Maintenance bulk connection error: ' . $e->getMessage());
    $_SESSION['flash_message'] = ['message' => 'Database connection failed.', 'type' => 'danger'];
    redirect_to($redirectUrl);
}

// Admin Verification
$roleQuery = $connection->prepare(
    'SELECT role FROM householdmembersTb WHERE household_id = ? AND user_id = ? LIMIT 1'
);
$roleQuery->execute([$householdId, $userId]);
$role = (string) $roleQuery->fetchColumn();
if (strcasecmp($role, 'Admin') !== 0) {
    $_SESSION['flash_message'] = ['message' => 'You do not have permission to manage maintenance reports.', 'type' => 'danger'];
    redirect_to($redirectUrl);
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
    $_SESSION['flash_message'] = ['message' => 'Please select at least one maintenance record.', 'type' => 'danger'];
    redirect_to($redirectUrl);
}

if ($action === 'resolve' || $action === 'open') {
    $newStatus = ($action === 'resolve') ? 'Resolved' : 'Open';
    try {
        $connection->beginTransaction();
        $inPlaceholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $stmt = $connection->prepare(
            "UPDATE maintenanceTb
             SET status = ?
             WHERE problem_id IN ({$inPlaceholders})
               AND household_id = ?"
        );
        $params = array_merge([$newStatus], $selectedIds, [$householdId]);
        $stmt->execute($params);
        $updated = $stmt->rowCount();
        $connection->commit();

        $word = $updated === 1 ? 'report' : 'reports';
        $_SESSION['flash_message'] = ['message' => "{$updated} maintenance {$word} marked as {$newStatus}.", 'type' => 'success'];
        redirect_to($redirectUrl);
    } catch (Throwable $e) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        error_log('Maintenance bulk status error: ' . $e->getMessage());
        $_SESSION['flash_message'] = ['message' => 'Failed to update maintenance status.', 'type' => 'danger'];
        redirect_to($redirectUrl);
    }
} elseif ($action === 'delete') {
    try {
        $connection->beginTransaction();
        $inPlaceholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $stmt = $connection->prepare(
            "DELETE FROM maintenanceTb
             WHERE problem_id IN ({$inPlaceholders})
               AND household_id = ?"
        );
        $params = array_merge($selectedIds, [$householdId]);
        $stmt->execute($params);
        $deleted = $stmt->rowCount();
        $connection->commit();

        $word = $deleted === 1 ? 'report' : 'reports';
        $_SESSION['flash_message'] = ['message' => "{$deleted} maintenance {$word} deleted successfully.", 'type' => 'success'];
        redirect_to($redirectUrl);
    } catch (Throwable $e) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        error_log('Maintenance bulk delete error: ' . $e->getMessage());
        $_SESSION['flash_message'] = ['message' => 'Failed to delete maintenance reports.', 'type' => 'danger'];
        redirect_to($redirectUrl);
    }
} else {
    $_SESSION['flash_message'] = ['message' => 'Unknown bulk action specified.', 'type' => 'danger'];
    redirect_to($redirectUrl);
}
