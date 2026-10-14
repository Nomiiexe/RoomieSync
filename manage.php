<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/db.php';

// Ensure user is signed in
if (!restore_remembered_login()) {
    redirect_to('login.php');
}

$userId = (int) $_SESSION['user_id'];
$householdId = selected_household_id();
if ($householdId === null) {
    redirect_to('households.php');
}

try {
    $connection = app_pdo();
} catch (Throwable $error) {
    error_log('RoomieSync manage page connection failed: ' . $error->getMessage());
    http_response_code(500);
    exit('The database is temporarily unavailable.');
}

// Authoritative Household & Role Verification
$contextQuery = $connection->prepare(
    'SELECT h.household_id, h.household_name, h.description, h.address, h.household_image, h.join_code, h.created_at, hm.role
     FROM householdmembersTb AS hm
     INNER JOIN householdTb AS h ON h.household_id = hm.household_id
     WHERE hm.household_id = ? AND hm.user_id = ?
     LIMIT 1'
);
$contextQuery->execute([$householdId, $userId]);
$household = $contextQuery->fetch();

if (!$household) {
    unset($_SESSION['household_id']);
    redirect_to('households.php');
}

$householdName = (string) $household['household_name'];
$role = (string) $household['role'];
$isAdmin = strcasecmp($role, 'Admin') === 0;

// User Profile Details for Navbar
$userQuery = $connection->prepare(
    'SELECT email, username, full_name FROM userTb WHERE user_id = ? LIMIT 1'
);
$userQuery->execute([$userId]);
$currentUser = $userQuery->fetch() ?: [];
$userEmail = (string) ($currentUser['email'] ?? '');
$username = (string) ($currentUser['username'] ?? ($_SESSION['username'] ?? ''));
$fullName = (string) ($currentUser['full_name'] ?? ($_SESSION['full_name'] ?? ''));
$displayName = $username !== '' ? $username : $fullName;

if (!function_exists('manage_initials')) {
    function manage_initials(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '?';
        }
        $parts = preg_split('/\s+/', $value) ?: [];
        if (count($parts) > 1) {
            return strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts) - 1], 0, 1));
        }
        return strtoupper(substr($value, 0, 2));
    }
}

$avatarText = manage_initials($displayName);

// ==================================================
// SERVER-SIDE ADMIN-ONLY ACCESS CHECK
// ==================================================
if (!$isAdmin) {
    // Normal Roommates must NOT be given access to admin-only management actions.
    // Display the access denied screen without the admin interface.
    ?>
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Manage Access Denied | RoomieSync</title>
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
      <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
      <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
      <link rel="stylesheet" href="assets/css/style.css">
      <link rel="stylesheet" href="assets/css/manage.css">
    </head>
    <body class="d-flex flex-column min-vh-100">
      <!-- Standard RoomieSync Navbar -->
      <div class="container rs-navbar-wrapper">
        <nav class="rs-pill-nav">
          <a href="dashboard.php" class="rs-brand">
            <div class="rs-brand-icon"><i class="bi bi-house-heart-fill"></i></div>
            <span>RoomieSync</span>
          </a>
          <button class="btn btn-rs-outline rs-mobile-toggle d-lg-none py-1 px-2" type="button" id="mobileNavToggle" aria-label="Toggle navigation">
            <i class="bi bi-list fs-5"></i>
          </button>
          <ul class="rs-nav-links" id="navLinksContainer">
            <li><a href="dashboard.php" class="rs-nav-link"><i class="bi bi-grid-1x2-fill"></i><span>Dashboard</span></a></li>
            <li><a href="chores.php" class="rs-nav-link"><i class="bi bi-check2-square"></i><span>Chores</span></a></li>
            <li><a href="bills.php" class="rs-nav-link"><i class="bi bi-receipt"></i><span>Bills</span></a></li>
            <li><a href="household.php" class="rs-nav-link"><i class="bi bi-house"></i><span>Household</span></a></li>
            <li><a href="reports.php" class="rs-nav-link"><i class="bi bi-bar-chart"></i><span>Reports</span></a></li>
          </ul>
          <div class="rs-nav-user dropdown">
            <a href="#" class="rs-user-btn dropdown-toggle" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              <?= render_avatar_markup($avatarText, null, $displayName) ?>
              <span class="d-none d-md-inline"><?= h($displayName) ?></span>
              <span class="role-badge roommate d-none d-xl-inline"><?= h($role) ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end rs-dropdown">
              <li><a class="dropdown-item rs-dropdown-item" href="profile.php"><i class="bi bi-person-circle"></i> Profile</a></li>
              <li><a class="dropdown-item rs-dropdown-item" href="households.php"><i class="bi bi-arrow-left-right"></i> Switch Household</a></li>
              <li><hr class="dropdown-divider rs-dropdown-divider"></li>
              <li><a class="dropdown-item rs-dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a></li>
            </ul>
          </div>
        </nav>
      </div>

      <main class="container py-5 flex-grow-1 d-flex align-items-center justify-content-center">
        <div class="access-denied-card">
          <div class="access-denied-icon"><i class="bi bi-shield-lock-fill"></i></div>
          <h1 class="h3 fw-bold text-main mb-2">Access Restricted</h1>
          <p class="text-secondary-custom mb-4">You do not have permission to access this page. The Manage section is available only to household Administrators.</p>
          <a href="dashboard.php" class="btn btn-rs-primary px-4 py-2">
            <i class="bi bi-house-door me-1"></i> Return to Dashboard
          </a>
        </div>
      </main>

      <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
      <script src="assets/js/main.js"></script>
    </body>
    </html>
    <?php
    exit;
}

// Helper for redirecting with flash message
if (!function_exists('manage_set_flash_and_redirect')) {
    function manage_set_flash_and_redirect(string $message, string $type = 'success', string $anchor = ''): never
    {
        $_SESSION['flash_message'] = ['message' => $message, 'type' => $type];
        $url = 'manage.php' . ($anchor !== '' ? '#' . ltrim($anchor, '#') : '');
        redirect_to($url);
    }
}

