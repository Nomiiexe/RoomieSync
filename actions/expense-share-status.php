<?php
declare(strict_types=1);

require_once __DIR__ . '/expense-bootstrap.php';

$rawShareId = $_POST['share_id'] ?? null;
$shareId = is_string($rawShareId) && ctype_digit($rawShareId) ? (int) $rawShareId : 0;
$rawStatus = $_POST['is_paid'] ?? null;
$isPaid = ($rawStatus === '1' || $rawStatus === 1 || $rawStatus === 'true') ? 1 : 0;

if ($shareId < 1) {
    expense_set_flash_and_redirect('Expense share record not found.');
}

// Verify share exists, belongs to an expense in the current household
$query = $expenseConnection->prepare(
    'SELECT s.share_id, s.user_id, s.share_amount, s.is_paid, e.paid_by, e.household_id
     FROM expenses_sharesTb AS s
     INNER JOIN expenseTb AS e ON e.expense_id = s.expense_id
     WHERE s.share_id = :share_id AND e.household_id = :household_id
     LIMIT 1'
);
$query->execute(['share_id' => $shareId, 'household_id' => $expenseHouseholdId]);
$shareRow = $query->fetch();

if (!$shareRow) {
    expense_set_flash_and_redirect('Expense share record not found.');
}

// Permission check: Admin, the payer, or the share assignee
$isAdmin = bill_is_admin($expenseConnection, $expenseUserId, $expenseHouseholdId);
if (!$isAdmin && $expenseUserId !== (int) $shareRow['paid_by'] && $expenseUserId !== (int) $shareRow['user_id']) {
    expense_set_flash_and_redirect('You do not have permission to update this share status.');
}

$update = $expenseConnection->prepare(
    'UPDATE expenses_sharesTb SET is_paid = :is_paid WHERE share_id = :share_id'
);
$update->execute(['is_paid' => $isPaid, 'share_id' => $shareId]);

$msg = $isPaid ? 'Expense share marked as paid.' : 'Expense share marked as unpaid.';
expense_set_flash_and_redirect($msg);
