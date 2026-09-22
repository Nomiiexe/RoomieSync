<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($rootPath)) {
    $rootPath = './';
}

$currentUser = $_SESSION['user_name'] ?? $_SESSION['username'] ?? 'User';
$currentEmail = $_SESSION['user_email'] ?? $_SESSION['email'] ?? '';
$currentRole = $_SESSION['role'] ?? $_SESSION['user_role'] ?? (isset($_GET['role']) && $_GET['role'] === 'user' ? 'user' : 'admin');
$isAdmin = ($currentRole === 'admin');

if (!isset($pageTitle)) {
    $pageTitle = 'RoomieSync - Household Management';
}
if (!isset($activeNav)) {
    $activeNav = '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($pageTitle); ?> | RoomieSync</title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  
  <link rel="stylesheet" href="<?php echo $rootPath; ?>assets/css/style.css">
</head>
<body>
