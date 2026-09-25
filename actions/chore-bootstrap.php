<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/chores.php';

if (!restore_remembered_login()) {
    redirect_to('../login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('../chores.php');
}

$choreUserId = (int) $_SESSION['user_id'];
$choreHouseholdId = selected_household_id();
if ($choreHouseholdId === null) {
    redirect_to('../households.php');
}

try {
    $choreConnection = chores_pdo();
} catch (Throwable $error) {
    error_log('RoomieSync chore action connection error: ' . $error->getMessage());
    chore_set_flash_and_redirect('Unable to complete the chore action.');
}

if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
    chore_set_flash_and_redirect('This form expired. Please try again.');
}

function chore_action_require_admin(PDO $connection, int $userId, int $householdId): void
{
    if (!chore_is_admin($connection, $userId, $householdId)) {
        chore_set_flash_and_redirect('You do not have permission to perform this action.');
    }
}
