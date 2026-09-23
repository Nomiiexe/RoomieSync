<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$errorMessage = '';
$fullName = '';
$username = '';
$email = '';
$registrationComplete = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim(is_string($_POST['full_name'] ?? null) ? $_POST['full_name'] : '');
    $username = trim(is_string($_POST['username'] ?? null) ? $_POST['username'] : '');
    $email = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirmPassword = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'This registration form expired. Please try again.';
    } elseif ($fullName === '' || strlen($fullName) > 100) {
        $errorMessage = 'Enter your name (up to 100 characters).';
    } elseif (!preg_match('/\A[A-Za-z0-9_.-]{3,50}\z/', $username)) {
        $errorMessage = 'Choose a username with 3-50 letters, numbers, dots, underscores, or hyphens.';
    } elseif ($email === '' || strlen($email) > 100 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errorMessage = 'Enter a valid email address (up to 100 characters).';
    } elseif (strlen($password) < 8) {
        $errorMessage = 'Use a password with at least 8 characters.';
    } elseif ($password !== $confirmPassword) {
        $errorMessage = 'The passwords do not match.';
    } else {
        $connection = database_connection();
        $exists = $connection->prepare('SELECT username, email FROM userTb WHERE username = ? OR email = ? LIMIT 1');
        $exists->bind_param('ss', $username, $email);
        $exists->execute();
        $existingUser = $exists->get_result()->fetch_assoc();

        if ($existingUser) {
            if (strcasecmp((string) $existingUser['username'], $username) === 0) {
                $errorMessage = 'That username is already in use. Choose another one.';
            } else {
                $errorMessage = 'That email address is already in use. Choose another one.';
            }
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $recoveryCode = strtoupper(bin2hex(random_bytes(8)));
            $recoveryCodeHash = password_hash($recoveryCode, PASSWORD_DEFAULT);
            $insert = $connection->prepare(
                'INSERT INTO userTb (full_name, username, email, password_hash, recovery_code_hash) VALUES (?, ?, ?, ?, ?)'
            );
            $insert->bind_param('sssss', $fullName, $username, $email, $passwordHash, $recoveryCodeHash);
            $insert->execute();

            $_SESSION['registration_complete'] = [
                'full_name' => $fullName,
                'username' => $username,
                'recovery_code' => $recoveryCode,
            ];
            redirect_to('register.php?created=1');
        }
    }
} elseif (isset($_GET['created'])) {
    $registrationComplete = $_SESSION['registration_complete'] ?? null;
    unset($_SESSION['registration_complete']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Create Account | RoomieSync</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="d-flex flex-column min-vh-100">
  <main class="container d-flex flex-column align-items-center justify-content-center flex-grow-1 py-5">
    <section class="rs-card p-4 p-md-5" style="max-width: 480px; width: 100%;">
      <div class="text-center mb-4">
        <a href="login.php" class="rs-brand-icon mx-auto mb-3 text-decoration-none" style="width: 56px; height: 56px; font-size: 1.65rem;">
          <i class="bi bi-house-heart-fill"></i>
        </a>
        <h1 class="h3 fw-bold mb-1"><?= $registrationComplete ? 'Account Created' : 'Create an Account' ?></h1>
        <p class="text-secondary-custom small mb-0">Join your housemates and synchronize daily living</p>
      </div>

      <?php if ($registrationComplete): ?>
        <div class="alert alert-success" role="status">
          Your account has been created, <?= h((string) $registrationComplete['full_name']) ?>.
        </div>
        <div class="alert alert-warning" role="alert">
          <strong>Save this recovery code now.</strong> It is shown only once and is required to reset your password.
        </div>
        <label for="recoveryCode" class="form-label">Your one-time recovery code</label>
        <div class="input-group mb-3">
          <input class="form-control font-monospace text-center fw-bold" id="recoveryCode" value="<?= h((string) $registrationComplete['recovery_code']) ?>" readonly>
          <button class="btn btn-rs-outline" type="button" id="copyRecoveryCode" data-code="<?= h((string) $registrationComplete['recovery_code']) ?>">Copy</button>
        </div>
        <p class="small text-secondary-custom">Keep it somewhere private. Anyone with your username and this code can reset your password.</p>
        <a href="login.php" class="btn btn-rs-primary w-100 py-2 justify-content-center">Continue to Sign In</a>
      <?php else: ?>
        <?php if ($errorMessage !== ''): ?>
          <div class="alert alert-danger" role="alert"><?= h($errorMessage) ?></div>
        <?php endif; ?>

        <form action="register.php" method="POST" id="registerForm">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <div class="mb-3">
            <label for="regFullName" class="form-label">Full Name</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-card-text"></i></span>
              <input type="text" class="form-control" id="regFullName" name="full_name" value="<?= h($fullName) ?>" autocomplete="name" maxlength="100" required>
            </div>
          </div>

          <div class="mb-3">
            <label for="regUsername" class="form-label">Username</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-person"></i></span>
              <input type="text" class="form-control" id="regUsername" name="username" value="<?= h($username) ?>" autocomplete="username" minlength="3" maxlength="50" pattern="[A-Za-z0-9_.-]{3,50}" required>
            </div>
            <div class="form-text">Use 3-50 letters, numbers, dots, underscores, or hyphens.</div>
          </div>

          <div class="mb-3">
            <label for="regEmail" class="form-label">Email Address</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-envelope"></i></span>
              <input type="email" class="form-control" id="regEmail" name="email" value="<?= h($email) ?>" autocomplete="email" maxlength="100" required>
            </div>
            <div class="form-text">Stored on your profile. It is not used for password recovery.</div>
          </div>

          <div class="row g-2 mb-4">
            <div class="col-sm-6">
              <label for="regPassword" class="form-label">Password</label>
              <div class="input-group">
                <input type="password" class="form-control" id="regPassword" name="password" autocomplete="new-password" minlength="8" required>
                <button class="btn btn-rs-outline toggle-password-btn px-2" type="button" data-target="regPassword" aria-label="Show password"><i class="bi bi-eye"></i></button>
              </div>
            </div>
            <div class="col-sm-6">
              <label for="regConfirmPassword" class="form-label">Confirm Password</label>
              <div class="input-group">
                <input type="password" class="form-control" id="regConfirmPassword" name="confirm_password" autocomplete="new-password" minlength="8" required>
                <button class="btn btn-rs-outline toggle-password-btn px-2" type="button" data-target="regConfirmPassword" aria-label="Show password"><i class="bi bi-eye"></i></button>
              </div>
            </div>
          </div>

          <button type="submit" class="btn btn-rs-primary w-100 py-2 justify-content-center mb-3">
            <i class="bi bi-person-check"></i> Create Account
          </button>
        </form>

        <div class="text-center pt-3 border-top">
          <p class="small text-secondary-custom mb-0">
            Already have an account? <a href="login.php" class="fw-bold text-dark-accent text-decoration-none">Sign In</a>
          </p>
        </div>
      <?php endif; ?>
    </section>
  </main>
  <script src="assets/js/main.js"></script>
  <?php if ($registrationComplete): ?>
  <script>
    document.getElementById('copyRecoveryCode').addEventListener('click', async function () {
      const code = this.dataset.code;
      try {
        await navigator.clipboard.writeText(code);
        this.textContent = 'Copied';
      } catch (error) {
        window.prompt('Copy and save this recovery code:', code);
      }
    });
  </script>
  <?php endif; ?>
</body>
</html>
