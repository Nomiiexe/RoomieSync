<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if (!restore_remembered_login()) {
    redirect_to('login.php');
}

$connection = database_connection();
$userId = (int) $_SESSION['user_id'];
$errorMessage = '';
$notice = consume_flash_message();
$inviteCode = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inviteCode = strtoupper(trim(is_string($_POST['invite_code'] ?? null) ? $_POST['invite_code'] : ''));
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'This form expired. Please try again.';
    } elseif ($action !== 'join_household') {
        $errorMessage = 'The join request was not valid.';
    } elseif (!preg_match('/\A[A-Z0-9-]{4,32}\z/', $inviteCode)) {
        $errorMessage = 'Enter a valid household invite code.';
    } else {
        $householdQuery = $connection->prepare(
            'SELECT household_id, household_name
             FROM householdTb
             WHERE join_code = ?
             LIMIT 1'
        );
        $householdQuery->bind_param('s', $inviteCode);
        $householdQuery->execute();
        $household = $householdQuery->get_result()->fetch_assoc();

        if (!$household) {
            $errorMessage = 'That household invite code was not found.';
        } else {
            $householdId = (int) $household['household_id'];
            $membershipQuery = $connection->prepare(
                'SELECT household_id FROM householdmembersTb WHERE household_id = ? AND user_id = ? LIMIT 1'
            );
            $membershipQuery->bind_param('ii', $householdId, $userId);
            $membershipQuery->execute();

            if ($membershipQuery->get_result()->fetch_assoc()) {
                $_SESSION['flash_message'] = [
                    'message' => 'You are already a member of ' . (string) $household['household_name'] . '.',
                ];
                redirect_to('households.php');
            }

            try {
                $insertMember = $connection->prepare(
                    "INSERT INTO householdmembersTb (household_id, user_id, role) VALUES (?, ?, 'Member')"
                );
                $insertMember->bind_param('ii', $householdId, $userId);
                $insertMember->execute();

                $_SESSION['flash_message'] = [
                    'message' => 'You joined ' . (string) $household['household_name'] . '. Your membership was saved.',
                ];
                redirect_to('households.php');
            } catch (mysqli_sql_exception $exception) {
                if ($exception->getCode() === 1062) {
                    $_SESSION['flash_message'] = [
                        'message' => 'You are already a member of this household.',
                    ];
                    redirect_to('households.php');
                }
                $errorMessage = 'The household could not be joined. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Join a Household | RoomieSync</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="d-flex flex-column min-vh-100">
  <main class="container py-5 flex-grow-1">
    <div class="row justify-content-center">
      <div class="col-lg-5 col-md-7">
        <div class="mb-3">
          <a href="households.php" class="text-decoration-none text-secondary-custom small">
            <i class="bi bi-arrow-left me-1"></i> Back to Household Selection
          </a>
        </div>

        <div class="rs-card p-4 p-md-5">
          <div class="text-center mb-4">
            <div class="rs-brand-icon mx-auto mb-3" style="width: 52px; height: 52px; font-size: 1.5rem; background: var(--rs-accent-light);">
              <i class="bi bi-key-fill text-dark-accent"></i>
            </div>
            <h1 class="h3 fw-bold mb-2">Join a Household</h1>
            <p class="text-secondary-custom small mb-0">
              Enter the unique household invite code provided by your household administrator.
            </p>
          </div>

          <?php if ($notice): ?>
            <div class="alert alert-success" role="status"><?= h((string) $notice['message']) ?></div>
          <?php endif; ?>
          <?php if ($errorMessage !== ''): ?>
            <div class="alert alert-danger" role="alert"><?= h($errorMessage) ?></div>
          <?php endif; ?>

          <form action="join-household.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="join_household">
            <div class="mb-4">
              <label for="householdCode" class="form-label text-center d-block">Household Invite Code</label>
              <input type="text" class="form-control text-uppercase text-center fw-bold py-3 fs-5 letter-spacing-3" id="householdCode" name="invite_code" value="<?= h($inviteCode) ?>" maxlength="32" autocomplete="off" required>
            </div>

            <div class="d-grid gap-2">
              <button type="submit" class="btn btn-rs-primary py-2 justify-content-center">
                <i class="bi bi-door-open"></i> Join Household
              </button>
              <a href="households.php" class="btn btn-rs-outline py-2 justify-content-center">Cancel</a>
            </div>
          </form>
        </div>
      </div>
    </div>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/main.js"></script>
</body>
</html>
