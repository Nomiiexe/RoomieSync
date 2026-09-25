<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if (!restore_remembered_login()) {
    redirect_to('login.php');
}

$connection = database_connection();
$userId = (int) $_SESSION['user_id'];
$username = (string) ($_SESSION['username'] ?? '');
$errorMessage = '';
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'This form expired. Please try again.';
    } elseif ($action === 'select_household') {
        $submittedHouseholdId = $_POST['household_id'] ?? null;
        $householdId = is_string($submittedHouseholdId) && ctype_digit($submittedHouseholdId)
            ? (int) $submittedHouseholdId
            : 0;

        if (!set_selected_household($householdId)) {
            $errorMessage = 'That household selection is not valid.';
        } else {
            redirect_to('dashboard.php');
        }
    } elseif ($action === 'create_household') {
        $householdName = trim(is_string($_POST['household_name'] ?? null) ? $_POST['household_name'] : '');
        $description = trim(is_string($_POST['description'] ?? null) ? $_POST['description'] : '');
        $address = trim(is_string($_POST['address'] ?? null) ? $_POST['address'] : '');

        if ($householdName === '' || strlen($householdName) > 100) {
            $errorMessage = 'Enter a household name with up to 100 characters.';
        } elseif (strlen($description) > 2000) {
            $errorMessage = 'The description must be 2,000 characters or fewer.';
        } elseif (strlen($address) > 255) {
            $errorMessage = 'The address must be 255 characters or fewer.';
        } else {
            $created = false;
            for ($attempt = 0; $attempt < 5 && !$created; $attempt++) {
                $joinCode = strtoupper(bin2hex(random_bytes(4)));

                try {
                    $connection->begin_transaction();

                    $insertHousehold = $connection->prepare(
                        'INSERT INTO householdTb (household_name, description, address, join_code) VALUES (?, ?, ?, ?)'
                    );
                    $insertHousehold->bind_param('ssss', $householdName, $description, $address, $joinCode);
                    $insertHousehold->execute();
                    $householdId = $connection->insert_id;

                    $insertMember = $connection->prepare(
                        "INSERT INTO householdmembersTb (household_id, user_id, role) VALUES (?, ?, 'Admin')"
                    );
                    $insertMember->bind_param('ii', $householdId, $userId);
                    $insertMember->execute();

                    $defaultCategories = ['Kitchen', 'Cleaning', 'Laundry', 'Trash'];
                    $insertCategory = $connection->prepare(
                        'INSERT INTO chore_categoriesTb (household_id, category_name, description)
                         VALUES (?, ?, NULL)'
                    );
                    foreach ($defaultCategories as $categoryName) {
                        $insertCategory->bind_param('is', $householdId, $categoryName);
                        $insertCategory->execute();
                    }

                    $connection->commit();
                    $created = true;
                } catch (mysqli_sql_exception $exception) {
                    $connection->rollback();
                    if ($exception->getCode() !== 1062 || $attempt === 4) {
                        $errorMessage = 'The household could not be created. Please try again.';
                    }
                }
            }

            if ($created) {
                set_selected_household((int) $householdId);
                $_SESSION['flash_message'] = [
                    'message' => 'Household created successfully. Your join code is ' . $joinCode . '.',
                ];
                redirect_to('dashboard.php');
            }
        }
    }
}

if ($errorMessage === '') {
    $notice = consume_flash_message();
}

