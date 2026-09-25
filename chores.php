<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/chores.php';

if (!restore_remembered_login()) {
    redirect_to('login.php');
}

$userId = (int) $_SESSION['user_id'];
$householdId = selected_household_id();
if ($householdId === null) {
    redirect_to('households.php');
}

$pageError = '';
$connection = null;
$context = null;
$userEmail = '';
$categories = [];
$members = [];
$chores = [];
$rotationChores = [];
$history = [];
$rotationByChore = [];

$tab = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : 'my';
$tab = in_array($tab, ['my', 'rotation', 'history'], true) ? $tab : 'my';
$search = trim(is_string($_GET['search'] ?? null) ? $_GET['search'] : '');
$search = substr($search, 0, 100);
$statusFilter = is_string($_GET['status'] ?? null) ? strtolower($_GET['status']) : 'all';
$statusFilter = in_array($statusFilter, ['all', 'pending', 'due soon', 'completed', 'overdue'], true)
    ? $statusFilter
    : 'all';
$notice = consume_flash_message();

try {
    $connection = chores_pdo();
    $context = chore_context($connection, $userId, $householdId);

    $userEmailQuery = $connection->prepare(
        'SELECT email FROM userTb WHERE user_id = :user_id LIMIT 1'
    );
    $userEmailQuery->execute(['user_id' => $userId]);
    $userEmail = (string) ($userEmailQuery->fetchColumn() ?: '');

    if ($context === null) {
        unset($_SESSION['household_id']);
        redirect_to('households.php');
    }

    $categoryQuery = $connection->prepare(
        'SELECT chore_category_id, category_name
         FROM chore_categoriesTb
         WHERE household_id = :household_id
         ORDER BY category_name ASC'
    );
    $categoryQuery->execute(['household_id' => $householdId]);
    $categories = $categoryQuery->fetchAll();

    $memberQuery = $connection->prepare(
        "SELECT u.user_id, u.full_name
         FROM householdmembersTb AS hm
         INNER JOIN userTb AS u ON u.user_id = hm.user_id
         WHERE hm.household_id = :household_id AND LOWER(u.status) = 'active'
         ORDER BY u.full_name ASC"
    );
    $memberQuery->execute(['household_id' => $householdId]);
    $members = $memberQuery->fetchAll();

    $searchClause = '';
    $searchParams = ['household_id' => $householdId];
    if ($search !== '') {
        $searchClause = "
            AND (c.chore_name LIKE :search_chore
                 OR COALESCE(assigned_user.full_name, '') LIKE :search_member
                 OR COALESCE(cat.category_name, '') LIKE :search_category)";
        $searchParams['search_chore'] = '%' . $search . '%';
        $searchParams['search_member'] = '%' . $search . '%';
        $searchParams['search_category'] = '%' . $search . '%';
    }

    if ($tab === 'my') {
        $choreQuery = $connection->prepare(
            "SELECT c.chore_id, c.category_id, c.chore_name, c.description, c.frequency, c.assignment_type,
                    cat.category_name, ca.assignment_id, ca.user_id AS assigned_user_id,
                    assigned_user.full_name AS assigned_name, ca.due_date, ca.status
             FROM choresTb AS c
             LEFT JOIN chore_categoriesTb AS cat
                    ON cat.chore_category_id = c.category_id
                   AND cat.household_id = c.household_id
             LEFT JOIN chore_assignmentsTb AS ca
                    ON ca.assignment_id = (
                        SELECT ca2.assignment_id
                        FROM chore_assignmentsTb AS ca2
                        WHERE ca2.chore_id = c.chore_id
                          AND LOWER(ca2.status) <> 'completed'
                        ORDER BY ca2.assignment_id DESC
                        LIMIT 1
                    )
             LEFT JOIN userTb AS assigned_user ON assigned_user.user_id = ca.user_id
             WHERE c.household_id = :household_id
               AND (
                   ca.assignment_id IS NOT NULL
                   OR NOT EXISTS (
                       SELECT 1
                       FROM chore_assignmentsTb AS any_assignment
                       WHERE any_assignment.chore_id = c.chore_id
                   )
               ){$searchClause}
             ORDER BY CASE WHEN ca.due_date IS NULL THEN 1 ELSE 0 END,
                      ca.due_date ASC, c.chore_name ASC"
        );
        $choreQuery->execute($searchParams);
        $allChores = $choreQuery->fetchAll();

        foreach ($allChores as $chore) {
            $chore['display_status'] = chore_status_label(
                isset($chore['status']) ? (string) $chore['status'] : null,
                isset($chore['due_date']) ? (string) $chore['due_date'] : null
            );
            if ($statusFilter !== 'all' && strtolower((string) $chore['display_status']) !== $statusFilter) {
                continue;
            }
            $chores[] = $chore;
        }
    } elseif ($tab === 'rotation') {
        $rotationSearchClause = '';
        $rotationParams = ['household_id' => $householdId];
        if ($search !== '') {
            $rotationSearchClause = "
               AND (c.chore_name LIKE :search_chore
                    OR COALESCE(assignee_user.full_name, '') LIKE :search_member
                    OR COALESCE(cat.category_name, '') LIKE :search_category)";
            $rotationParams['search_chore'] = '%' . $search . '%';
            $rotationParams['search_member'] = '%' . $search . '%';
            $rotationParams['search_category'] = '%' . $search . '%';
        }
        $rotationQuery = $connection->prepare(
            "SELECT c.chore_id, c.chore_name, c.frequency, c.assignment_type,
                    cat.category_name, ca.user_id AS current_user_id, ca.due_date AS next_due,
                    assignee_user.full_name AS current_name,
                    r.user_id AS rotation_user_id, r.rotation_order,
                    rotation_user.full_name AS rotation_name
             FROM choresTb AS c
             LEFT JOIN chore_categoriesTb AS cat
                    ON cat.chore_category_id = c.category_id
                   AND cat.household_id = c.household_id
             LEFT JOIN chore_assignmentsTb AS ca
                    ON ca.assignment_id = (
                        SELECT ca2.assignment_id
                        FROM chore_assignmentsTb AS ca2
                        WHERE ca2.chore_id = c.chore_id
                          AND LOWER(ca2.status) <> 'completed'
                        ORDER BY ca2.assignment_id DESC
                        LIMIT 1
                    )
             LEFT JOIN userTb AS assignee_user ON assignee_user.user_id = ca.user_id
             LEFT JOIN chore_rotationTb AS r ON r.chore_id = c.chore_id
             LEFT JOIN userTb AS rotation_user ON rotation_user.user_id = r.user_id
             WHERE c.household_id = :household_id
               AND c.assignment_type = 'Rotation'{$rotationSearchClause}
             ORDER BY c.chore_name ASC, r.rotation_order ASC"
        );
        $rotationQuery->execute($rotationParams);
        foreach ($rotationQuery->fetchAll() as $row) {
            $choreId = (int) $row['chore_id'];
            if (!isset($rotationChores[$choreId])) {
                $rotationChores[$choreId] = [
                    'chore_id' => $choreId,
                    'chore_name' => (string) $row['chore_name'],
                    'frequency' => (string) $row['frequency'],
                    'category_name' => (string) ($row['category_name'] ?? ''),
                    'current_user_id' => $row['current_user_id'],
                    'current_name' => $row['current_name'],
                    'next_due' => $row['next_due'],
                    'members' => [],
                ];
            }
            if ($row['rotation_user_id'] !== null) {
                $rotationChores[$choreId]['members'][] = [
                    'user_id' => (int) $row['rotation_user_id'],
                    'rotation_order' => (int) $row['rotation_order'],
                    'full_name' => (string) $row['rotation_name'],
                ];
            }
        }
        $rotationChores = array_values($rotationChores);
    } else {
        $historyParams = ['household_id' => $householdId];
        $historySearch = '';
        if ($search !== '') {
            $historySearch = " AND (c.chore_name LIKE :search_chore OR u.full_name LIKE :search_member)";
            $historyParams['search_chore'] = '%' . $search . '%';
            $historyParams['search_member'] = '%' . $search . '%';
        }
        $historyQuery = $connection->prepare(
            "SELECT c.chore_name, u.full_name AS completed_by, ca.completed_at, ca.status
             FROM chore_assignmentsTb AS ca
             INNER JOIN choresTb AS c ON c.chore_id = ca.chore_id
             INNER JOIN userTb AS u ON u.user_id = ca.user_id
             WHERE c.household_id = :household_id
               AND LOWER(ca.status) = 'completed'
               AND ca.completed_at IS NOT NULL{$historySearch}
             ORDER BY ca.completed_at DESC"
        );
        $historyQuery->execute($historyParams);
        $history = $historyQuery->fetchAll();
    }

    $rotationConfigQuery = $connection->prepare(
        'SELECT r.chore_id, r.user_id, r.rotation_order
         FROM chore_rotationTb AS r
         INNER JOIN choresTb AS c ON c.chore_id = r.chore_id
         WHERE c.household_id = :household_id
         ORDER BY r.chore_id, r.rotation_order'
    );
    $rotationConfigQuery->execute(['household_id' => $householdId]);
    foreach ($rotationConfigQuery->fetchAll() as $rotationMember) {
        $rotationByChore[(int) $rotationMember['chore_id']][] = (int) $rotationMember['user_id'];
    }
} catch (Throwable $error) {
    error_log('RoomieSync chores page failed: ' . $error->getMessage());
    $pageError = 'The chores page is temporarily unavailable.';
}

