<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if (!restore_remembered_login()) {
    redirect_to('login.php');
}

$connection = database_connection();
$userId = (int) $_SESSION['user_id'];
$selectedHouseholdId = selected_household_id();
if ($selectedHouseholdId === null) {
    redirect_to('households.php');
}
$errorMessage = '';
$notice = consume_flash_message();

$profileQuery = $connection->prepare(
    'SELECT user_id, username, full_name, email, password_hash, status, created_at
     FROM userTb
     WHERE user_id = ?
     LIMIT 1'
);
$profileQuery->bind_param('i', $userId);
$profileQuery->execute();
$profile = $profileQuery->get_result()->fetch_assoc();

if (!$profile) {
    redirect_to('logout.php');
}

$formFullName = (string) $profile['full_name'];
$formUsername = (string) $profile['username'];
$formEmail = (string) ($profile['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'This form expired. Please try again.';
    } elseif ($action === 'update_profile') {
        $formFullName = trim(is_string($_POST['full_name'] ?? null) ? $_POST['full_name'] : '');
        $formUsername = trim(is_string($_POST['username'] ?? null) ? $_POST['username'] : '');
        $formEmail = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');

        if ($formFullName === '' || strlen($formFullName) > 100) {
            $errorMessage = 'Enter your full name with up to 100 characters.';
        } elseif (!preg_match('/\A[A-Za-z0-9_.-]{3,50}\z/', $formUsername)) {
            $errorMessage = 'Choose a username with 3-50 letters, numbers, dots, underscores, or hyphens.';
        } elseif ($formEmail !== '' && (strlen($formEmail) > 100 || filter_var($formEmail, FILTER_VALIDATE_EMAIL) === false)) {
            $errorMessage = 'Enter a valid email address or leave it blank.';
        } else {
            $usernameCheck = $connection->prepare(
                'SELECT user_id FROM userTb WHERE username = ? AND user_id <> ? LIMIT 1'
            );
            $usernameCheck->bind_param('si', $formUsername, $userId);
            $usernameCheck->execute();

            if ($usernameCheck->get_result()->fetch_assoc()) {
                $errorMessage = 'That username is already in use.';
            } else {
                $emailTaken = false;
                if ($formEmail !== '') {
                    $emailCheck = $connection->prepare(
                        'SELECT user_id FROM userTb WHERE email = ? AND user_id <> ? LIMIT 1'
                    );
                    $emailCheck->bind_param('si', $formEmail, $userId);
                    $emailCheck->execute();
                    $emailTaken = (bool) $emailCheck->get_result()->fetch_assoc();
                }

                if ($emailTaken) {
                    $errorMessage = 'That email address is already in use.';
                } else {
                    if ($formEmail === '') {
                        $update = $connection->prepare(
                            'UPDATE userTb SET full_name = ?, username = ?, email = NULL WHERE user_id = ?'
                        );
                        $update->bind_param('ssi', $formFullName, $formUsername, $userId);
                    } else {
                        $update = $connection->prepare(
                            'UPDATE userTb SET full_name = ?, username = ?, email = ? WHERE user_id = ?'
                        );
                        $update->bind_param('sssi', $formFullName, $formUsername, $formEmail, $userId);
                    }
                    $update->execute();

                    $_SESSION['full_name'] = $formFullName;
                    $_SESSION['username'] = $formUsername;
                    $_SESSION['flash_message'] = ['message' => 'Your profile was updated successfully.'];
                    redirect_to('profile.php');
                }
            }
        }
    } elseif ($action === 'change_password') {
        $currentPassword = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
        $newPassword = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
        $confirmPassword = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

        if (!password_verify($currentPassword, (string) $profile['password_hash'])) {
            $errorMessage = 'Your current password is incorrect.';
        } elseif (strlen($newPassword) < 8) {
            $errorMessage = 'Use a new password with at least 8 characters.';
        } elseif ($newPassword !== $confirmPassword) {
            $errorMessage = 'The new passwords do not match.';
        } else {
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $updatePassword = $connection->prepare(
                'UPDATE userTb SET password_hash = ?, recovery_code_hash = NULL WHERE user_id = ?'
            );
            $updatePassword->bind_param('si', $passwordHash, $userId);
            $updatePassword->execute();

            $deleteTokens = $connection->prepare('DELETE FROM authTokensTb WHERE user_id = ?');
            $deleteTokens->bind_param('i', $userId);
            $deleteTokens->execute();

            $_SESSION['flash_message'] = ['message' => 'Your password was changed. Please sign in again on other devices.'];
            redirect_to('profile.php');
        }
    } elseif ($action === 'delete_account') {
        $currentPassword = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';

        if (!password_verify($currentPassword, (string) $profile['password_hash'])) {
            $errorMessage = 'Your current password is incorrect.';
        } else {
            $deleteUser = $connection->prepare('DELETE FROM userTb WHERE user_id = ?');
            $deleteUser->bind_param('i', $userId);
            $deleteUser->execute();

            expire_remember_cookie();
            $_SESSION = [];
            session_destroy();
            redirect_to('login.php?deleted=1');
        }
    }
}

