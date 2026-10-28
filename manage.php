<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/pagination.php';

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
              <?= render_current_user_avatar($avatarText, $displayName) ?>
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
    function manage_set_flash_and_redirect(string $message, string $type = 'success', string $url = 'manage.php'): never
    {
        $_SESSION['flash_message'] = ['message' => $message, 'type' => $type];
        redirect_to($url);
    }
}

// Determine active openable page section (overview, users, categories, announcements, settings, maintenance)
$section = isset($_GET['section']) ? strtolower(trim((string)$_GET['section'])) : '';
$validSections = ['overview', 'users', 'categories', 'announcements', 'settings', 'maintenance'];
if (!in_array($section, $validSections, true)) {
    $section = 'overview';
}

// ==================================================
// POST ACTIONS HANDLING (ADMIN-ONLY)
// ==================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        manage_set_flash_and_redirect('This form expired. Please try again.', 'danger', 'manage.php');
    }

    $action = is_string($_POST['action'] ?? null) ? trim($_POST['action']) : '';

    // 1. CHANGE MEMBER ROLE
    if ($action === 'change_role') {
        $rawTargetUserId = $_POST['target_user_id'] ?? null;
        $targetUserId = is_string($rawTargetUserId) && ctype_digit($rawTargetUserId) ? (int) $rawTargetUserId : 0;
        $newRole = trim(is_string($_POST['new_role'] ?? null) ? $_POST['new_role'] : '');

        if ($targetUserId < 1 || !in_array($newRole, ['Admin', 'Roommate'], true)) {
            manage_set_flash_and_redirect('Invalid role or member specified.', 'danger', 'manage.php?section=users');
        }

        $checkMember = $connection->prepare(
            'SELECT hm.role, COALESCE(NULLIF(u.full_name, ""), u.username) AS member_name
             FROM householdmembersTb hm
             INNER JOIN userTb u ON u.user_id = hm.user_id
             WHERE hm.household_id = ? AND hm.user_id = ? LIMIT 1'
        );
        $checkMember->execute([$householdId, $targetUserId]);
        $target = $checkMember->fetch();
        if (!$target) {
            manage_set_flash_and_redirect('Member not found in this household.', 'danger', 'manage.php?section=users');
        }

        $currentRole = (string) $target['role'];

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
                    'manage.php?section=users'
                );
            }
        }

        $updateRole = $connection->prepare(
            'UPDATE householdmembersTb SET role = ? WHERE household_id = ? AND user_id = ?'
        );
        $updateRole->execute([$newRole, $householdId, $targetUserId]);

        $mName = (string) $target['member_name'];
        manage_set_flash_and_redirect("Updated {$mName}'s role to {$newRole}.", 'success', 'manage.php?section=users');
    }

    // 2. REMOVE MEMBER FROM HOUSEHOLD
    if ($action === 'remove_member') {
        $rawTargetUserId = $_POST['target_user_id'] ?? null;
        $targetUserId = is_string($rawTargetUserId) && ctype_digit($rawTargetUserId) ? (int) $rawTargetUserId : 0;

        if ($targetUserId < 1) {
            manage_set_flash_and_redirect('Invalid member specified.', 'danger', 'manage.php?section=users');
        }

        $checkMember = $connection->prepare(
            'SELECT hm.role, COALESCE(NULLIF(u.full_name, ""), u.username) AS member_name
             FROM householdmembersTb hm
             INNER JOIN userTb u ON u.user_id = hm.user_id
             WHERE hm.household_id = ? AND hm.user_id = ? LIMIT 1'
        );
        $checkMember->execute([$householdId, $targetUserId]);
        $target = $checkMember->fetch();
        if (!$target) {
            manage_set_flash_and_redirect('Member not found in this household.', 'danger', 'manage.php?section=users');
        }

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
                    'manage.php?section=users'
                );
            }
        }

        $deleteMember = $connection->prepare(
            'DELETE FROM householdmembersTb WHERE household_id = ? AND user_id = ?'
        );
        $deleteMember->execute([$householdId, $targetUserId]);

        $mName = (string) $target['member_name'];
        manage_set_flash_and_redirect("Removed {$mName} from the household.", 'success', 'manage.php?section=users');
    }

    // 3. CREATE ANNOUNCEMENT
    if ($action === 'create_announcement') {
        $title = trim(is_string($_POST['title'] ?? null) ? $_POST['title'] : '');
        $content = trim(is_string($_POST['content'] ?? null) ? $_POST['content'] : '');

        if ($title === '' || strlen($title) > 100) {
            manage_set_flash_and_redirect('Enter an announcement title (up to 100 characters).', 'danger', 'manage.php?section=announcements');
        }
        if ($content === '') {
            manage_set_flash_and_redirect('Please enter announcement content.', 'danger', 'manage.php?section=announcements');
        }

        $insertAnn = $connection->prepare(
            'INSERT INTO announceTb (household_id, created_by, title, content, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        );
        $insertAnn->execute([$householdId, $userId, $title, $content]);

        manage_set_flash_and_redirect('Announcement published successfully!', 'success', 'manage.php?section=announcements');
    }

    // 4. EDIT ANNOUNCEMENT
    if ($action === 'edit_announcement') {
        $rawAnnId = $_POST['announcement_id'] ?? null;
        $annId = is_string($rawAnnId) && ctype_digit($rawAnnId) ? (int) $rawAnnId : 0;
        $title = trim(is_string($_POST['title'] ?? null) ? $_POST['title'] : '');
        $content = trim(is_string($_POST['content'] ?? null) ? $_POST['content'] : '');

        if ($annId < 1) {
            manage_set_flash_and_redirect('Invalid announcement specified.', 'danger', 'manage.php?section=announcements');
        }
        if ($title === '' || strlen($title) > 100) {
            manage_set_flash_and_redirect('Enter an announcement title (up to 100 characters).', 'danger', 'manage.php?section=announcements');
        }
        if ($content === '') {
            manage_set_flash_and_redirect('Please enter announcement content.', 'danger', 'manage.php?section=announcements');
        }

        $updateAnn = $connection->prepare(
            'UPDATE announceTb SET title = ?, content = ? WHERE announcement_id = ? AND household_id = ?'
        );
        $updateAnn->execute([$title, $content, $annId, $householdId]);

        manage_set_flash_and_redirect('Announcement updated successfully.', 'success', 'manage.php?section=announcements');
    }

    // 5. DELETE ANNOUNCEMENT
    if ($action === 'delete_announcement') {
        $rawAnnId = $_POST['announcement_id'] ?? null;
        $annId = is_string($rawAnnId) && ctype_digit($rawAnnId) ? (int) $rawAnnId : 0;

        if ($annId < 1) {
            manage_set_flash_and_redirect('Invalid announcement specified.', 'danger', 'manage.php?section=announcements');
        }

        $deleteAnn = $connection->prepare('DELETE FROM announceTb WHERE announcement_id = ? AND household_id = ?');
        $deleteAnn->execute([$annId, $householdId]);

        manage_set_flash_and_redirect('Announcement deleted successfully.', 'success', 'manage.php?section=announcements');
    }

    // 6. ADD CATEGORY (Chores, Expenses, Bills)
    if ($action === 'add_category') {
        $catType = trim(is_string($_POST['category_type'] ?? null) ? $_POST['category_type'] : '');
        $name = trim(is_string($_POST['category_name'] ?? null) ? $_POST['category_name'] : '');
        $desc = trim(is_string($_POST['description'] ?? null) ? $_POST['description'] : '');

        if (!in_array($catType, ['chore', 'expense', 'bill'], true)) {
            manage_set_flash_and_redirect('Invalid category type.', 'danger', 'manage.php?section=categories');
        }
        if ($name === '' || strlen($name) > 50) {
            manage_set_flash_and_redirect('Enter a category name up to 50 characters.', 'danger', 'manage.php?section=categories');
        }

        $tableMap = [
            'chore' => 'chore_categoriesTb',
            'expense' => 'expense_categoriesTb',
            'bill' => 'bill_categoriesTb',
        ];
        $targetTable = $tableMap[$catType];

        $checkDup = $connection->prepare("SELECT 1 FROM {$targetTable} WHERE household_id = ? AND LOWER(category_name) = LOWER(?) LIMIT 1");
        $checkDup->execute([$householdId, $name]);
        if ($checkDup->fetchColumn()) {
            manage_set_flash_and_redirect("A {$catType} category named '{$name}' already exists.", 'danger', 'manage.php?section=categories');
        }

        $insertCat = $connection->prepare("INSERT INTO {$targetTable} (household_id, category_name, description) VALUES (?, ?, ?)");
        $insertCat->execute([$householdId, $name, $desc !== '' ? $desc : null]);

        $formattedType = ucfirst($catType);
        manage_set_flash_and_redirect("{$formattedType} category '{$name}' added successfully.", 'success', 'manage.php?section=categories');
    }

    // 7. EDIT CATEGORY
    if ($action === 'edit_category') {
        $catType = trim(is_string($_POST['category_type'] ?? null) ? $_POST['category_type'] : '');
        $rawCatId = $_POST['category_id'] ?? null;
        $catId = is_string($rawCatId) && ctype_digit($rawCatId) ? (int) $rawCatId : 0;
        $name = trim(is_string($_POST['category_name'] ?? null) ? $_POST['category_name'] : '');
        $desc = trim(is_string($_POST['description'] ?? null) ? $_POST['description'] : '');

        if (!in_array($catType, ['chore', 'expense', 'bill'], true) || $catId < 1) {
            manage_set_flash_and_redirect('Invalid category specified.', 'danger', 'manage.php?section=categories');
        }
        if ($name === '' || strlen($name) > 50) {
            manage_set_flash_and_redirect('Enter a category name up to 50 characters.', 'danger', 'manage.php?section=categories');
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

        $checkDup = $connection->prepare(
            "SELECT 1 FROM {$targetTable} WHERE household_id = ? AND LOWER(category_name) = LOWER(?) AND {$idCol} <> ? LIMIT 1"
        );
        $checkDup->execute([$householdId, $name, $catId]);
        if ($checkDup->fetchColumn()) {
            manage_set_flash_and_redirect("Another {$catType} category with that name already exists.", 'danger', 'manage.php?section=categories');
        }

        $updateCat = $connection->prepare(
            "UPDATE {$targetTable} SET category_name = ?, description = ? WHERE {$idCol} = ? AND household_id = ?"
        );
        $updateCat->execute([$name, $desc !== '' ? $desc : null, $catId, $householdId]);

        manage_set_flash_and_redirect('Category updated successfully.', 'success', 'manage.php?section=categories');
    }

    // 8. DELETE CATEGORY
    if ($action === 'delete_category') {
        $catType = trim(is_string($_POST['category_type'] ?? null) ? $_POST['category_type'] : '');
        $rawCatId = $_POST['category_id'] ?? null;
        $catId = is_string($rawCatId) && ctype_digit($rawCatId) ? (int) $rawCatId : 0;

        if (!in_array($catType, ['chore', 'expense', 'bill'], true) || $catId < 1) {
            manage_set_flash_and_redirect('Invalid category specified.', 'danger', 'manage.php?section=categories');
        }

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
                'manage.php?section=categories'
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

        manage_set_flash_and_redirect('Category deleted successfully.', 'success', 'manage.php?section=categories');
    }

    // 9. UPDATE HOUSEHOLD SETTINGS
    if ($action === 'update_household') {
        $name = trim(is_string($_POST['household_name'] ?? null) ? $_POST['household_name'] : '');
        $description = trim(is_string($_POST['description'] ?? null) ? $_POST['description'] : '');
        $address = trim(is_string($_POST['address'] ?? null) ? $_POST['address'] : '');
        if ($name === '' || strlen($name) > 100) {
            manage_set_flash_and_redirect('Enter a household name with up to 100 characters.', 'danger', 'manage.php?section=settings');
        }
        if ($address !== '' && strlen($address) > 255) {
            manage_set_flash_and_redirect('Address must be 255 characters or fewer.', 'danger', 'manage.php?section=settings');
        }

        $finalImagePath = (string) ($household['household_image'] ?? '');

        if (isset($_FILES['household_image_file']) && is_array($_FILES['household_image_file']) && $_FILES['household_image_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['household_image_file'];
            $fileSize = (int) $file['size'];
            $fileTmp = (string) $file['tmp_name'];

            if ($fileSize > 2 * 1024 * 1024) {
                manage_set_flash_and_redirect('Household image file must be smaller than 2MB.', 'danger', 'manage.php?section=settings');
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
                manage_set_flash_and_redirect('Please upload a valid image file (JPG, PNG, WebP, or GIF).', 'danger', 'manage.php?section=settings');
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

        manage_set_flash_and_redirect('Household settings updated successfully.', 'success', 'manage.php?section=settings');
    }

    // 10. ADD CHORE (Admin shortcut)
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
            manage_set_flash_and_redirect('Enter a chore name with up to 100 characters.', 'danger', 'manage.php?section=settings');
        }
        if (!in_array($assignmentType, ['Single', 'Rotation'], true)) {
            manage_set_flash_and_redirect('Invalid assignment type.', 'danger', 'manage.php?section=settings');
        }
        if (!preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $dueDate)) {
            manage_set_flash_and_redirect('Enter a valid due date.', 'danger', 'manage.php?section=settings');
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
                $firstUser = $rotationMembers[0];
                $insertAssign = $connection->prepare(
                    'INSERT INTO chore_assignmentsTb (chore_id, user_id, due_date, status) VALUES (?, ?, ?, "Pending")'
                );
                $insertAssign->execute([$choreId, $firstUser, $dueDate]);
            }

            $connection->commit();
            manage_set_flash_and_redirect("Chore '{$choreName}' created successfully.", 'success', 'manage.php?section=settings');
        } catch (Throwable $e) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            error_log('Manage add chore error: ' . $e->getMessage());
            manage_set_flash_and_redirect('Unable to create chore. Please try again.', 'danger', 'manage.php?section=settings');
        }
    }

    // 11. UPDATE MAINTENANCE REPORT
    if ($action === 'update_maintenance') {
        $rawProbId = $_POST['problem_id'] ?? null;
        $probId = is_string($rawProbId) && ctype_digit($rawProbId) ? (int) $rawProbId : 0;
        $probType = trim(is_string($_POST['problem_type'] ?? null) ? $_POST['problem_type'] : '');
        $status = trim(is_string($_POST['status'] ?? null) ? $_POST['status'] : '');
        $probDesc = trim(is_string($_POST['problem_desc'] ?? null) ? $_POST['problem_desc'] : '');

        if ($probId < 1) {
            manage_set_flash_and_redirect('Invalid maintenance report specified.', 'danger', 'manage.php?section=maintenance');
        }
        if ($probType === '' || mb_strlen($probType) > 50) {
            manage_set_flash_and_redirect('Provide a valid problem type (up to 50 characters).', 'danger', 'manage.php?section=maintenance');
        }
        $validStatuses = ['Open', 'In Progress', 'Resolved'];
        if (!in_array($status, $validStatuses, true)) {
            manage_set_flash_and_redirect('Invalid maintenance status.', 'danger', 'manage.php?section=maintenance');
        }

        $checkMaint = $connection->prepare('SELECT problem_id FROM maintenanceTb WHERE problem_id = ? AND household_id = ? LIMIT 1');
        $checkMaint->execute([$probId, $householdId]);
        if (!$checkMaint->fetch()) {
            manage_set_flash_and_redirect('Maintenance report not found in this household.', 'danger', 'manage.php?section=maintenance');
        }

        $updateM = $connection->prepare(
            'UPDATE maintenanceTb
             SET problem_type = ?, status = ?, problem_desc = ?
             WHERE problem_id = ? AND household_id = ?'
        );
        $updateM->execute([$probType, $status, $probDesc !== '' ? $probDesc : null, $probId, $householdId]);

        manage_set_flash_and_redirect('Maintenance report updated successfully.', 'success', 'manage.php?section=maintenance');
    }

    // 12. TOGGLE MAINTENANCE STATUS (e.g. Mark as Resolved / Reopen)
    if ($action === 'toggle_maintenance_status') {
        $rawProbId = $_POST['problem_id'] ?? null;
        $probId = is_string($rawProbId) && ctype_digit($rawProbId) ? (int) $rawProbId : 0;
        $newStatus = trim(is_string($_POST['new_status'] ?? null) ? $_POST['new_status'] : 'Resolved');

        if ($probId < 1) {
            manage_set_flash_and_redirect('Invalid maintenance report specified.', 'danger', 'manage.php?section=maintenance');
        }
        $validStatuses = ['Open', 'In Progress', 'Resolved'];
        if (!in_array($newStatus, $validStatuses, true)) {
            $newStatus = 'Resolved';
        }

        $checkMaint = $connection->prepare('SELECT problem_id FROM maintenanceTb WHERE problem_id = ? AND household_id = ? LIMIT 1');
        $checkMaint->execute([$probId, $householdId]);
        if (!$checkMaint->fetch()) {
            manage_set_flash_and_redirect('Maintenance report not found in this household.', 'danger', 'manage.php?section=maintenance');
        }

        $updateM = $connection->prepare(
            'UPDATE maintenanceTb SET status = ? WHERE problem_id = ? AND household_id = ?'
        );
        $updateM->execute([$newStatus, $probId, $householdId]);

        manage_set_flash_and_redirect("Maintenance report marked as {$newStatus}.", 'success', 'manage.php?section=maintenance');
    }

    // 13. DELETE MAINTENANCE REPORT
    if ($action === 'delete_maintenance') {
        $rawProbId = $_POST['problem_id'] ?? null;
        $probId = is_string($rawProbId) && ctype_digit($rawProbId) ? (int) $rawProbId : 0;

        if ($probId < 1) {
            manage_set_flash_and_redirect('Invalid maintenance report specified.', 'danger', 'manage.php?section=maintenance');
        }

        $delM = $connection->prepare('DELETE FROM maintenanceTb WHERE problem_id = ? AND household_id = ?');
        $delM->execute([$probId, $householdId]);

        manage_set_flash_and_redirect('Maintenance report deleted successfully.', 'success', 'manage.php?section=maintenance');
    }

    manage_set_flash_and_redirect('Action completed.', 'info', 'manage.php');
}

