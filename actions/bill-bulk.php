<?php
declare(strict_types=1);

require_once __DIR__ . '/bill-bootstrap.php';

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

$view = is_string($_POST['view'] ?? null) ? trim($_POST['view']) : 'bills';
$tab = is_string($_POST['tab'] ?? null) ? trim($_POST['tab']) : 'all';
$search = is_string($_POST['search'] ?? null) ? trim($_POST['search']) : '';
$statusFilter = is_string($_POST['status'] ?? null) ? trim($_POST['status']) : 'all';
$categoryFilter = is_string($_POST['category'] ?? null) && ctype_digit($_POST['category']) ? (int) $_POST['category'] : 0;

$redirectUrl = '../bills.php?view=' . rawurlencode($view) . '&tab=' . rawurlencode($tab);
if ($search !== '') {
    $redirectUrl .= '&search=' . rawurlencode($search);
}
if ($statusFilter !== 'all') {
    $redirectUrl .= '&status=' . rawurlencode($statusFilter);
}
if ($categoryFilter > 0) {
    $redirectUrl .= '&category=' . $categoryFilter;
}

if ($selectedIds === []) {
    bill_set_flash_and_redirect('Please select at least one bill record.', $redirectUrl);
}

$isAdmin = bill_is_admin($billConnection, $billUserId, $billHouseholdId);

if ($action === 'mark_paid' || $action === 'mark_unpaid') {
    bill_action_require_admin($billConnection, $billUserId, $billHouseholdId);
    $targetStatus = ($action === 'mark_paid') ? 'Paid' : 'Unpaid';
    $updatedCount = 0;

    foreach ($selectedIds as $billId) {
        if (bill_set_status($billConnection, $billId, $billHouseholdId, $targetStatus)) {
            $updatedCount++;
        }
    }

    $word = $updatedCount === 1 ? 'bill' : 'bills';
    bill_set_flash_and_redirect("{$updatedCount} {$word} marked as {$targetStatus}.", $redirectUrl);
} elseif ($action === 'delete') {
    bill_action_require_admin($billConnection, $billUserId, $billHouseholdId);

    try {
        $billConnection->beginTransaction();

        $inPlaceholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $stmt = $billConnection->prepare(
            "DELETE FROM billsTb
             WHERE bill_id IN ({$inPlaceholders})
               AND household_id = ?"
        );
        $params = array_merge($selectedIds, [$billHouseholdId]);
        $stmt->execute($params);
        $deletedCount = $stmt->rowCount();

        $billConnection->commit();
        $word = $deletedCount === 1 ? 'bill' : 'bills';
        bill_set_flash_and_redirect("{$deletedCount} {$word} deleted successfully.", $redirectUrl);
    } catch (Throwable $e) {
        if ($billConnection->inTransaction()) {
            $billConnection->rollBack();
        }
        error_log('Bill bulk delete error: ' . $e->getMessage());
        bill_set_flash_and_redirect('Failed to delete selected bills.', $redirectUrl);
    }
} elseif ($action === 'share_paid' || $action === 'share_unpaid') {
    $targetStatus = ($action === 'share_paid') ? 'Paid' : 'Unpaid';
    $updatedCount = 0;

    foreach ($selectedIds as $shareId) {
        // Verify ownership if not admin
        $chk = $billConnection->prepare(
            'SELECT s.user_id
             FROM bill_sharesTb AS s
             INNER JOIN billsTb AS b ON b.bill_id = s.bill_id
             WHERE s.bill_share_id = ? AND b.household_id = ?
             LIMIT 1'
        );
        $chk->execute([$shareId, $billHouseholdId]);
        $row = $chk->fetch();
        if (!$row) {
            continue;
        }

        if (!$isAdmin && (int) $row['user_id'] !== $billUserId) {
            continue;
        }

        if (bill_set_share_status($billConnection, $shareId, $billHouseholdId, $targetStatus)) {
            $updatedCount++;
        }
    }

    $word = $updatedCount === 1 ? 'share' : 'shares';
    bill_set_flash_and_redirect("{$updatedCount} {$word} marked as {$targetStatus}.", $redirectUrl);
} else {
    bill_set_flash_and_redirect('Unknown bulk action specified.', $redirectUrl);
}
