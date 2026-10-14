<?php
declare(strict_types=1);

require_once __DIR__ . '/expense-bootstrap.php';

expense_action_require_admin($expenseConnection, $expenseUserId, $expenseHouseholdId);

$rawExpenseId = $_POST['expense_id'] ?? null;
$expenseId = is_string($rawExpenseId) && ctype_digit($rawExpenseId) ? (int) $rawExpenseId : 0;
$rawPaid = $_POST['is_paid'] ?? null;
$isPaid = ($rawPaid === '1' || $rawPaid === 1 || $rawPaid === 'true') ? 1 : 0;

if ($expenseId < 1) {
    expense_set_flash_and_redirect('The expense record could not be found.');
}

if (expense_set_status($expenseConnection, $expenseId, $expenseHouseholdId, $isPaid)) {
    $msg = ($isPaid === 1) ? 'Expense marked as Paid / Settled.' : 'Expense marked as Unpaid.';
    expense_set_flash_and_redirect($msg);
} else {
    expense_set_flash_and_redirect('Unable to update expense status.');
}