// ==================================================
// QUERY DATA FOR MANAGE PAGE DISPLAY
// ==================================================
$notice = consume_flash_message();

// Reload Household Details
$contextQuery->execute([$householdId, $userId]);
$household = $contextQuery->fetch();
$householdName = (string) $household['household_name'];
$householdDesc = (string) ($household['description'] ?? '');
$householdAddress = (string) ($household['address'] ?? '');
$householdImage = (string) ($household['household_image'] ?? '');
$householdJoinCode = (string) ($household['join_code'] ?? '');
$householdCreatedAt = (string) $household['created_at'];

// All household members (for select boxes in modals)
$allHouseholdMembersStmt = $connection->prepare(
    'SELECT u.user_id, u.full_name, u.username
     FROM householdmembersTb hm
     INNER JOIN userTb u ON u.user_id = hm.user_id
     WHERE hm.household_id = ?
     ORDER BY u.full_name ASC, u.username ASC'
);
$allHouseholdMembersStmt->execute([$householdId]);
$allHouseholdMembers = $allHouseholdMembersStmt->fetchAll();

// 1. Members with Server-Side Search, Filter & Pagination
$memSearch = trim((string) ($_GET['mem_q'] ?? ''));
$memRoleFilter = trim((string) ($_GET['mem_role'] ?? 'all'));

$memWhere = ['hm.household_id = ?'];
$memParams = [$householdId];