$household = null;
$members = [];
$householdQuery = $connection->prepare(
    'SELECT h.household_id, h.household_name, h.description, h.address,
            h.household_image, h.created_at, hm.role, hm.joined_at
     FROM householdmembersTb AS hm
     INNER JOIN householdTb AS h ON h.household_id = hm.household_id
     WHERE hm.user_id = ? AND hm.household_id = ?
     LIMIT 1'
);
$householdQuery->bind_param('ii', $userId, $selectedHouseholdId);
$householdQuery->execute();
$household = $householdQuery->get_result()->fetch_assoc() ?: null;

if ($household) {
    $membersQuery = $connection->prepare(
        'SELECT u.full_name, u.username, u.email, hm.role, hm.joined_at
         FROM householdmembersTb AS hm
         INNER JOIN userTb AS u ON u.user_id = hm.user_id
         WHERE hm.household_id = ?
         ORDER BY hm.joined_at ASC, u.full_name ASC'
    );
    $householdId = (int) $household['household_id'];
    $membersQuery->bind_param('i', $householdId);
    $membersQuery->execute();
    $members = $membersQuery->get_result()->fetch_all(MYSQLI_ASSOC);
}

function profile_initials(string $value): string
{
    $parts = preg_split('/\s+/', trim($value)) ?: [];
    if (count($parts) > 1) {
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts) - 1], 0, 1));
    }
    return strtoupper(substr(trim($value), 0, 2)) ?: '?';
}

function profile_date(?string $value): string
{
    return $value ? date('M j, Y', strtotime($value)) : 'Date unavailable';
}

