<?php
declare(strict_types=1);

require_once __DIR__ . '/chore-bootstrap.php';

$action = is_string($_POST['bulk_action'] ?? null) ? trim($_POST['bulk_action']) : '';
$rawIds = $_POST['selected_ids'] ?? [];
$selectedIds = [];
if (is_array($rawIds)) {
    foreach ($rawIds as $id) {
        if ((is_int($id) || is_string($id)) && ctype_digit((string) $id) && (int) $id > 0) {
            $selectedIds[] = (int) $id;
        }
    }
}
$selectedIds = array_values(array_unique($selectedIds));

$tab = is_string($_POST['tab'] ?? null) ? trim($_POST['tab']) : 'my';
$search = is_string($_POST['search'] ?? null) ? trim($_POST['search']) : '';
$statusFilter = is_string($_POST['status'] ?? null) ? trim($_POST['status']) : 'all';

$redirectUrl = '../chores.php?tab=' . rawurlencode($tab);
if ($search !== '') {
    $redirectUrl .= '&search=' . rawurlencode($search);
}
if ($statusFilter !== 'all') {
    $redirectUrl .= '&status=' . rawurlencode($statusFilter);
}

if ($selectedIds === []) {
    chore_set_flash_and_redirect('Please select at least one chore record.', $redirectUrl);
}

$isAdmin = chore_is_admin($choreConnection, $choreUserId, $choreHouseholdId);