if ($memSearch !== '') {
    $memWhere[] = '(u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)';
    $memParams[] = '%' . $memSearch . '%';
    $memParams[] = '%' . $memSearch . '%';
    $memParams[] = '%' . $memSearch . '%';
}
if ($memRoleFilter === 'admin') {
    $memWhere[] = 'LOWER(hm.role) = "admin"';
} elseif ($memRoleFilter === 'roommate') {
    $memWhere[] = 'LOWER(hm.role) <> "admin"';
}

$memWhereSql = implode(' AND ', $memWhere);

// Total matching members
$countMemStmt = $connection->prepare("SELECT COUNT(*) FROM householdmembersTb hm INNER JOIN userTb u ON u.user_id = hm.user_id WHERE {$memWhereSql}");
$countMemStmt->execute($memParams);
$totalMatchingMembers = (int) $countMemStmt->fetchColumn();

// Overall counts for summary
$countAllMemStmt = $connection->prepare('SELECT COUNT(*) FROM householdmembersTb WHERE household_id = ?');
$countAllMemStmt->execute([$householdId]);
$totalMembersCount = (int) $countAllMemStmt->fetchColumn();

$countAdminsStmt = $connection->prepare('SELECT COUNT(*) FROM householdmembersTb WHERE household_id = ? AND LOWER(role) = "admin"');
$countAdminsStmt->execute([$householdId]);
$adminCount = (int) $countAdminsStmt->fetchColumn();
$roommateCount = max(0, $totalMembersCount - $adminCount);

$memberPagination = get_pagination_params($totalMatchingMembers, 10, [5, 10, 20, 50], 'mem_page', 'mem_per_page');
$memLimit = (int) $memberPagination['per_page'];
$memOffset = (int) $memberPagination['offset'];

$membersStmt = $connection->prepare(
    "SELECT u.user_id, u.full_name, u.username, u.email, u.profile_picture, u.status, hm.role, hm.joined_at
     FROM householdmembersTb hm
     INNER JOIN userTb u ON u.user_id = hm.user_id
     WHERE {$memWhereSql}
     ORDER BY (hm.role = 'Admin') DESC, u.full_name ASC, u.username ASC
     LIMIT {$memLimit} OFFSET {$memOffset}"
);
$membersStmt->execute($memParams);
$members = $membersStmt->fetchAll();

// 2. Announcements with Server-Side Pagination
$countAnnStmt = $connection->prepare('SELECT COUNT(*) FROM announceTb WHERE household_id = ?');
$countAnnStmt->execute([$householdId]);
$totalAnnouncementsCount = (int) $countAnnStmt->fetchColumn();

$annPagination = get_pagination_params($totalAnnouncementsCount, 6, [4, 6, 10, 20], 'ann_page', 'ann_per_page');
$annLimit = (int) $annPagination['per_page'];
$annOffset = (int) $annPagination['offset'];

$annStmt = $connection->prepare(
    "SELECT a.announcement_id, a.title, a.content, a.created_at,
            COALESCE(NULLIF(u.full_name, ''), u.username, 'Admin') AS author_name
     FROM announceTb a
     LEFT JOIN userTb u ON u.user_id = a.created_by
     WHERE a.household_id = ?
     ORDER BY a.created_at DESC
     LIMIT {$annLimit} OFFSET {$annOffset}"
);
$annStmt->execute([$householdId]);
$announcements = $annStmt->fetchAll();

// 3. Categories (Chores, Expenses, Bills) with Pagination
$choreCatsStmt = $connection->prepare(
    'SELECT cc.chore_category_id, cc.category_name, cc.description,
           (SELECT COUNT(*) FROM choresTb c WHERE c.category_id = cc.chore_category_id AND c.household_id = cc.household_id) AS usage_count
     FROM chore_categoriesTb cc
     WHERE cc.household_id = ?
     ORDER BY cc.category_name ASC'
);
$choreCatsStmt->execute([$householdId]);
$allChoreCategories = $choreCatsStmt->fetchAll();

$expenseCatsStmt = $connection->prepare(
    'SELECT ec.expense_category_id, ec.category_name, ec.description,
           (SELECT COUNT(*) FROM expenseTb e WHERE e.category_id = ec.expense_category_id AND e.household_id = ec.household_id) AS usage_count
     FROM expense_categoriesTb ec
     WHERE ec.household_id = ?
     ORDER BY ec.category_name ASC'
);
$expenseCatsStmt->execute([$householdId]);
$allExpenseCategories = $expenseCatsStmt->fetchAll();

$billCatsStmt = $connection->prepare(
    'SELECT bc.bill_category_id, bc.category_name, bc.description,
           (SELECT COUNT(*) FROM billsTb b WHERE b.category_id = bc.bill_category_id AND b.household_id = bc.household_id) AS usage_count
     FROM bill_categoriesTb bc
     WHERE bc.household_id = ?
     ORDER BY bc.category_name ASC'
);
$billCatsStmt->execute([$householdId]);
$allBillCategories = $billCatsStmt->fetchAll();

$totalCategoriesCount = count($allChoreCategories) + count($allExpenseCategories) + count($allBillCategories);

$choreCatPagination = get_pagination_params(count($allChoreCategories), 8, [5, 8, 15, 30], 'c_page', 'c_per_page');
$choreCategories = array_slice($allChoreCategories, (int)$choreCatPagination['offset'], (int)$choreCatPagination['per_page']);

$expenseCatPagination = get_pagination_params(count($allExpenseCategories), 8, [5, 8, 15, 30], 'e_page', 'e_per_page');
$expenseCategories = array_slice($allExpenseCategories, (int)$expenseCatPagination['offset'], (int)$expenseCatPagination['per_page']);

$billCatPagination = get_pagination_params(count($allBillCategories), 8, [5, 8, 15, 30], 'b_page', 'b_per_page');
$billCategories = array_slice($allBillCategories, (int)$billCatPagination['offset'], (int)$billCatPagination['per_page']);

