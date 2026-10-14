<?php
declare(strict_types=1);

require_once __DIR__ . '/expense-bootstrap.php';

expense_action_require_admin($expenseConnection, $expenseUserId, $expenseHouseholdId);

$rawExpenseId = $_POST['expense_id'] ?? null;
$expenseId = is_string($rawExpenseId) && ctype_digit($rawExpenseId) ? (int) $rawExpenseId : 0;
if ($expenseId < 1) {
    expense_set_flash_and_redirect('The expense record could not be found.');
}

try {
    $expenseConnection->beginTransaction();

    // Verify expense exists and belongs to current household
    $verifyQuery = $expenseConnection->prepare(
        'SELECT expense_id
         FROM expenseTb
         WHERE expense_id = :expense_id AND household_id = :household_id
         LIMIT 1'
    );
    $verifyQuery->execute(['expense_id' => $expenseId, 'household_id' => $expenseHouseholdId]);
    if (!$verifyQuery->fetch()) {
        $expenseConnection->rollBack();
        expense_set_flash_and_redirect('The expense record could not be found.');
    }

    // Delete expense record (ON DELETE CASCADE handles expenses_sharesTb)
    $delete = $expenseConnection->prepare(
        'DELETE FROM expenseTb
         WHERE expense_id = :expense_id AND household_id = :household_id'
    );
    $delete->execute(['expense_id' => $expenseId, 'household_id' => $expenseHouseholdId]);

    $expenseConnection->commit();
    expense_set_flash_and_redirect('Expense deleted successfully.');
} catch (Throwable $error) {
    if ($expenseConnection->inTransaction()) {
        $expenseConnection->rollBack();
    }
    error_log('RoomieSync expense deletion error: ' . $error->getMessage());
    expense_set_flash_and_redirect('Unable to delete expense.');
}
