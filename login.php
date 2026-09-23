<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if (restore_remembered_login()) {
    redirect_to('households.php');
}

$errorMessage = '';
$username = '';
$rememberMe = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim(is_string($_POST['username'] ?? null) ? $_POST['username'] : '');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $rememberMe = isset($_POST['remember_me']) && $_POST['remember_me'] === '1';

    if (!valid_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'This sign-in form expired. Please try again.';
    } elseif ($username === '' || $password === '') {
        $errorMessage = 'Enter your username and password.';
    } else {
        $query = database_connection()->prepare(
            'SELECT user_id, full_name, username, password_hash FROM userTb WHERE username = ? LIMIT 1'
        );
        $query->bind_param('s', $username);
        $query->execute();
        $user = $query->get_result()->fetch_assoc();

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            $errorMessage = 'The username or password is incorrect.';
        } else {
            set_signed_in_user($user);
            if ($rememberMe) {
                start_remembered_login($user);
            } elseif (($parts = remember_cookie_parts()) !== null) {
                $delete = database_connection()->prepare('DELETE FROM authTokensTb WHERE selector = ?');
                $delete->bind_param('s', $parts[0]);
                $delete->execute();
                expire_remember_cookie();
            }
            redirect_to('households.php');
        }
    }
}

$notice = isset($_GET['deleted']) && $_GET['deleted'] === '1'
    ? ['message' => 'Your account was deleted successfully.']
    : consume_flash_message();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign In | RoomieSync</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="d-flex flex-column min-vh-100">
  <main class="container d-flex flex-column align-items-center justify-content-center flex-grow-1 py-5">
    <section class="rs-card p-4 p-md-5" style="max-width: 440px; width: 100%;">
      <div class="text-center mb-4">
        <div class="rs-brand-icon mx-auto mb-3" style="width: 56px; height: 56px; font-size: 1.65rem;">
          <i class="bi bi-house-heart-fill"></i>
        </div>
        <h1 class="h3 fw-bold mb-1">Welcome Back</h1>
        <p class="text-secondary-custom small mb-0">Sign in to manage your household with RoomieSync</p>
      </div>

      <?php if ($notice): ?>
        <div class="alert alert-success" role="status"><?= h((string) $notice['message']) ?></div>
      <?php endif; ?>
      <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= h($errorMessage) ?></div>
      <?php endif; ?>

      <form action="login.php" method="POST" id="loginForm">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <div class="mb-3">
          <label for="loginUsername" class="form-label">Username</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-person"></i></span>
            <input type="text" class="form-control" id="loginUsername" name="username" value="<?= h($username) ?>" autocomplete="username" maxlength="50" required>
          </div>
        </div>

        <div class="mb-3">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <label for="loginPassword" class="form-label mb-0">Password</label>
            <a href="reset-password.php" class="small text-secondary-custom text-decoration-none">Reset Password</a>
          </div>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-key"></i></span>
            <input type="password" class="form-control" id="loginPassword" name="password" autocomplete="current-password" required>
            <button class="btn btn-rs-outline toggle-password-btn" type="button" data-target="loginPassword" title="Show or hide password" aria-label="Show password">
              <i class="bi bi-eye"></i>
            </button>
          </div>
        </div>

        <div class="form-check mb-4">
          <input class="form-check-input" type="checkbox" id="rememberMe" name="remember_me" value="1" <?= $rememberMe ? 'checked' : '' ?>>
          <label class="form-check-label small text-secondary-custom" for="rememberMe">Remember Me for 30 days</label>
        </div>

        <button type="submit" class="btn btn-rs-primary w-100 py-2 justify-content-center mb-3">
          <i class="bi bi-box-arrow-in-right"></i> Sign In
        </button>
      </form>

      <div class="text-center pt-3 border-top">
        <p class="small text-secondary-custom mb-0">
          Don't have an account? <a href="register.php" class="fw-bold text-dark-accent text-decoration-none">Create Account</a>
        </p>
      </div>
    </section>
  </main>
  <script src="assets/js/main.js"></script>
</body>
</html>