// ==================================================
// POST ACTIONS HANDLING (ADMIN-ONLY)
// ==================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        manage_set_flash_and_redirect('This form expired. Please try again.', 'danger');
    }

    $action = is_string($_POST['action'] ?? null) ? trim($_POST['action']) : '';



    // 2. CHANGE MEMBER ROLE
    if ($action === 'change_role') {
        $rawTargetUserId = $_POST['target_user_id'] ?? null;
        $targetUserId = is_string($rawTargetUserId) && ctype_digit($rawTargetUserId) ? (int) $rawTargetUserId : 0;
        $newRole = trim(is_string($_POST['new_role'] ?? null) ? $_POST['new_role'] : '');

        if ($targetUserId < 1 || !in_array($newRole, ['Admin', 'Roommate'], true)) {
            manage_set_flash_and_redirect('Invalid role or member specified.', 'danger', 'manageUsers');
        }

        // Verify user is in current household
        $checkMember = $connection->prepare(
            'SELECT hm.role, COALESCE(NULLIF(u.full_name, ""), u.username) AS member_name
             FROM householdmembersTb hm
             INNER JOIN userTb u ON u.user_id = hm.user_id
             WHERE hm.household_id = ? AND hm.user_id = ? LIMIT 1'
        );
        $checkMember->execute([$householdId, $targetUserId]);
        $target = $checkMember->fetch();
        if (!$target) {
            manage_set_flash_and_redirect('Member not found in this household.', 'danger', 'manageUsers');
        }

        $currentRole = (string) $target['role'];

        // If demoting from Admin to Roommate, ensure at least one Admin remains
        if (strcasecmp($currentRole, 'Admin') === 0 && strcasecmp($newRole, 'Admin') !== 0) {
            $countAdmins = $connection->prepare(
                'SELECT COUNT(*) FROM householdmembersTb WHERE household_id = ? AND LOWER(role) = "admin"'
            );
            $countAdmins->execute([$householdId]);
            $adminCount = (int) $countAdmins->fetchColumn();

            if ($adminCount <= 1) {
                manage_set_flash_and_redirect(
                    'Cannot demote this user: the household must have at least one Administrator.',
                    'danger',
                    'manageUsers'
                );
            }
        }

        // Update role
        $updateRole = $connection->prepare(
            'UPDATE householdmembersTb SET role = ? WHERE household_id = ? AND user_id = ?'
        );
        $updateRole->execute([$newRole, $householdId, $targetUserId]);

        $mName = (string) $target['member_name'];
        manage_set_flash_and_redirect("Updated {$mName}'s role to {$newRole}.", 'success', 'manageUsers');
    }

    // 3. REMOVE MEMBER FROM HOUSEHOLD
    if ($action === 'remove_member') {
        $rawTargetUserId = $_POST['target_user_id'] ?? null;
        $targetUserId = is_string($rawTargetUserId) && ctype_digit($rawTargetUserId) ? (int) $rawTargetUserId : 0;

        if ($targetUserId < 1) {
            manage_set_flash_and_redirect('Invalid member specified.', 'danger', 'manageUsers');
        }

        // Check target exists in household
        $checkMember = $connection->prepare(
            'SELECT hm.role, COALESCE(NULLIF(u.full_name, ""), u.username) AS member_name
             FROM householdmembersTb hm
             INNER JOIN userTb u ON u.user_id = hm.user_id
             WHERE hm.household_id = ? AND hm.user_id = ? LIMIT 1'
        );
        $checkMember->execute([$householdId, $targetUserId]);
        $target = $checkMember->fetch();
        if (!$target) {
            manage_set_flash_and_redirect('Member not found in this household.', 'danger', 'manageUsers');
        }

        // If removing an Admin, ensure household has another Admin
        if (strcasecmp((string) $target['role'], 'Admin') === 0) {
            $countAdmins = $connection->prepare(
                'SELECT COUNT(*) FROM householdmembersTb WHERE household_id = ? AND LOWER(role) = "admin"'
            );
            $countAdmins->execute([$householdId]);
            $adminCount = (int) $countAdmins->fetchColumn();

            if ($adminCount <= 1) {
                manage_set_flash_and_redirect(
                    'Cannot remove the only Administrator from the household. Please assign another Admin first.',
                    'danger',
                    'manageUsers'
                );
            }
        }

        // Remove from householdmembersTb only (DO NOT delete from userTb)
        $deleteMember = $connection->prepare(
            'DELETE FROM householdmembersTb WHERE household_id = ? AND user_id = ?'
        );
        $deleteMember->execute([$householdId, $targetUserId]);

        $mName = (string) $target['member_name'];
        manage_set_flash_and_redirect("Removed {$mName} from the household.", 'success', 'manageUsers');
    }

    // 4. CREATE ANNOUNCEMENT
    if ($action === 'create_announcement') {
        $title = trim(is_string($_POST['title'] ?? null) ? $_POST['title'] : '');
        $content = trim(is_string($_POST['content'] ?? null) ? $_POST['content'] : '');

        if ($title === '' || strlen($title) > 100) {
            manage_set_flash_and_redirect('Enter an announcement title (up to 100 characters).', 'danger', 'announcementsSection');
        }
        if ($content === '') {
            manage_set_flash_and_redirect('Please enter announcement content.', 'danger', 'announcementsSection');
        }

        $insertAnn = $connection->prepare(
            'INSERT INTO announceTb (household_id, created_by, title, content, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        );
        $insertAnn->execute([$householdId, $userId, $title, $content]);

        manage_set_flash_and_redirect('Announcement published successfully!', 'success', 'announcementsSection');
    }

    // 5. EDIT ANNOUNCEMENT
    if ($action === 'edit_announcement') {
        $rawAnnId = $_POST['announcement_id'] ?? null;
        $annId = is_string($rawAnnId) && ctype_digit($rawAnnId) ? (int) $rawAnnId : 0;
        $title = trim(is_string($_POST['title'] ?? null) ? $_POST['title'] : '');
        $content = trim(is_string($_POST['content'] ?? null) ? $_POST['content'] : '');

        if ($annId < 1) {
            manage_set_flash_and_redirect('Invalid announcement specified.', 'danger', 'announcementsSection');
        }
        if ($title === '' || strlen($title) > 100) {
            manage_set_flash_and_redirect('Enter an announcement title (up to 100 characters).', 'danger', 'announcementsSection');
        }
        if ($content === '') {
            manage_set_flash_and_redirect('Please enter announcement content.', 'danger', 'announcementsSection');
        }

        // Verify announcement belongs to active household
        $updateAnn = $connection->prepare(
            'UPDATE announceTb SET title = ?, content = ? WHERE announcement_id = ? AND household_id = ?'
        );
        $updateAnn->execute([$title, $content, $annId, $householdId]);

        manage_set_flash_and_redirect('Announcement updated successfully.', 'success', 'announcementsSection');
    }

    // 6. DELETE ANNOUNCEMENT
    if ($action === 'delete_announcement') {
        $rawAnnId = $_POST['announcement_id'] ?? null;
        $annId = is_string($rawAnnId) && ctype_digit($rawAnnId) ? (int) $rawAnnId : 0;

        if ($annId < 1) {
            manage_set_flash_and_redirect('Invalid announcement specified.', 'danger', 'announcementsSection');
        }

        $deleteAnn = $connection->prepare('DELETE FROM announceTb WHERE announcement_id = ? AND household_id = ?');
        $deleteAnn->execute([$annId, $householdId]);

        manage_set_flash_and_redirect('Announcement deleted successfully.', 'success', 'announcementsSection');
    }

    // 7. ADD CATEGORY (Chores, Expenses, Bills)
    if ($action === 'add_category') {
        $catType = trim(is_string($_POST['category_type'] ?? null) ? $_POST['category_type'] : '');
        $name = trim(is_string($_POST['category_name'] ?? null) ? $_POST['category_name'] : '');
        $desc = trim(is_string($_POST['description'] ?? null) ? $_POST['description'] : '');

        if (!in_array($catType, ['chore', 'expense', 'bill'], true)) {
            manage_set_flash_and_redirect('Invalid category type.', 'danger', 'categoriesSection');
        }
        if ($name === '' || strlen($name) > 50) {
            manage_set_flash_and_redirect('Enter a category name up to 50 characters.', 'danger', 'categoriesSection');
        }

        $tableMap = [
            'chore' => 'chore_categoriesTb',
            'expense' => 'expense_categoriesTb',
            'bill' => 'bill_categoriesTb',
        ];
        $targetTable = $tableMap[$catType];

        // Check if category name already exists in this household
        $checkDup = $connection->prepare("SELECT 1 FROM {$targetTable} WHERE household_id = ? AND LOWER(category_name) = LOWER(?) LIMIT 1");
        $checkDup->execute([$householdId, $name]);
        if ($checkDup->fetchColumn()) {
            manage_set_flash_and_redirect("A {$catType} category named '{$name}' already exists.", 'danger', 'categoriesSection');
        }

        $insertCat = $connection->prepare("INSERT INTO {$targetTable} (household_id, category_name, description) VALUES (?, ?, ?)");
        $insertCat->execute([$householdId, $name, $desc !== '' ? $desc : null]);

        $formattedType = ucfirst($catType);
        manage_set_flash_and_redirect("{$formattedType} category '{$name}' added successfully.", 'success', 'categoriesSection');
    }

    // 8. EDIT CATEGORY
    if ($action === 'edit_category') {
        $catType = trim(is_string($_POST['category_type'] ?? null) ? $_POST['category_type'] : '');
        $rawCatId = $_POST['category_id'] ?? null;
        $catId = is_string($rawCatId) && ctype_digit($rawCatId) ? (int) $rawCatId : 0;
        $name = trim(is_string($_POST['category_name'] ?? null) ? $_POST['category_name'] : '');
        $desc = trim(is_string($_POST['description'] ?? null) ? $_POST['description'] : '');

        if (!in_array($catType, ['chore', 'expense', 'bill'], true) || $catId < 1) {
            manage_set_flash_and_redirect('Invalid category specified.', 'danger', 'categoriesSection');
        }
        if ($name === '' || strlen($name) > 50) {
            manage_set_flash_and_redirect('Enter a category name up to 50 characters.', 'danger', 'categoriesSection');
        }

        $idColMap = [
            'chore' => 'chore_category_id',
            'expense' => 'expense_category_id',
            'bill' => 'bill_category_id',
        ];
        $tableMap = [
            'chore' => 'chore_categoriesTb',
            'expense' => 'expense_categoriesTb',
            'bill' => 'bill_categoriesTb',
        ];

        $targetTable = $tableMap[$catType];
        $idCol = $idColMap[$catType];

        // Check for duplicate name in other categories
        $checkDup = $connection->prepare(
            "SELECT 1 FROM {$targetTable} WHERE household_id = ? AND LOWER(category_name) = LOWER(?) AND {$idCol} <> ? LIMIT 1"
        );
        $checkDup->execute([$householdId, $name, $catId]);
        if ($checkDup->fetchColumn()) {
            manage_set_flash_and_redirect("Another {$catType} category with that name already exists.", 'danger', 'categoriesSection');
        }

        $updateCat = $connection->prepare(
            "UPDATE {$targetTable} SET category_name = ?, description = ? WHERE {$idCol} = ? AND household_id = ?"
        );
        $updateCat->execute([$name, $desc !== '' ? $desc : null, $catId, $householdId]);

        manage_set_flash_and_redirect('Category updated successfully.', 'success', 'categoriesSection');
    }

    // 9. DELETE CATEGORY
    if ($action === 'delete_category') {
        $catType = trim(is_string($_POST['category_type'] ?? null) ? $_POST['category_type'] : '');
        $rawCatId = $_POST['category_id'] ?? null;
        $catId = is_string($rawCatId) && ctype_digit($rawCatId) ? (int) $rawCatId : 0;

        if (!in_array($catType, ['chore', 'expense', 'bill'], true) || $catId < 1) {
            manage_set_flash_and_redirect('Invalid category specified.', 'danger', 'categoriesSection');
        }

        // Check whether existing records are using this category per requirements
        $usageCount = 0;
        if ($catType === 'chore') {
            $chk = $connection->prepare('SELECT COUNT(*) FROM choresTb WHERE category_id = ? AND household_id = ?');
            $chk->execute([$catId, $householdId]);
            $usageCount = (int) $chk->fetchColumn();
        } elseif ($catType === 'expense') {
            $chk = $connection->prepare('SELECT COUNT(*) FROM expenseTb WHERE category_id = ? AND household_id = ?');
            $chk->execute([$catId, $householdId]);
            $usageCount = (int) $chk->fetchColumn();
        } elseif ($catType === 'bill') {
            $chk = $connection->prepare('SELECT COUNT(*) FROM billsTb WHERE category_id = ? AND household_id = ?');
            $chk->execute([$catId, $householdId]);
            $usageCount = (int) $chk->fetchColumn();
        }

        if ($usageCount > 0) {
            manage_set_flash_and_redirect(
                "This category is currently being used by {$usageCount} record(s) and cannot be deleted.",
                'danger',
                'categoriesSection'
            );
        }

        $idColMap = [
            'chore' => 'chore_category_id',
            'expense' => 'expense_category_id',
            'bill' => 'bill_category_id',
        ];
        $tableMap = [
            'chore' => 'chore_categoriesTb',
            'expense' => 'expense_categoriesTb',
            'bill' => 'bill_categoriesTb',
        ];

        $targetTable = $tableMap[$catType];
        $idCol = $idColMap[$catType];

        $deleteCat = $connection->prepare("DELETE FROM {$targetTable} WHERE {$idCol} = ? AND household_id = ?");
        $deleteCat->execute([$catId, $householdId]);

        manage_set_flash_and_redirect('Category deleted successfully.', 'success', 'categoriesSection');
    }

    // 10. UPDATE HOUSEHOLD SETTINGS
    if ($action === 'update_household') {
        $name = trim(is_string($_POST['household_name'] ?? null) ? $_POST['household_name'] : '');
        $description = trim(is_string($_POST['description'] ?? null) ? $_POST['description'] : '');
        $address = trim(is_string($_POST['address'] ?? null) ? $_POST['address'] : '');
        if ($name === '' || strlen($name) > 100) {
            manage_set_flash_and_redirect('Enter a household name with up to 100 characters.', 'danger', 'householdSettingsSection');
        }
        if ($address !== '' && strlen($address) > 255) {
            manage_set_flash_and_redirect('Address must be 255 characters or fewer.', 'danger', 'householdSettingsSection');
        }

        $finalImagePath = (string) ($household['household_image'] ?? '');

        // Check if an image file was uploaded
        if (isset($_FILES['household_image_file']) && is_array($_FILES['household_image_file']) && $_FILES['household_image_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['household_image_file'];
            $fileSize = (int) $file['size'];
            $fileTmp = (string) $file['tmp_name'];
            $originalName = (string) $file['name'];

            if ($fileSize > 2 * 1024 * 1024) {
                manage_set_flash_and_redirect('Household image file must be smaller than 2MB.', 'danger', 'householdSettingsSection');
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($fileTmp);
            $allowedMimes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
            ];

            if (!isset($allowedMimes[$mimeType])) {
                manage_set_flash_and_redirect('Please upload a valid image file (JPG, PNG, WebP, or GIF).', 'danger', 'householdSettingsSection');
            }

            $ext = $allowedMimes[$mimeType];
            $uploadDir = __DIR__ . '/uploads/households/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $newFileName = 'household_' . $householdId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $destPath = $uploadDir . $newFileName;

            if (move_uploaded_file($fileTmp, $destPath)) {
                $finalImagePath = 'uploads/households/' . $newFileName;
            }
        }

        $updateH = $connection->prepare(
            'UPDATE householdTb
             SET household_name = ?, description = ?, address = ?, household_image = ?
             WHERE household_id = ?'
        );
        $updateH->execute([
            $name,
            $description !== '' ? $description : null,
            $address !== '' ? $address : null,
            $finalImagePath !== '' ? $finalImagePath : null,
            $householdId,
        ]);

        manage_set_flash_and_redirect('Household settings updated successfully.', 'success', 'householdSettingsSection');
    }

    // 11. ADD CHORE (Admin shortcut per revised chore schema)
    if ($action === 'add_chore') {
        $choreName = trim(is_string($_POST['chore_name'] ?? null) ? $_POST['chore_name'] : '');
        $description = trim(is_string($_POST['description'] ?? null) ? $_POST['description'] : '');
        $frequency = trim(is_string($_POST['frequency'] ?? null) ? $_POST['frequency'] : '');
        $assignmentType = is_string($_POST['assignment_type'] ?? null) ? $_POST['assignment_type'] : 'Single';
        $dueDate = trim(is_string($_POST['due_date'] ?? null) ? $_POST['due_date'] : '');
        $rawCategoryId = $_POST['category_id'] ?? null;
        $categoryId = is_string($rawCategoryId) && ctype_digit($rawCategoryId) ? (int) $rawCategoryId : null;
        $assignedUserId = is_string($_POST['assigned_user_id'] ?? null) && ctype_digit($_POST['assigned_user_id']) ? (int) $_POST['assigned_user_id'] : 0;
        $rotationMembers = is_array($_POST['rotation_members'] ?? null) ? array_map('intval', $_POST['rotation_members']) : [];

        if ($choreName === '' || strlen($choreName) > 100) {
            manage_set_flash_and_redirect('Enter a chore name with up to 100 characters.', 'danger');
        }
        if (!in_array($assignmentType, ['Single', 'Rotation'], true)) {
            manage_set_flash_and_redirect('Invalid assignment type.', 'danger');
        }
        if (!preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $dueDate)) {
            manage_set_flash_and_redirect('Enter a valid due date.', 'danger');
        }

        try {
            $connection->beginTransaction();

            $insertChore = $connection->prepare(
                'INSERT INTO choresTb (household_id, category_id, chore_name, description, frequency, assignment_type, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $insertChore->execute([
                $householdId,
                $categoryId > 0 ? $categoryId : null,
                $choreName,
                $description !== '' ? $description : null,
                $frequency !== '' ? $frequency : 'Weekly',
                $assignmentType,
                $userId,
            ]);
            $choreId = (int) $connection->lastInsertId();

            if ($assignmentType === 'Single') {
                if ($assignedUserId < 1) {
                    $assignedUserId = $userId;
                }
                $insertAssign = $connection->prepare(
                    'INSERT INTO chore_assignmentsTb (chore_id, user_id, due_date, status) VALUES (?, ?, ?, "Pending")'
                );
                $insertAssign->execute([$choreId, $assignedUserId, $dueDate]);
            } else {
                if (empty($rotationMembers)) {
                    $rotationMembers = [$userId];
                }
                $insertRot = $connection->prepare(
                    'INSERT INTO chore_rotationTb (chore_id, user_id, rotation_order) VALUES (?, ?, ?)'
                );
                foreach ($rotationMembers as $idx => $mId) {
                    $insertRot->execute([$choreId, $mId, $idx + 1]);
                }
                // First rotation user gets the assignment
                $firstUser = $rotationMembers[0];
                $insertAssign = $connection->prepare(
                    'INSERT INTO chore_assignmentsTb (chore_id, user_id, due_date, status) VALUES (?, ?, ?, "Pending")'
                );
                $insertAssign->execute([$choreId, $firstUser, $dueDate]);
            }

            $connection->commit();
            manage_set_flash_and_redirect("Chore '{$choreName}' created successfully.", 'success');
        } catch (Throwable $e) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            error_log('Manage add chore error: ' . $e->getMessage());
            manage_set_flash_and_redirect('Unable to create chore. Please try again.', 'danger');
        }
    }

    manage_set_flash_and_redirect('Action completed.', 'info');
}

