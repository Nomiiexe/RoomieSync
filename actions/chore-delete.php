<?php
declare(strict_types=1);

require_once __DIR__ . '/chore-bootstrap.php';

chore_action_require_admin($choreConnection, $choreUserId, $choreHouseholdId);

$rawChoreId = $_POST['chore_id'] ?? null;
$choreId = is_string($rawChoreId) && ctype_digit($rawChoreId) ? (int) $rawChoreId : 0;
if ($choreId < 1) {
    chore_set_flash_and_redirect('The chore could not be found.');
}

$delete = $choreConnection->prepare(
    'DELETE FROM choresTb
     WHERE chore_id = :chore_id AND household_id = :household_id'
);
$delete->execute(['chore_id' => $choreId, 'household_id' => $choreHouseholdId]);

if ($delete->rowCount() < 1) {
    chore_set_flash_and_redirect('The chore could not be found.');
}

chore_set_flash_and_redirect('Chore deleted successfully.');
