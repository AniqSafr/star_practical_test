<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Your form expired. Please try again.';
    } else {
        $username = $_POST['username'] ?? null;
        $password = $_POST['password'] ?? null;

        // Validate the input before touching the database
        if (!is_string($username) || !is_string($password)) {
            $error = 'Invalid form data.';
        } elseif (($username = trim($username)) === '' || $password === '') {
            $error = 'Please enter your username and password.';
        } elseif (mb_strlen($username) > 50 || strlen($password) > 200) {
            $error = 'Invalid username or password.';   // too long to be real; same message on purpose
        } elseif (!preg_match('/^[A-Za-z0-9_.@-]+$/', $username)) {
            $error = 'Invalid username or password.';   // letters, numbers and _ . @ - only
        } else {
            $stmt = db()->prepare('SELECT id, password_hash FROM admins WHERE username = ?');
            $stmt->execute([$username]);
            $admin = $stmt->fetch();

            if ($admin && password_verify($password, $admin['password_hash'])) {
                session_regenerate_id(true);   // prevents session fixation
                $_SESSION['admin_id']   = (int) $admin['id'];
                $_SESSION['admin_user'] = $username;
                header('Location: dashboard.php');
                exit;
            }

            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin login</title>
    <link rel="stylesheet" href="../public/css/style.css">
</head>
<body>
<div class="login-box">
    <h1>Admin login</h1>

    <?php if ($error !== ''): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <label for="username">Username</label>
        <input id="username" name="username" type="text" required autofocus maxlength="50" pattern="[A-Za-z0-9_.@\-]+" title="Letters, numbers and _ . @ - only">

        <label for="password">Password</label>
        <input id="password" name="password" type="password" required maxlength="200">

        <button class="btn" type="submit">Log in</button>
    </form>
</div>
</body>
</html>