if ($action === 'complete') {
    $completedCount = 0;
    try {
        $choreConnection->beginTransaction();

        $inPlaceholders = implode(',', array_fill(0, count($selectedIds), '?'));
        
        // Fetch all matching uncompleted assignments belonging to this household
        $querySql = "
            SELECT ca.assignment_id, ca.user_id, ca.due_date, ca.status,
                   c.chore_id, c.frequency, c.assignment_type
            FROM chore_assignmentsTb AS ca
            INNER JOIN choresTb AS c ON c.chore_id = ca.chore_id
            WHERE ca.assignment_id IN ({$inPlaceholders})
              AND c.household_id = ?
              AND LOWER(ca.status) <> 'completed'
            FOR UPDATE
        ";
        $params = array_merge($selectedIds, [$choreHouseholdId]);
        $stmt = $choreConnection->prepare($querySql);
        $stmt->execute($params);
        $assignments = $stmt->fetchAll();

        foreach ($assignments as $assignment) {
            $assignmentId = (int) $assignment['assignment_id'];
            $assignedUserId = (int) $assignment['user_id'];

            // Roommates can only complete assignments assigned to themselves
            if (!$isAdmin && $assignedUserId !== $choreUserId) {
                continue;
            }

            // Mark as completed
            $update = $choreConnection->prepare(
                "UPDATE chore_assignmentsTb
                 SET status = 'Completed', completed_at = NOW()
                 WHERE assignment_id = ?"
            );
            $update->execute([$assignmentId]);

            // Handle rotation if applicable
            if (strcasecmp((string) $assignment['assignment_type'], 'Rotation') === 0) {
                $rotStmt = $choreConnection->prepare(
                    "SELECT user_id, rotation_order
                     FROM chore_rotationTb
                     WHERE chore_id = ?
                     ORDER BY rotation_order ASC"
                );
                $rotStmt->execute([(int) $assignment['chore_id']]);
                $rotation = $rotStmt->fetchAll();

                if ($rotation !== []) {
                    $currentIndex = null;
                    foreach ($rotation as $idx => $rMember) {
                        if ((int) $rMember['user_id'] === $assignedUserId) {
                            $currentIndex = $idx;
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
                                 VALUES (?, ?, ?, 'Pending', NULL)"
                            );
                            $insertNext->execute([
                                (int) $assignment['chore_id'],
                                (int) $nextMember['user_id'],
                                $nextDueDate,
                            ]);
                        }
                    }
                }
            }

            $completedCount++;
        }

        $choreConnection->commit();

        if ($completedCount > 0) {
            $word = $completedCount === 1 ? 'chore' : 'chores';
            chore_set_flash_and_redirect("{$completedCount} {$word} marked as completed.", $redirectUrl);
        } else {
            // Check if items were already completed
            $checkAll = $choreConnection->prepare(
                "SELECT COUNT(*) FROM chore_assignmentsTb WHERE assignment_id IN ({$inPlaceholders}) AND LOWER(status) = 'completed'"
            );
            $checkAll->execute($selectedIds);
            $alreadyCompleted = (int) $checkAll->fetchColumn();
            if ($alreadyCompleted > 0) {
                chore_set_flash_and_redirect('Selected chore(s) are already marked as completed.', $redirectUrl);
            } else {
                chore_set_flash_and_redirect("No eligible chores were completed. You do not have permission to mark other members' chores as done.", $redirectUrl);
            }
        }
    } catch (Throwable $e) {
        if ($choreConnection->inTransaction()) {
            $choreConnection->rollBack();
        }
        error_log('Chore bulk complete error: ' . $e->getMessage());
        chore_set_flash_and_redirect('Failed to complete selected chores.', $redirectUrl);
    }
} elseif ($action === 'uncomplete') {
    $uncompletedCount = 0;
    try {
        $choreConnection->beginTransaction();

        $inPlaceholders = implode(',', array_fill(0, count($selectedIds), '?'));
        
        // Fetch all matching completed assignments belonging to this household
        $querySql = "
            SELECT ca.assignment_id, ca.user_id, ca.status
            FROM chore_assignmentsTb AS ca
            INNER JOIN choresTb AS c ON c.chore_id = ca.chore_id
            WHERE ca.assignment_id IN ({$inPlaceholders})
              AND c.household_id = ?
              AND LOWER(ca.status) = 'completed'
            FOR UPDATE
        ";
        $params = array_merge($selectedIds, [$choreHouseholdId]);
        $stmt = $choreConnection->prepare($querySql);
        $stmt->execute($params);
        $assignments = $stmt->fetchAll();

        foreach ($assignments as $assignment) {
            $assignmentId = (int) $assignment['assignment_id'];
            $assignedUserId = (int) $assignment['user_id'];

            if (!$isAdmin && $assignedUserId !== $choreUserId) {
                continue;
            }

            $update = $choreConnection->prepare(
                "UPDATE chore_assignmentsTb
                 SET status = 'Pending', completed_at = NULL
                 WHERE assignment_id = ?"
            );
            $update->execute([$assignmentId]);
            $uncompletedCount++;
        }

        $choreConnection->commit();

        if ($uncompletedCount > 0) {
            $word = $uncompletedCount === 1 ? 'chore' : 'chores';
            chore_set_flash_and_redirect("{$uncompletedCount} {$word} marked as incomplete (Pending).", $redirectUrl);
        } else {
            chore_set_flash_and_redirect("No eligible completed chores were updated.", $redirectUrl);
        }
    } catch (Throwable $e) {
        if ($choreConnection->inTransaction()) {
            $choreConnection->rollBack();
        }
        error_log('Chore bulk uncomplete error: ' . $e->getMessage());
        chore_set_flash_and_redirect('Failed to update selected chores.', $redirectUrl);
    }
} elseif ($action === 'delete') {
    chore_action_require_admin($choreConnection, $choreUserId, $choreHouseholdId);

    try {
        $choreConnection->beginTransaction();

        $inPlaceholders = implode(',', array_fill(0, count($selectedIds), '?'));
        // Support IDs being either chore_id directly or assignment_id from the My Chores view
        $stmt = $choreConnection->prepare(
            "DELETE FROM choresTb
             WHERE (
                 chore_id IN ({$inPlaceholders})
                 OR chore_id IN (
                     SELECT chore_id FROM chore_assignmentsTb WHERE assignment_id IN ({$inPlaceholders})
                 )
             )
             AND household_id = ?"
        );
        $params = array_merge($selectedIds, $selectedIds, [$choreHouseholdId]);
        $stmt->execute($params);
        $deletedCount = $stmt->rowCount();

        $choreConnection->commit();
        $word = $deletedCount === 1 ? 'chore' : 'chores';
        chore_set_flash_and_redirect("{$deletedCount} {$word} deleted successfully.", $redirectUrl);
    } catch (Throwable $e) {
        if ($choreConnection->inTransaction()) {
            $choreConnection->rollBack();
        }
        error_log('Chore bulk delete error: ' . $e->getMessage());
        chore_set_flash_and_redirect('Failed to delete selected chores.', $redirectUrl);
    }
} elseif ($action === 'delete_history') {
    chore_action_require_admin($choreConnection, $choreUserId, $choreHouseholdId);

    try {
        $choreConnection->beginTransaction();

        $inPlaceholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $stmt = $choreConnection->prepare(
            "DELETE FROM chore_assignmentsTb
             WHERE assignment_id IN ({$inPlaceholders})
               AND chore_id IN (
                   SELECT chore_id FROM choresTb WHERE household_id = ?
               )"
        );
        $params = array_merge($selectedIds, [$choreHouseholdId]);
        $stmt->execute($params);
        $deletedCount = $stmt->rowCount();

        $choreConnection->commit();
        $word = $deletedCount === 1 ? 'history record' : 'history records';
        chore_set_flash_and_redirect("{$deletedCount} {$word} deleted successfully.", $redirectUrl);
    } catch (Throwable $e) {
        if ($choreConnection->inTransaction()) {
            $choreConnection->rollBack();
        }
        error_log('Chore history bulk delete error: ' . $e->getMessage());
        chore_set_flash_and_redirect('Failed to delete history records.', $redirectUrl);
    }
} else {
    chore_set_flash_and_redirect('Unknown bulk action specified.', $redirectUrl);
}