$householdName = $context ? (string) $context['household_name'] : '';
$role = $context ? (string) $context['role'] : '';
$isAdmin = strcasecmp($role, 'Admin') === 0;
$displayName = (string) ($_SESSION['username'] ?? $_SESSION['full_name'] ?? '');
$avatarText = strtoupper(substr($displayName, 0, 2)) ?: '?';
$queryString = $search !== '' ? '&search=' . rawurlencode($search) : '';
$defaultDueDate = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Chores | RoomieSync</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="assets/css/chores.css">
</head>
<body class="d-flex flex-column min-vh-100">
  <div class="container rs-navbar-wrapper">
    <nav class="rs-pill-nav">
      <a href="dashboard.php" class="rs-brand">
        <div class="rs-brand-icon"><i class="bi bi-house-heart-fill"></i></div>
        <span>RoomieSync</span>
      </a>
      <button class="btn btn-rs-outline rs-mobile-toggle d-lg-none py-1 px-2" type="button" id="mobileNavToggle" aria-label="Toggle navigation"><i class="bi bi-list fs-5"></i></button>
      <ul class="rs-nav-links" id="navLinksContainer">
        <li><a href="dashboard.php" class="rs-nav-link"><i class="bi bi-grid-1x2-fill"></i><span>Dashboard</span></a></li>
        <li><a href="chores.php" class="rs-nav-link active"><i class="bi bi-check2-square"></i><span>Chores</span></a></li>
        <li><a href="#" class="rs-nav-link" aria-disabled="true" title="Bills page coming soon"><i class="bi bi-receipt"></i><span>Bills</span></a></li>
        <li><a href="#" class="rs-nav-link" aria-disabled="true" title="Household page coming soon"><i class="bi bi-house"></i><span>Household</span></a></li>
        <li><a href="#" class="rs-nav-link" aria-disabled="true" title="Reports page coming soon"><i class="bi bi-bar-chart"></i><span>Reports</span></a></li>
        <?php if ($isAdmin): ?><li><a href="#" class="rs-nav-link" aria-disabled="true" title="Manage page coming soon"><i class="bi bi-sliders"></i><span>Manage</span></a></li><?php endif; ?>
      </ul>
      <div class="rs-nav-user dropdown">
        <a href="#" class="rs-user-btn dropdown-toggle" role="button" data-bs-toggle="dropdown" aria-expanded="false">
          <div class="rs-avatar"><?= h($avatarText) ?></div>
          <span class="d-none d-md-inline"><?= h($displayName) ?></span>
          <?php if ($role !== ''): ?><span class="role-badge roommate d-none d-xl-inline"><?= h($role) ?></span><?php endif; ?>
        </a>
        <ul class="dropdown-menu dropdown-menu-end rs-dropdown">
          <li class="px-3 py-2 border-bottom mb-1">
            <div class="fw-bold text-dark-accent"><?= h($displayName) ?></div>
            <?php if ($userEmail !== ''): ?>
              <small class="text-secondary-custom"><?= h($userEmail) ?></small>
            <?php else: ?>
              <small class="text-secondary-custom">No email on profile</small>
            <?php endif; ?>
            <?php if ($role !== ''): ?><div class="mt-1"><span class="role-badge roommate"><?= h($role) ?></span></div><?php endif; ?>
          </li>
          <li><a class="dropdown-item rs-dropdown-item" href="profile.php"><i class="bi bi-person-circle"></i> Profile</a></li>
          <li><a class="dropdown-item rs-dropdown-item" href="households.php"><i class="bi bi-arrow-left-right"></i> Switch Household</a></li>
          <li><a class="dropdown-item rs-dropdown-item" href="join-household.php"><i class="bi bi-door-open"></i> Join Another Home</a></li>
          <li><hr class="dropdown-divider rs-dropdown-divider"></li>
          <li><a class="dropdown-item rs-dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a></li>
        </ul>
      </div>
    </nav>
  </div>

  <main class="container py-4 py-lg-5 flex-grow-1">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
      <div>
        <p class="rs-eyebrow mb-2"><?= h($householdName) ?></p>
        <h1 class="display-6 fw-bold mb-2">Chores</h1>
        <p class="text-secondary-custom mb-0">Keep our home clean, comfortable, and running smoothly.</p>
      </div>
      <?php if ($isAdmin): ?>
        <div class="d-flex align-items-center gap-2">
          <button type="button" class="btn btn-rs-primary" data-bs-toggle="modal" data-bs-target="#addChoreModal"><i class="bi bi-plus-lg me-1"></i> Add Chore</button>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($notice): ?><div class="alert alert-success" role="status"><?= h((string) ($notice['message'] ?? '')) ?></div><?php endif; ?>
    <?php if ($pageError !== ''): ?><div class="alert alert-danger" role="alert"><?= h($pageError) ?></div><?php endif; ?>

    <div class="rs-card p-3 p-md-4">
      <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
        <nav class="nav nav-pills chore-tabs" aria-label="Chore sections">
          <a class="nav-link <?= $tab === 'my' ? 'active' : '' ?>" href="chores.php?tab=my<?= $queryString ?>">My Chores</a>
          <a class="nav-link <?= $tab === 'rotation' ? 'active' : '' ?>" href="chores.php?tab=rotation<?= $queryString ?>">Rotation</a>
          <a class="nav-link <?= $tab === 'history' ? 'active' : '' ?>" href="chores.php?tab=history<?= $queryString ?>">History</a>
        </nav>
        <form action="chores.php" method="GET" class="d-flex flex-column flex-sm-row gap-2 chore-toolbar">
          <input type="hidden" name="tab" value="<?= h($tab) ?>">
          <div class="input-group chore-search">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" class="form-control" name="search" value="<?= h($search) ?>" placeholder="Search chores, members, categories" aria-label="Search chores">
          </div>
          <?php if ($tab === 'my'): ?>
            <select class="form-select chore-filter" name="status" aria-label="Filter chores by status">
              <?php foreach (['all' => 'All', 'pending' => 'Pending', 'due soon' => 'Due Soon', 'completed' => 'Completed', 'overdue' => 'Overdue'] as $value => $label): ?>
                <option value="<?= h($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
          <button class="btn btn-rs-outline" type="submit">Filter</button>
        </form>
      </div>

      <?php if ($tab === 'my'): ?>
        <div class="table-responsive" id="choresTable">
          <table class="table align-middle chore-table mb-0">
            <thead><tr><th>Chore</th><th>Assigned To</th><th>Due Date</th><th>Frequency</th><th>Status</th><?php if ($isAdmin): ?><th class="text-end">Manage</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($chores as $chore): ?>
              <?php $displayStatus = (string) $chore['display_status']; $choreId = (int) $chore['chore_id']; $selectedRotation = $rotationByChore[$choreId] ?? []; ?>
              <tr>
                <td data-label="Chore"><div class="fw-semibold text-main"><?= h((string) $chore['chore_name']) ?></div><?php if ((string) ($chore['description'] ?? '') !== ''): ?><small class="text-secondary-custom"><?= h((string) $chore['description']) ?></small><?php endif; ?></td>
                <td data-label="Assigned To"><?= h((string) ($chore['assigned_name'] ?? 'Unassigned')) ?></td>
                <td data-label="Due Date"><?= h(chore_display_date(isset($chore['due_date']) ? (string) $chore['due_date'] : null)) ?></td>
                <td data-label="Frequency"><?= h((string) $chore['frequency']) ?></td>
                <td data-label="Status"><span class="chore-status <?= h(chore_status_class($displayStatus)) ?>"><span class="status-dot"></span><?= h($displayStatus) ?></span></td>
                <?php if ($isAdmin): ?><td data-label="Manage" class="text-end"><div class="d-flex justify-content-end gap-1"><button class="btn btn-sm btn-rs-outline" type="button" data-bs-toggle="modal" data-bs-target="#editChoreModal<?= $choreId ?>" aria-label="Edit <?= h((string) $chore['chore_name']) ?>"><i class="bi bi-pencil"></i></button><button class="btn btn-sm btn-rs-outline text-danger chore-delete-trigger" type="button" data-bs-toggle="modal" data-bs-target="#deleteChoreModal" data-chore-id="<?= $choreId ?>" data-chore-name="<?= h((string) $chore['chore_name']) ?>" aria-label="Delete <?= h((string) $chore['chore_name']) ?>"><i class="bi bi-trash3"></i></button></div></td><?php endif; ?>
              </tr>
              <?php if (!$isAdmin && (int) ($chore['assigned_user_id'] ?? 0) === $userId && strtolower((string) ($chore['status'] ?? '')) !== 'completed'): ?>
                <tr class="chore-complete-row"><td colspan="<?= $isAdmin ? '6' : '5' ?>"><form action="actions/chore-complete.php" method="POST" class="d-flex justify-content-end align-items-center gap-2"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="assignment_id" value="<?= (int) $chore['assignment_id'] ?>"><span class="small text-secondary-custom me-auto">This chore is assigned to you.</span><button class="btn btn-sm btn-rs-primary" type="submit"><i class="bi bi-check2"></i> Mark Completed</button></form></td></tr>
              <?php elseif ($isAdmin && (int) ($chore['assigned_user_id'] ?? 0) > 0 && strtolower((string) ($chore['status'] ?? '')) !== 'completed'): ?>
                <tr class="chore-complete-row"><td colspan="6"><form action="actions/chore-complete.php" method="POST" class="d-flex justify-content-end"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="assignment_id" value="<?= (int) $chore['assignment_id'] ?>"><button class="btn btn-sm btn-rs-outline" type="submit"><i class="bi bi-check2"></i> Mark Completed</button></form></td></tr>
              <?php endif; ?>
              <?php if ($isAdmin): ?>
                <tr class="chore-modal-row"><td colspan="6"><div class="modal fade" id="editChoreModal<?= $choreId ?>" tabindex="-1" aria-labelledby="editChoreModalLabel<?= $choreId ?>" aria-hidden="true">
                  <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content rs-modal"><div class="modal-header rs-modal-header"><h2 class="modal-title h5" id="editChoreModalLabel<?= $choreId ?>">Edit Chore</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <form action="actions/chore-update.php" method="POST"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="chore_id" value="<?= $choreId ?>"><div class="modal-body rs-modal-body"><div class="row g-3"><div class="col-md-7"><label class="form-label" for="editName<?= $choreId ?>">Chore Name *</label><input class="form-control" id="editName<?= $choreId ?>" name="chore_name" maxlength="100" value="<?= h((string) $chore['chore_name']) ?>" required></div><div class="col-md-5"><label class="form-label" for="editCategory<?= $choreId ?>">Category *</label><select class="form-select" id="editCategory<?= $choreId ?>" name="category_id" required><?php foreach ($categories as $category): ?><option value="<?= (int) $category['chore_category_id'] ?>" <?= (int) $category['chore_category_id'] === (int) ($chore['category_id'] ?? 0) ? 'selected' : '' ?>><?= h((string) $category['category_name']) ?></option><?php endforeach; ?></select></div><div class="col-12"><label class="form-label" for="editDescription<?= $choreId ?>">Description</label><textarea class="form-control" id="editDescription<?= $choreId ?>" name="description" rows="2" maxlength="5000"><?= h((string) ($chore['description'] ?? '')) ?></textarea></div><div class="col-md-4"><label class="form-label" for="editFrequency<?= $choreId ?>">Frequency *</label><input class="form-control" id="editFrequency<?= $choreId ?>" name="frequency" maxlength="50" value="<?= h((string) $chore['frequency']) ?>" required></div><div class="col-md-4"><label class="form-label" for="editDueDate<?= $choreId ?>">Due Date *</label><input class="form-control" type="date" id="editDueDate<?= $choreId ?>" name="due_date" value="<?= h((string) ($chore['due_date'] ?? $defaultDueDate)) ?>" required></div><div class="col-md-4"><label class="form-label" for="editType<?= $choreId ?>">Assignment Type *</label><select class="form-select chore-assignment-type" data-target="editAssignmentFields<?= $choreId ?>" id="editType<?= $choreId ?>" name="assignment_type" required><option value="Single" <?= (string) $chore['assignment_type'] === 'Single' ? 'selected' : '' ?>>Single Assignment</option><option value="Rotation" <?= (string) $chore['assignment_type'] === 'Rotation' ? 'selected' : '' ?>>Rotation</option></select></div></div><div id="editAssignmentFields<?= $choreId ?>" class="mt-3"><?php if ((string) $chore['assignment_type'] === 'Single'): ?><label class="form-label" for="editAssigned<?= $choreId ?>">Assign To *</label><select class="form-select" id="editAssigned<?= $choreId ?>" name="assigned_user_id"><?php foreach ($members as $member): ?><option value="<?= (int) $member['user_id'] ?>" <?= (int) $member['user_id'] === (int) ($chore['assigned_user_id'] ?? 0) ? 'selected' : '' ?>><?= h((string) $member['full_name']) ?></option><?php endforeach; ?></select><?php endif; ?><div class="rotation-fields <?= (string) $chore['assignment_type'] === 'Rotation' ? '' : 'd-none' ?>"><label class="form-label">Rotate Between *</label><?php foreach ($members as $member): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="rotation_members[]" value="<?= (int) $member['user_id'] ?>" id="editRot<?= $choreId ?><?= (int) $member['user_id'] ?>" <?= in_array((int) $member['user_id'], $selectedRotation, true) ? 'checked' : '' ?>><label class="form-check-label" for="editRot<?= $choreId ?><?= (int) $member['user_id'] ?>"><?= h((string) $member['full_name']) ?></label></div><?php endforeach; ?></div></div></div><div class="modal-footer rs-modal-footer"><button type="button" class="btn btn-rs-outline" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-rs-primary"><i class="bi bi-check2"></i> Save Changes</button></div></form>
                  </div></div>
                </div></td></tr>
              <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($chores === []): ?><tr><td colspan="<?= $isAdmin ? '6' : '5' ?>" class="text-center py-5"><i class="bi bi-check2-square chore-empty-icon"></i><p class="fw-semibold mb-1">No chores found</p><p class="text-secondary-custom mb-0">Try another filter or add a chore for this household.</p></td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      <?php elseif ($tab === 'rotation'): ?>
        <div class="row g-3">
          <?php foreach ($rotationChores as $rotationChore): ?>
            <?php $memberNames = array_map(static fn (array $member): string => (string) $member['full_name'], $rotationChore['members']); $currentIndex = null; foreach ($rotationChore['members'] as $index => $member) { if ((int) $member['user_id'] === (int) $rotationChore['current_user_id']) { $currentIndex = $index; break; } } $nextMember = $rotationChore['members'] !== [] ? $rotationChore['members'][($currentIndex === null ? 0 : ($currentIndex + 1) % count($rotationChore['members']))] : null; ?>
            <div class="col-12"><article class="rotation-card"><div class="d-flex flex-column flex-md-row justify-content-between gap-3"><div><h2 class="h5 fw-bold mb-1"><?= h((string) $rotationChore['chore_name']) ?></h2><p class="small text-secondary-custom mb-0"><?= h((string) ($rotationChore['category_name'] ?: 'Uncategorized')) ?> · <?= h((string) $rotationChore['frequency']) ?></p></div><div class="text-md-end"><span class="small text-secondary-custom d-block">Next due</span><strong><?= h(chore_display_date($rotationChore['next_due'] !== null ? (string) $rotationChore['next_due'] : null)) ?></strong></div></div><div class="rotation-flow mt-3"><div><span class="rotation-label">Current</span><strong><?= h((string) ($rotationChore['current_name'] ?: 'Unassigned')) ?></strong></div><i class="bi bi-arrow-right text-dark-accent"></i><div><span class="rotation-label">Next</span><strong><?= h((string) ($nextMember['full_name'] ?? 'Unassigned')) ?></strong></div></div><div class="rotation-sequence mt-3"><span class="rotation-label">Stored rotation order</span><div class="d-flex flex-wrap gap-2"><?php foreach ($rotationChore['members'] as $member): ?><span class="rotation-chip"><?= (int) $member['rotation_order'] ?>. <?= h((string) $member['full_name']) ?></span><?php endforeach; ?></div></div></article></div>
          <?php endforeach; ?>
          <?php if ($rotationChores === []): ?><div class="col-12 text-center py-5"><i class="bi bi-arrow-repeat chore-empty-icon"></i><p class="fw-semibold mb-1">No rotation chores found</p><p class="text-secondary-custom mb-0">Rotation assignments for this household will appear here.</p></div><?php endif; ?>
        </div>
      <?php else: ?>
        <div class="table-responsive"><table class="table align-middle chore-table mb-0"><thead><tr><th>Chore</th><th>Completed By</th><th>Completed Date</th><th>Status</th></tr></thead><tbody><?php foreach ($history as $entry): ?><tr><td data-label="Chore" class="fw-semibold text-main"><?= h((string) $entry['chore_name']) ?></td><td data-label="Completed By"><?= h((string) $entry['completed_by']) ?></td><td data-label="Completed Date"><?= h(chore_display_date((string) $entry['completed_at'])) ?></td><td data-label="Status"><span class="chore-status rs-chore-status-completed"><span class="status-dot"></span>Completed</span></td></tr><?php endforeach; ?><?php if ($history === []): ?><tr><td colspan="4" class="text-center py-5"><i class="bi bi-clock-history chore-empty-icon"></i><p class="fw-semibold mb-1">No completed chores yet</p><p class="text-secondary-custom mb-0">Completed assignments will be kept here as history.</p></td></tr><?php endif; ?></tbody></table></div>
      <?php endif; ?>
    </div>
  </main>

  <?php if ($isAdmin): ?>
    <div class="modal fade" id="addChoreModal" tabindex="-1" aria-labelledby="addChoreModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl add-chore-dialog">
        <div class="modal-content rs-modal add-chore-modal">
          <form action="actions/chore-create.php" method="POST" id="addChoreForm">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <div class="modal-header rs-modal-header add-chore-modal-header">
              <div class="d-flex align-items-center gap-3">
                <div class="add-chore-icon"><i class="bi bi-list-check"></i></div>
                <div>
                  <h2 class="modal-title h3 mb-1" id="addChoreModalLabel">Add chore</h2>
                  <p class="text-secondary-custom mb-0">Create a new chore and set how it will be assigned.</p>
                </div>
              </div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body rs-modal-body add-chore-modal-body">
              <div class="add-chore-layout">
                <section class="add-chore-form-panel">
                  <div class="row g-3">
                    <div class="col-md-7">
                      <label class="form-label" for="choreName">Chore name<span class="required-mark">*</span></label>
                      <input class="form-control" id="choreName" name="chore_name" maxlength="100" placeholder="e.g. Take out trash" required>
                    </div>
                    <div class="col-md-5">
                      <label class="form-label" for="choreCategory">Category<span class="required-mark">*</span></label>
                      <select class="form-select" id="choreCategory" name="category_id" required>
                        <option value="">Choose category</option>
                        <?php foreach ($categories as $category): ?><option value="<?= (int) $category['chore_category_id'] ?>"><?= h((string) $category['category_name']) ?></option><?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-12">
                      <label class="form-label" for="choreDescription">Description <span class="optional-label">(optional)</span></label>
                      <textarea class="form-control" id="choreDescription" name="description" rows="2" maxlength="5000" placeholder="Add helpful details for your housemates"></textarea>
                    </div>
                  </div>

                  <fieldset class="mt-4">
                    <legend class="form-label mb-2">Assignment type<span class="required-mark">*</span></legend>
                    <div class="assignment-type-options">
                      <label class="assignment-type-card is-selected">
                        <input class="chore-assignment-type" type="radio" name="assignment_type" value="Single" data-target="addAssignmentFields" checked>
                        <span class="assignment-type-card-content"><strong>Single Assignment</strong><small>Assign to one person only</small></span>
                      </label>
                      <label class="assignment-type-card">
                        <input class="chore-assignment-type" type="radio" name="assignment_type" value="Rotation" data-target="addAssignmentFields">
                        <span class="assignment-type-card-content"><strong>Rotation</strong><small>Automatically rotate between members</small></span>
                      </label>
                    </div>
                  </fieldset>

                  <div id="addAssignmentFields" class="mt-4">
                    <div id="singleAssignmentField">
                      <label class="form-label" for="assignedUser">Assign to<span class="required-mark">*</span></label>
                      <select class="form-select" id="assignedUser" name="assigned_user_id">
                        <option value="">Choose household member</option>
                        <?php foreach ($members as $member): ?><option value="<?= (int) $member['user_id'] ?>"><?= h((string) $member['full_name']) ?></option><?php endforeach; ?>
                      </select>
                    </div>
                    <div id="rotationAssignmentField" class="rotation-fields d-none">
                      <label class="form-label">Rotate between<span class="required-mark">*</span></label>
                      <?php foreach ($members as $member): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="rotation_members[]" value="<?= (int) $member['user_id'] ?>" id="rotationMember<?= (int) $member['user_id'] ?>"><label class="form-check-label" for="rotationMember<?= (int) $member['user_id'] ?>"><?= h((string) $member['full_name']) ?></label></div><?php endforeach; ?>
                    </div>
                  </div>

                  <div class="row g-3 mt-1">
                    <div class="col-sm-6">
                      <label class="form-label" for="choreFrequency">Frequency<span class="required-mark">*</span></label>
                      <select class="form-select" id="choreFrequency" name="frequency" required>
                        <option value="">Choose frequency</option>
                        <option value="One-time">One-time</option>
                        <option value="Daily">Daily</option>
                        <option value="Weekly">Weekly</option>
                      </select>
                    </div>
                    <div class="col-sm-6">
                      <label class="form-label" for="choreDueDate">First due date<span class="required-mark">*</span></label>
                      <input class="form-control" type="date" id="choreDueDate" name="due_date" value="<?= h($defaultDueDate) ?>" required>
                    </div>
                  </div>
                </section>

                <aside class="assignment-summary" aria-live="polite">
                  <div class="assignment-summary-heading">
                    <div class="summary-heading-icon"><i class="bi bi-clipboard-check"></i></div>
                    <div><h3 class="h5 mb-1">Assignment Summary</h3><p class="text-secondary-custom mb-0">Here’s how this chore will be set up.</p></div>
                  </div>
                  <div class="assignment-summary-items">
                    <div class="assignment-summary-item"><div class="summary-item-icon"><i class="bi bi-person"></i></div><div><small>Assigned to</small><strong id="summaryAssigned">Choose a member</strong></div></div>
                    <div class="assignment-summary-item"><div class="summary-item-icon"><i class="bi bi-calendar3"></i></div><div><small>Due date</small><strong id="summaryDueDate"><?= h(date('F j, Y', strtotime($defaultDueDate))) ?></strong></div></div>
                    <div class="assignment-summary-item"><div class="summary-item-icon"><i class="bi bi-arrow-repeat"></i></div><div><small>Frequency</small><strong id="summaryFrequency">Not set</strong></div></div>
                  </div>
                </aside>
              </div>
            </div>
            <div class="modal-footer rs-modal-footer add-chore-modal-footer">
              <button type="button" class="btn btn-rs-outline add-chore-cancel" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-rs-primary add-chore-submit"><i class="bi bi-plus-lg"></i> Create Chore</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <div class="modal fade" id="deleteChoreModal" tabindex="-1" aria-labelledby="deleteChoreModalLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content rs-modal"><div class="modal-header rs-modal-header"><h2 class="modal-title h5" id="deleteChoreModalLabel">Delete Chore?</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><form action="actions/chore-delete.php" method="POST"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="chore_id" id="deleteChoreId"><div class="modal-body rs-modal-body"><p class="mb-0">Are you sure you want to delete <strong id="deleteChoreName">this chore</strong>?</p></div><div class="modal-footer rs-modal-footer"><button type="button" class="btn btn-rs-outline" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger"><i class="bi bi-trash3"></i> Delete Chore</button></div></form></div></div></div>
  <?php endif; ?>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/main.js"></script>
  <script src="assets/js/chores.js"></script>
</body>
</html>
