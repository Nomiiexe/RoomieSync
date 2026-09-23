<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$errorMessage = '';
$username = '';
$notice = consume_flash_message();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim(is_string($_POST['username'] ?? null) ? $_POST['username'] : '');
    $recoveryCode = strtoupper(trim(is_string($_POST['recovery_code'] ?? null) ? $_POST['recovery_code'] : ''));
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirmPassword = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'This reset form expired. Please try again.';
    } elseif ($username === '' || $recoveryCode === '') {
        $errorMessage = 'Enter your username and recovery code.';
    } elseif (strlen($password) < 8) {
        $errorMessage = 'Use a new password with at least 8 characters.';
    } elseif ($password !== $confirmPassword) {
        $errorMessage = 'The new passwords do not match.';
    } else {
        $query = database_connection()->prepare(
            'SELECT user_id, recovery_code_hash FROM userTb WHERE username = ? LIMIT 1'
        );
        $query->bind_param('s', $username);
        $query->execute();
        $user = $query->get_result()->fetch_assoc();

        if (
            !$user
            || empty($user['recovery_code_hash'])
            || !password_verify($recoveryCode, (string) $user['recovery_code_hash'])
        ) {
            $errorMessage = 'The username or recovery code is incorrect, or the code has already been used.';
        } else {
            $userId = (int) $user['user_id'];
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $update = database_connection()->prepare(
                'UPDATE userTb SET password_hash = ?, recovery_code_hash = NULL WHERE user_id = ?'
            );
            $update->bind_param('si', $passwordHash, $userId);
            $update->execute();

            $deleteTokens = database_connection()->prepare('DELETE FROM authTokensTb WHERE user_id = ?');
            $deleteTokens->bind_param('i', $userId);
            $deleteTokens->execute();

            $_SESSION['flash_message'] = ['message' => 'Your password has been reset. Sign in with your new password.'];
            redirect_to('login.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password | RoomieSync</title>
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
        <div class="rs-brand-icon mx-auto mb-3" style="width: 56px; height: 56px; font-size: 1.65rem;"><i class="bi bi-key-fill"></i></div>
        <h1 class="h3 fw-bold mb-1">Reset Password</h1>
        <p class="text-secondary-custom small mb-0">Use your username and saved recovery code.</p>
      </div>

      <?php if ($notice): ?>
        <div class="alert alert-success" role="status"><?= h((string) $notice['message']) ?></div>
      <?php endif; ?>
      <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= h($errorMessage) ?></div>
      <?php endif; ?>

      <form action="reset-password.php" method="POST">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <div class="mb-3">
          <label for="resetUsername" class="form-label">Username</label>
          <input type="text" class="form-control" id="resetUsername" name="username" value="<?= h($username) ?>" autocomplete="username" maxlength="50" required>
        </div>
        <div class="mb-3">
          <label for="recoveryCode" class="form-label">Recovery Code</label>
          <input type="text" class="form-control font-monospace" id="recoveryCode" name="recovery_code" autocomplete="off" maxlength="16" required>
        </div>
        <div class="mb-3">
          <label for="resetPassword" class="form-label">New Password</label>
          <div class="input-group">
            <input type="password" class="form-control" id="resetPassword" name="password" autocomplete="new-password" minlength="8" required>
            <button class="btn btn-rs-outline toggle-password-btn px-2" type="button" data-target="resetPassword" aria-label="Show password"><i class="bi bi-eye"></i></button>
          </div>
        </div>
        <div class="mb-4">
          <label for="confirmResetPassword" class="form-label">Confirm New Password</label>
          <div class="input-group">
            <input type="password" class="form-control" id="confirmResetPassword" name="confirm_password" autocomplete="new-password" minlength="8" required>
            <button class="btn btn-rs-outline toggle-password-btn px-2" type="button" data-target="confirmResetPassword" aria-label="Show password"><i class="bi bi-eye"></i></button>
          </div>
        </div>
        <button type="submit" class="btn btn-rs-primary w-100 py-2 justify-content-center mb-3">Save New Password</button>
      </form>
      <div class="text-center pt-3 border-top">
        <a href="login.php" class="fw-bold text-dark-accent text-decoration-none">Back to Sign In</a>
      </div>
    </section>
  </main>
  <script src="assets/js/main.js"></script>
</body>
</html>
