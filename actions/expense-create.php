<?php
declare(strict_types=1);

require_once __DIR__ . '/expense-bootstrap.php';

expense_action_require_admin($expenseConnection, $expenseUserId, $expenseHouseholdId);

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

    $insertExpense = $expenseConnection->prepare(
        'INSERT INTO expenseTb (household_id, category_id, title, amount, paid_by, expense_date, description, created_at)
         VALUES (:household_id, :category_id, :title, :amount, :paid_by, :expense_date, :description, NOW())'
    );
    $insertExpense->execute([
        'household_id' => $expenseHouseholdId,
        'category_id' => $categoryId,
        'title' => $title,
        'amount' => $amount,
        'paid_by' => $paidBy,
        'expense_date' => $expenseDate,
        'description' => $description !== '' ? $description : null,
    ]);
    $expenseId = (int) $expenseConnection->lastInsertId();

    if (!empty($sanitizedShares)) {
        $insertShare = $expenseConnection->prepare(
            'INSERT INTO expenses_sharesTb (expense_id, user_id, share_amount, is_paid)
             VALUES (:expense_id, :user_id, :share_amount, :is_paid)'
        );

        foreach ($sanitizedShares as $memberId => $shareAmount) {
            // The person who paid already covered their portion upfront
            $isPaid = ($memberId === $paidBy) ? 1 : 0;
            $insertShare->execute([
                'expense_id' => $expenseId,
                'user_id' => $memberId,
                'share_amount' => $shareAmount,
                'is_paid' => $isPaid,
            ]);
        }
    }

    $expenseConnection->commit();
    expense_set_flash_and_redirect('Expense recorded successfully.');
} catch (Throwable $error) {
    if ($expenseConnection->inTransaction()) {
        $expenseConnection->rollBack();
    }
    error_log('RoomieSync expense creation error: ' . $error->getMessage());
    expense_set_flash_and_redirect('Unable to record expense due to a server error.');
}
