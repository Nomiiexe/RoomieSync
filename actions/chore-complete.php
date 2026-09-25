<?php
declare(strict_types=1);

require_once __DIR__ . '/chore-bootstrap.php';

$rawAssignmentId = $_POST['assignment_id'] ?? null;
$assignmentId = is_string($rawAssignmentId) && ctype_digit($rawAssignmentId) ? (int) $rawAssignmentId : 0;
if ($assignmentId < 1) {
    chore_set_flash_and_redirect('The chore assignment could not be found.');
}

$isAdmin = chore_is_admin($choreConnection, $choreUserId, $choreHouseholdId);

try {
    $choreConnection->beginTransaction();

    $assignmentQuery = $choreConnection->prepare(
        'SELECT ca.assignment_id, ca.user_id, ca.due_date, ca.status,
                c.chore_id, c.frequency, c.assignment_type
         FROM chore_assignmentsTb AS ca
         INNER JOIN choresTb AS c ON c.chore_id = ca.chore_id
         WHERE ca.assignment_id = :assignment_id AND c.household_id = :household_id
         LIMIT 1
         FOR UPDATE'
    );
    $assignmentQuery->execute([
        'assignment_id' => $assignmentId,
        'household_id' => $choreHouseholdId,
    ]);
    $assignment = $assignmentQuery->fetch();

    if (!is_array($assignment)) {
        $choreConnection->rollBack();
        chore_set_flash_and_redirect('The chore assignment could not be found.');
    }
    if (strcasecmp((string) $assignment['status'], 'completed') === 0) {
        $choreConnection->rollBack();
        chore_set_flash_and_redirect('This chore is already completed.');
    }
    if (!$isAdmin && (int) $assignment['user_id'] !== $choreUserId) {
        $choreConnection->rollBack();
        chore_set_flash_and_redirect('You do not have permission to complete this chore.');
    }

    $markCompleted = $choreConnection->prepare(
        "UPDATE chore_assignmentsTb
         SET status = 'Completed', completed_at = NOW()
         WHERE assignment_id = :assignment_id"
    );
    $markCompleted->execute(['assignment_id' => $assignmentId]);

    if (strcasecmp((string) $assignment['assignment_type'], 'Rotation') === 0) {
        $rotationQuery = $choreConnection->prepare(
            'SELECT user_id, rotation_order
             FROM chore_rotationTb
             WHERE chore_id = :chore_id
             ORDER BY rotation_order ASC'
        );
        $rotationQuery->execute(['chore_id' => (int) $assignment['chore_id']]);
        $rotation = $rotationQuery->fetchAll();

        if ($rotation !== []) {
            $currentIndex = null;
            foreach ($rotation as $index => $rotationMember) {
                if ((int) $rotationMember['user_id'] === (int) $assignment['user_id']) {
                    $currentIndex = $index;
                    break;
                }
            }

            if ($currentIndex !== null) {
                $nextMember = $rotation[($currentIndex + 1) % count($rotation)];
                $nextDueDate = chore_next_due_date(
                    (string) $assignment['frequency'],
                    (string) $assignment['due_date']
                );
                if ($nextDueDate !== null) {
                    $insertNext = $choreConnection->prepare(
                        "INSERT INTO chore_assignmentsTb
                            (chore_id, user_id, due_date, status, completed_at)
                         VALUES (:chore_id, :user_id, :due_date, 'Pending', NULL)"
                    );
                    $insertNext->execute([
                        'chore_id' => (int) $assignment['chore_id'],
                        'user_id' => (int) $nextMember['user_id'],
                        'due_date' => $nextDueDate,
                    ]);
                }
            }
        }
    }

    $choreConnection->commit();
    chore_set_flash_and_redirect('Chore marked as completed.', '../chores.php?tab=history');
} catch (Throwable $error) {
    if ($choreConnection->inTransaction()) {
        $choreConnection->rollBack();
    }
    error_log('RoomieSync chore completion failed: ' . $error->getMessage());
    chore_set_flash_and_redirect('Unable to complete chore.');
}