$householdsQuery = $connection->prepare(
    'SELECT h.household_id, h.household_name, h.description, h.address, h.join_code,
            h.created_at, hm.role, COUNT(m.user_id) AS member_count
     FROM householdmembersTb AS hm
     INNER JOIN householdTb AS h ON h.household_id = hm.household_id
     LEFT JOIN householdmembersTb AS m ON m.household_id = h.household_id
     WHERE hm.user_id = ?
     GROUP BY h.household_id, h.household_name, h.description, h.address,
              h.join_code, h.created_at, hm.role
     ORDER BY h.created_at DESC'
);
$householdsQuery->bind_param('i', $userId);
$householdsQuery->execute();
$households = $householdsQuery->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Select Household | RoomieSync</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="d-flex flex-column min-vh-100">
  <main class="container py-5 flex-grow-1">
    <div class="text-center mb-5">
      <a href="dashboard.php" class="rs-brand-icon mx-auto mb-3 text-decoration-none" style="width: 58px; height: 58px; font-size: 1.75rem;">
        <i class="bi bi-house-heart-fill"></i>
      </a>
      <h1 class="h2 fw-bold mb-2">Welcome Back, <?= h($username) ?></h1>
      <p class="text-secondary-custom fs-6 mb-0">Select a household or create a new one</p>
    </div>

    <?php if ($notice): ?>
      <div class="alert alert-success mx-auto mb-4" style="max-width: 960px;" role="status"><?= h((string) $notice['message']) ?></div>
    <?php endif; ?>
    <?php if ($errorMessage !== ''): ?>
      <div class="alert alert-danger mx-auto mb-4" style="max-width: 960px;" role="alert"><?= h($errorMessage) ?></div>
    <?php endif; ?>

    <?php if ($households): ?>
      <div class="row justify-content-center g-4 mb-4">
        <?php foreach ($households as $household): ?>
          <div class="col-md-6 col-lg-5">
            <div class="rs-card rs-card-hover h-100 p-0 overflow-hidden d-flex flex-column">
              <div class="p-4 text-center" style="background: linear-gradient(135deg, #F3E8DD 0%, #EAD8C7 100%); min-height: 150px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                <div class="rs-stat-icon mb-2" style="width: 64px; height: 64px; font-size: 2rem; background: rgba(255,255,255,0.85);">
                  <i class="bi bi-building text-dark-accent"></i>
                </div>
                <span class="badge bg-warm-secondary text-dark-accent px-3 py-1 rounded-pill fw-semibold border" style="font-size: 0.8rem;">
                  <i class="bi bi-people me-1"></i> <?= (int) $household['member_count'] ?> <?= (int) $household['member_count'] === 1 ? 'Member' : 'Members' ?>
                </span>
              </div>

              <div class="p-4 d-flex flex-column flex-grow-1">
                <div class="d-flex justify-content-between align-items-start mb-2 gap-2">
                  <div>
                    <h2 class="h4 fw-bold mb-1"><?= h((string) $household['household_name']) ?></h2>
                    <?php if ((string) $household['description'] !== ''): ?>
                      <p class="text-secondary-custom small mb-0"><?= h((string) $household['description']) ?></p>
                    <?php endif; ?>
                  </div>
                  <span class="rs-badge rs-badge-active"><span class="rs-badge-dot"></span> <?= h((string) $household['role']) ?></span>
                </div>

                <div class="my-3 py-2 border-top border-bottom d-flex justify-content-between align-items-center gap-2">
                  <span class="small text-secondary-custom">
                    <?php if ((string) $household['address'] !== ''): ?>
                      <i class="bi bi-geo-alt me-1"></i><?= h((string) $household['address']) ?>
                    <?php else: ?>
                      <i class="bi bi-calendar3 me-1"></i>Created <?= h(date('M j, Y', strtotime((string) $household['created_at']))) ?>
                    <?php endif; ?>
                  </span>
                  <span class="small text-secondary-custom text-nowrap">Code: <strong><?= h((string) $household['join_code']) ?></strong></span>
                </div>

                <div class="mt-auto pt-2">
                  <form action="households.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="select_household">
                    <input type="hidden" name="household_id" value="<?= (int) $household['household_id'] ?>">
                    <button type="submit" class="btn btn-rs-primary w-100 justify-content-center py-2">
                      <span>Enter Household</span><i class="bi bi-arrow-right"></i>
                    </button>
                  </form>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="row justify-content-center g-4">
      <div class="col-md-6 col-lg-5">
        <div class="rs-card rs-card-hover p-4 h-100 d-flex flex-column justify-content-between">
          <div class="d-flex align-items-center gap-3 mb-3">
            <div class="rs-stat-icon" style="background-color: var(--rs-accent-light);"><i class="bi bi-plus-lg text-dark-accent"></i></div>
            <div>
              <h2 class="h5 fw-bold mb-1">Create a Household</h2>
              <p class="text-secondary-custom small mb-0">Start a shared space for your housemates.</p>
            </div>
          </div>
          <button type="button" class="btn btn-rs-primary w-100 justify-content-center" data-bs-toggle="modal" data-bs-target="#createHouseholdModal">
            <i class="bi bi-plus-circle"></i> Create Household
          </button>
        </div>
      </div>

      <div class="col-md-6 col-lg-5">
        <div class="rs-card rs-card-hover p-4 h-100 d-flex flex-column justify-content-between">
          <div class="d-flex align-items-center gap-3 mb-3">
            <div class="rs-stat-icon" style="background-color: var(--rs-accent-light);"><i class="bi bi-key text-dark-accent"></i></div>
            <div>
              <h2 class="h5 fw-bold mb-1">Join with Invite Code</h2>
              <p class="text-secondary-custom small mb-0">Have a code from a household administrator?</p>
            </div>
          </div>
          <a href="join-household.php" class="btn btn-rs-outline w-100 justify-content-center">
            <i class="bi bi-door-open"></i> Join Household
          </a>
        </div>
      </div>
    </div>

    <div class="text-center mt-4">
      <span class="small text-secondary-custom">Signed in as <strong class="text-dark-accent"><?= h($username) ?></strong></span>
      <a href="logout.php" class="btn btn-sm btn-rs-outline text-danger ms-2"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
    </div>
  </main>

  <div class="modal fade" id="createHouseholdModal" tabindex="-1" aria-labelledby="createHouseholdModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content rs-modal">
        <div class="modal-header rs-modal-header">
          <h2 class="modal-title h5" id="createHouseholdModalLabel"><i class="bi bi-house-add text-dark-accent me-2"></i>Create a Household</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form action="households.php" method="POST">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="create_household">
          <div class="modal-body rs-modal-body">
            <div class="mb-3">
              <label for="householdName" class="form-label">Household Name</label>
              <input type="text" class="form-control" id="householdName" name="household_name" maxlength="100" required>
            </div>
            <div class="mb-3">
              <label for="householdDescription" class="form-label">Description <span class="text-secondary-custom fw-normal">(optional)</span></label>
              <textarea class="form-control" id="householdDescription" name="description" rows="3" maxlength="2000"></textarea>
            </div>
            <div class="mb-0">
              <label for="householdAddress" class="form-label">Address <span class="text-secondary-custom fw-normal">(optional)</span></label>
              <input type="text" class="form-control" id="householdAddress" name="address" maxlength="255">
            </div>
          </div>
          <div class="modal-footer rs-modal-footer">
            <button type="button" class="btn btn-rs-outline" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-rs-primary"><i class="bi bi-plus-circle"></i> Create Household</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/main.js"></script>
</body>
</html>