// ==================================================
// QUERY DATA FOR MANAGE PAGE DISPLAY
// ==================================================
$notice = consume_flash_message();

// Reload Household Details (in case updated)
$contextQuery->execute([$householdId, $userId]);
$household = $contextQuery->fetch();
$householdName = (string) $household['household_name'];
$householdDesc = (string) ($household['description'] ?? '');
$householdAddress = (string) ($household['address'] ?? '');
$householdImage = (string) ($household['household_image'] ?? '');
$householdJoinCode = (string) ($household['join_code'] ?? '');
$householdCreatedAt = (string) $household['created_at'];

// 1. Members of Current Household
$membersStmt = $connection->prepare(
    'SELECT u.user_id, u.full_name, u.username, u.email, u.profile_picture, u.status, hm.role, hm.joined_at
     FROM householdmembersTb hm
     INNER JOIN userTb u ON u.user_id = hm.user_id
     WHERE hm.household_id = ?
     ORDER BY (hm.role = "Admin") DESC, u.full_name ASC, u.username ASC'
);
$membersStmt->execute([$householdId]);
$members = $membersStmt->fetchAll();

$totalMembersCount = count($members);
$adminCount = 0;
$roommateCount = 0;
foreach ($members as $m) {
    if (strcasecmp((string) $m['role'], 'Admin') === 0) {
        $adminCount++;
    } else {
        $roommateCount++;
    }
}

// 2. Announcements
$annStmt = $connection->prepare(
    'SELECT a.announcement_id, a.title, a.content, a.created_at,
           COALESCE(NULLIF(u.full_name, ""), u.username, "Admin") AS author_name
     FROM announceTb a
     LEFT JOIN userTb u ON u.user_id = a.created_by
     WHERE a.household_id = ?
     ORDER BY a.created_at DESC'
);
$annStmt->execute([$householdId]);
$announcements = $annStmt->fetchAll();
$totalAnnouncementsCount = count($announcements);

// 3. Categories (by type: Chores, Expenses, Bills)
$choreCatsStmt = $connection->prepare(
    'SELECT cc.chore_category_id, cc.category_name, cc.description,
           (SELECT COUNT(*) FROM choresTb c WHERE c.category_id = cc.chore_category_id AND c.household_id = cc.household_id) AS usage_count
     FROM chore_categoriesTb cc
     WHERE cc.household_id = ?
     ORDER BY cc.category_name ASC'
);
$choreCatsStmt->execute([$householdId]);
$choreCategories = $choreCatsStmt->fetchAll();

$expenseCatsStmt = $connection->prepare(
    'SELECT ec.expense_category_id, ec.category_name, ec.description,
           (SELECT COUNT(*) FROM expenseTb e WHERE e.category_id = ec.expense_category_id AND e.household_id = ec.household_id) AS usage_count
     FROM expense_categoriesTb ec
     WHERE ec.household_id = ?
     ORDER BY ec.category_name ASC'
);
$expenseCatsStmt->execute([$householdId]);
$expenseCategories = $expenseCatsStmt->fetchAll();

$billCatsStmt = $connection->prepare(
    'SELECT bc.bill_category_id, bc.category_name, bc.description,
           (SELECT COUNT(*) FROM billsTb b WHERE b.category_id = bc.bill_category_id AND b.household_id = bc.household_id) AS usage_count
     FROM bill_categoriesTb bc
     WHERE bc.household_id = ?
     ORDER BY bc.category_name ASC'
);
$billCatsStmt->execute([$householdId]);
$billCategories = $billCatsStmt->fetchAll();

$totalCategoriesCount = count($choreCategories) + count($expenseCategories) + count($billCategories);

