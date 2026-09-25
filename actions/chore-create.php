<?php
declare(strict_types=1);

require_once __DIR__ . '/chore-bootstrap.php';

chore_action_require_admin($choreConnection, $choreUserId, $choreHouseholdId);

$name = trim(is_string($_POST['chore_name'] ?? null) ? $_POST['chore_name'] : '');
$description = trim(is_string($_POST['description'] ?? null) ? $_POST['description'] : '');
$frequency = trim(is_string($_POST['frequency'] ?? null) ? $_POST['frequency'] : '');
$assignmentType = is_string($_POST['assignment_type'] ?? null) ? $_POST['assignment_type'] : '';
$dueDate = chore_valid_date($_POST['due_date'] ?? null);
$rawCategoryId = $_POST['category_id'] ?? null;
$categoryId = is_string($rawCategoryId) && ctype_digit($rawCategoryId) ? (int) $rawCategoryId : 0;
$assignedUserId = $_POST['assigned_user_id'] ?? null;
$assignedUserId = is_string($assignedUserId) && ctype_digit($assignedUserId) ? (int) $assignedUserId : 0;
$rotationMembers = chore_member_ids($_POST['rotation_members'] ?? []);

if ($name === '' || strlen($name) > 100) {
    chore_set_flash_and_redirect('Enter a chore name with up to 100 characters.');
}
if (strlen($description) > 5000) {
    chore_set_flash_and_redirect('The chore description is too long.');
}
if (!chore_frequency_is_valid($frequency)) {
    chore_set_flash_and_redirect('Enter a valid chore frequency.');
}
if (!in_array($assignmentType, ['Single', 'Rotation'], true)) {
    chore_set_flash_and_redirect('Choose a valid assignment type.');
}
if ($dueDate === null) {
    chore_set_flash_and_redirect('Enter a valid due date.');
}
if ($categoryId < 1 || !chore_category_is_valid($choreConnection, $choreHouseholdId, $categoryId)) {
    chore_set_flash_and_redirect('Invalid category.');
}

if ($assignmentType === 'Single') {
    if ($assignedUserId < 1 || !chore_member_is_valid($choreConnection, $choreHouseholdId, $assignedUserId)) {
        chore_set_flash_and_redirect('Invalid household member.');
    }
    $rotationMembers = [];
} else {
    if ($rotationMembers === []) {
        chore_set_flash_and_redirect('Select at least one household member for the rotation.');
    }
    foreach ($rotationMembers as $memberId) {
        if (!chore_member_is_valid($choreConnection, $choreHouseholdId, $memberId)) {
            chore_set_flash_and_redirect('Invalid household member.');
        }
    }
}

try {
    $choreConnection->beginTransaction();

    $insertChore = $choreConnection->prepare(
        'INSERT INTO choresTb
            (household_id, category_id, chore_name, description, frequency, assignment_type, created_by)
         VALUES
            (:household_id, :category_id, :chore_name, :description, :frequency, :assignment_type, :created_by)'
    );
    $insertChore->execute([
        'household_id' => $choreHouseholdId,
        'category_id' => $categoryId,
        'chore_name' => $name,
        'description' => $description !== '' ? $description : null,
        'frequency' => $frequency,
        'assignment_type' => $assignmentType,
        'created_by' => $choreUserId,
    ]);
    $choreId = (int) $choreConnection->lastInsertId();

    if ($assignmentType === 'Single') {
        $insertAssignment = $choreConnection->prepare(
            'INSERT INTO chore_assignmentsTb (chore_id, user_id, due_date, status, completed_at)
             VALUES (:chore_id, :user_id, :due_date, \'Pending\', NULL)'
        );
        $insertAssignment->execute([
            'chore_id' => $choreId,
            'user_id' => $assignedUserId,
            'due_date' => $dueDate,
        ]);
    } else {
        $insertRotation = $choreConnection->prepare(
            'INSERT INTO chore_rotationTb (chore_id, user_id, rotation_order)
             VALUES (:chore_id, :user_id, :rotation_order)'
        );
        foreach ($rotationMembers as $index => $memberId) {
            $insertRotation->execute([
                'chore_id' => $choreId,
                'user_id' => $memberId,
                'rotation_order' => $index + 1,
            ]);
        }

        $insertAssignment = $choreConnection->prepare(
            'INSERT INTO chore_assignmentsTb (chore_id, user_id, due_date, status, completed_at)
             VALUES (:chore_id, :user_id, :due_date, \'Pending\', NULL)'
        );
        $insertAssignment->execute([
            'chore_id' => $choreId,
            'user_id' => $rotationMembers[0],
            'due_date' => $dueDate,
        ]);
    }

    $choreConnection->commit();
    chore_set_flash_and_redirect('Chore created successfully.');
} catch (Throwable $error) {
    if ($choreConnection->inTransaction()) {
        $choreConnection->rollBack();
    }
    error_log('RoomieSync chore creation failed: ' . $error->getMessage());
    chore_set_flash_and_redirect('Unable to create chore.');
}
