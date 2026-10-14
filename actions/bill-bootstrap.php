<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bills.php';

if (!restore_remembered_login()) {
    redirect_to('../login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('../bills.php');
}

$billUserId = (int) $_SESSION['user_id'];
$billHouseholdId = selected_household_id();
if ($billHouseholdId === null) {
    redirect_to('../households.php');
}

try {
    $billConnection = app_pdo();
} catch (Throwable $error) {
    error_log('RoomieSync bill action connection error: ' . $error->getMessage());
    bill_set_flash_and_redirect('Unable to complete the bill action.');
}

if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
    bill_set_flash_and_redirect('This form expired. Please try again.');
}

function bill_action_require_admin(PDO $connection, int $userId, int $householdId): void
{
    if (!bill_is_admin($connection, $userId, $householdId)) {
        bill_set_flash_and_redirect('You do not have permission to perform this action.');
    }
}
