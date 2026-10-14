<?php
declare(strict_types=1);

require_once __DIR__ . '/bill-bootstrap.php';

$rawShareId = $_POST['share_id'] ?? null;
$shareId = is_string($rawShareId) && ctype_digit($rawShareId) ? (int) $rawShareId : 0;
$rawStatus = trim(is_string($_POST['status'] ?? null) ? $_POST['status'] : '');
$targetStatus = (strcasecmp($rawStatus, 'paid') === 0) ? 'Paid' : 'Unpaid';

if ($shareId < 1) {
    bill_set_flash_and_redirect('Bill share record not found.', '../bills.php?view=bills');
}

// Verify share exists and belongs to a bill in current household
$query = $billConnection->prepare(
    'SELECT s.bill_share_id, s.bill_id, s.user_id, s.payment_status, b.household_id
     FROM bill_sharesTb AS s
     INNER JOIN billsTb AS b ON b.bill_id = s.bill_id
     WHERE s.bill_share_id = :share_id AND b.household_id = :household_id
     LIMIT 1'
);
$query->execute(['share_id' => $shareId, 'household_id' => $billHouseholdId]);
$share = $query->fetch();

if (!$share) {
    bill_set_flash_and_redirect('Bill share record not found.', '../bills.php?view=bills');
}

// Permission: Admin or the assigned roommate can toggle this share
$isAdmin = bill_is_admin($billConnection, $billUserId, $billHouseholdId);
if (!$isAdmin && $billUserId !== (int) $share['user_id']) {
    bill_set_flash_and_redirect('You do not have permission to update this payment status.', '../bills.php?view=bills');
}

if (bill_set_share_status($billConnection, $shareId, $billHouseholdId, $targetStatus)) {
    $msg = ($targetStatus === 'Paid') ? 'Share marked as Paid.' : 'Share marked as Unpaid.';
    bill_set_flash_and_redirect($msg, '../bills.php?view=bills');
} else {
    bill_set_flash_and_redirect('Unable to update share payment status.', '../bills.php?view=bills');
}