// 4. Activity Logs with Server-Side Pagination
$activityUnionSql = '
    (SELECT "announcement" AS type, a.announcement_id AS id,
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
';

$countActStmt = $connection->prepare("SELECT COUNT(*) FROM ({$activityUnionSql}) AS act_tbl");
$countActStmt->execute([$householdId, $householdId, $householdId, $householdId, $householdId, $householdId, $householdId]);
$totalActivityCount = (int) $countActStmt->fetchColumn();

$actPagination = get_pagination_params($totalActivityCount, 10, [5, 10, 20, 50], 'act_page', 'act_per_page');
$actLimit = (int) $actPagination['per_page'];
$actOffset = (int) $actPagination['offset'];

$activityLogsStmt = $connection->prepare(
    "{$activityUnionSql}
     ORDER BY event_time DESC
     LIMIT {$actLimit} OFFSET {$actOffset}"
);
$activityLogsStmt->execute([$householdId, $householdId, $householdId, $householdId, $householdId, $householdId, $householdId]);
$activityLogs = $activityLogsStmt->fetchAll();

// 5. Maintenance Tickets & Stats
$maintStatsStmt = $connection->prepare(
    'SELECT COUNT(*) AS total_count,
            COALESCE(SUM(CASE WHEN LOWER(status) = "open" THEN 1 ELSE 0 END), 0) AS open_count,
            COALESCE(SUM(CASE WHEN LOWER(status) = "in progress" THEN 1 ELSE 0 END), 0) AS in_progress_count,
            COALESCE(SUM(CASE WHEN LOWER(status) = "resolved" THEN 1 ELSE 0 END), 0) AS resolved_count
     FROM maintenanceTb
     WHERE household_id = ?'
);
$maintStatsStmt->execute([$householdId]);
$maintStats = $maintStatsStmt->fetch() ?: [
    'total_count' => 0,
    'open_count' => 0,
    'in_progress_count' => 0,
    'resolved_count' => 0,
];
$totalMaintenanceCount = (int) $maintStats['total_count'];
$openMaintenanceCount = (int) $maintStats['open_count'];
$inProgressMaintenanceCount = (int) $maintStats['in_progress_count'];
$resolvedMaintenanceCount = (int) $maintStats['resolved_count'];

$maintSearch = isset($_GET['maint_q']) && is_string($_GET['maint_q']) ? trim($_GET['maint_q']) : '';
$maintStatusFilter = isset($_GET['maint_status']) && is_string($_GET['maint_status']) ? strtolower(trim($_GET['maint_status'])) : 'all';
if (!in_array($maintStatusFilter, ['all', 'open', 'in_progress', 'resolved'], true)) {
    $maintStatusFilter = 'all';
}

$maintWhere = ['m.household_id = ?'];
$maintParams = [$householdId];

if ($maintStatusFilter === 'open') {
    $maintWhere[] = 'LOWER(m.status) = "open"';
} elseif ($maintStatusFilter === 'in_progress') {
    $maintWhere[] = 'LOWER(m.status) = "in progress"';
} elseif ($maintStatusFilter === 'resolved') {
    $maintWhere[] = 'LOWER(m.status) = "resolved"';
}

if ($maintSearch !== '') {
    $maintWhere[] = '(m.problem_type LIKE ? OR m.problem_desc LIKE ? OR u.full_name LIKE ? OR u.username LIKE ?)';
    $sPattern = '%' . $maintSearch . '%';
    $maintParams[] = $sPattern;
    $maintParams[] = $sPattern;
    $maintParams[] = $sPattern;
    $maintParams[] = $sPattern;
}

$maintWhereClause = implode(' AND ', $maintWhere);

$countMaintStmt = $connection->prepare(
    "SELECT COUNT(*) FROM maintenanceTb m
     LEFT JOIN userTb u ON u.user_id = m.reported_by
     WHERE {$maintWhereClause}"
);
$countMaintStmt->execute($maintParams);
$filteredMaintCount = (int) $countMaintStmt->fetchColumn();

$maintPagination = get_pagination_params($filteredMaintCount, 10, [5, 10, 20, 50], 'm_page', 'm_per_page');
$maintLimit = (int) $maintPagination['per_page'];
$maintOffset = (int) $maintPagination['offset'];

$maintListStmt = $connection->prepare(
    "SELECT m.problem_id, m.household_id, m.reported_by, m.problem_type, m.problem_desc, m.reported_at, m.status,
            COALESCE(NULLIF(u.full_name, ''), u.username, 'Anonymous') AS reported_by_name,
            u.profile_picture, u.username
     FROM maintenanceTb m
     LEFT JOIN userTb u ON u.user_id = m.reported_by
     WHERE {$maintWhereClause}
     ORDER BY m.reported_at DESC, m.problem_id DESC
     LIMIT {$maintLimit} OFFSET {$maintOffset}"
);
$maintListStmt->execute($maintParams);
$maintenanceTickets = $maintListStmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Manage Household | RoomieSync</title>
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
          <?= render_current_user_avatar($avatarText, $displayName) ?>
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
        <p class="text-secondary-custom mb-0">Admin control center for <?= h($householdName) ?> &ndash; select a section to manage members, categories, announcements, or settings.</p>
      </div>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <button type="button" class="btn btn-rs-outline btn-sm" data-bs-toggle="modal" data-bs-target="#addChoreModal">
          <i class="bi bi-check2-circle me-1"></i> New Chore
        </button>
        <button type="button" class="btn btn-rs-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createAnnouncementModal">
          <i class="bi bi-megaphone me-1"></i> New Announcement
        </button>
      </div>
    </header>

    <?php if ($section !== 'overview'): ?>
      <!-- Section Back Navigation & Quick Switcher -->
      <div class="rs-view-toolbar">
        <a href="manage.php" class="rs-btn-back">
          <i class="bi bi-arrow-left"></i> Back to Manage Hub
        </a>
        <div class="rs-view-pills">
          <a href="manage.php?section=users" class="rs-view-pill-btn <?= $section === 'users' ? 'active' : '' ?>">
            <i class="bi bi-people-fill"></i> Users &amp; Members
          </a>
          <a href="manage.php?section=categories" class="rs-view-pill-btn <?= $section === 'categories' ? 'active' : '' ?>">
            <i class="bi bi-tags-fill"></i> Categories
          </a>
          <a href="manage.php?section=announcements" class="rs-view-pill-btn <?= $section === 'announcements' ? 'active' : '' ?>">
            <i class="bi bi-megaphone-fill"></i> Announcements &amp; Logs
          </a>
          <a href="manage.php?section=settings" class="rs-view-pill-btn <?= $section === 'settings' ? 'active' : '' ?>">
            <i class="bi bi-gear-fill"></i> Settings
          </a>
          <a href="manage.php?section=maintenance" class="rs-view-pill-btn <?= $section === 'maintenance' ? 'active' : '' ?>">
            <i class="bi bi-tools"></i> Maintenance &amp; Repairs
          </a>
        </div>
      </div>
    <?php endif; ?>

    <!-- ==================================================
         VIEW 1: OVERVIEW HUB (SQUARED OPENABLE CARDS)
         ================================================== -->
    <?php if ($section === 'overview'): ?>
      <div class="rs-hub-grid">
        <!-- 1. User & Member Management -->
        <a href="manage.php?section=users" class="rs-hub-card card-blue">
          <div>
            <div class="rs-hub-top">
              <div class="rs-hub-icon blue">
                <i class="bi bi-people-fill"></i>
              </div>
              <span class="rs-hub-pill"><?= $totalMembersCount ?> Members</span>
            </div>
            <div class="rs-hub-main">
              <h2 class="rs-hub-title">User and Member Management</h2>
              <div class="rs-hub-metric text-primary"><?= $adminCount ?> Admin &bull; <?= $roommateCount ?> Roommate<?= $roommateCount === 1 ? '' : 's' ?></div>
              <p class="rs-hub-desc">Review household members, assign Admin or Roommate roles, adjust permissions, and manage membership.</p>
            </div>
          </div>
          <div class="rs-hub-footer">
            <span>Open Member Management</span>
            <i class="bi bi-arrow-right"></i>
          </div>
        </a>

        <!-- 2. Category Management -->
        <a href="manage.php?section=categories" class="rs-hub-card card-amber">
          <div>
            <div class="rs-hub-top">
              <div class="rs-hub-icon amber">
                <i class="bi bi-tags-fill"></i>
              </div>
              <span class="rs-hub-pill"><?= $totalCategoriesCount ?> Categories</span>
            </div>
            <div class="rs-hub-main">
              <h2 class="rs-hub-title">Category Management</h2>
              <div class="rs-hub-metric text-warning" style="color: #B45309 !important;"><?= count($allChoreCategories) ?> Chores &bull; <?= count($allExpenseCategories) ?> Exp &bull; <?= count($allBillCategories) ?> Bills</div>
              <p class="rs-hub-desc">Organize and customize categories for chores, shared expenses, and household utility bills.</p>
            </div>
          </div>
          <div class="rs-hub-footer">
            <span>Open Category Management</span>
            <i class="bi bi-arrow-right"></i>
          </div>
        </a>

        <!-- 3. Announcements & Activities -->
        <a href="manage.php?section=announcements" class="rs-hub-card card-purple">
          <div>
            <div class="rs-hub-top">
              <div class="rs-hub-icon purple">
                <i class="bi bi-megaphone-fill"></i>
              </div>
              <span class="rs-hub-pill"><?= $totalAnnouncementsCount ?> Posts</span>
            </div>
            <div class="rs-hub-main">
              <h2 class="rs-hub-title">Announcements &amp; Activities</h2>
              <div class="rs-hub-metric text-info" style="color: #6D28D9 !important;"><?= $totalActivityCount ?> Logged Events</div>
              <p class="rs-hub-desc">Broadcast updates to household members and inspect the real-time activity timeline across all modules.</p>
            </div>
          </div>
          <div class="rs-hub-footer">
            <span>Open Announcements &amp; Activities</span>
            <i class="bi bi-arrow-right"></i>
          </div>
        </a>

        <!-- 4. Household Settings -->
        <a href="manage.php?section=settings" class="rs-hub-card card-green">
          <div>
            <div class="rs-hub-top">
              <div class="rs-hub-icon green">
                <i class="bi bi-gear-fill"></i>
              </div>
              <span class="rs-hub-pill">Code: <?= h($householdJoinCode ?: 'N/A') ?></span>
            </div>
            <div class="rs-hub-main">
              <h2 class="rs-hub-title">Household Settings</h2>
              <div class="rs-hub-metric text-success" style="color: #047857 !important;"><?= h($householdName) ?></div>
              <p class="rs-hub-desc">Display and update household name, address, description, upload banner image, and manage invite code.</p>
            </div>
          </div>
          <div class="rs-hub-footer">
            <span>Open Household Settings</span>
            <i class="bi bi-arrow-right"></i>
          </div>
        </a>

        <!-- 5. Maintenance & Repairs -->
        <a href="manage.php?section=maintenance" class="rs-hub-card card-coral">
          <div>
            <div class="rs-hub-top">
              <div class="rs-hub-icon coral">
                <i class="bi bi-tools"></i>
              </div>
              <span class="rs-hub-pill"><?= $openMaintenanceCount ?> Open</span>
            </div>
            <div class="rs-hub-main">
              <h2 class="rs-hub-title">Maintenance &amp; Repairs</h2>
              <div class="rs-hub-metric text-primary" style="color: var(--rs-primary) !important;"><?= $totalMaintenanceCount ?> Total &bull; <?= $resolvedMaintenanceCount ?> Resolved</div>
              <p class="rs-hub-desc">Review household repair issues reported by roommates, edit ticket details, update status, or mark as done.</p>
            </div>
          </div>
          <div class="rs-hub-footer">
            <span>Open Maintenance &amp; Repairs</span>
            <i class="bi bi-arrow-right"></i>
          </div>
        </a>
      </div>
    <?php endif; ?>

    <!-- ==================================================
         VIEW 2: USER AND MEMBER MANAGEMENT (OPENABLE PAGE)
         ================================================== -->
    <?php if ($section === 'users'): ?>
      <section class="manage-card card-household shadow-sm" id="manageUsers">
        <div class="manage-card-header">
          <div class="manage-card-title-wrap">
            <div class="manage-card-icon users"><i class="bi bi-people-fill"></i></div>
            <div>
              <h2 class="h5 fw-bold text-main mb-0">User and Member Management</h2>
              <small class="text-secondary-custom">Review household members, assign Admin or Roommate roles, and manage permissions</small>
            </div>
          </div>
          <span class="badge bg-warm-secondary text-dark-accent border px-3 py-2 fw-bold">
            <?= $totalMembersCount ?> Member<?= $totalMembersCount === 1 ? '' : 's' ?> &bull; <?= $adminCount ?> Admin
          </span>
        </div>

        <!-- Filter and Search Row -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
          <form action="manage.php" method="GET" class="d-flex align-items-center gap-2 flex-grow-1" style="max-width: 480px;">
            <input type="hidden" name="section" value="users">
            <input type="hidden" name="mem_page" value="1">
            <div class="input-group input-group-sm">
              <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-secondary-custom"></i></span>
              <input type="text" name="mem_q" class="form-control form-control-sm border-start-0" placeholder="Search members by name, username, or email..." value="<?= h($memSearch) ?>">
              <?php if ($memSearch !== ''): ?>
                <a href="manage.php?section=users" class="btn btn-outline-secondary btn-sm" title="Clear search"><i class="bi bi-x-lg"></i></a>
              <?php endif; ?>
              <button type="submit" class="btn btn-rs-primary btn-sm">Search</button>
            </div>
          </form>

          <div class="d-flex align-items-center gap-2">
            <div class="btn-group btn-group-sm" role="group" aria-label="Member role filter">
              <a href="manage.php?section=users&mem_role=all" class="btn <?= $memRoleFilter === 'all' ? 'btn-rs-primary' : 'btn-rs-outline' ?>">All</a>
              <a href="manage.php?section=users&mem_role=admin" class="btn <?= $memRoleFilter === 'admin' ? 'btn-rs-primary' : 'btn-rs-outline' ?>">Admins (<?= $adminCount ?>)</a>
              <a href="manage.php?section=users&mem_role=roommate" class="btn <?= $memRoleFilter === 'roommate' ? 'btn-rs-primary' : 'btn-rs-outline' ?>">Roommates (<?= $roommateCount ?>)</a>
            </div>
          </div>
        </div>

        <!-- Members Table -->
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
                    <p class="mb-0">No matching household members found.</p>
                    <?php if ($memSearch !== '' || $memRoleFilter !== 'all'): ?>
                      <a href="manage.php?section=users" class="btn btn-sm btn-rs-outline mt-2">Clear Filters</a>
                    <?php endif; ?>
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
                        <button type="button" class="btn btn-sm btn-rs-outline btn-change-role"
                                data-bs-toggle="modal" data-bs-target="#changeRoleModal"
                                data-id="<?= $memId ?>" data-name="<?= h($memName) ?>" data-role="<?= h($memRole) ?>"
                                title="Change Role">
                          <i class="bi bi-shield-shaded"></i> <span class="d-none d-lg-inline ms-1">Role</span>
                        </button>
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

        <!-- Pagination for User and Member Management -->
        <?= render_pagination($memberPagination, ['section' => 'users', 'mem_q' => $memSearch !== '' ? $memSearch : null, 'mem_role' => $memRoleFilter !== 'all' ? $memRoleFilter : null]) ?>
      </section>
    <?php endif; ?>

    <!-- ==================================================
         VIEW 3: CATEGORY MANAGEMENT (OPENABLE PAGE)
         ================================================== -->
    <?php if ($section === 'categories'): ?>
      <section class="manage-card card-money shadow-sm" id="categoriesSection">
        <div class="manage-card-header">
          <div class="manage-card-title-wrap">
            <div class="manage-card-icon categories"><i class="bi bi-tags-fill"></i></div>
            <div>
              <h2 class="h5 fw-bold text-main mb-0">Category Management</h2>
              <small class="text-secondary-custom">Organize and customize categories for household Chores, Expenses, and Bills</small>
            </div>
          </div>
          <div>
            <button type="button" class="btn btn-sm btn-rs-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
              <i class="bi bi-plus-lg me-1"></i> Add Category
            </button>
          </div>
        </div>

        <!-- Category Overview Mini Cards -->
        <div class="row g-3 mb-4">
          <div class="col-12 col-md-4">
            <div class="category-overview-card">
              <div class="category-overview-title">
                <span><i class="bi bi-check2-square text-primary me-1"></i> Chore Categories</span>
                <span class="badge bg-white text-dark-accent border"><?= count($allChoreCategories) ?></span>
              </div>
              <div class="category-pill-list">
                <?php if (empty($allChoreCategories)): ?>
                  <small class="text-muted">No categories</small>
                <?php else: ?>
                  <?php foreach (array_slice($allChoreCategories, 0, 4) as $cc): ?>
                    <span class="category-chip"><?= h((string) $cc['category_name']) ?></span>
                  <?php endforeach; ?>
                  <?php if (count($allChoreCategories) > 4): ?>
                    <span class="category-chip bg-warm-secondary">+<?= count($allChoreCategories) - 4 ?> more</span>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-4">
            <div class="category-overview-card">
              <div class="category-overview-title">
                <span><i class="bi bi-wallet2 text-dark-accent me-1"></i> Expense Categories</span>
                <span class="badge bg-white text-dark-accent border"><?= count($allExpenseCategories) ?></span>
              </div>
              <div class="category-pill-list">
                <?php if (empty($allExpenseCategories)): ?>
                  <small class="text-muted">No categories</small>
                <?php else: ?>
                  <?php foreach (array_slice($allExpenseCategories, 0, 4) as $ec): ?>
                    <span class="category-chip"><?= h((string) $ec['category_name']) ?></span>
                  <?php endforeach; ?>
                  <?php if (count($allExpenseCategories) > 4): ?>
                    <span class="category-chip bg-warm-secondary">+<?= count($allExpenseCategories) - 4 ?> more</span>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-4">
            <div class="category-overview-card">
              <div class="category-overview-title">
                <span><i class="bi bi-receipt text-warning me-1"></i> Bill Categories</span>
                <span class="badge bg-white text-dark-accent border"><?= count($allBillCategories) ?></span>
              </div>
              <div class="category-pill-list">
                <?php if (empty($allBillCategories)): ?>
                  <small class="text-muted">No categories</small>
                <?php else: ?>
                  <?php foreach (array_slice($allBillCategories, 0, 4) as $bc): ?>
                    <span class="category-chip"><?= h((string) $bc['category_name']) ?></span>
                  <?php endforeach; ?>
                  <?php if (count($allBillCategories) > 4): ?>
                    <span class="category-chip bg-warm-secondary">+<?= count($allBillCategories) - 4 ?> more</span>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

        <!-- Category Management Tabs -->
        <div class="manage-tabs">
          <button type="button" class="nav-link category-tab-btn active" data-category-type="chore">
            <i class="bi bi-check2-square me-1"></i> Chores (<?= count($allChoreCategories) ?>)
          </button>
          <button type="button" class="nav-link category-tab-btn" data-category-type="expense">
            <i class="bi bi-wallet2 me-1"></i> Expenses (<?= count($allExpenseCategories) ?>)
          </button>
          <button type="button" class="nav-link category-tab-btn" data-category-type="bill">
            <i class="bi bi-receipt me-1"></i> Bills (<?= count($allBillCategories) ?>)
          </button>
        </div>

        <!-- Chores Category Panel -->
        <div class="category-panel" data-category-type="chore">
          <div class="manage-table-wrapper" data-rs-selection data-toolbar="#choreCatBulkToolbar" data-form="#choreCatBulkForm">
            <table class="table manage-table">
              <thead>
                <tr>
                  <th class="rs-select-col"><input type="checkbox" class="form-check-input rs-select-chk rs-select-all" aria-label="Select all chore categories"></th>
                  <th>Category Name</th>
                  <th>Description</th>
                  <th class="text-center">Used in Chores</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($choreCategories)): ?>
                  <tr><td colspan="5" class="manage-empty-state"><i class="bi bi-tag"></i><p class="mb-0">No chore categories defined.</p></td></tr>
                <?php else: ?>
                  <?php foreach ($choreCategories as $cat): ?>
                    <?php $cId = (int) $cat['chore_category_id']; $cName = (string) $cat['category_name']; $cDesc = (string) ($cat['description'] ?? ''); $uCount = (int) $cat['usage_count']; ?>
                    <tr>
                      <td class="rs-select-col"><input type="checkbox" class="form-check-input rs-select-chk rs-select-item" value="<?= $cId ?>"></td>
                      <td class="fw-bold text-main"><?= h($cName) ?></td>
                      <td><?= $cDesc !== '' ? h($cDesc) : '<span class="text-muted fst-italic">None</span>' ?></td>
                      <td class="text-center"><span class="badge bg-warm-secondary text-dark-accent border"><?= $uCount ?></span></td>
                      <td class="text-end">
                        <button type="button" class="btn btn-sm btn-rs-outline btn-edit-category" data-bs-toggle="modal" data-bs-target="#editCategoryModal" data-type="chore" data-id="<?= $cId ?>" data-name="<?= h($cName) ?>" data-desc="<?= h($cDesc) ?>" title="Edit"><i class="bi bi-pencil"></i></button>
                        <button type="button" class="btn btn-sm btn-rs-outline text-danger btn-delete-category" data-bs-toggle="modal" data-bs-target="#deleteCategoryModal" data-type="chore" data-id="<?= $cId ?>" data-name="<?= h($cName) ?>" title="Delete"><i class="bi bi-trash3"></i></button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <?= render_pagination($choreCatPagination, ['section' => 'categories']) ?>
        </div>

        <!-- Expenses Category Panel -->
        <div class="category-panel d-none" data-category-type="expense">
          <div class="manage-table-wrapper" data-rs-selection data-toolbar="#expCatBulkToolbar" data-form="#expCatBulkForm">
            <table class="table manage-table">
              <thead>
                <tr>
                  <th class="rs-select-col"><input type="checkbox" class="form-check-input rs-select-chk rs-select-all" aria-label="Select all expense categories"></th>
                  <th>Category Name</th>
                  <th>Description</th>
                  <th class="text-center">Used in Expenses</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($expenseCategories)): ?>
                  <tr><td colspan="5" class="manage-empty-state"><i class="bi bi-tag"></i><p class="mb-0">No expense categories defined.</p></td></tr>
                <?php else: ?>
                  <?php foreach ($expenseCategories as $cat): ?>
                    <?php $cId = (int) $cat['expense_category_id']; $cName = (string) $cat['category_name']; $cDesc = (string) ($cat['description'] ?? ''); $uCount = (int) $cat['usage_count']; ?>
                    <tr>
                      <td class="rs-select-col"><input type="checkbox" class="form-check-input rs-select-chk rs-select-item" value="<?= $cId ?>"></td>
                      <td class="fw-bold text-main"><?= h($cName) ?></td>
                      <td><?= $cDesc !== '' ? h($cDesc) : '<span class="text-muted fst-italic">None</span>' ?></td>
                      <td class="text-center"><span class="badge bg-warm-secondary text-dark-accent border"><?= $uCount ?></span></td>
                      <td class="text-end">
                        <button type="button" class="btn btn-sm btn-rs-outline btn-edit-category" data-bs-toggle="modal" data-bs-target="#editCategoryModal" data-type="expense" data-id="<?= $cId ?>" data-name="<?= h($cName) ?>" data-desc="<?= h($cDesc) ?>" title="Edit"><i class="bi bi-pencil"></i></button>
                        <button type="button" class="btn btn-sm btn-rs-outline text-danger btn-delete-category" data-bs-toggle="modal" data-bs-target="#deleteCategoryModal" data-type="expense" data-id="<?= $cId ?>" data-name="<?= h($cName) ?>" title="Delete"><i class="bi bi-trash3"></i></button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <?= render_pagination($expenseCatPagination, ['section' => 'categories']) ?>
        </div>

        <!-- Bills Category Panel -->
        <div class="category-panel d-none" data-category-type="bill">
          <div class="manage-table-wrapper" data-rs-selection data-toolbar="#billCatBulkToolbar" data-form="#billCatBulkForm">
            <table class="table manage-table">
              <thead>
                <tr>
                  <th class="rs-select-col"><input type="checkbox" class="form-check-input rs-select-chk rs-select-all" aria-label="Select all bill categories"></th>
                  <th>Category Name</th>
                  <th>Description</th>
                  <th class="text-center">Used in Bills</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($billCategories)): ?>
                  <tr><td colspan="5" class="manage-empty-state"><i class="bi bi-tag"></i><p class="mb-0">No bill categories defined.</p></td></tr>
                <?php else: ?>
                  <?php foreach ($billCategories as $cat): ?>
                    <?php $cId = (int) $cat['bill_category_id']; $cName = (string) $cat['category_name']; $cDesc = (string) ($cat['description'] ?? ''); $uCount = (int) $cat['usage_count']; ?>
                    <tr>
                      <td class="rs-select-col"><input type="checkbox" class="form-check-input rs-select-chk rs-select-item" value="<?= $cId ?>"></td>
                      <td class="fw-bold text-main"><?= h($cName) ?></td>
                      <td><?= $cDesc !== '' ? h($cDesc) : '<span class="text-muted fst-italic">None</span>' ?></td>
                      <td class="text-center"><span class="badge bg-warm-secondary text-dark-accent border"><?= $uCount ?></span></td>
                      <td class="text-end">
                        <button type="button" class="btn btn-sm btn-rs-outline btn-edit-category" data-bs-toggle="modal" data-bs-target="#editCategoryModal" data-type="bill" data-id="<?= $cId ?>" data-name="<?= h($cName) ?>" data-desc="<?= h($cDesc) ?>" title="Edit"><i class="bi bi-pencil"></i></button>
                        <button type="button" class="btn btn-sm btn-rs-outline text-danger btn-delete-category" data-bs-toggle="modal" data-bs-target="#deleteCategoryModal" data-type="bill" data-id="<?= $cId ?>" data-name="<?= h($cName) ?>" title="Delete"><i class="bi bi-trash3"></i></button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <?= render_pagination($billCatPagination, ['section' => 'categories']) ?>
        </div>

        <!-- Categories Floating Bulk Toolbars & Forms -->
        <div class="rs-bulk-toolbar" id="choreCatBulkToolbar" style="display: none;" role="region" aria-label="Bulk actions">
          <div class="rs-bulk-toolbar-info">
            <span class="rs-bulk-count" data-count="0">0</span>
            <span class="rs-bulk-label">categories selected</span>
          </div>
          <div class="rs-bulk-actions">
            <button type="button" class="btn btn-danger btn-sm rs-bulk-submit-btn" data-action="delete_chore_categories" data-confirm="Are you sure you want to delete the selected chore categories? Categories currently assigned to chores will be detached.">
              <i class="bi bi-trash3 me-1"></i> Delete Selected
            </button>
            <button type="button" class="btn btn-rs-outline btn-sm rs-bulk-clear-btn">
              <i class="bi bi-x-lg me-1"></i> Clear
            </button>
          </div>
        </div>
        <form id="choreCatBulkForm" method="POST" action="actions/manage-bulk.php" style="display: none;">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="bulk_action" id="choreCatBulkAction" value="delete_chore_categories">
        </form>

        <div class="rs-bulk-toolbar" id="expCatBulkToolbar" style="display: none;" role="region" aria-label="Bulk actions">
          <div class="rs-bulk-toolbar-info">
            <span class="rs-bulk-count" data-count="0">0</span>
            <span class="rs-bulk-label">categories selected</span>
          </div>
          <div class="rs-bulk-actions">
            <button type="button" class="btn btn-danger btn-sm rs-bulk-submit-btn" data-action="delete_expense_categories" data-confirm="Are you sure you want to delete the selected expense categories?">
              <i class="bi bi-trash3 me-1"></i> Delete Selected
            </button>
            <button type="button" class="btn btn-rs-outline btn-sm rs-bulk-clear-btn">
              <i class="bi bi-x-lg me-1"></i> Clear
            </button>
          </div>
        </div>
        <form id="expCatBulkForm" method="POST" action="actions/manage-bulk.php" style="display: none;">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="bulk_action" id="expCatBulkAction" value="delete_expense_categories">
        </form>

        <div class="rs-bulk-toolbar" id="billCatBulkToolbar" style="display: none;" role="region" aria-label="Bulk actions">
          <div class="rs-bulk-toolbar-info">
            <span class="rs-bulk-count" data-count="0">0</span>
            <span class="rs-bulk-label">categories selected</span>
          </div>
          <div class="rs-bulk-actions">
            <button type="button" class="btn btn-danger btn-sm rs-bulk-submit-btn" data-action="delete_bill_categories" data-confirm="Are you sure you want to delete the selected bill categories?">
              <i class="bi bi-trash3 me-1"></i> Delete Selected
            </button>
            <button type="button" class="btn btn-rs-outline btn-sm rs-bulk-clear-btn">
              <i class="bi bi-x-lg me-1"></i> Clear
            </button>
          </div>
        </div>
        <form id="billCatBulkForm" method="POST" action="actions/manage-bulk.php" style="display: none;">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="bulk_action" id="billCatBulkAction" value="delete_bill_categories">
        </form>
      </section>
    <?php endif; ?>

    <!-- ==================================================
         VIEW 4: ANNOUNCEMENTS & ACTIVITIES (OPENABLE PAGE)
         ================================================== -->
    <?php if ($section === 'announcements'): ?>
      <section class="manage-card card-announcements shadow-sm" id="announcementsSection">
        <div class="manage-card-header">
          <div class="manage-card-title-wrap">
            <div class="manage-card-icon announcements"><i class="bi bi-megaphone-fill"></i></div>
            <div>
              <h2 class="h5 fw-bold text-main mb-0">Announcements and Activities</h2>
              <small class="text-secondary-custom">Broadcast household updates and review recent household activity timeline</small>
            </div>
          </div>
          <div>
            <button type="button" class="btn btn-sm btn-rs-primary" data-bs-toggle="modal" data-bs-target="#createAnnouncementModal">
              <i class="bi bi-plus-lg me-1"></i> New Announcement
            </button>
          </div>
        </div>

        <!-- Sub-tabs navigation -->
        <div class="manage-subtabs">
          <button type="button" class="manage-subtab-btn active" data-subtab="announcements">
            <i class="bi bi-megaphone me-1"></i> Announcements (<?= $totalAnnouncementsCount ?>)
          </button>
          <button type="button" class="manage-subtab-btn" data-subtab="activity">
            <i class="bi bi-clock-history me-1"></i> Household Activity Logs (<?= $totalActivityCount ?>)
          </button>
        </div>

        <!-- Subtab Panel 1: Announcements -->
        <div class="manage-subtab-panel" data-subtab="announcements" data-rs-selection data-toolbar="#annBulkToolbar" data-form="#annBulkForm">
          <?php if (empty($announcements)): ?>
            <div class="manage-empty-state">
              <i class="bi bi-megaphone"></i>
              <p class="mb-2">No announcements posted yet for this household.</p>
              <button type="button" class="btn btn-sm btn-rs-primary" data-bs-toggle="modal" data-bs-target="#createAnnouncementModal">
                <i class="bi bi-plus-lg me-1"></i> Post First Announcement
              </button>
            </div>
          <?php else: ?>
            <div class="d-flex justify-content-between align-items-center mb-3 p-2 bg-warm-secondary rounded border">
              <div class="form-check m-0">
                <input type="checkbox" class="form-check-input rs-select-chk rs-select-all" id="annSelectAll" aria-label="Select all announcements">
                <label class="form-check-label small fw-semibold text-main cursor-pointer" for="annSelectAll">Select All Announcements</label>
              </div>
              <small class="text-secondary-custom"><?= $totalAnnouncementsCount ?> announcements total</small>
            </div>

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
                      <div class="d-flex justify-content-between align-items-center mb-2">
                        <div class="announcement-meta mb-0">
                          <span><i class="bi bi-person-fill text-dark-accent me-1"></i> <?= h($annAuthor) ?></span>
                          <span>&bull;</span>
                          <span><i class="bi bi-clock me-1"></i> <?= h($annDate) ?></span>
                        </div>
                        <input type="checkbox" class="form-check-input rs-select-chk rs-select-item" value="<?= $annId ?>" aria-label="Select announcement">
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
            <?= render_pagination($annPagination, ['section' => 'announcements']) ?>

            <!-- Announcements Floating Bulk Toolbar & Form -->
            <div class="rs-bulk-toolbar" id="annBulkToolbar" style="display: none;" role="region" aria-label="Bulk actions">
              <div class="rs-bulk-toolbar-info">
                <span class="rs-bulk-count" data-count="0">0</span>
                <span class="rs-bulk-label">announcements selected</span>
              </div>
              <div class="rs-bulk-actions">
                <button type="button" class="btn btn-danger btn-sm rs-bulk-submit-btn" data-action="delete_announcements" data-confirm="Are you sure you want to delete the selected announcements?">
                  <i class="bi bi-trash3 me-1"></i> Delete Selected
                </button>
                <button type="button" class="btn btn-rs-outline btn-sm rs-bulk-clear-btn">
                  <i class="bi bi-x-lg me-1"></i> Clear
                </button>
              </div>
            </div>
            <form id="annBulkForm" method="POST" action="actions/manage-bulk.php" style="display: none;">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="bulk_action" id="annBulkAction" value="delete_announcements">
            </form>
          <?php endif; ?>
        </div>

        <!-- Subtab Panel 2: Activity Logs -->
        <div class="manage-subtab-panel d-none" data-subtab="activity">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <small class="text-secondary-custom">System-wide audit trail of records, payments, chore completions, and member activity.</small>
            <span class="badge bg-warm-secondary text-dark-accent border px-2 py-1"><?= $totalActivityCount ?> Total Events</span>
          </div>

          <?php if (empty($activityLogs)): ?>
            <div class="manage-empty-state">
              <i class="bi bi-clock-history"></i>
              <p class="mb-0">No recent activity recorded for this household.</p>
            </div>
          <?php else: ?>
            <div class="activity-list">
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
            <?= render_pagination($actPagination, ['section' => 'announcements']) ?>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>

    <!-- ==================================================
         VIEW 5: HOUSEHOLD SETTINGS (OPENABLE PAGE)
         ================================================== -->
    <?php if ($section === 'settings'): ?>
      <section class="manage-card card-household shadow-sm" id="householdSettingsSection">
        <div class="manage-card-header">
          <div class="manage-card-title-wrap">
            <div class="manage-card-icon settings"><i class="bi bi-gear-fill"></i></div>
            <div>
              <h2 class="h5 fw-bold text-main mb-0">Household Settings</h2>
              <small class="text-secondary-custom">Display and update household information, banner image, address, and join code</small>
            </div>
          </div>
          <span class="badge bg-warm-secondary text-dark-accent border px-3 py-2 fw-bold">
            Join Code: <?= h($householdJoinCode !== '' ? $householdJoinCode : 'N/A') ?>
          </span>
        </div>

        <form action="manage.php" method="POST" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="update_household">

          <div class="row g-4 mb-3">
            <!-- Left: Banner Preview & Upload -->
            <div class="col-12 col-md-5">
              <label class="form-label text-secondary-custom small fw-bold text-uppercase">Household Banner</label>
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

              <div>
                <label for="householdImageFileInput" class="form-label text-secondary-custom small fw-bold text-uppercase">Change Banner Image</label>
                <input type="file" name="household_image_file" id="householdImageFileInput" class="form-control manage-input" accept="image/jpeg,image/png,image/webp,image/gif">
                <small class="text-secondary-custom d-block mt-1">Recommended: landscape (JPG, PNG, WebP &bull; Max 2MB).</small>
              </div>
            </div>

            <!-- Right: Household Details Form -->
            <div class="col-12 col-md-7">
              <div class="mb-3">
                <label for="household_name" class="form-label text-secondary-custom small fw-bold text-uppercase">Household Name <span class="text-danger">*</span></label>
                <input type="text" name="household_name" id="household_name" class="form-control manage-input" value="<?= h($householdName) ?>" maxlength="100" required>
              </div>

              <div class="mb-3">
                <label for="description" class="form-label text-secondary-custom small fw-bold text-uppercase">Description</label>
                <textarea name="description" id="description" rows="3" class="form-control manage-input" placeholder="A cozy home for roommates..."><?= h($householdDesc) ?></textarea>
              </div>

              <div class="mb-3">
                <label for="address" class="form-label text-secondary-custom small fw-bold text-uppercase">Address</label>
                <input type="text" name="address" id="address" class="form-control manage-input" value="<?= h($householdAddress) ?>" maxlength="255" placeholder="e.g. 123 Main St, Apt 4B">
              </div>
            </div>
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
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center mt-2 gap-1">
              <small class="text-secondary-custom">Share this code with roommates to invite them to join.</small>
              <small class="text-secondary-custom">Created on: <?= date('M j, Y', strtotime($householdCreatedAt)) ?></small>
            </div>
          </div>

          <!-- Admin Shortcuts & Submit Button -->
          <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3">
            <button type="button" class="btn btn-rs-outline btn-sm" data-bs-toggle="modal" data-bs-target="#addChoreModal">
              <i class="bi bi-check2-square me-1"></i> Create Chore (Admin Shortcut)
            </button>
            <button type="submit" class="btn btn-rs-primary px-4">
              <i class="bi bi-check2-circle me-1"></i> Save Household Changes
            </button>
          </div>
        </form>
      </section>
    <?php endif; ?>

    <!-- ==================================================
         VIEW 6: MAINTENANCE & REPAIRS (OPENABLE PAGE)
         ================================================== -->
    <?php if ($section === 'maintenance'): ?>
      <section class="manage-card card-household shadow-sm" id="maintenanceSection">
        <div class="manage-card-header">
          <div class="manage-card-title-wrap">
            <div class="manage-card-icon maintenance"><i class="bi bi-tools"></i></div>
            <div>
              <h2 class="h5 fw-bold text-main mb-0">Maintenance &amp; Repairs</h2>
              <small class="text-secondary-custom">Review tickets submitted by roommates, update repair progress, and mark issues as resolved</small>
            </div>
          </div>
          <div class="d-flex align-items-center gap-2">
            <span class="badge bg-warm-secondary text-dark-accent border px-3 py-2 fw-bold">
              <?= $totalMaintenanceCount ?> Total &bull; <?= $openMaintenanceCount ?> Open
            </span>
            <button type="button" class="btn btn-rs-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createMaintenanceModal">
              <i class="bi bi-plus-lg me-1"></i> New Report
            </button>
          </div>
        </div>

        <!-- Filter and Search Row -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
          <form action="manage.php" method="GET" class="d-flex align-items-center gap-2 flex-grow-1" style="max-width: 480px;">
            <input type="hidden" name="section" value="maintenance">
            <input type="hidden" name="m_page" value="1">
            <div class="input-group input-group-sm">
              <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-secondary-custom"></i></span>
              <input type="text" name="maint_q" class="form-control form-control-sm border-start-0" placeholder="Search by problem type, description, or reporter..." value="<?= h($maintSearch) ?>">
              <?php if ($maintSearch !== ''): ?>
                <a href="manage.php?section=maintenance<?= $maintStatusFilter !== 'all' ? '&maint_status=' . urlencode($maintStatusFilter) : '' ?>" class="btn btn-outline-secondary btn-sm" title="Clear search"><i class="bi bi-x-lg"></i></a>
              <?php endif; ?>
              <button type="submit" class="btn btn-rs-primary btn-sm">Search</button>
            </div>
          </form>

          <div class="d-flex align-items-center gap-2 flex-wrap">
            <div class="btn-group btn-group-sm" role="group" aria-label="Maintenance status filter">
              <a href="manage.php?section=maintenance&maint_status=all<?= $maintSearch !== '' ? '&maint_q=' . urlencode($maintSearch) : '' ?>" class="btn <?= $maintStatusFilter === 'all' ? 'btn-rs-primary' : 'btn-rs-outline' ?>">All (<?= $totalMaintenanceCount ?>)</a>
              <a href="manage.php?section=maintenance&maint_status=open<?= $maintSearch !== '' ? '&maint_q=' . urlencode($maintSearch) : '' ?>" class="btn <?= $maintStatusFilter === 'open' ? 'btn-rs-primary' : 'btn-rs-outline' ?>">Open (<?= $openMaintenanceCount ?>)</a>
              <a href="manage.php?section=maintenance&maint_status=in_progress<?= $maintSearch !== '' ? '&maint_q=' . urlencode($maintSearch) : '' ?>" class="btn <?= $maintStatusFilter === 'in_progress' ? 'btn-rs-primary' : 'btn-rs-outline' ?>">In Progress (<?= $inProgressMaintenanceCount ?>)</a>
              <a href="manage.php?section=maintenance&maint_status=resolved<?= $maintSearch !== '' ? '&maint_q=' . urlencode($maintSearch) : '' ?>" class="btn <?= $maintStatusFilter === 'resolved' ? 'btn-rs-primary' : 'btn-rs-outline' ?>">Resolved (<?= $resolvedMaintenanceCount ?>)</a>
            </div>
          </div>
        </div>

        <!-- Maintenance Table -->
        <div class="manage-table-wrapper">
          <table class="table manage-table" id="manageMaintenanceTable">
            <thead>
              <tr>
                <th style="width: 25%;">Issue / Problem</th>
                <th style="width: 30%;">Description</th>
                <th style="width: 15%;">Reported By</th>
                <th style="width: 12%;">Date</th>
                <th style="width: 10%;">Status</th>
                <th class="text-end" style="width: 8%;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($maintenanceTickets)): ?>
                <tr>
                  <td colspan="6" class="manage-empty-state">
                    <i class="bi bi-tools"></i>
                    <p class="mb-0">No maintenance reports found matching your criteria.</p>
                    <?php if ($maintSearch !== '' || $maintStatusFilter !== 'all'): ?>
                      <a href="manage.php?section=maintenance" class="btn btn-sm btn-rs-outline mt-2">Clear Filters</a>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($maintenanceTickets as $ticket): ?>
                  <?php
                    $tStatus = strtolower((string) $ticket['status']);
                    $tBadgeClass = 'rs-badge-open';
                    $tLabel = 'Open';
                    if ($tStatus === 'resolved') {
                        $tBadgeClass = 'rs-badge-resolved';
                        $tLabel = 'Resolved';
                    } elseif ($tStatus === 'in progress' || $tStatus === 'in_progress') {
                        $tBadgeClass = 'rs-badge-in-progress';
                        $tLabel = 'In Progress';
                    }

                    $tReporter = (string) $ticket['reported_by_name'];
                    $tInitials = manage_initials($tReporter);
                  ?>
                  <tr>
                    <td>
                      <div class="fw-bold text-main d-flex align-items-center gap-2">
                        <i class="bi bi-wrench text-dark-accent"></i>
                        <span><?= h((string) $ticket['problem_type']) ?></span>
                      </div>
                      <small class="text-secondary-custom">Ticket #<?= (int) $ticket['problem_id'] ?></small>
                    </td>
                    <td>
                      <?php if (!empty($ticket['problem_desc'])): ?>
                        <div class="text-main small" style="max-width: 340px; white-space: normal; word-break: break-word;">
                          <?= nl2br(h((string) $ticket['problem_desc'])) ?>
                        </div>
                      <?php else: ?>
                        <span class="text-secondary-custom fst-italic small">No description provided</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <?= render_avatar_markup($tInitials, (string) ($ticket['profile_picture'] ?? ''), $tReporter, '', 'width: 32px; height: 32px; font-size: 0.8rem;') ?>
                        <div class="text-truncate" style="max-width: 120px;">
                          <div class="fw-semibold text-main small text-truncate"><?= h($tReporter) ?></div>
                          <?php if (!empty($ticket['username'])): ?>
                            <small class="text-secondary-custom d-block text-truncate">@<?= h((string) $ticket['username']) ?></small>
                          <?php endif; ?>
                        </div>
                      </div>
                    </td>
                    <td class="text-nowrap small text-secondary-custom">
                      <?= h(date('M j, Y', strtotime((string) $ticket['reported_at']))) ?>
                    </td>
                    <td>
                      <span class="rs-badge <?= $tBadgeClass ?>">
                        <span class="badge-dot"></span> <?= h($tLabel) ?>
                      </span>
                    </td>
                    <td class="text-end">
                      <div class="d-flex justify-content-end align-items-center gap-1">
                        <?php if ($tStatus !== 'resolved'): ?>
                          <form action="manage.php" method="POST" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="toggle_maintenance_status">
                            <input type="hidden" name="problem_id" value="<?= (int) $ticket['problem_id'] ?>">
                            <input type="hidden" name="new_status" value="Resolved">
                            <button type="submit" class="btn btn-sm btn-rs-primary p-1 px-2" data-confirm="Mark this maintenance issue as Resolved?" title="Mark as Done">
                              <i class="bi bi-check2"></i> <span class="d-none d-xl-inline">Done</span>
                            </button>
                          </form>
                        <?php else: ?>
                          <form action="manage.php" method="POST" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="toggle_maintenance_status">
                            <input type="hidden" name="problem_id" value="<?= (int) $ticket['problem_id'] ?>">
                            <input type="hidden" name="new_status" value="Open">
                            <button type="submit" class="btn btn-sm btn-rs-outline p-1 px-2" data-confirm="Reopen this maintenance ticket?" title="Reopen Ticket">
                              <i class="bi bi-arrow-counterclockwise"></i> <span class="d-none d-xl-inline">Reopen</span>
                            </button>
                          </form>
                        <?php endif; ?>

                        <button type="button" class="btn btn-sm btn-rs-outline p-1 px-2 btn-edit-maintenance"
                                data-bs-toggle="modal" data-bs-target="#editMaintenanceModal"
                                data-id="<?= (int) $ticket['problem_id'] ?>"
                                data-type="<?= h((string) $ticket['problem_type']) ?>"
                                data-status="<?= h((string) $ticket['status']) ?>"
                                data-desc="<?= h((string) ($ticket['problem_desc'] ?? '')) ?>"
                                title="Edit Report">
                          <i class="bi bi-pencil"></i>
                        </button>

                        <form action="manage.php" method="POST" class="d-inline">
                          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                          <input type="hidden" name="action" value="delete_maintenance">
                          <input type="hidden" name="problem_id" value="<?= (int) $ticket['problem_id'] ?>">
                          <button type="submit" class="btn btn-sm btn-outline-danger p-1 px-2" data-confirm="Are you sure you want to permanently delete this maintenance report?" title="Delete Report">
                            <i class="bi bi-trash3"></i>
                          </button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Maintenance Pagination -->
        <?= render_pagination($maintPagination, ['section' => 'maintenance', 'maint_q' => $maintSearch !== '' ? $maintSearch : null, 'maint_status' => $maintStatusFilter !== 'all' ? $maintStatusFilter : null]) ?>
      </section>
    <?php endif; ?>

  </main>

  <!-- ==================================================
       MODALS FOR ADMIN ACTIONS
       ================================================== -->

  <!-- Modal 1: Change Member Role -->
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

  <!-- Modal 2: Remove Member Confirmation -->
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

  <!-- Modal 3: Create Announcement -->
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

  <!-- Modal 4: Edit Announcement -->
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

  <!-- Modal 5: Delete Announcement Confirmation -->
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

  <!-- Modal 6: Add Category -->
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

  <!-- Modal 7: Edit Category -->
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

  <!-- Modal 8: Delete Category Confirmation -->
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

  <!-- Modal 9: Admin-Only Add Chore Shortcut -->
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
                  <?php foreach ($allChoreCategories as $cc): ?>
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
                  <?php foreach ($allHouseholdMembers as $m): ?>
                    <option value="<?= (int) $m['user_id'] ?>" <?= ((int) $m['user_id'] === $userId) ? 'selected' : '' ?>>
                      <?= h((string) (!empty($m['full_name']) ? $m['full_name'] : $m['username'])) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12" id="quickChoreRotationMembersWrap" style="display: none;">
                <label class="form-label text-secondary-custom small fw-bold text-uppercase d-block">Rotation Order (Check Members)</label>
                <div class="d-flex flex-wrap gap-2">
                  <?php foreach ($allHouseholdMembers as $m): ?>
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

  <!-- Modal 10: Create Maintenance Report (Admin shortcut) -->
  <div class="modal fade" id="createMaintenanceModal" tabindex="-1" aria-labelledby="createMaintenanceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content rs-modal">
        <div class="modal-header rs-modal-header">
          <h2 class="modal-title h5 text-main" id="createMaintenanceModalLabel"><i class="bi bi-tools text-dark-accent me-2"></i>New Maintenance Report</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form action="actions/maintenance-create.php" method="POST">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="return_to" value="manage.php?section=maintenance">
          <div class="modal-body rs-modal-body">
            <div class="mb-3">
              <label for="createMaintProblemType" class="form-label text-secondary-custom small fw-bold text-uppercase">Problem Category <span class="text-danger">*</span></label>
              <select class="form-select manage-input" id="createMaintProblemType" name="problem_type" required>
                <option value="" disabled selected>Select issue category...</option>
                <option value="Plumbing">Plumbing (leaks, pipes, drainage, toilet)</option>
                <option value="Electrical">Electrical (outlets, wiring, lighting)</option>
                <option value="Appliance">Appliance (refrigerator, stove, washer)</option>
                <option value="Carpentry">Carpentry / Structural (doors, locks, windows, walls)</option>
                <option value="HVAC">HVAC / Air Conditioning &amp; Heating</option>
                <option value="Pest Control">Pest Control</option>
                <option value="Cleaning">Cleaning &amp; Waste Disposal</option>
                <option value="Security">Security &amp; Keys</option>
                <option value="Other">Other / General Maintenance</option>
              </select>
            </div>
            <div class="mb-3">
              <label for="createMaintProblemDesc" class="form-label text-secondary-custom small fw-bold text-uppercase">Description</label>
              <textarea name="problem_desc" id="createMaintProblemDesc" rows="4" class="form-control manage-input" maxlength="2000" placeholder="Provide details about the issue..."></textarea>
            </div>
          </div>
          <div class="modal-footer rs-modal-footer">
            <button type="button" class="btn btn-rs-outline" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-rs-primary"><i class="bi bi-send me-1"></i> Submit Report</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Modal 11: Edit Maintenance Report -->
  <div class="modal fade" id="editMaintenanceModal" tabindex="-1" aria-labelledby="editMaintenanceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content rs-modal">
        <div class="modal-header rs-modal-header">
          <h2 class="modal-title h5 text-main" id="editMaintenanceModalLabel"><i class="bi bi-pencil text-dark-accent me-2"></i>Edit Maintenance Report</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form action="manage.php" method="POST">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="update_maintenance">
          <input type="hidden" name="problem_id" id="editMaintId" value="">
          <div class="modal-body rs-modal-body">
            <div class="mb-3">
              <label for="editMaintType" class="form-label text-secondary-custom small fw-bold text-uppercase">Problem Category <span class="text-danger">*</span></label>
              <select class="form-select manage-input" id="editMaintType" name="problem_type" required>
                <option value="Plumbing">Plumbing</option>
                <option value="Electrical">Electrical</option>
                <option value="Appliance">Appliance</option>
                <option value="Carpentry">Carpentry / Structural</option>
                <option value="HVAC">HVAC / Air Conditioning</option>
                <option value="Pest Control">Pest Control</option>
                <option value="Cleaning">Cleaning &amp; Waste Disposal</option>
                <option value="Security">Security &amp; Keys</option>
                <option value="Other">Other / General Maintenance</option>
              </select>
            </div>
            <div class="mb-3">
              <label for="editMaintStatus" class="form-label text-secondary-custom small fw-bold text-uppercase">Status <span class="text-danger">*</span></label>
              <select class="form-select manage-input" id="editMaintStatus" name="status" required>
                <option value="Open">Open</option>
                <option value="In Progress">In Progress</option>
                <option value="Resolved">Resolved</option>
              </select>
            </div>
            <div class="mb-3">
              <label for="editMaintDesc" class="form-label text-secondary-custom small fw-bold text-uppercase">Description</label>
              <textarea name="problem_desc" id="editMaintDesc" rows="4" class="form-control manage-input" maxlength="2000" placeholder="Provide details about the issue..."></textarea>
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

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/main.js"></script>
  <script src="assets/js/manage.js"></script>
  <script src="assets/js/selection.js"></script>
  <script>
    // Inline quick chore assignment switcher
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
