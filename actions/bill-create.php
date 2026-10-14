<?php
declare(strict_types=1);

require_once __DIR__ . '/bill-bootstrap.php';

bill_action_require_admin($billConnection, $billUserId, $billHouseholdId);

$billName = trim(is_string($_POST['bill_name'] ?? null) ? $_POST['bill_name'] : '');
$rawAmount = $_POST['amount'] ?? null;
$amount = is_numeric($rawAmount) ? round((float) $rawAmount, 2) : 0.0;
$dueDate = bill_valid_date($_POST['due_date'] ?? null);
$rawCategoryId = $_POST['category_id'] ?? null;
$categoryId = is_string($rawCategoryId) && ctype_digit($rawCategoryId) ? (int) $rawCategoryId : 0;
$status = trim(is_string($_POST['status'] ?? null) ? $_POST['status'] : 'Unpaid');
$validStatuses = ['Unpaid', 'Paid', 'Due Soon', 'Overdue'];
if (!in_array($status, $validStatuses, true)) {
    $status = 'Unpaid';
}

$rawShares = $_POST['shares'] ?? [];
$selectedMembers = $_POST['selected_members'] ?? [];

if ($billName === '' || mb_strlen($billName) > 100) {
    bill_set_flash_and_redirect('Enter a bill name with up to 100 characters.');
}
if ($amount <= 0.0) {
    bill_set_flash_and_redirect('Enter a valid total bill amount greater than 0.');
}
if ($dueDate === null) {
    bill_set_flash_and_redirect('Enter a valid due date.');
}
if ($categoryId < 1 || !bill_category_is_valid($billConnection, $billHouseholdId, $categoryId)) {
    bill_set_flash_and_redirect('Select a valid bill category.');
}

if (!is_array($selectedMembers) || empty($selectedMembers)) {
    bill_set_flash_and_redirect('Select at least one household member to assign bill shares.');
}

$totalShares = 0.0;
$sanitizedShares = [];

foreach ($selectedMembers as $rawMemberId) {
    if (!is_string($rawMemberId) && !is_int($rawMemberId)) {
        continue;
    }
    $memberId = (int) $rawMemberId;
    if ($memberId < 1 || !bill_member_is_valid($billConnection, $billHouseholdId, $memberId)) {
        bill_set_flash_and_redirect('Invalid household member selected for bill share.');
    }

    $rawMemberShare = $rawShares[$memberId] ?? null;
    $memberShare = is_numeric($rawMemberShare) ? round((float) $rawMemberShare, 2) : 0.0;
    if ($memberShare <= 0.0) {
        bill_set_flash_and_redirect('Each selected member must have a share amount greater than 0.');
    }

    $sanitizedShares[$memberId] = $memberShare;
    $totalShares += $memberShare;
}

$totalShares = round($totalShares, 2);
if (abs($totalShares - $amount) > 0.01) {
    bill_set_flash_and_redirect(
        sprintf('The sum of assigned shares (₱%s) must equal the total bill amount (₱%s).', number_format($totalShares, 2), number_format($amount, 2))
    );
}

try {
    $billConnection->beginTransaction();

    $insertBill = $billConnection->prepare(
        'INSERT INTO billsTb (household_id, category_id, bill_name, amount, due_date, status, created_by)
         VALUES (:household_id, :category_id, :bill_name, :amount, :due_date, :status, :created_by)'
    );
    $insertBill->execute([
        'household_id' => $billHouseholdId,
        'category_id' => $categoryId,
        'bill_name' => $billName,
        'amount' => $amount,
        'due_date' => $dueDate,
        'status' => $status,
        'created_by' => $billUserId,
    ]);
    $billId = (int) $billConnection->lastInsertId();

    $insertShare = $billConnection->prepare(
        'INSERT INTO bill_sharesTb (bill_id, user_id, share_amount, payment_status)
         VALUES (:bill_id, :user_id, :share_amount, :payment_status)'
    );

    foreach ($sanitizedShares as $memberId => $shareAmount) {
        $memberStatus = (strcasecmp($status, 'Paid') === 0) ? 'Paid' : 'Unpaid';
        $insertShare->execute([
            'bill_id' => $billId,
            'user_id' => $memberId,
            'share_amount' => $shareAmount,
            'payment_status' => $memberStatus,
        ]);
    }

    $billConnection->commit();
    bill_set_flash_and_redirect('Bill added successfully.');
} catch (Throwable $error) {
    if ($billConnection->inTransaction()) {
        $billConnection->rollBack();
    }
    error_log('RoomieSync bill creation error: ' . $error->getMessage());
    bill_set_flash_and_redirect('Unable to create bill due to a server error.');
}
