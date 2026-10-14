<?php
declare(strict_types=1);

require_once __DIR__ . '/bill-bootstrap.php';

bill_action_require_admin($billConnection, $billUserId, $billHouseholdId);

$rawBillId = $_POST['bill_id'] ?? null;
$billId = is_string($rawBillId) && ctype_digit($rawBillId) ? (int) $rawBillId : 0;
if ($billId < 1) {
    bill_set_flash_and_redirect('The bill could not be found.');
}

try {
    $billConnection->beginTransaction();

    // Verify bill exists and belongs to current household
    $verifyQuery = $billConnection->prepare(
        'SELECT bill_id
         FROM billsTb
         WHERE bill_id = :bill_id AND household_id = :household_id
         LIMIT 1'
    );
    $verifyQuery->execute(['bill_id' => $billId, 'household_id' => $billHouseholdId]);
    if (!$verifyQuery->fetch()) {
        $billConnection->rollBack();
        bill_set_flash_and_redirect('The bill could not be found.');
    }

    // Delete bill record (foreign keys with ON DELETE CASCADE handle bill_sharesTb and paymentsTb)
    $delete = $billConnection->prepare(
        'DELETE FROM billsTb
         WHERE bill_id = :bill_id AND household_id = :household_id'
    );
    $delete->execute(['bill_id' => $billId, 'household_id' => $billHouseholdId]);

    $billConnection->commit();
    bill_set_flash_and_redirect('Bill deleted successfully.');
} catch (Throwable $error) {
    if ($billConnection->inTransaction()) {
        $billConnection->rollBack();
    }
    error_log('RoomieSync bill deletion error: ' . $error->getMessage());
    bill_set_flash_and_redirect('Unable to delete bill.');
}
