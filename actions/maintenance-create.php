<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!restore_remembered_login()) {
    redirect_to('../login.php');
}

$userId = (int) $_SESSION['user_id'];
$householdId = selected_household_id();

$returnUrl = '../household.php';
if (isset($_POST['return_to']) && is_string($_POST['return_to'])) {
    $rawReturn = trim($_POST['return_to']);
    if (in_array($rawReturn, ['household.php', 'manage.php?section=maintenance', 'reports.php?view=maintenance'], true)) {
        $returnUrl = '../' . $rawReturn;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to($returnUrl);
}

if ($householdId === null) {
    $_SESSION['flash_message'] = [
        'type' => 'danger',
        'message' => 'You must belong to an active household to submit maintenance reports.',
    ];
    redirect_to('../households.php');
}

if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
    $_SESSION['flash_message'] = [
        'type' => 'danger',
        'message' => 'This form expired. Please try again.',
    ];
    redirect_to($returnUrl);
}

$rawType = $_POST['problem_type'] ?? '';
$problemType = trim(is_string($rawType) ? $rawType : '');

$rawDesc = $_POST['problem_desc'] ?? '';
$problemDesc = trim(is_string($rawDesc) ? $rawDesc : '');

if ($problemType === '' || mb_strlen($problemType) > 50) {
    $_SESSION['flash_message'] = [
        'type' => 'danger',
        'message' => 'Please select or provide a valid problem type (up to 50 characters).',
    ];
    redirect_to($returnUrl);
}

if (mb_strlen($problemDesc) > 2000) {
    $_SESSION['flash_message'] = [
        'type' => 'danger',
        'message' => 'Problem description is too long (maximum 2,000 characters).',
    ];
    redirect_to($returnUrl);
}

try {
    $connection = app_pdo();

    // Verify user is a member of this household
    $memCheck = $connection->prepare(
        'SELECT role FROM householdmembersTb WHERE household_id = ? AND user_id = ? LIMIT 1'
    );
    $memCheck->execute([$householdId, $userId]);
    if (!$memCheck->fetch()) {
        $_SESSION['flash_message'] = [
            'type' => 'danger',
            'message' => 'You are not a member of this household.',
        ];
        redirect_to('../households.php');
    }

    $stmt = $connection->prepare(
        'INSERT INTO maintenanceTb (household_id, reported_by, problem_type, problem_desc, status, reported_at)
         VALUES (?, ?, ?, ?, "Open", NOW())'
    );
    $stmt->execute([
        $householdId,
        $userId,
        $problemType,
        $problemDesc !== '' ? $problemDesc : null,
    ]);

    $_SESSION['flash_message'] = [
        'type' => 'success',
        'message' => 'Maintenance report for "' . $problemType . '" submitted successfully!',
    ];
    redirect_to($returnUrl);
} catch (Throwable $e) {
    error_log('Failed to create maintenance report: ' . $e->getMessage());
    $_SESSION['flash_message'] = [
        'type' => 'danger',
        'message' => 'Could not save the maintenance report. Please try again.',
    ];
    redirect_to($returnUrl);
}

