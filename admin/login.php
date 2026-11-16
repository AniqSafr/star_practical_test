<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

// checked on every request, so the form is disabled on page load too
$wait = 0;
try {
    $wait = login_locked_seconds();
} catch (PDOException $ex) {
    error_log('login throttle check failed: ' . $ex->getMessage()); 
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!csrf_check($_POST['csrf'] ?? null)) {
            $error = 'Your form expired. Please try again.';
        } elseif ($wait > 0) {
            // too many failures: do not even look at the password
            http_response_code(429);
            header('Retry-After: ' . $wait);
            $error = 'Too many failed login attempts. Please try again in ' . (int) ceil($wait / 60) . ' minute(s).';
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
                login_record_failure();
            } elseif (!preg_match('/^[A-Za-z0-9_.@-]+$/', $username)) {
                $error = 'Invalid username or password.';   // letters, numbers and _ . @ - only
                login_record_failure();
            } else {
                $stmt = db()->prepare('SELECT id, password_hash FROM admins WHERE username = ?');
                $stmt->execute([$username]);
                $admin = $stmt->fetch();

                if ($admin && password_verify($password, $admin['password_hash'])) {
                    login_clear_failures();
                    session_regenerate_id(true);   // prevents session fixation
                    $_SESSION['admin_id']   = (int) $admin['id'];
                    $_SESSION['admin_user'] = $username;
                    header('Location: dashboard.php');
                    exit;
                }

                login_record_failure();
                $error = 'Invalid username or password.';
            }
        }
    }
} catch (PDOException $ex) {
    // the database is down or a query failed: say so, never show the PHP error
    error_log('login failed: ' . $ex->getMessage());
    http_response_code(503);
    $error = 'Login is temporarily unavailable. Please try again in a moment.';
}

// Re-check after this request: the failure above may have been the last allowed attempt.
if ($wait === 0 && $error !== '' && $error !== 'Your form expired. Please try again.' && http_response_code() !== 503) {
    try {
        $wait = login_locked_seconds();
    } catch (PDOException $ex) {
        $wait = 0;
    }
    if ($wait > 0) {
        http_response_code(429);
        header('Retry-After: ' . $wait);
    }
}

if ($wait > 0) {
    $error = 'Too many failed login attempts. Please try again in ' . (int) ceil($wait / 60) . ' minute(s).';
}
$locked = $wait > 0;
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

    <form method="post" autocomplete="off" id="login-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <label for="username">Username</label>
        <input id="username" name="username" type="text" required<?= $locked ? ' disabled' : ' autofocus' ?> maxlength="50" pattern="[A-Za-z0-9_.@\-]+" title="Letters, numbers and _ . @ - only">

        <label for="password">Password</label>
        <input id="password" name="password" type="password" required maxlength="200"<?= $locked ? ' disabled' : '' ?>>

        <button class="btn" type="submit" id="login-btn"<?= $locked ? ' disabled' : '' ?>>Log in</button>
    </form>
</div>
<?php if ($locked): ?>
<script>
// Counts down until the lock expires, recalculating based on the actual time whenever the tab becomes visible to avoid timer delays; the server still validates every request.
(function () {
  var end = Date.now() + <?= (int) $wait ?> * 1000;   // the moment the lock ends
  var btn = document.getElementById('login-btn');
  var inputs = document.querySelectorAll('#login-form input[type=text], #login-form input[type=password]');
  var note = document.querySelector('.error');
  var timer = null;

  function unlock() {
    clearInterval(timer);
    btn.disabled = false;
    btn.textContent = 'Log in';
    inputs.forEach(function (i) { i.disabled = false; });
    if (note) { note.hidden = true; }   // the "too many attempts" message is out of date now
  }

  function tick() {
    var ms = end - Date.now();
    if (ms <= -1000) {      // one extra second, so the form never opens before the server does
      unlock();
      return;
    }
    var left = Math.max(0, Math.ceil(ms / 1000));
    var m = Math.floor(left / 60), s = left % 60;
    btn.textContent = 'Locked: try again in ' + m + ':' + (s < 10 ? '0' : '') + s;
  }

  tick();
  timer = setInterval(tick, 1000);
  document.addEventListener('visibilitychange', tick);   // back to this tab
  window.addEventListener('focus', tick);
  window.addEventListener('pageshow', tick);             // page restored with the browser's back / forward button
})();
</script>
<?php endif; ?>
</body>
</html>