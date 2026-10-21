<?php
declare(strict_types=1);

require_once __DIR__ . '/bill-bootstrap.php';

bill_action_require_admin($billConnection, $billUserId, $billHouseholdId);

$rawBillId = $_POST['bill_id'] ?? null;
$billId = is_string($rawBillId) && ctype_digit($rawBillId) ? (int) $rawBillId : 0;
if ($billId < 1) {
    bill_set_flash_and_redirect('The bill could not be found.');
}

// Verify bill exists and belongs to current household
$verifyQuery = $billConnection->prepare(
    'SELECT bill_id, household_id, amount, status
     FROM billsTb
     WHERE bill_id = :bill_id AND household_id = :household_id
     LIMIT 1'
);
$verifyQuery->execute(['bill_id' => $billId, 'household_id' => $billHouseholdId]);
$existingBill = $verifyQuery->fetch();
if (!$existingBill) {
    bill_set_flash_and_redirect('The bill could not be found.');
}

$billName = trim(is_string($_POST['bill_name'] ?? null) ? $_POST['bill_name'] : '');
$rawAmount = $_POST['amount'] ?? null;
$amount = is_numeric($rawAmount) ? round((float) $rawAmount, 2) : 0.0;
$dueDate = bill_valid_date($_POST['due_date'] ?? null);
$rawCategoryId = $_POST['category_id'] ?? null;
$categoryId = is_string($rawCategoryId) && ctype_digit($rawCategoryId) ? (int) $rawCategoryId : 0;
$status = trim(is_string($_POST['status'] ?? null) ? $_POST['status'] : 'Unpaid');
$validStatuses = ['Unpaid', 'Paid', 'Overdue'];
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

    // Fetch existing shares for this bill to preserve payment history/status
    $existingSharesQuery = $billConnection->prepare(
        'SELECT bill_share_id, user_id, share_amount, payment_status
         FROM bill_sharesTb
         WHERE bill_id = :bill_id'
    );
    $existingSharesQuery->execute(['bill_id' => $billId]);
    $existingShares = $existingSharesQuery->fetchAll();

    $existingByUser = [];
    foreach ($existingShares as $exShare) {
        $existingByUser[(int) $exShare['user_id']] = $exShare;
    }

    // Update the bill record
    $updateBill = $billConnection->prepare(
        'UPDATE billsTb
         SET category_id = :category_id,
             bill_name = :bill_name,
             amount = :amount,
             due_date = :due_date,
             status = :status
         WHERE bill_id = :bill_id AND household_id = :household_id'
    );
    $updateBill->execute([
        'category_id' => $categoryId,
        'bill_name' => $billName,
        'amount' => $amount,
        'due_date' => $dueDate,
        'status' => $status,
        'bill_id' => $billId,
        'household_id' => $billHouseholdId,
    ]);

    // Handle members removed from shares
    $updatePaymentStatus = (strcasecmp($status, 'Paid') === 0);

    foreach ($existingByUser as $existingUserId => $exShare) {
        if (!isset($sanitizedShares[$existingUserId])) {
            // Member was unselected. Check if they have payment records in paymentsTb
            $checkPayments = $billConnection->prepare(
                'SELECT COUNT(*) FROM paymentsTb WHERE bill_share_id = :bill_share_id'
            );
            $checkPayments->execute(['bill_share_id' => $exShare['bill_share_id']]);
            $hasPayments = (int) $checkPayments->fetchColumn();

            if ($hasPayments > 0) {
                // To preserve historical payment information, reject removing a member who already has payments logged
                throw new RuntimeException('Cannot remove a member share with existing payment records.');
            }

            // Safe to delete this bill share
            $deleteShare = $billConnection->prepare('DELETE FROM bill_sharesTb WHERE bill_share_id = :bill_share_id');
            $deleteShare->execute(['bill_share_id' => $exShare['bill_share_id']]);
        }
    }

    // Insert or update shares for selected members
    $updateShareStmt = $billConnection->prepare(
        'UPDATE bill_sharesTb
         SET share_amount = :share_amount,
             payment_status = :payment_status
         WHERE bill_share_id = :bill_share_id'
    );

    $insertShareStmt = $billConnection->prepare(
        'INSERT INTO bill_sharesTb (bill_id, user_id, share_amount, payment_status)
         VALUES (:bill_id, :user_id, :share_amount, :payment_status)'
    );

    foreach ($sanitizedShares as $memberId => $shareAmount) {
        if (isset($existingByUser[$memberId])) {
            $exShare = $existingByUser[$memberId];
            $currentPaymentStatus = (string) $exShare['payment_status'];
            // If overall bill is marked Paid by admin, reflect Paid on shares, else preserve their existing payment_status
            $newPaymentStatus = $updatePaymentStatus ? 'Paid' : $currentPaymentStatus;

            $updateShareStmt->execute([
                'share_amount' => $shareAmount,
                'payment_status' => $newPaymentStatus,
                'bill_share_id' => $exShare['bill_share_id'],
            ]);
        } else {
            // New member share added
            $newPaymentStatus = $updatePaymentStatus ? 'Paid' : 'Unpaid';
            $insertShareStmt->execute([
                'bill_id' => $billId,
                'user_id' => $memberId,
                'share_amount' => $shareAmount,
                'payment_status' => $newPaymentStatus,
            ]);
        }
    }

    $billConnection->commit();
    bill_set_flash_and_redirect('Bill updated successfully.');
} catch (RuntimeException $re) {
    if ($billConnection->inTransaction()) {
        $billConnection->rollBack();
    }
    bill_set_flash_and_redirect($re->getMessage());
} catch (Throwable $error) {
    if ($billConnection->inTransaction()) {
        $billConnection->rollBack();
    }
    error_log('RoomieSync bill update error: ' . $error->getMessage());
    bill_set_flash_and_redirect('Unable to update bill due to a server error.');
}
