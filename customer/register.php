<?php
require_once __DIR__ . '/../includes/auth.php';
auth_redirect_logged_in();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/activity_log.php';

$error = '';
$firstName = '';
$lastName = '';
$email = '';
$username = '';
$phone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim(auth_post_string('first_name'));
    $lastName = trim(auth_post_string('last_name'));
    $email = trim(auth_post_string('email'));
    $username = trim(auth_post_string('username'));
    $phone = trim(auth_post_string('phone'));
    $password = auth_post_string('password');
    $confirmPassword = auth_post_string('confirm_password');

    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please submit the form again.';
    } elseif (
        $firstName === '' || $lastName === '' || $email === '' || $username === ''
        || $phone === '' || $password === '' || $confirmPassword === ''
    ) {
        $error = 'Complete all fields to create your account.';
    } elseif (
        auth_string_length($firstName) > 100
        || auth_string_length($lastName) > 100
        || auth_string_length($email) > 150
        || auth_string_length($username) > 50
        || auth_string_length($phone) > 20
    ) {
        $error = 'One or more fields exceed the allowed length.';
    } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $error = 'Enter a valid email address.';
    } elseif ($password !== $confirmPassword) {
        $error = 'The password and confirmation do not match.';
    } elseif (strlen($password) < 8) {
        $error = 'Choose a password with at least 8 characters.';
    } elseif (strlen($password) > 72) {
        $error = 'Choose a password no longer than 72 bytes.';
    } else {
        try {
            $check = $conn->prepare('SELECT email, username FROM users WHERE email = ? OR username = ? LIMIT 1');
            $check->bind_param('ss', $email, $username);
            $check->execute();
            $existingUser = $check->get_result()->fetch_assoc();
            $check->close();

            if ($existingUser) {
                if (strcasecmp($existingUser['email'], $email) === 0) {
                    $error = 'That email address is already registered.';
                } elseif (strcasecmp($existingUser['username'], $username) === 0) {
                    $error = 'That username is already taken.';
                } else {
                    $error = 'That email address or username is already registered.';
                }
            } else {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $transactionStarted = false;
                $conn->begin_transaction();
                $transactionStarted = true;
                $insert = $conn->prepare(
                    "INSERT INTO users (first_name, last_name, email, username, password, phone, role)
                     VALUES (?, ?, ?, ?, ?, ?, 'customer')"
                );
                $insert->bind_param('ssssss', $firstName, $lastName, $email, $username, $passwordHash, $phone);
                $insert->execute();
                $userId = (int) $conn->insert_id;
                $insert->close();
                activity_log_record(
                    $conn,
                    $userId,
                    trim($firstName . ' ' . $lastName),
                    'customer',
                    'account.registered',
                    'Customer account registered.',
                    ['registration_method' => 'public']
                );
                $conn->commit();
                $transactionStarted = false;

                auth_set_flash('success', 'Your account is ready. Log in to continue.');
                header('Location: login.php', true, 303);
                exit;
            }
        } catch (mysqli_sql_exception $exception) {
            if (!empty($transactionStarted)) {
                $conn->rollback();
            }
            if ((int) $exception->getCode() === 1062) {
                $error = 'That email address or username is already registered.';
            } else {
                error_log('Pawsome registration database error: ' . $exception->getMessage());
                $error = 'We could not create your account right now. Please try again.';
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
    <meta name="description" content="Create a Pawsome account to plan your pet's grooming care.">
    <meta name="theme-color" content="#fbf8ff">
    <title>Register | Pawsome</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@500;600;700;800&display=swap" rel="stylesheet">
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
                <span>Already a member?</span>
                <a class="button button-small button-primary" href="login.php">Log in</a>
            </nav>
        </div>
    </header>

    <main class="auth-main auth-main-register" id="main-content">
        <section class="auth-card auth-card-register" aria-labelledby="auth-title">
            <span class="eyebrow">A fresh start for their care</span>
            <h1 id="auth-title">Join <span>Pawsome</span></h1>
            <p class="auth-intro">Create an account to make your pet's grooming days easier.</p>

            <?php if ($error !== ''): ?>
                <p class="auth-message auth-message-error" role="alert"><?= auth_escape($error) ?></p>
            <?php endif; ?>

            <form class="auth-form" method="post" action="register.php">
                <input type="hidden" name="csrf_token" value="<?= auth_escape(auth_csrf_token()) ?>">
                <div class="auth-form-grid">
                    <div class="auth-field">
                        <label for="first_name">First name</label>
                        <input id="first_name" name="first_name" type="text" autocomplete="given-name" maxlength="100" value="<?= auth_escape($firstName) ?>" required>
                    </div>
                    <div class="auth-field">
                        <label for="last_name">Last name</label>
                        <input id="last_name" name="last_name" type="text" autocomplete="family-name" maxlength="100" value="<?= auth_escape($lastName) ?>" required>
                    </div>
                    <div class="auth-field">
                        <label for="email">Email</label>
                        <input id="email" name="email" type="email" autocomplete="email" maxlength="150" value="<?= auth_escape($email) ?>" required>
                    </div>
                    <div class="auth-field">
                        <label for="username">Username</label>
                        <input id="username" name="username" type="text" autocomplete="username" maxlength="50" value="<?= auth_escape($username) ?>" required>
                    </div>
                    <div class="auth-field auth-field-full">
                        <label for="phone">Phone number</label>
                        <input id="phone" name="phone" type="tel" autocomplete="tel" maxlength="20" value="<?= auth_escape($phone) ?>" required>
                    </div>
                    <div class="auth-field">
                        <label for="password">Password <span class="field-hint">(8 characters minimum)</span></label>
                        <div class="password-control">
                            <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" maxlength="72" required>
                            <button class="password-toggle" type="button" aria-label="Show password" aria-pressed="false" data-password-toggle="password">Show</button>
                        </div>
                    </div>
                    <div class="auth-field">
                        <label for="confirm_password">Confirm password</label>
                        <div class="password-control">
                            <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="8" maxlength="72" required>
                            <button class="password-toggle" type="button" aria-label="Show password" aria-pressed="false" data-password-toggle="confirm_password">Show</button>
                        </div>
                    </div>
                </div>
                <button class="button button-primary auth-submit" type="submit">Create account <span aria-hidden="true">→</span></button>
            </form>

            <p class="auth-switch">Already have an account? <a href="login.php">Log in here</a></p>
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