// 4. Activity Logs (Real database activities across modules)
$activityLogsStmt = $connection->prepare(
    '(SELECT "announcement" AS type, a.announcement_id AS id,
            CONCAT("Created announcement: ", a.title) AS title,
            a.content AS detail,
            a.created_at AS event_time,
            COALESCE(NULLIF(u.full_name, ""), u.username, "Admin") AS user_name
     FROM announceTb a
     LEFT JOIN userTb u ON u.user_id = a.created_by
     WHERE a.household_id = ?)
    UNION ALL
    (SELECT "chore_completed" AS type, ca.assignment_id AS id,
            CONCAT("Completed chore: ", c.chore_name) AS title,
            c.frequency AS detail,
            ca.completed_at AS event_time,
            COALESCE(NULLIF(u.full_name, ""), u.username, "Member") AS user_name
     FROM chore_assignmentsTb ca
     INNER JOIN choresTb c ON c.chore_id = ca.chore_id
     LEFT JOIN userTb u ON u.user_id = ca.user_id
     WHERE c.household_id = ? AND LOWER(ca.status) = "completed" AND ca.completed_at IS NOT NULL)
    UNION ALL
    (SELECT "chore_created" AS type, c.chore_id AS id,
            CONCAT("Created new chore: ", c.chore_name) AS title,
            c.description AS detail,
            c.created_at AS event_time,
            COALESCE(NULLIF(u.full_name, ""), u.username, "Admin") AS user_name
     FROM choresTb c
     LEFT JOIN userTb u ON u.user_id = c.created_by
     WHERE c.household_id = ?)
    UNION ALL
    (SELECT "expense" AS type, e.expense_id AS id,
            CONCAT("Recorded expense: ", e.title, " (₱", FORMAT(e.amount, 2), ")") AS title,
            e.description AS detail,
            e.created_at AS event_time,
            COALESCE(NULLIF(u.full_name, ""), u.username, "Member") AS user_name
     FROM expenseTb e
     LEFT JOIN userTb u ON u.user_id = e.paid_by
     WHERE e.household_id = ?)
    UNION ALL
    (SELECT "bill" AS type, b.bill_id AS id,
            CONCAT("Added bill: ", b.bill_name, " (₱", FORMAT(b.amount, 2), ")") AS title,
            CONCAT("Due: ", b.due_date) AS detail,
            b.created_at AS event_time,
            COALESCE(NULLIF(u.full_name, ""), u.username, "Admin") AS user_name
     FROM billsTb b
     LEFT JOIN userTb u ON u.user_id = b.created_by
     WHERE b.household_id = ?)
    UNION ALL
    (SELECT "maintenance" AS type, m.problem_id AS id,
            CONCAT("Reported issue: ", m.problem_type) AS title,
            m.problem_desc AS detail,
            m.reported_at AS event_time,
            COALESCE(NULLIF(u.full_name, ""), u.username, "Member") AS user_name
     FROM maintenanceTb m
     LEFT JOIN userTb u ON u.user_id = m.reported_by
     WHERE m.household_id = ?)
    UNION ALL
    (SELECT "member_joined" AS type, hm.user_id AS id,
            CONCAT("Joined household as ", hm.role) AS title,
            NULL AS detail,
            hm.joined_at AS event_time,
            COALESCE(NULLIF(u.full_name, ""), u.username, "Member") AS user_name
     FROM householdmembersTb hm
     INNER JOIN userTb u ON u.user_id = hm.user_id
     WHERE hm.household_id = ?)
    ORDER BY event_time DESC
    LIMIT 25'
);
$activityLogsStmt->execute([$householdId, $householdId, $householdId, $householdId, $householdId, $householdId, $householdId]);
$activityLogs = $activityLogsStmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Manage | RoomieSync</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="assets/css/manage.css">
</head>
<body class="d-flex flex-column min-vh-100">

  <!-- RoomieSync Oblong Navbar -->
  <div class="container rs-navbar-wrapper">
    <nav class="rs-pill-nav">
      <a href="dashboard.php" class="rs-brand">
        <div class="rs-brand-icon"><i class="bi bi-house-heart-fill"></i></div>
        <span>RoomieSync</span>
      </a>
      <button class="btn btn-rs-outline rs-mobile-toggle d-lg-none py-1 px-2" type="button" id="mobileNavToggle" aria-label="Toggle navigation">
        <i class="bi bi-list fs-5"></i>
      </button>
      <ul class="rs-nav-links" id="navLinksContainer">
        <li><a href="dashboard.php" class="rs-nav-link"><i class="bi bi-grid-1x2-fill"></i><span>Dashboard</span></a></li>
        <li><a href="chores.php" class="rs-nav-link"><i class="bi bi-check2-square"></i><span>Chores</span></a></li>
        <li><a href="bills.php" class="rs-nav-link"><i class="bi bi-receipt"></i><span>Bills</span></a></li>
        <li><a href="household.php" class="rs-nav-link"><i class="bi bi-house"></i><span>Household</span></a></li>
        <li><a href="reports.php" class="rs-nav-link"><i class="bi bi-bar-chart"></i><span>Reports</span></a></li>
        <li><a href="manage.php" class="rs-nav-link active"><i class="bi bi-sliders"></i><span>Manage</span></a></li>
      </ul>
      <div class="rs-nav-user dropdown">
        <a href="#" class="rs-user-btn dropdown-toggle" role="button" data-bs-toggle="dropdown" aria-expanded="false">
          <?= render_avatar_markup($avatarText, null, $displayName) ?>
          <span class="d-none d-md-inline"><?= h($displayName) ?></span>
          <span class="role-badge admin d-none d-xl-inline">Admin</span>
        </a>
        <ul class="dropdown-menu dropdown-menu-end rs-dropdown">
          <li class="px-3 py-2 border-bottom mb-1">
            <div class="fw-bold text-dark-accent"><?= h($displayName) ?></div>
            <?php if ($userEmail !== ''): ?>
              <small class="text-secondary-custom"><?= h($userEmail) ?></small>
            <?php else: ?>
              <small class="text-secondary-custom">No email on profile</small>
            <?php endif; ?>
            <div class="mt-1"><span class="role-badge admin">Admin</span></div>
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

  <main class="container py-4 flex-grow-1">

    <!-- Flash Notice -->
    <?php if ($notice): ?>
      <?php $alertType = ($notice['type'] ?? 'success') === 'danger' ? 'danger' : 'success'; ?>
      <div class="alert alert-<?= $alertType ?> alert-dismissible fade show mb-4 shadow-sm" role="alert">
        <i class="bi <?= $alertType === 'danger' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill' ?> me-2"></i><?= h((string) $notice['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <!-- Manage Header -->
    <header class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
      <div>
        <span class="rs-eyebrow d-block mb-1">Household Administration</span>
        <h1 class="h2 fw-bold text-main mb-1">Manage</h1>
        <p class="text-secondary-custom mb-0">Admin control center for <?= h($householdName) ?> &ndash; users, announcements, categories, and settings.</p>
      </div>
      <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn btn-rs-primary" data-bs-toggle="modal" data-bs-target="#createAnnouncementModal">
          <i class="bi bi-megaphone me-1"></i> New Announcement
        </button>
      </div>
    </header>

    <!-- Top Management Cards (1. Manage Users, 2. Announcements & Activities, 3. Manage Categories) -->
    <section class="row g-3 mb-4" id="manageSummary">
      <div class="col-12 col-md-4">
        <a href="#manageUsers" class="manage-summary-card card-household">
          <div class="manage-summary-icon users">
            <i class="bi bi-people-fill"></i>
          </div>
          <div>
            <div class="manage-summary-val"><?= $totalMembersCount ?></div>
            <div class="manage-summary-lbl">Manage Users</div>
            <div class="manage-summary-sub"><?= $adminCount ?> Admin<?= $adminCount === 1 ? '' : 's' ?> &bull; <?= $roommateCount ?> Roommate<?= $roommateCount === 1 ? '' : 's' ?></div>
          </div>
        </a>
      </div>
      <div class="col-12 col-md-4">
        <a href="#announcementsSection" class="manage-summary-card card-announcements">
          <div class="manage-summary-icon announcements">
            <i class="bi bi-megaphone-fill"></i>
          </div>
          <div>
            <div class="manage-summary-val"><?= $totalAnnouncementsCount ?></div>
            <div class="manage-summary-lbl">Announcements &amp; Activities</div>
            <div class="manage-summary-sub">Post updates &bull; View activity timeline</div>
          </div>
        </a>
      </div>
      <div class="col-12 col-md-4">
        <a href="#categoriesSection" class="manage-summary-card card-money">
          <div class="manage-summary-icon categories">
            <i class="bi bi-tags-fill"></i>
          </div>
          <div>
            <div class="manage-summary-val"><?= $totalCategoriesCount ?></div>
            <div class="manage-summary-lbl">Manage Categories</div>
            <div class="manage-summary-sub"><?= count($choreCategories) ?> Chores &bull; <?= count($expenseCategories) ?> Expenses &bull; <?= count($billCategories) ?> Bills</div>
          </div>
        </a>
      </div>
    </section>

    <!-- 1. MANAGE USERS SECTION -->
    <section class="manage-card card-household" id="manageUsers">
      <div class="manage-card-header">
        <div class="manage-card-title-wrap">
          <div class="manage-card-icon users"><i class="bi bi-people-fill"></i></div>
          <div>
            <h2 class="h5 fw-bold text-main mb-0">Manage Users</h2>
            <small class="text-secondary-custom">Members currently in this household and their assigned permissions</small>
          </div>
        </div>
        <div class="d-flex align-items-center gap-2">
          <input type="text" id="memberSearchInput" class="form-control form-control-sm manage-input" placeholder="Search members..." style="max-width: 220px;">
        </div>
      </div>

      <!-- Members Table: Full Name | Username | Email | Role | Status | Joined Date | Actions -->
      <div class="manage-table-wrapper">
        <table class="table manage-table" id="manageMembersTable">
          <thead>
            <tr>
              <th>Full Name</th>
              <th>Username</th>
              <th>Email</th>
              <th>Role</th>
              <th>Status</th>
              <th>Joined Date</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($members)): ?>
              <tr>
                <td colspan="7" class="manage-empty-state">
                  <i class="bi bi-people"></i>
                  <p class="mb-0">No household members found.</p>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($members as $mem): ?>
                <?php
                  $memId = (int) $mem['user_id'];
                  $memName = (string) (!empty($mem['full_name']) ? $mem['full_name'] : $mem['username']);
                  $memRole = (string) $mem['role'];
                  $isMemberAdmin = strcasecmp($memRole, 'Admin') === 0;
                  $isSelf = ($memId === $userId);
                  $status = (string) ($mem['status'] ?? 'Active');
                ?>
                <tr>
                  <td>
                    <div class="d-flex align-items-center gap-2">
                      <div class="member-avatar-pill">
                        <?php if (!empty($mem['profile_picture'])): ?>
                          <img src="<?= h((string) $mem['profile_picture']) ?>" alt="<?= h($memName) ?>" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%; display: block;">
                        <?php else: ?>
                          <?= h(manage_initials($memName)) ?>
                        <?php endif; ?>
                      </div>
                      <span class="fw-bold text-main"><?= h((string) $mem['full_name']) ?></span>
                    </div>
                  </td>
                  <td>
                    <span class="text-secondary-custom">@<?= h((string) $mem['username']) ?></span>
                    <?php if ($isSelf): ?>
                      <span class="badge bg-accent-light text-dark-accent ms-1">You</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?= !empty($mem['email']) ? h((string) $mem['email']) : '<span class="text-muted fst-italic">No email</span>' ?>
                  </td>
                  <td>
                    <span class="role-badge <?= $isMemberAdmin ? 'admin' : 'roommate' ?>">
                      <?= h($memRole) ?>
                    </span>
                  </td>
                  <td>
                    <span class="badge <?= strtolower($status) === 'active' ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary text-white' ?> px-2 py-1">
                      <?= h($status) ?>
                    </span>
                  </td>
                  <td class="text-nowrap">
                    <?= h(date('M j, Y', strtotime((string) $mem['joined_at']))) ?>
                  </td>
                  <td class="text-end">
                    <div class="d-inline-flex gap-1">
                      <!-- Change Role Button -->
                      <button type="button" class="btn btn-sm btn-rs-outline btn-change-role"
                              data-bs-toggle="modal" data-bs-target="#changeRoleModal"
                              data-id="<?= $memId ?>" data-name="<?= h($memName) ?>" data-role="<?= h($memRole) ?>"
                              title="Change Role">
                        <i class="bi bi-shield-shaded"></i> <span class="d-none d-lg-inline ms-1">Role</span>
                      </button>

                      <!-- Remove Member Button -->
                      <button type="button" class="btn btn-sm btn-rs-outline text-danger btn-remove-member"
                              data-bs-toggle="modal" data-bs-target="#removeMemberModal"
                              data-id="<?= $memId ?>" data-name="<?= h($memName) ?>"
                              title="Remove from Household">
                        <i class="bi bi-person-x"></i>
                      </button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- 2. ANNOUNCEMENTS & ACTIVITIES SECTION -->
    <section class="manage-card card-announcements" id="announcementsSection">
      <div class="manage-card-header">
        <div class="manage-card-title-wrap">
          <div class="manage-card-icon announcements"><i class="bi bi-megaphone-fill"></i></div>
          <div>
            <h2 class="h5 fw-bold text-main mb-0">Announcements &amp; Activities</h2>
            <small class="text-secondary-custom">Broadcast announcements to all household members</small>
          </div>
        </div>
        <div>
          <button type="button" class="btn btn-sm btn-rs-primary" data-bs-toggle="modal" data-bs-target="#createAnnouncementModal">
            <i class="bi bi-plus-lg me-1"></i> Create Announcement
          </button>
        </div>
      </div>

      <?php if (empty($announcements)): ?>
        <div class="manage-empty-state">
          <i class="bi bi-megaphone"></i>
          <p class="mb-2">No announcements posted yet for this household.</p>
          <button type="button" class="btn btn-sm btn-rs-primary" data-bs-toggle="modal" data-bs-target="#createAnnouncementModal">
            <i class="bi bi-plus-lg me-1"></i> Post First Announcement
          </button>
        </div>
      <?php else: ?>
        <div class="row g-3">
          <?php foreach ($announcements as $ann): ?>
            <?php
              $annId = (int) $ann['announcement_id'];
              $annTitle = (string) $ann['title'];
              $annContent = (string) $ann['content'];
              $annAuthor = (string) $ann['author_name'];
              $annDate = date('M j, Y, g:i A', strtotime((string) $ann['created_at']));
            ?>
            <div class="col-12 col-lg-6">
              <div class="announcement-card h-100 d-flex flex-column justify-content-between">
                <div>
                  <div class="announcement-meta">
                    <span><i class="bi bi-person-fill text-dark-accent me-1"></i> <?= h($annAuthor) ?></span>
                    <span>&bull;</span>
                    <span><i class="bi bi-clock me-1"></i> <?= h($annDate) ?></span>
                  </div>
                  <h3 class="h6 fw-bold text-main mb-2"><?= h($annTitle) ?></h3>
                  <p class="text-secondary-custom mb-3 small" style="white-space: pre-wrap;"><?= h($annContent) ?></p>
                </div>
                <div class="d-flex justify-content-end gap-2 border-top pt-2">
                  <button type="button" class="btn btn-sm btn-rs-outline btn-edit-announcement"
                          data-bs-toggle="modal" data-bs-target="#editAnnouncementModal"
                          data-id="<?= $annId ?>" data-title="<?= h($annTitle) ?>" data-content="<?= h($annContent) ?>"
                          title="Edit Announcement">
                    <i class="bi bi-pencil me-1"></i> Edit
                  </button>
                  <button type="button" class="btn btn-sm btn-rs-outline text-danger btn-delete-announcement"
                          data-bs-toggle="modal" data-bs-target="#deleteAnnouncementModal"
                          data-id="<?= $annId ?>" data-title="<?= h($annTitle) ?>"
                          title="Delete Announcement">
                    <i class="bi bi-trash3 me-1"></i> Delete
                  </button>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <!-- MIDDLE SECTION: 4. CATEGORIES OVERVIEW & 5. QUICK ACTIONS -->
    <div class="row g-4 mb-4">

      <!-- 4. CATEGORIES OVERVIEW & MANAGEMENT -->
      <div class="col-12 col-xl-8" id="categoriesSection">
        <section class="manage-card mb-0 h-100 card-money">
          <div class="manage-card-header">
            <div class="manage-card-title-wrap">
              <div class="manage-card-icon categories"><i class="bi bi-tags-fill"></i></div>
              <div>
                <h2 class="h5 fw-bold text-main mb-0">Manage Categories</h2>
                <small class="text-secondary-custom">Configure category labels for Chores, Expenses, and Bills</small>
              </div>
            </div>
            <div>
              <button type="button" class="btn btn-sm btn-rs-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                <i class="bi bi-plus-lg me-1"></i> Add Category
              </button>
            </div>
          </div>

          <!-- Category Overview Cards (Chores, Expenses, Bills) -->
          <div class="row g-3 mb-4">
            <div class="col-12 col-md-4">
              <div class="category-overview-card">
                <div class="category-overview-title">
                  <span><i class="bi bi-check2-square text-primary me-1"></i> Chores</span>
                  <span class="badge bg-white text-dark-accent border"><?= count($choreCategories) ?></span>
                </div>
                <div class="category-pill-list">
                  <?php if (empty($choreCategories)): ?>
                    <small class="text-muted">No categories</small>
                  <?php else: ?>
                    <?php foreach (array_slice($choreCategories, 0, 4) as $cc): ?>
                      <span class="category-chip"><?= h((string) $cc['category_name']) ?></span>
                    <?php endforeach; ?>
                    <?php if (count($choreCategories) > 4): ?>
                      <span class="category-chip bg-warm-secondary">+<?= count($choreCategories) - 4 ?> more</span>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <div class="col-12 col-md-4">
              <div class="category-overview-card">
                <div class="category-overview-title">
                  <span><i class="bi bi-wallet2 text-dark-accent me-1"></i> Expenses</span>
                  <span class="badge bg-white text-dark-accent border"><?= count($expenseCategories) ?></span>
                </div>
                <div class="category-pill-list">
                  <?php if (empty($expenseCategories)): ?>
                    <small class="text-muted">No categories</small>
                  <?php else: ?>
                    <?php foreach (array_slice($expenseCategories, 0, 4) as $ec): ?>
                      <span class="category-chip"><?= h((string) $ec['category_name']) ?></span>
                    <?php endforeach; ?>
                    <?php if (count($expenseCategories) > 4): ?>
                      <span class="category-chip bg-warm-secondary">+<?= count($expenseCategories) - 4 ?> more</span>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <div class="col-12 col-md-4">
              <div class="category-overview-card">
                <div class="category-overview-title">
                  <span><i class="bi bi-receipt text-warning me-1"></i> Bills</span>
                  <span class="badge bg-white text-dark-accent border"><?= count($billCategories) ?></span>
                </div>
                <div class="category-pill-list">
                  <?php if (empty($billCategories)): ?>
                    <small class="text-muted">No categories</small>
                  <?php else: ?>
                    <?php foreach (array_slice($billCategories, 0, 4) as $bc): ?>
                      <span class="category-chip"><?= h((string) $bc['category_name']) ?></span>
                    <?php endforeach; ?>
                    <?php if (count($billCategories) > 4): ?>
                      <span class="category-chip bg-warm-secondary">+<?= count($billCategories) - 4 ?> more</span>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <!-- Category Management Tabs (Chores | Expenses | Bills) -->
          <div class="manage-tabs">
            <button type="button" class="nav-link category-tab-btn active" data-category-type="chore">
              <i class="bi bi-check2-square me-1"></i> Chore Categories (<?= count($choreCategories) ?>)
            </button>
            <button type="button" class="nav-link category-tab-btn" data-category-type="expense">
              <i class="bi bi-wallet2 me-1"></i> Expense Categories (<?= count($expenseCategories) ?>)
            </button>
            <button type="button" class="nav-link category-tab-btn" data-category-type="bill">
              <i class="bi bi-receipt me-1"></i> Bill Categories (<?= count($billCategories) ?>)
            </button>
          </div>

          <!-- Category Panels -->
          <!-- Chores Panel -->
          <div class="category-panel" data-category-type="chore">
            <div class="manage-table-wrapper">
              <table class="table manage-table">
                <thead>
                  <tr>
                    <th>Category Name</th>
                    <th>Description</th>
                    <th class="text-center">Used in Chores</th>
                    <th class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($choreCategories)): ?>
                    <tr><td colspan="4" class="manage-empty-state"><i class="bi bi-tag"></i><p class="mb-0">No chore categories defined.</p></td></tr>
                  <?php else: ?>
                    <?php foreach ($choreCategories as $cat): ?>
                      <?php $cId = (int) $cat['chore_category_id']; $cName = (string) $cat['category_name']; $cDesc = (string) ($cat['description'] ?? ''); $uCount = (int) $cat['usage_count']; ?>
                      <tr>
                        <td class="fw-bold text-main"><?= h($cName) ?></td>
                        <td><?= $cDesc !== '' ? h($cDesc) : '<span class="text-muted fst-italic">None</span>' ?></td>
                        <td class="text-center"><span class="badge bg-warm-secondary text-dark-accent border"><?= $uCount ?></span></td>
                        <td class="text-end">
                          <button type="button" class="btn btn-sm btn-rs-outline btn-edit-category" data-bs-toggle="modal" data-bs-target="#editCategoryModal" data-type="chore" data-id="<?= $cId ?>" data-name="<?= h($cName) ?>" data-desc="<?= h($cDesc) ?>"><i class="bi bi-pencil"></i></button>
                          <button type="button" class="btn btn-sm btn-rs-outline text-danger btn-delete-category" data-bs-toggle="modal" data-bs-target="#deleteCategoryModal" data-type="chore" data-id="<?= $cId ?>" data-name="<?= h($cName) ?>"><i class="bi bi-trash3"></i></button>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Expenses Panel -->
          <div class="category-panel d-none" data-category-type="expense">
            <div class="manage-table-wrapper">
              <table class="table manage-table">
                <thead>
                  <tr>
                    <th>Category Name</th>
                    <th>Description</th>
                    <th class="text-center">Used in Expenses</th>
                    <th class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($expenseCategories)): ?>
                    <tr><td colspan="4" class="manage-empty-state"><i class="bi bi-tag"></i><p class="mb-0">No expense categories defined.</p></td></tr>
                  <?php else: ?>
                    <?php foreach ($expenseCategories as $cat): ?>
                      <?php $cId = (int) $cat['expense_category_id']; $cName = (string) $cat['category_name']; $cDesc = (string) ($cat['description'] ?? ''); $uCount = (int) $cat['usage_count']; ?>
                      <tr>
                        <td class="fw-bold text-main"><?= h($cName) ?></td>
                        <td><?= $cDesc !== '' ? h($cDesc) : '<span class="text-muted fst-italic">None</span>' ?></td>
                        <td class="text-center"><span class="badge bg-warm-secondary text-dark-accent border"><?= $uCount ?></span></td>
                        <td class="text-end">
                          <button type="button" class="btn btn-sm btn-rs-outline btn-edit-category" data-bs-toggle="modal" data-bs-target="#editCategoryModal" data-type="expense" data-id="<?= $cId ?>" data-name="<?= h($cName) ?>" data-desc="<?= h($cDesc) ?>"><i class="bi bi-pencil"></i></button>
                          <button type="button" class="btn btn-sm btn-rs-outline text-danger btn-delete-category" data-bs-toggle="modal" data-bs-target="#deleteCategoryModal" data-type="expense" data-id="<?= $cId ?>" data-name="<?= h($cName) ?>"><i class="bi bi-trash3"></i></button>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Bills Panel -->
          <div class="category-panel d-none" data-category-type="bill">
            <div class="manage-table-wrapper">
              <table class="table manage-table">
                <thead>
                  <tr>
                    <th>Category Name</th>
                    <th>Description</th>
                    <th class="text-center">Used in Bills</th>
                    <th class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($billCategories)): ?>
                    <tr><td colspan="4" class="manage-empty-state"><i class="bi bi-tag"></i><p class="mb-0">No bill categories defined.</p></td></tr>
                  <?php else: ?>
                    <?php foreach ($billCategories as $cat): ?>
                      <?php $cId = (int) $cat['bill_category_id']; $cName = (string) $cat['category_name']; $cDesc = (string) ($cat['description'] ?? ''); $uCount = (int) $cat['usage_count']; ?>
                      <tr>
                        <td class="fw-bold text-main"><?= h($cName) ?></td>
                        <td><?= $cDesc !== '' ? h($cDesc) : '<span class="text-muted fst-italic">None</span>' ?></td>
                        <td class="text-center"><span class="badge bg-warm-secondary text-dark-accent border"><?= $uCount ?></span></td>
                        <td class="text-end">
                          <button type="button" class="btn btn-sm btn-rs-outline btn-edit-category" data-bs-toggle="modal" data-bs-target="#editCategoryModal" data-type="bill" data-id="<?= $cId ?>" data-name="<?= h($cName) ?>" data-desc="<?= h($cDesc) ?>"><i class="bi bi-pencil"></i></button>
                          <button type="button" class="btn btn-sm btn-rs-outline text-danger btn-delete-category" data-bs-toggle="modal" data-bs-target="#deleteCategoryModal" data-type="bill" data-id="<?= $cId ?>" data-name="<?= h($cName) ?>"><i class="bi bi-trash3"></i></button>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </section>
      </div>

      <!-- 5. QUICK ACTIONS -->
      <div class="col-12 col-xl-4" id="quickActionsSection">
        <section class="manage-card mb-0 h-100">
          <div class="manage-card-header">
            <div class="manage-card-title-wrap">
              <div class="manage-card-icon actions"><i class="bi bi-lightning-charge-fill"></i></div>
              <div>
                <h2 class="h5 fw-bold text-main mb-0">Quick Actions</h2>
                <small class="text-secondary-custom">Convenient admin shortcuts</small>
              </div>
            </div>
          </div>

          <div class="d-flex flex-column gap-2">
            <a href="#createAnnouncementModal" data-bs-toggle="modal" class="quick-action-btn">
              <div class="quick-action-icon"><i class="bi bi-megaphone"></i></div>
              <div class="quick-action-text">
                <strong>New Announcement</strong>
                <small>Post an update to your household</small>
              </div>
            </a>

            <a href="#addCategoryModal" data-bs-toggle="modal" class="quick-action-btn">
              <div class="quick-action-icon"><i class="bi bi-tag"></i></div>
              <div class="quick-action-text">
                <strong>Add Category</strong>
                <small>Create a new chore, expense, or bill type</small>
              </div>
            </a>
            <!-- Admin-Only Add Chore Shortcut -->
            <a href="#addChoreModal" data-bs-toggle="modal" class="quick-action-btn">
              <div class="quick-action-icon"><i class="bi bi-check2-circle"></i></div>
              <div class="quick-action-text">
                <strong>Create Chore (Admin)</strong>
                <small>Set up a single or rotating chore task</small>
              </div>
            </a>
            <a href="#householdSettingsSection" class="quick-action-btn">
              <div class="quick-action-icon"><i class="bi bi-gear"></i></div>
              <div class="quick-action-text">
                <strong>Household Settings</strong>
                <small>Edit name, address, and picture</small>
              </div>
            </a>
            <a href="#activityLogsSection" class="quick-action-btn">
              <div class="quick-action-icon"><i class="bi bi-clock-history"></i></div>
              <div class="quick-action-text">
                <strong>View Activity Logs</strong>
                <small>Review recent household actions</small>
              </div>
            </a>
          </div>
        </section>
      </div>

    </div>

    <!-- BOTTOM SECTION: 6. ACTIVITY LOGS & 7. HOUSEHOLD SETTINGS -->
    <div class="row g-4 mb-5">

      <!-- 6. ACTIVITY LOGS SECTION -->
      <div class="col-12 col-lg-6" id="activityLogsSection">
        <section class="manage-card mb-0 h-100 card-household">
          <div class="manage-card-header">
            <div class="manage-card-title-wrap">
              <div class="manage-card-icon activity"><i class="bi bi-clock-history"></i></div>
              <div>
                <h2 class="h5 fw-bold text-main mb-0">Activity Logs</h2>
                <small class="text-secondary-custom">Recent actions derived from household records</small>
              </div>
            </div>
            <span class="badge bg-warm-secondary text-dark-accent border py-1 px-2">
              <?= count($activityLogs) ?> Logged Events
            </span>
          </div>

          <?php if (empty($activityLogs)): ?>
            <div class="manage-empty-state">
              <i class="bi bi-clock-history"></i>
              <p class="mb-0">No recent activity recorded for this household.</p>
            </div>
          <?php else: ?>
            <div class="activity-list" style="max-height: 480px; overflow-y: auto;">
              <?php foreach ($activityLogs as $log): ?>
                <?php
                  $lType = (string) $log['type'];
                  $iconClass = 'bi-activity';
                  if ($lType === 'announcement') $iconClass = 'bi-megaphone-fill text-primary';
                  elseif ($lType === 'chore_completed') $iconClass = 'bi-check2-circle text-success';
                  elseif ($lType === 'chore_created') $iconClass = 'bi-plus-circle text-info';
                  elseif ($lType === 'expense') $iconClass = 'bi-wallet2 text-dark-accent';
                  elseif ($lType === 'bill') $iconClass = 'bi-receipt text-warning';
                  elseif ($lType === 'maintenance') $iconClass = 'bi-tools text-danger';
                  elseif ($lType === 'member_joined') $iconClass = 'bi-person-plus-fill text-success';

                  $logDate = date('M j, Y, g:i A', strtotime((string) $log['event_time']));
                ?>
                <div class="activity-item">
                  <div class="activity-item-left">
                    <div class="activity-item-icon">
                      <i class="bi <?= $iconClass ?>"></i>
                    </div>
                    <div>
                      <div class="activity-item-title"><?= h((string) $log['title']) ?></div>
                      <div class="activity-item-meta">
                        <span><i class="bi bi-person me-1"></i><?= h((string) $log['user_name']) ?></span>
                        <span class="mx-1">&bull;</span>
                        <span><?= h($logDate) ?></span>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
      </div>

      <!-- 7. HOUSEHOLD SETTINGS SECTION -->
      <div class="col-12 col-lg-6" id="householdSettingsSection">
        <section class="manage-card mb-0 h-100 card-household">
          <div class="manage-card-header">
            <div class="manage-card-title-wrap">
              <div class="manage-card-icon settings"><i class="bi bi-gear-fill"></i></div>
              <div>
                <h2 class="h5 fw-bold text-main mb-0">Household Settings</h2>
                <small class="text-secondary-custom">Manage household details, banner image, and invite code</small>
              </div>
            </div>
          </div>

          <form action="manage.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_household">

            <!-- Banner Preview -->
            <div class="mb-3" id="householdImagePreviewBox">
              <?php if ($householdImage !== ''): ?>
                <img src="<?= h($householdImage) ?>" alt="<?= h($householdName) ?>" class="household-banner-img mb-2">
              <?php else: ?>
                <div class="household-banner-placeholder mb-2">
                  <i class="bi bi-house-heart-fill mb-1"></i>
                  <span class="small fw-bold opacity-75">RoomieSync Home</span>
                </div>
              <?php endif; ?>
            </div>

            <!-- Household Name -->
            <div class="mb-3">
              <label for="household_name" class="form-label text-secondary-custom small fw-bold text-uppercase">Household Name <span class="text-danger">*</span></label>
              <input type="text" name="household_name" id="household_name" class="form-control manage-input" value="<?= h($householdName) ?>" maxlength="100" required>
            </div>

            <!-- Description -->
            <div class="mb-3">
              <label for="description" class="form-label text-secondary-custom small fw-bold text-uppercase">Description</label>
              <textarea name="description" id="description" rows="2" class="form-control manage-input" placeholder="A cozy home for students..."><?= h($householdDesc) ?></textarea>
            </div>

            <!-- Address -->
            <div class="mb-3">
              <label for="address" class="form-label text-secondary-custom small fw-bold text-uppercase">Address</label>
              <input type="text" name="address" id="address" class="form-control manage-input" value="<?= h($householdAddress) ?>" maxlength="255" placeholder="e.g. 123 Main St, Apt 4B">
            </div>

            <!-- Image File Upload -->
            <div class="mb-3">
              <label for="householdImageFileInput" class="form-label text-secondary-custom small fw-bold text-uppercase">Household Banner Image</label>
              <input type="file" name="household_image_file" id="householdImageFileInput" class="form-control manage-input" accept="image/jpeg,image/png,image/webp,image/gif">
              <small class="text-secondary-custom d-block mt-1">Recommended: landscape orientation (JPG, PNG, WebP, GIF &bull; Max 2MB).</small>
            </div>

            <!-- Join Code Section -->
            <div class="p-3 rounded bg-warm-secondary border mb-4">
              <label class="form-label text-secondary-custom small fw-bold text-uppercase d-block mb-1">Household Join Code</label>
              <div class="join-code-box w-100 justify-content-between">
                <div class="d-flex align-items-center gap-2">
                  <i class="bi bi-key-fill text-dark-accent"></i>
                  <span class="join-code-val"><?= h($householdJoinCode !== '' ? $householdJoinCode : 'N/A') ?></span>
                </div>
                <?php if ($householdJoinCode !== ''): ?>
                  <button type="button" class="btn btn-rs-outline btn-sm btn-copy-code" data-code="<?= h($householdJoinCode) ?>">
                    <i class="bi bi-clipboard me-1"></i> Copy
                  </button>
                <?php endif; ?>
              </div>
              <div class="d-flex justify-content-between align-items-center mt-2">
                <small class="text-secondary-custom">Share this code with roommates to join.</small>
                <small class="text-secondary-custom">Created on: <?= date('M j, Y', strtotime($householdCreatedAt)) ?></small>
              </div>
            </div>

            <button type="submit" class="btn btn-rs-primary w-100">
              <i class="bi bi-check2-circle me-1"></i> Save Household Changes
            </button>
          </form>
        </section>
      </div>

    </div>

  </main>

  <!-- ==================================================
       MODALS FOR ADMIN ACTIONS
       ================================================== -->



  <!-- Modal 2: Change Member Role -->
  <div class="modal fade" id="changeRoleModal" tabindex="-1" aria-labelledby="changeRoleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content rs-modal">
        <div class="modal-header rs-modal-header">
          <h2 class="modal-title h5 text-main" id="changeRoleModalLabel"><i class="bi bi-shield-shaded text-dark-accent me-2"></i>Change Member Role</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form action="manage.php" method="POST">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="change_role">
          <input type="hidden" name="target_user_id" id="changeRoleUserId" value="">
          <div class="modal-body rs-modal-body">
            <p class="mb-3">Change household role for <strong id="changeRoleMemberNameText" class="text-main">this member</strong>.</p>
            <div class="mb-3">
              <label for="changeRoleSelect" class="form-label text-secondary-custom small fw-bold text-uppercase">New Role <span class="text-danger">*</span></label>
              <select name="new_role" id="changeRoleSelect" class="form-select manage-input" required>
                <option value="Roommate">Roommate (Standard member access)</option>
                <option value="Admin">Admin (Full household management rights)</option>
              </select>
            </div>
            <small class="text-secondary-custom d-block">
              <i class="bi bi-info-circle me-1"></i> Every household must retain at least one Administrator.
            </small>
          </div>
          <div class="modal-footer rs-modal-footer">
            <button type="button" class="btn btn-rs-outline" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-rs-primary"><i class="bi bi-check2 me-1"></i> Update Role</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Modal 3: Remove Member Confirmation -->
  <div class="modal fade" id="removeMemberModal" tabindex="-1" aria-labelledby="removeMemberModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content rs-modal">
        <div class="modal-header rs-modal-header">
          <h2 class="modal-title h5 text-main" id="removeMemberModalLabel"><i class="bi bi-person-x text-danger me-2"></i>Remove Member</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form action="manage.php" method="POST">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="remove_member">
          <input type="hidden" name="target_user_id" id="removeMemberId" value="">
          <div class="modal-body rs-modal-body">
            <p class="mb-2">Are you sure you want to remove <strong id="removeMemberNameText" class="text-main">this member</strong> from <strong><?= h($householdName) ?></strong>?</p>
            <p class="text-secondary-custom small mb-0">This will remove their household membership. Their user account will NOT be deleted.</p>
          </div>
          <div class="modal-footer rs-modal-footer">
            <button type="button" class="btn btn-rs-outline" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-danger"><i class="bi bi-person-x me-1"></i> Remove Member</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Modal 4: Create Announcement -->
  <div class="modal fade" id="createAnnouncementModal" tabindex="-1" aria-labelledby="createAnnouncementModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content rs-modal">
        <div class="modal-header rs-modal-header">
          <h2 class="modal-title h5 text-main" id="createAnnouncementModalLabel"><i class="bi bi-megaphone text-dark-accent me-2"></i>New Announcement</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form action="manage.php" method="POST">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="create_announcement">
          <div class="modal-body rs-modal-body">
            <div class="mb-3">
              <label for="createAnnTitle" class="form-label text-secondary-custom small fw-bold text-uppercase">Title <span class="text-danger">*</span></label>
              <input type="text" name="title" id="createAnnTitle" class="form-control manage-input" placeholder="e.g. Monthly House Meeting this Sunday" maxlength="100" required>
            </div>
            <div class="mb-3">
              <label for="createAnnContent" class="form-label text-secondary-custom small fw-bold text-uppercase">Content <span class="text-danger">*</span></label>
              <textarea name="content" id="createAnnContent" rows="4" class="form-control manage-input" placeholder="Share details with your roommates..." required></textarea>
            </div>
          </div>
          <div class="modal-footer rs-modal-footer">
            <button type="button" class="btn btn-rs-outline" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-rs-primary"><i class="bi bi-send me-1"></i> Create Announcement</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Modal 5: Edit Announcement -->
  <div class="modal fade" id="editAnnouncementModal" tabindex="-1" aria-labelledby="editAnnouncementModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content rs-modal">
        <div class="modal-header rs-modal-header">
          <h2 class="modal-title h5 text-main" id="editAnnouncementModalLabel"><i class="bi bi-pencil text-dark-accent me-2"></i>Edit Announcement</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form action="manage.php" method="POST">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="edit_announcement">
          <input type="hidden" name="announcement_id" id="editAnnouncementId" value="">
          <div class="modal-body rs-modal-body">
            <div class="mb-3">
              <label for="editAnnouncementTitle" class="form-label text-secondary-custom small fw-bold text-uppercase">Title <span class="text-danger">*</span></label>
              <input type="text" name="title" id="editAnnouncementTitle" class="form-control manage-input" maxlength="100" required>
            </div>
            <div class="mb-3">
              <label for="editAnnouncementContent" class="form-label text-secondary-custom small fw-bold text-uppercase">Content <span class="text-danger">*</span></label>
              <textarea name="content" id="editAnnouncementContent" rows="4" class="form-control manage-input" required></textarea>
            </div>
          </div>
          <div class="modal-footer rs-modal-footer">
            <button type="button" class="btn btn-rs-outline" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-rs-primary"><i class="bi bi-check2 me-1"></i> Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Modal 6: Delete Announcement Confirmation -->
  <div class="modal fade" id="deleteAnnouncementModal" tabindex="-1" aria-labelledby="deleteAnnouncementModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content rs-modal">
        <div class="modal-header rs-modal-header">
          <h2 class="modal-title h5 text-main" id="deleteAnnouncementModalLabel"><i class="bi bi-trash3 text-danger me-2"></i>Delete Announcement</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form action="manage.php" method="POST">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="delete_announcement">
          <input type="hidden" name="announcement_id" id="deleteAnnouncementId" value="">
          <div class="modal-body rs-modal-body">
            <p class="mb-0">Are you sure you want to delete <strong id="deleteAnnouncementTitleText" class="text-main">this announcement</strong>?</p>
          </div>
          <div class="modal-footer rs-modal-footer">
            <button type="button" class="btn btn-rs-outline" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-danger"><i class="bi bi-trash3 me-1"></i> Delete Announcement</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Modal 7: Add Category -->
  <div class="modal fade" id="addCategoryModal" tabindex="-1" aria-labelledby="addCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content rs-modal">
        <div class="modal-header rs-modal-header">
          <h2 class="modal-title h5 text-main" id="addCategoryModalLabel"><i class="bi bi-tag text-dark-accent me-2"></i>Add New Category</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form action="manage.php" method="POST">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="add_category">
          <div class="modal-body rs-modal-body">
            <div class="mb-3">
              <label for="addCategoryTypeSelect" class="form-label text-secondary-custom small fw-bold text-uppercase">Category Type <span class="text-danger">*</span></label>
              <select name="category_type" id="addCategoryTypeSelect" class="form-select manage-input" required>
                <option value="chore">Chores Category</option>
                <option value="expense">Expense Category</option>
                <option value="bill">Bill Category</option>
              </select>
            </div>
            <div class="mb-3">
              <label for="addCategoryNameInput" class="form-label text-secondary-custom small fw-bold text-uppercase">Category Name <span class="text-danger">*</span></label>
              <input type="text" name="category_name" id="addCategoryNameInput" class="form-control manage-input" placeholder="e.g. Groceries, Living Room, Internet" maxlength="50" required>
            </div>
            <div class="mb-3">
              <label for="addCategoryDescInput" class="form-label text-secondary-custom small fw-bold text-uppercase">Description</label>
              <textarea name="description" id="addCategoryDescInput" rows="2" class="form-control manage-input" placeholder="Optional notes for this category..."></textarea>
            </div>
          </div>
          <div class="modal-footer rs-modal-footer">
            <button type="button" class="btn btn-rs-outline" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-rs-primary"><i class="bi bi-plus-lg me-1"></i> Add Category</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Modal 8: Edit Category -->
  <div class="modal fade" id="editCategoryModal" tabindex="-1" aria-labelledby="editCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content rs-modal">
        <div class="modal-header rs-modal-header">
          <h2 class="modal-title h5 text-main" id="editCategoryModalLabel">
            <i class="bi bi-pencil text-dark-accent me-2"></i>Edit <span id="editCategoryTypeBadge" class="badge bg-warm-secondary text-dark-accent border">Category</span>
          </h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form action="manage.php" method="POST">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="edit_category">
          <input type="hidden" name="category_type" id="editCategoryType" value="chore">
          <input type="hidden" name="category_id" id="editCategoryId" value="">
          <div class="modal-body rs-modal-body">
            <div class="mb-3">
              <label for="editCategoryName" class="form-label text-secondary-custom small fw-bold text-uppercase">Category Name <span class="text-danger">*</span></label>
              <input type="text" name="category_name" id="editCategoryName" class="form-control manage-input" maxlength="50" required>
            </div>
            <div class="mb-3">
              <label for="editCategoryDesc" class="form-label text-secondary-custom small fw-bold text-uppercase">Description</label>
              <textarea name="description" id="editCategoryDesc" rows="2" class="form-control manage-input"></textarea>
            </div>
          </div>
          <div class="modal-footer rs-modal-footer">
            <button type="button" class="btn btn-rs-outline" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-rs-primary"><i class="bi bi-check2 me-1"></i> Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Modal 9: Delete Category Confirmation -->
  <div class="modal fade" id="deleteCategoryModal" tabindex="-1" aria-labelledby="deleteCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content rs-modal">
        <div class="modal-header rs-modal-header">
          <h2 class="modal-title h5 text-main" id="deleteCategoryModalLabel"><i class="bi bi-trash3 text-danger me-2"></i>Delete Category</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form action="manage.php" method="POST">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="delete_category">
          <input type="hidden" name="category_type" id="deleteCategoryType" value="">
          <input type="hidden" name="category_id" id="deleteCategoryId" value="">
          <div class="modal-body rs-modal-body">
            <p class="mb-2">Are you sure you want to delete <strong id="deleteCategoryNameText" class="text-main">this category</strong>?</p>
            <small class="text-secondary-custom d-block">
              <i class="bi bi-exclamation-circle me-1"></i> Categories currently referenced by existing records cannot be deleted.
            </small>
          </div>
          <div class="modal-footer rs-modal-footer">
            <button type="button" class="btn btn-rs-outline" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-danger"><i class="bi bi-trash3 me-1"></i> Delete Category</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Modal 10: Admin-Only Add Chore Shortcut -->
  <div class="modal fade" id="addChoreModal" tabindex="-1" aria-labelledby="addChoreModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content rs-modal">
        <div class="modal-header rs-modal-header">
          <h2 class="modal-title h5 text-main" id="addChoreModalLabel"><i class="bi bi-check2-square text-dark-accent me-2"></i>Create Chore (Admin Shortcut)</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form action="manage.php" method="POST">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="add_chore">
          <div class="modal-body rs-modal-body">
            <div class="row g-3">
              <div class="col-12 col-md-6">
                <label for="quickChoreName" class="form-label text-secondary-custom small fw-bold text-uppercase">Chore Name <span class="text-danger">*</span></label>
                <input type="text" name="chore_name" id="quickChoreName" class="form-control manage-input" placeholder="e.g. Wash Dishes" maxlength="100" required>
              </div>
              <div class="col-12 col-md-6">
                <label for="quickChoreCategory" class="form-label text-secondary-custom small fw-bold text-uppercase">Category</label>
                <select name="category_id" id="quickChoreCategory" class="form-select manage-input">
                  <option value="">-- General / None --</option>
                  <?php foreach ($choreCategories as $cc): ?>
                    <option value="<?= (int) $cc['chore_category_id'] ?>"><?= h((string) $cc['category_name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12">
                <label for="quickChoreDesc" class="form-label text-secondary-custom small fw-bold text-uppercase">Description</label>
                <textarea name="description" id="quickChoreDesc" rows="2" class="form-control manage-input" placeholder="Optional instructions..."></textarea>
              </div>
              <div class="col-12 col-md-4">
                <label for="quickChoreFreq" class="form-label text-secondary-custom small fw-bold text-uppercase">Frequency</label>
                <select name="frequency" id="quickChoreFreq" class="form-select manage-input">
                  <option value="Daily">Daily</option>
                  <option value="Weekly" selected>Weekly</option>
                  <option value="Bi-weekly">Bi-weekly</option>
                  <option value="Monthly">Monthly</option>
                  <option value="One-time">One-time</option>
                </select>
              </div>
              <div class="col-12 col-md-4">
                <label for="quickChoreType" class="form-label text-secondary-custom small fw-bold text-uppercase">Assignment Type</label>
                <select name="assignment_type" id="quickChoreType" class="form-select manage-input">
                  <option value="Single" selected>Single Member</option>
                  <option value="Rotation">Rotation</option>
                </select>
              </div>
              <div class="col-12 col-md-4">
                <label for="quickChoreDueDate" class="form-label text-secondary-custom small fw-bold text-uppercase">Due Date <span class="text-danger">*</span></label>
                <input type="date" name="due_date" id="quickChoreDueDate" class="form-control manage-input" value="<?= date('Y-m-d') ?>" required>
              </div>
              <div class="col-12 col-md-6" id="quickChoreSingleMemberWrap">
                <label for="quickChoreAssignee" class="form-label text-secondary-custom small fw-bold text-uppercase">Assign To</label>
                <select name="assigned_user_id" id="quickChoreAssignee" class="form-select manage-input">
                  <?php foreach ($members as $m): ?>
                    <option value="<?= (int) $m['user_id'] ?>" <?= ((int) $m['user_id'] === $userId) ? 'selected' : '' ?>>
                      <?= h((string) (!empty($m['full_name']) ? $m['full_name'] : $m['username'])) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12" id="quickChoreRotationMembersWrap" style="display: none;">
                <label class="form-label text-secondary-custom small fw-bold text-uppercase d-block">Rotation Order (Check Members)</label>
                <div class="d-flex flex-wrap gap-2">
                  <?php foreach ($members as $m): ?>
                    <div class="form-check form-check-inline">
                      <input class="form-check-input" type="checkbox" name="rotation_members[]" value="<?= (int) $m['user_id'] ?>" id="quickRot_<?= (int) $m['user_id'] ?>" checked>
                      <label class="form-check-label" for="quickRot_<?= (int) $m['user_id'] ?>">
                        <?= h((string) (!empty($m['full_name']) ? $m['full_name'] : $m['username'])) ?>
                      </label>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>
          <div class="modal-footer rs-modal-footer">
            <button type="button" class="btn btn-rs-outline" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-rs-primary"><i class="bi bi-plus-lg me-1"></i> Create Chore</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/main.js"></script>
  <script src="assets/js/manage.js"></script>
  <script>
    // Inline quick chore type switcher
    const qChoreType = document.getElementById('quickChoreType');
    const qSingleWrap = document.getElementById('quickChoreSingleMemberWrap');
    const qRotWrap = document.getElementById('quickChoreRotationMembersWrap');
    if (qChoreType && qSingleWrap && qRotWrap) {
      qChoreType.addEventListener('change', () => {
        if (qChoreType.value === 'Rotation') {
          qSingleWrap.style.display = 'none';
          qRotWrap.style.display = 'block';
        } else {
          qSingleWrap.style.display = 'block';
          qRotWrap.style.display = 'none';
        }
      });
    }
  </script>
</body>
</html>
