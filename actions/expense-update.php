<?php
declare(strict_types=1);

require_once __DIR__ . '/expense-bootstrap.php';

expense_action_require_admin($expenseConnection, $expenseUserId, $expenseHouseholdId);

$rawExpenseId = $_POST['expense_id'] ?? null;
$expenseId = is_string($rawExpenseId) && ctype_digit($rawExpenseId) ? (int) $rawExpenseId : 0;
if ($expenseId < 1) {
    expense_set_flash_and_redirect('The expense record could not be found.');
}

// Verify expense exists and belongs to current household
$verifyQuery = $expenseConnection->prepare(
    'SELECT expense_id, household_id, amount, paid_by
     FROM expenseTb
     WHERE expense_id = :expense_id AND household_id = :household_id
     LIMIT 1'
);
$verifyQuery->execute(['expense_id' => $expenseId, 'household_id' => $expenseHouseholdId]);
$existingExpense = $verifyQuery->fetch();
if (!$existingExpense) {
    expense_set_flash_and_redirect('The expense record could not be found.');
}

$title = trim(is_string($_POST['title'] ?? null) ? $_POST['title'] : '');
$rawAmount = $_POST['amount'] ?? null;
$amount = is_numeric($rawAmount) ? round((float) $rawAmount, 2) : 0.0;
$expenseDate = bill_valid_date($_POST['expense_date'] ?? null);
$rawCategoryId = $_POST['category_id'] ?? null;
$categoryId = is_string($rawCategoryId) && ctype_digit($rawCategoryId) ? (int) $rawCategoryId : 0;
$rawPaidBy = $_POST['paid_by'] ?? null;
$paidBy = is_string($rawPaidBy) && ctype_digit($rawPaidBy) ? (int) $rawPaidBy : 0;
$description = trim(is_string($_POST['description'] ?? null) ? $_POST['description'] : '');

$rawShares = $_POST['shares'] ?? [];
$selectedMembers = $_POST['selected_members'] ?? [];

if ($title === '' || mb_strlen($title) > 100) {
    expense_set_flash_and_redirect('Enter an expense title with up to 100 characters.');
}
if ($amount <= 0.0) {
    expense_set_flash_and_redirect('Enter a valid expense amount greater than 0.');
}
if ($expenseDate === null) {
    expense_set_flash_and_redirect('Enter a valid expense date.');
}
if ($categoryId < 1 || !expense_category_is_valid($expenseConnection, $expenseHouseholdId, $categoryId)) {
    expense_set_flash_and_redirect('Select a valid expense category.');
}
if ($paidBy < 1 || !expense_member_is_valid($expenseConnection, $expenseHouseholdId, $paidBy)) {
    expense_set_flash_and_redirect('Select a valid household member who paid for the expense.');
}

// Optional sharing/split validation
$sanitizedShares = [];
if (is_array($selectedMembers) && !empty($selectedMembers)) {
    $totalShares = 0.0;
    foreach ($selectedMembers as $rawMemberId) {
        if (!is_string($rawMemberId) && !is_int($rawMemberId)) {
            continue;
        }
        $memberId = (int) $rawMemberId;
        if ($memberId < 1 || !expense_member_is_valid($expenseConnection, $expenseHouseholdId, $memberId)) {
            expense_set_flash_and_redirect('Invalid household member selected for expense share.');
        }

        $rawMemberShare = $rawShares[$memberId] ?? null;
        $memberShare = is_numeric($rawMemberShare) ? round((float) $rawMemberShare, 2) : 0.0;
        if ($memberShare <= 0.0) {
            expense_set_flash_and_redirect('Each selected member must have a share amount greater than 0.');
        }

        $sanitizedShares[$memberId] = $memberShare;
        $totalShares += $memberShare;
    }

    $totalShares = round($totalShares, 2);
    if (abs($totalShares - $amount) > 0.01) {
        expense_set_flash_and_redirect(
            sprintf('The sum of assigned shares (₱%s) must equal the total expense amount (₱%s).', number_format($totalShares, 2), number_format($amount, 2))
        );
    }
}

try {
    $expenseConnection->beginTransaction();

    // 1. Update expense record
    $updateStmt = $expenseConnection->prepare(
        'UPDATE expenseTb
         SET category_id = :category_id,
             title = :title,
             amount = :amount,
             paid_by = :paid_by,
             expense_date = :expense_date,
             description = :description
         WHERE expense_id = :expense_id AND household_id = :household_id'
    );
    $updateStmt->execute([
        'category_id' => $categoryId,
        'title' => $title,
        'amount' => $amount,
        'paid_by' => $paidBy,
        'expense_date' => $expenseDate,
        'description' => $description !== '' ? $description : null,
        'expense_id' => $expenseId,
        'household_id' => $expenseHouseholdId,
    ]);

    // 2. Fetch existing shares to preserve payment status (is_paid)
    $existingSharesQuery = $expenseConnection->prepare(
        'SELECT share_id, user_id, share_amount, is_paid
         FROM expenses_sharesTb
         WHERE expense_id = :expense_id'
    );
    $existingSharesQuery->execute(['expense_id' => $expenseId]);
    $existingShares = $existingSharesQuery->fetchAll();

    $existingByUser = [];
    foreach ($existingShares as $sh) {
        $existingByUser[(int) $sh['user_id']] = $sh;
    }

    // Delete shares for members no longer selected
    $deleteShareStmt = $expenseConnection->prepare(
        'DELETE FROM expenses_sharesTb WHERE share_id = :share_id'
    );
    foreach ($existingByUser as $existingUserId => $existingShareRow) {
        if (!isset($sanitizedShares[$existingUserId])) {
            $deleteShareStmt->execute(['share_id' => (int) $existingShareRow['share_id']]);
        }
    }

    // Update or Insert shares
    $updateShareStmt = $expenseConnection->prepare(
        'UPDATE expenses_sharesTb
         SET share_amount = :share_amount, is_paid = :is_paid
         WHERE share_id = :share_id'
    );
    $insertShareStmt = $expenseConnection->prepare(
        'INSERT INTO expenses_sharesTb (expense_id, user_id, share_amount, is_paid)
         VALUES (:expense_id, :user_id, :share_amount, :is_paid)'
    );

    foreach ($sanitizedShares as $memberId => $shareAmount) {
        if (isset($existingByUser[$memberId])) {
            $prevPaid = (int) $existingByUser[$memberId]['is_paid'];
            // If member is the payer, ensure is_paid = 1
            if ($memberId === $paidBy) {
                $prevPaid = 1;
            }
            $updateShareStmt->execute([
                'share_amount' => $shareAmount,
                'is_paid' => $prevPaid,
                'share_id' => (int) $existingByUser[$memberId]['share_id'],
            ]);
        } else {
            $isPaid = ($memberId === $paidBy) ? 1 : 0;
            $insertShareStmt->execute([
                'expense_id' => $expenseId,
                'user_id' => $memberId,
                'share_amount' => $shareAmount,
                'is_paid' => $isPaid,
            ]);
        }
    }

    $expenseConnection->commit();
    expense_set_flash_and_redirect('Expense updated successfully.');
} catch (Throwable $error) {
    if ($expenseConnection->inTransaction()) {
        $expenseConnection->rollBack();
    }
    error_log('RoomieSync expense update error: ' . $error->getMessage());
    expense_set_flash_and_redirect('Unable to update expense due to a server error.');
}
