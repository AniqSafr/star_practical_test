<?php
declare(strict_types=1);

// Lets the logged in admin change their own password.
// Needs the current password (so a left-open browser cannot be used to take over the account), uses the same CSRF
// token and the same wrong-password limit as the login page, and stores only a hash of the new password.

require_once __DIR__ . '/../includes/auth.php';
require_login();

const DEFAULT_ADMIN_PASSWORD = 'Admin@12345';   // the password in database.sql: it may not be chosen again
const NEW_PASSWORD_MIN       = 10;
const NEW_PASSWORD_MAX       = 72;              // bcrypt only uses the first 72 bytes, so a longer password would be a lie

$error = '';
$done  = !empty($_SESSION['password_changed']);  // set by the redirect below
unset($_SESSION['password_changed']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $current = $_POST['current'] ?? null;
        $new     = $_POST['new'] ?? null;
        $confirm = $_POST['confirm'] ?? null;
        $user    = (string) ($_SESSION['admin_user'] ?? '');

        if (!csrf_check($_POST['csrf'] ?? null)) {
            $error = 'Your form expired. Please try again.';
        } elseif (($wait = login_locked_seconds()) > 0) {
            // same limit as the login page: too many wrong passwords, so do not even look at this one
            http_response_code(429);
            header('Retry-After: ' . $wait);
            $error = 'Too many failed attempts. Please try again in ' . (int) ceil($wait / 60) . ' minute(s).';
        } elseif (!is_string($current) || !is_string($new) || !is_string($confirm)) {
            $error = 'Invalid form data.';
        } elseif ($current === '' || $new === '' || $confirm === '') {
            $error = 'Please fill in every field.';
        } elseif (strlen($current) > 200) {
            $error = 'Your current password is not correct.';   // too long to be real (the login page has the same limit)
            login_record_failure();
        } elseif (mb_strlen($new) < NEW_PASSWORD_MIN) {
            $error = 'The new password must have at least ' . NEW_PASSWORD_MIN . ' characters.';
        } elseif (strlen($new) > NEW_PASSWORD_MAX) {
            $error = 'The new password can have at most ' . NEW_PASSWORD_MAX . ' characters.';
        } elseif ($new !== $confirm) {
            $error = 'The two new passwords do not match.';
        } elseif ($new === DEFAULT_ADMIN_PASSWORD || mb_strtolower($new) === mb_strtolower($user)) {
            $error = 'Please choose a password that is not the default one and not your username.';
        } else {
            $stmt = db()->prepare('SELECT password_hash FROM admins WHERE id = ?');
            $stmt->execute([(int) $_SESSION['admin_id']]);
            $hash = $stmt->fetchColumn();

            if ($hash === false || !password_verify($current, (string) $hash)) {
                login_record_failure();
                $error = 'Your current password is not correct.';
            } elseif ($new === $current) {
                $error = 'The new password must be different from the current one.';
            } else {
                $upd = db()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?');
                $upd->execute([password_hash($new, PASSWORD_DEFAULT), (int) $_SESSION['admin_id']]);

                login_clear_failures();
                session_regenerate_id(true);               // a new session ID after a change of credentials
                $_SESSION['password_changed'] = true;
                header('Location: change_password.php');   // reload as a normal page, so a refresh does not send the form again
                exit;
            }
        }
    } catch (PDOException $ex) {
        error_log('password change failed: ' . $ex->getMessage());
        http_response_code(503);
        $error = 'The password could not be changed right now. Please try again in a moment.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Change password | Admin</title>
    <link rel="stylesheet" href="../public/css/style.css">
</head>
<body>
<div class="login-box">
    <h1>Change password</h1>

    <?php if ($done): ?>
        <div class="success">Your password was changed.</div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <label for="current">Current password</label>
        <input id="current" name="current" type="password" required maxlength="200" autocomplete="current-password" autofocus>

        <label for="new">New password</label>
        <input id="new" name="new" type="password" required minlength="<?= NEW_PASSWORD_MIN ?>" maxlength="<?= NEW_PASSWORD_MAX ?>" autocomplete="new-password">
        <p class="muted" style="margin:4px 0 0;font-size:.85rem">At least <?= NEW_PASSWORD_MIN ?> characters. A few random words make a good password.</p>

        <label for="confirm">Repeat the new password</label>
        <input id="confirm" name="confirm" type="password" required maxlength="<?= NEW_PASSWORD_MAX ?>" autocomplete="new-password">

        <button class="btn" type="submit">Change password</button>
    </form>

    <p><a href="dashboard.php">&larr; Back to the dashboard</a></p>
</div>
</body>
</html>
