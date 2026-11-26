<?php
require_once __DIR__ . '/../includes/auth.php';
auth_redirect_logged_in();

require_once __DIR__ . '/../config/database.php';

$error = '';
$identity = '';
$success = auth_take_flash('success');

if (isset($_GET['logged_out']) && $_GET['logged_out'] === '1') {
    $success = 'You have been logged out successfully.';
} elseif (isset($_GET['session_expired']) && $_GET['session_expired'] === '1') {
    $error = 'Your session ended because account access changed. Please sign in again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identity = trim(auth_post_string('identity'));
    $password = auth_post_string('password');

    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($identity === '' || $password === '') {
        $error = 'Enter your username or email and password.';
    } else {
        try {
            $statement = $conn->prepare(
                'SELECT user_id, first_name, last_name, username, password, role, account_status, auth_version
                 FROM users
                 WHERE username = ? OR email = ?
                 LIMIT 1'
            );
            $statement->bind_param('ss', $identity, $identity);
            $statement->execute();
            $user = $statement->get_result()->fetch_assoc();
            $statement->close();

            if ($user && password_verify($password, $user['password'])) {
                if ($user['account_status'] !== 'Active') {
                    $error = 'The username/email or password you entered is incorrect.';
                } elseif (!in_array($user['role'], ['admin', 'customer'], true)) {
                    $error = 'The username/email or password you entered is incorrect.';
                } else {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int) $user['user_id'];
                    $_SESSION['first_name'] = $user['first_name'];
                    $_SESSION['last_name'] = $user['last_name'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['auth_version'] = (int) $user['auth_version'];

                    $destination = $user['role'] === 'admin'
                        ? '/admin/dashboard.php'
                        : '/customer/dashboard.php';
                    header('Location: ' . PAWSOME_BASE_PATH . $destination, true, 303);
                    exit;
                }
            }

            if ($error === '') {
                $error = 'The username/email or password you entered is incorrect.';
            }
        } catch (mysqli_sql_exception $exception) {
            error_log('Pawsome login database error: ' . $exception->getMessage());
            $error = 'We could not sign you in right now. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Log in to your Pawsome pet grooming account.">
    <meta name="theme-color" content="#fbf8ff">
    <title>Log In | Pawsome</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Nunito:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/auth.css">
</head>
<body class="auth-page">
    <a class="skip-link" href="#main-content">Skip to content</a>
    <header class="site-header">
        <div class="container header-inner auth-header-inner">
            <a class="brand" href="../index.php" aria-label="Pawsome home">
                <span class="brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 40 40" focusable="false">
                        <path d="M20 31.3c-1.6 0-3.1-1.1-5.4-1.1-2.1 0-3.6 1-5.3.2-2.4-1.2-2.1-4.7.6-7.5 2.4-2.5 6-4.4 10.1-4.4s7.7 1.9 10.1 4.4c2.7 2.8 3 6.3.6 7.5-1.7.8-3.2-.2-5.3-.2-2.3 0-3.8 1.1-5.4 1.1Z" fill="currentColor"/>
                        <ellipse cx="9.8" cy="15.3" rx="3.3" ry="4.3" transform="rotate(-27 9.8 15.3)" fill="currentColor"/>
                        <ellipse cx="17.1" cy="10.6" rx="3.2" ry="4.2" transform="rotate(-10 17.1 10.6)" fill="currentColor"/>
                        <ellipse cx="24.9" cy="10.6" rx="3.2" ry="4.2" transform="rotate(10 24.9 10.6)" fill="currentColor"/>
                        <ellipse cx="32.2" cy="15.3" rx="3.3" ry="4.3" transform="rotate(27 32.2 15.3)" fill="currentColor"/>
                    </svg>
                </span>
                <span class="brand-name">Paw<span>some</span></span>
            </a>
            <nav class="auth-nav" aria-label="Account navigation">
                <span>New to Pawsome?</span>
                <a class="button button-small button-primary" href="register.php">Create an account</a>
            </nav>
        </div>
    </header>

    <main class="auth-main" id="main-content">
        <section class="auth-card" aria-labelledby="auth-title">
            <span class="eyebrow">Good to see you again</span>
            <h1 id="auth-title">Welcome <span>back</span></h1>
            <p class="auth-intro">Log in to keep your pet's care plans close at paw.</p>

            <?php if ($success !== null): ?>
                <p class="auth-message auth-message-success" role="status"><?= auth_escape($success) ?></p>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <p class="auth-message auth-message-error" role="alert"><?= auth_escape($error) ?></p>
            <?php endif; ?>

            <form class="auth-form" method="post" action="login.php">
                <input type="hidden" name="csrf_token" value="<?= auth_escape(auth_csrf_token()) ?>">
                <div class="auth-field">
                    <label for="identity">Username or email</label>
                    <input id="identity" name="identity" type="text" autocomplete="username" maxlength="150" value="<?= auth_escape($identity) ?>" required>
                </div>
                <div class="auth-field">
                    <label for="password">Password</label>
                    <div class="password-control">
                        <input id="password" name="password" type="password" autocomplete="current-password" required>
                        <button class="password-toggle" type="button" aria-label="Show password" aria-pressed="false" data-password-toggle="password">Show</button>
                    </div>
                </div>
                <button class="button button-primary auth-submit" type="submit">Log In <span aria-hidden="true">→</span></button>
            </form>

            <p class="auth-switch">Don't have an account? <a href="register.php">Register here</a></p>
            <a class="auth-home-link" href="../index.php">← Back to Pawsome home</a>
        </section>
    </main>

    <footer class="auth-footer">
        <div class="container">
            <p>&copy; <?= date('Y') ?> Pawsome. Made with care for pets and their people.</p>
        </div>
    </footer>
    <script>
        document.querySelectorAll('[data-password-toggle]').forEach((toggle) => {
            toggle.addEventListener('click', () => {
                const input = document.getElementById(toggle.dataset.passwordToggle);
                const showing = input.type === 'password';
                input.type = showing ? 'text' : 'password';
                toggle.textContent = showing ? 'Hide' : 'Show';
                toggle.setAttribute('aria-label', showing ? 'Hide password' : 'Show password');
                toggle.setAttribute('aria-pressed', String(showing));
            });
        });
    </script>
</body>
</html>
