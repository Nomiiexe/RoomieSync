<?php
declare(strict_types=1);

require_once __DIR__ . '/bill-bootstrap.php';

bill_action_require_admin($billConnection, $billUserId, $billHouseholdId);

$rawBillId = $_POST['bill_id'] ?? null;
$billId = is_string($rawBillId) && ctype_digit($rawBillId) ? (int) $rawBillId : 0;
$targetStatus = trim(is_string($_POST['status'] ?? null) ? $_POST['status'] : 'Paid');
if (!in_array($targetStatus, ['Paid', 'Unpaid'], true)) {
    $targetStatus = 'Paid';
}

if ($billId < 1) {
    bill_set_flash_and_redirect('The bill could not be found.', '../bills.php?view=bills');
}

if (bill_set_status($billConnection, $billId, $billHouseholdId, $targetStatus)) {
    $msg = ($targetStatus === 'Paid') ? 'Bill marked as Paid successfully.' : 'Bill marked as Unpaid.';
    bill_set_flash_and_redirect($msg, '../bills.php?view=bills');
} else {
    bill_set_flash_and_redirect('Unable to update bill payment status.', '../bills.php?view=bills');
}