$displayName = $formUsername !== '' ? $formUsername : $formFullName;
$role = $household ? (string) $household['role'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Profile | RoomieSync</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/style.css">
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
        <li><a href="#" class="rs-nav-link" aria-disabled="true" title="Bills page coming soon"><i class="bi bi-receipt"></i><span>Bills</span></a></li>
        <li><a href="#" class="rs-nav-link" aria-disabled="true" title="Household page coming soon"><i class="bi bi-house"></i><span>Household</span></a></li>
        <li><a href="#" class="rs-nav-link" aria-disabled="true" title="Reports page coming soon"><i class="bi bi-bar-chart"></i><span>Reports</span></a></li>
        <?php if (strcasecmp($role, 'Admin') === 0): ?><li><a href="#" class="rs-nav-link" aria-disabled="true" title="Manage page coming soon"><i class="bi bi-sliders"></i><span>Manage</span></a></li><?php endif; ?>
      </ul>
      <div class="rs-nav-user dropdown">
        <a href="#" class="rs-user-btn dropdown-toggle" role="button" data-bs-toggle="dropdown" aria-expanded="false">
          <div class="rs-avatar"><?= h(profile_initials($displayName)) ?></div>
          <span class="d-none d-md-inline"><?= h($displayName) ?></span>
        </a>
        <ul class="dropdown-menu dropdown-menu-end rs-dropdown">
          <li class="px-3 py-2 border-bottom mb-1">
            <div class="fw-bold text-dark-accent"><?= h($displayName) ?></div>
            <small class="text-secondary-custom"><?= $formEmail !== '' ? h($formEmail) : 'No email set' ?></small>
          </li>
          <li><a class="dropdown-item rs-dropdown-item" href="profile.php"><i class="bi bi-person-circle"></i> Profile</a></li>
          <li><a class="dropdown-item rs-dropdown-item" href="households.php"><i class="bi bi-arrow-left-right"></i> Switch Household</a></li>
          <li><hr class="dropdown-divider rs-dropdown-divider"></li>
          <li><a class="dropdown-item rs-dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a></li>
        </ul>
      </div>
    </nav>
  </div>

  <main class="container py-4 flex-grow-1">
    <div class="mb-4">
      <h1 class="h3 fw-bold mb-1">Profile</h1>
      <p class="text-secondary-custom mb-0">View and manage your account information</p>
    </div>

    <?php if ($notice): ?><div class="alert alert-success" role="status"><?= h((string) $notice['message']) ?></div><?php endif; ?>
    <?php if ($errorMessage !== ''): ?><div class="alert alert-danger" role="alert"><?= h($errorMessage) ?></div><?php endif; ?>

    <section class="rs-card mb-4 p-4 p-md-5" style="background: linear-gradient(135deg, #F3E8DD 0%, #EAD8C7 100%);">
      <div class="d-flex flex-column flex-sm-row align-items-center align-items-sm-center gap-4">
        <div class="rs-avatar avatar-lg" style="background-color: var(--rs-card-primary); color: var(--rs-dark-accent);"><?= h(profile_initials($formFullName)) ?></div>
        <div class="text-center text-sm-start">
          <h2 class="h3 fw-bold mb-1"><?= h($formFullName) ?></h2>
          <div class="d-flex flex-wrap justify-content-center justify-content-sm-start align-items-center gap-2">
            <span class="rs-badge rs-badge-active"><span class="rs-badge-dot"></span><?= h($role !== '' ? $role : 'No household') ?></span>
            <small class="text-secondary-custom">Member since <?= h(profile_date((string) $profile['created_at'])) ?></small>
          </div>
        </div>
      </div>
    </section>

    <div class="row g-4">
      <div class="col-lg-6">
        <section class="rs-card h-100">
          <div class="rs-card-header">
            <div><h2 class="rs-card-title">Personal Information</h2><div class="rs-card-subtitle">Update your personal details</div></div>
          </div>

          <div class="d-flex flex-column gap-3">
            <div>
              <label class="form-label">Full name</label>
              <div class="input-group">
                <input class="form-control" value="<?= h($formFullName) ?>" readonly>
                <button class="btn btn-rs-outline" type="button" data-bs-toggle="modal" data-bs-target="#editProfileModal" data-edit-field="profileFullName">Edit</button>
              </div>
            </div>
            <div>
              <label class="form-label">Username</label>
              <div class="input-group">
                <input class="form-control" value="<?= h($formUsername) ?>" readonly>
                <button class="btn btn-rs-outline" type="button" data-bs-toggle="modal" data-bs-target="#editProfileModal" data-edit-field="profileUsername">Edit</button>
              </div>
            </div>
            <div>
              <label class="form-label">Email address</label>
              <div class="input-group">
                <input class="form-control" value="<?= h($formEmail) ?>" placeholder="No email set" readonly>
                <button class="btn btn-rs-outline" type="button" data-bs-toggle="modal" data-bs-target="#editProfileModal" data-edit-field="profileEmail">Edit</button>
              </div>
            </div>
          </div>

          <hr class="my-4">
          <div class="d-flex justify-content-between align-items-center gap-3">
            <div><h3 class="h6 fw-bold mb-1">Account Security</h3><small class="text-secondary-custom">Keep your account secure</small></div>
            <button type="button" class="btn btn-rs-outline" data-bs-toggle="modal" data-bs-target="#changePasswordModal">Change Password</button>
          </div>
          <button type="button" class="btn btn-link text-danger text-decoration-none px-0 mt-4" data-bs-toggle="modal" data-bs-target="#deleteAccountModal">Delete my account</button>
        </section>
      </div>

      <div class="col-lg-6 d-flex flex-column gap-4">
        <section class="rs-card">
          <div class="rs-card-header mb-3">
            <div><h2 class="rs-card-title">Household Information</h2><div class="rs-card-subtitle">Details about your current household</div></div>
          </div>
          <?php if ($household): ?>
            <div class="d-flex flex-column flex-sm-row gap-3 align-items-start">
              <?php if ((string) $household['household_image'] !== ''): ?>
                <img src="<?= h((string) $household['household_image']) ?>" alt="" class="rounded-3" style="width: 176px; height: 112px; object-fit: cover;">
              <?php else: ?>
                <div class="rounded-3 bg-warm-secondary d-flex align-items-center justify-content-center" style="width: 176px; height: 112px;"><i class="bi bi-house fs-1 text-dark-accent"></i></div>
              <?php endif; ?>
              <div>
                <h3 class="h5 fw-bold mb-2"><?= h((string) $household['household_name']) ?></h3>
                <?php if ((string) $household['address'] !== ''): ?><p class="small text-secondary-custom mb-2"><?= h((string) $household['address']) ?></p><?php endif; ?>
                <p class="small text-secondary-custom mb-1">Joined <?= h(profile_date((string) $household['joined_at'])) ?></p>
                <p class="small text-secondary-custom mb-0"><?= count($members) ?> <?= count($members) === 1 ? 'member' : 'members' ?></p>
              </div>
            </div>
          <?php else: ?>
            <div class="p-4 text-center bg-warm-secondary rounded-3">
              <i class="bi bi-house-slash fs-3 text-dark-accent"></i>
              <p class="mb-2 mt-2 text-secondary-custom">You are not connected to a household.</p>
              <a href="households.php" class="btn btn-rs-outline btn-sm">Choose a Household</a>
            </div>
          <?php endif; ?>
        </section>

        <section class="rs-card flex-grow-1">
          <div class="rs-card-header mb-3">
            <div><h2 class="rs-card-title">Household Members</h2><div class="rs-card-subtitle">People in your current household</div></div>
          </div>
          <?php if ($members): ?>
            <div class="d-flex flex-column">
              <?php foreach ($members as $member): ?>
                <div class="d-flex align-items-center gap-2 py-2 border-bottom">
                  <div class="rs-avatar avatar-sm"><?= h(profile_initials((string) $member['full_name'])) ?></div>
                  <div class="flex-grow-1">
                    <div class="small fw-semibold"><?= h((string) $member['full_name']) ?></div>
                    <div class="text-secondary-custom" style="font-size: 0.72rem;"><?= h((string) $member['username']) ?><?= !empty($member['email']) ? ' · ' . h((string) $member['email']) : '' ?></div>
                  </div>
                  <span class="rs-badge rs-badge-active"><?= h((string) $member['role']) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="p-4 text-center bg-warm-secondary rounded-3"><i class="bi bi-people fs-3 text-dark-accent"></i><p class="mb-0 mt-2 text-secondary-custom">No household members to display.</p></div>
          <?php endif; ?>
        </section>
      </div>
    </div>
  </main>

  <div class="modal fade" id="editProfileModal" tabindex="-1" aria-labelledby="editProfileModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content rs-modal">
        <div class="modal-header rs-modal-header"><h2 class="modal-title h5" id="editProfileModalLabel">Edit Profile</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <form method="POST" action="profile.php">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="update_profile">
          <div class="modal-body rs-modal-body">
            <div class="mb-3"><label for="profileFullName" class="form-label">Full name</label><input type="text" class="form-control" id="profileFullName" name="full_name" value="<?= h($formFullName) ?>" maxlength="100" required></div>
            <div class="mb-3"><label for="profileUsername" class="form-label">Username</label><input type="text" class="form-control" id="profileUsername" name="username" value="<?= h($formUsername) ?>" pattern="[A-Za-z0-9_.-]{3,50}" maxlength="50" required></div>
            <div class="mb-0"><label for="profileEmail" class="form-label">Email address</label><input type="email" class="form-control" id="profileEmail" name="email" value="<?= h($formEmail) ?>" maxlength="100"><div class="form-text">Leave blank if you do not want to store an email.</div></div>
          </div>
          <div class="modal-footer rs-modal-footer"><button type="button" class="btn btn-rs-outline" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-rs-primary">Save Changes</button></div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="changePasswordModal" tabindex="-1" aria-labelledby="changePasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content rs-modal">
        <div class="modal-header rs-modal-header"><h2 class="modal-title h5" id="changePasswordModalLabel">Change Password</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <form method="POST" action="profile.php">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="change_password">
          <div class="modal-body rs-modal-body">
            <div class="mb-3"><label for="currentPassword" class="form-label">Current password</label><input type="password" class="form-control" id="currentPassword" name="current_password" autocomplete="current-password" required></div>
            <div class="mb-3"><label for="newPassword" class="form-label">New password</label><input type="password" class="form-control" id="newPassword" name="new_password" minlength="8" autocomplete="new-password" required></div>
            <div><label for="confirmPassword" class="form-label">Confirm new password</label><input type="password" class="form-control" id="confirmPassword" name="confirm_password" minlength="8" autocomplete="new-password" required></div>
          </div>
          <div class="modal-footer rs-modal-footer"><button type="button" class="btn btn-rs-outline" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-rs-primary">Change Password</button></div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="deleteAccountModal" tabindex="-1" aria-labelledby="deleteAccountModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content rs-modal">
        <div class="modal-header rs-modal-header"><h2 class="modal-title h5 text-danger" id="deleteAccountModalLabel">Delete Account</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <form method="POST" action="profile.php">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="delete_account">
          <div class="modal-body rs-modal-body">
            <p class="mb-3">This permanently deletes your account and removes your household memberships. Enter your current password to continue.</p>
            <label for="deletePassword" class="form-label">Current password</label>
            <input type="password" class="form-control" id="deletePassword" name="current_password" autocomplete="current-password" required>
          </div>
          <div class="modal-footer rs-modal-footer"><button type="button" class="btn btn-rs-outline" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger">Delete Account</button></div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/main.js"></script>
  <script>
    document.querySelectorAll('[data-edit-field]').forEach(function (button) {
      button.addEventListener('click', function () {
        window.setTimeout(function () {
          const field = document.getElementById(button.dataset.editField);
          if (field) field.focus();
        }, 200);
      });
    });
  </script>
</body>
</html>
