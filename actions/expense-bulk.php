<?php
declare(strict_types=1);

require_once __DIR__ . '/expense-bootstrap.php';

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

$search = is_string($_POST['expense_search'] ?? null) ? trim($_POST['expense_search']) : '';
$categoryFilter = is_string($_POST['expense_category'] ?? null) && ctype_digit($_POST['expense_category']) ? (int) $_POST['expense_category'] : 0;
$dateFilter = is_string($_POST['expense_date_filter'] ?? null) ? trim($_POST['expense_date_filter']) : 'all';
$sort = is_string($_POST['expense_sort'] ?? null) ? trim($_POST['expense_sort']) : 'date_desc';

$redirectUrl = '../bills.php?view=expenses';
if ($search !== '') {
    $redirectUrl .= '&expense_search=' . rawurlencode($search);
}
if ($categoryFilter > 0) {
    $redirectUrl .= '&expense_category=' . $categoryFilter;
}
if ($dateFilter !== 'all') {
    $redirectUrl .= '&expense_date_filter=' . rawurlencode($dateFilter);
}
if ($sort !== 'date_desc') {
    $redirectUrl .= '&expense_sort=' . rawurlencode($sort);
}

if ($selectedIds === []) {
    expense_set_flash_and_redirect('Please select at least one expense record.', $redirectUrl);
}

$isAdmin = expense_is_admin($expenseConnection, $expenseUserId, $expenseHouseholdId);

if ($action === 'mark_settled' || $action === 'mark_unsettled') {
    expense_action_require_admin($expenseConnection, $expenseUserId, $expenseHouseholdId);
    $isPaid = ($action === 'mark_settled') ? 1 : 0;
    $updatedCount = 0;

    foreach ($selectedIds as $expenseId) {
        if (expense_set_status($expenseConnection, $expenseId, $expenseHouseholdId, $isPaid)) {
            $updatedCount++;
        }
    }

    $word = $updatedCount === 1 ? 'expense' : 'expenses';
    $statusText = $isPaid === 1 ? 'Settled / Paid' : 'Unsettled';
    expense_set_flash_and_redirect("{$updatedCount} {$word} marked as {$statusText}.", $redirectUrl);
} elseif ($action === 'delete') {
    expense_action_require_admin($expenseConnection, $expenseUserId, $expenseHouseholdId);

    try {
        $expenseConnection->beginTransaction();

        $inPlaceholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $stmt = $expenseConnection->prepare(
            "DELETE FROM expenseTb
             WHERE expense_id IN ({$inPlaceholders})
               AND household_id = ?"
        );
        $params = array_merge($selectedIds, [$expenseHouseholdId]);
        $stmt->execute($params);
        $deletedCount = $stmt->rowCount();

        $expenseConnection->commit();
        $word = $deletedCount === 1 ? 'expense' : 'expenses';
        expense_set_flash_and_redirect("{$deletedCount} {$word} deleted successfully.", $redirectUrl);
    } catch (Throwable $e) {
        if ($expenseConnection->inTransaction()) {
            $expenseConnection->rollBack();
        }
        error_log('Expense bulk delete error: ' . $e->getMessage());
        expense_set_flash_and_redirect('Failed to delete selected expenses.', $redirectUrl);
    }
} elseif ($action === 'share_paid' || $action === 'share_unpaid') {
    $isPaid = ($action === 'share_paid') ? 1 : 0;
    $updatedCount = 0;

    foreach ($selectedIds as $shareId) {
        $chk = $expenseConnection->prepare(
            'SELECT s.user_id, e.paid_by
             FROM expenses_sharesTb AS s
             INNER JOIN expenseTb AS e ON e.expense_id = s.expense_id
             WHERE s.share_id = ? AND e.household_id = ?
             LIMIT 1'
        );
        $chk->execute([$shareId, $expenseHouseholdId]);
        $row = $chk->fetch();
        if (!$row) {
            continue;
        }

        if (!$isAdmin && (int) $row['user_id'] !== $expenseUserId && (int) $row['paid_by'] !== $expenseUserId) {
            continue;
        }

        $up = $expenseConnection->prepare('UPDATE expenses_sharesTb SET is_paid = ? WHERE share_id = ?');
        $up->execute([$isPaid, $shareId]);
        $updatedCount++;
    }

    $word = $updatedCount === 1 ? 'share' : 'shares';
    $statusText = $isPaid === 1 ? 'Paid' : 'Unpaid';
    expense_set_flash_and_redirect("{$updatedCount} {$word} marked as {$statusText}.", $redirectUrl);
} else {
    expense_set_flash_and_redirect('Unknown bulk action specified.', $redirectUrl);
}
