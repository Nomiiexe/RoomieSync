<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!restore_remembered_login()) {
    redirect_to('../login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('../household.php');
}

if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
    $_SESSION['flash_message'] = [
        'type' => 'danger',
        'message' => 'This form expired. Please try again.',
    ];
    redirect_to('../household.php');
}

$userId = (int) $_SESSION['user_id'];
$rawInviteCode = $_POST['invite_code'] ?? null;
$inviteCode = strtoupper(trim(is_string($rawInviteCode) ? $rawInviteCode : ''));

if ($inviteCode === '' || !preg_match('/\A[A-Z0-9-]{4,32}\z/', $inviteCode)) {
    $_SESSION['flash_message'] = [
        'type' => 'danger',
        'message' => 'Enter a valid household invite code (4-32 alphanumeric characters).',
    ];
    redirect_to('../household.php');
}

try {
    $connection = app_pdo();

    // 1. Find household by join_code
    $findQuery = $connection->prepare(
        'SELECT household_id, household_name
         FROM householdTb
         WHERE join_code = :join_code
         LIMIT 1'
    );
    $findQuery->execute(['join_code' => $inviteCode]);
    $household = $findQuery->fetch();

    if (!$household) {
        $_SESSION['flash_message'] = [
            'type' => 'danger',
            'message' => 'No household was found with that join code.',
        ];
        redirect_to('../household.php');
    }

    $householdId = (int) $household['household_id'];
    $householdName = (string) $household['household_name'];

    // 2. Check whether current user is already a member
    $memberQuery = $connection->prepare(
        'SELECT household_id, role
         FROM householdmembersTb
         WHERE household_id = :household_id AND user_id = :user_id
         LIMIT 1'
    );
    $memberQuery->execute([
        'household_id' => $householdId,
        'user_id' => $userId,
    ]);
    if ($memberQuery->fetch()) {
        // Already a member - simply switch active household to it
        set_selected_household($householdId);
        $_SESSION['flash_message'] = [
            'type' => 'info',
            'message' => 'You are already a member of ' . $householdName . '. Switched to it now.',
        ];
        redirect_to('../household.php');
    }

    // 3. Add user with default role: 'Roommate'
    $connection->beginTransaction();

    $insertMember = $connection->prepare(
        "INSERT INTO householdmembersTb (household_id, user_id, role, joined_at)
         VALUES (:household_id, :user_id, 'Roommate', CURRENT_TIMESTAMP)"
    );
    $insertMember->execute([
        'household_id' => $householdId,
        'user_id' => $userId,
    ]);

    $connection->commit();

    // 4. Update session to the joined household
    set_selected_household($householdId);

    $_SESSION['flash_message'] = [
        'type' => 'success',
        'message' => 'Welcome to ' . $householdName . '! You have successfully joined as a Roommate.',
    ];
    redirect_to('../household.php');

} catch (PDOException $pe) {
    if (isset($connection) && $connection->inTransaction()) {
        $connection->rollBack();
    }
    // Duplicate key error
    if ($pe->getCode() === '23000') {
        set_selected_household($householdId);
        $_SESSION['flash_message'] = [
            'type' => 'info',
            'message' => 'You are already a member of this household.',
        ];
        redirect_to('../household.php');
    }
    error_log('RoomieSync household-join PDO error: ' . $pe->getMessage());
    $_SESSION['flash_message'] = [
        'type' => 'danger',
        'message' => 'Unable to join household due to a database error.',
    ];
    redirect_to('../household.php');
} catch (Throwable $error) {
    if (isset($connection) && $connection->inTransaction()) {
        $connection->rollBack();
    }
    error_log('RoomieSync household-join error: ' . $error->getMessage());
    $_SESSION['flash_message'] = [
        'type' => 'danger',
        'message' => 'An error occurred while joining the household.',
    ];
    redirect_to('../household.php');
}
