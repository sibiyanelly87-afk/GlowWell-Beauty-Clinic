
<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

$errors = [];

$first_name = '';
$last_name = '';
$email = '';
$phone = '';

// Process registration.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');

    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    // Verify CSRF protection.
    if (!verify_csrf_token($token)) {
        $errors[] = 'Invalid form submission. Please refresh and try again.';
    }

    // Validate first name.
    if ($first_name === '' || strlen($first_name) > 50) {
        $errors[] = 'First name is required and must not exceed 50 characters.';
    }

    // Validate last name.
    if ($last_name === '' || strlen($last_name) > 50) {
        $errors[] = 'Last name is required and must not exceed 50 characters.';
    }

    // Validate email.
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
        $errors[] = 'Please enter a valid email address.';
    }

    // Validate phone number.
    if (!preg_match('/^[0-9+\s()\-]{7,20}$/', $phone)) {
        $errors[] = 'Please enter a valid phone number.';
    }

    // Validate password.
    if (strlen($password) < 8) {
        $errors[] = 'Your password must contain at least 8 characters.';
    }

    // Confirm that passwords match.
    if ($password !== $confirm_password) {
        $errors[] = 'The passwords do not match.';
    }

    // Check for duplicate email addresses.
    if (empty($errors)) {

        try {

            $check = $pdo->prepare(
                "SELECT user_id FROM users WHERE email = :email"
            );

            $check->execute(['email' => $email]);

            if ($check->fetch()) {

                $errors[] = 'An account with this email address already exists.';

            } else {

                // Securely hash the password.
                $passwordHash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                // Insert the customer into the database.
                $sql = "INSERT INTO users
                        (
                            first_name,
                            last_name,
                            email,
                            phone,
                            password_hash,
                            role
                        )
                        VALUES
                        (
                            :first_name,
                            :last_name,
                            :email,
                            :phone,
                            :password_hash,
                            'customer'
                        )";

                $stmt = $pdo->prepare($sql);

                $stmt->execute([
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'email' => $email,
                    'phone' => $phone,
                    'password_hash' => $passwordHash
                ]);

                set_flash(
                    'success',
                    'Your account has been created successfully. Please log in.'
                );

                header('Location: login.php');
                exit;
            }

        } catch (PDOException $e) {

            error_log('Registration error: ' . $e->getMessage());

            if ($e->getCode() === '23000') {
                $errors[] = 'An account with this email address already exists.';
            } else {
                $errors[] = 'We could not create your account. Please try again.';
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

    <title>Create Account | GlowWell Beauty Clinic</title>

    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        /* Registration page luxury design */

        body.auth-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 35px 20px;
            background:
                radial-gradient(circle at 10% 10%, #f0dcdf, transparent 35%),
                linear-gradient(135deg, #fbf8f4, #f3e8e8);
        }

        .auth-layout {
            width: min(100%, 1050px);
            display: grid;
            grid-template-columns: 0.85fr 1.15fr;
            overflow: hidden;
            border-radius: 25px;
            background: #ffffff;
            box-shadow: 0 25px 75px rgba(72, 46, 61, 0.16);
            border: 1px solid #eadde0;
        }

        .auth-intro {
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 45px;
            color: #fffaf7;
            background: linear-gradient(145deg, #382633, #684354, #815b69);
        }

        .auth-intro::before {
            content: "";
            position: absolute;
            width: 280px;
            height: 280px;
            right: -130px;
            top: -90px;
            border: 1px solid rgba(233, 200, 158, 0.5);
            border-radius: 50%;
            box-shadow:
                0 0 0 30px rgba(233, 200, 158, 0.05),
                0 0 0 65px rgba(233, 200, 158, 0.04);
        }

        .auth-brand {
            position: relative;
            z-index: 1;
            margin-bottom: 55px;
            color: #fffaf7;
            font-family: Georgia, serif;
            font-size: 2rem;
            letter-spacing: -1px;
        }

        .auth-brand span {
            color: #e9c89e;
        }

        .auth-eyebrow {
            color: #e9c89e;
            font-size: 0.77rem;
            font-weight: 700;
            letter-spacing: 3px;
        }

        .auth-intro h1 {
            color: #fffaf7;
            font-family: Georgia, serif;
            font-size: clamp(2.2rem, 4vw, 3.3rem);
            font-weight: 500;
            line-height: 1.2;
            margin: 12px 0 18px;
        }

        .auth-intro > p:not(.auth-eyebrow) {
            color: rgba(255, 250, 247, 0.85);
            font-size: 0.97rem;
            line-height: 1.8;
        }

        .auth-decoration {
            margin-top: 40px;
            padding-top: 22px;
            border-top: 1px solid rgba(233, 200, 158, 0.3);
            color: #edd8bf;
            font-family: Georgia, serif;
            font-size: 1.1rem;
            font-style: italic;
        }

        .auth-form-panel {
            padding: 42px;
            background: #ffffff;
        }

        .auth-form-panel h2 {
            margin: 0 0 5px;
            color: #482e3d;
            font-family: Georgia, serif;
            font-size: 1.9rem;
        }

        .auth-subtitle {
            margin: 0 0 25px;
            color: #82777d;
            font-size: 0.9rem;
        }

        .auth-form {
            width: 100%;
            max-width: none;
            margin: 0;
            padding: 0;
            background: transparent;
            border: none;
            border-radius: 0;
            box-shadow: none;
        }

        .auth-field {
            margin-bottom: 15px;
        }

        .auth-field label {
            display: block;
            margin-bottom: 6px;
            color: #482e3d;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .auth-field input {
            display: block;
            width: 100%;
            margin: 0;
            padding: 12px 14px;
            border: 1px solid #e9dfe2;
            border-radius: 9px;
            background: #fdfaf9;
            color: #393238;
            font-family: inherit;
            font-size: 0.94rem;
        }

        .auth-field input:focus {
            outline: none;
            border-color: #bd9b68;
            box-shadow: 0 0 0 3px rgba(189, 155, 104, 0.13);
        }

        .password-input-wrap {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 8px;
        }

        .password-input-wrap .password-toggle {
            width: auto;
            margin: 0;
            padding: 0 12px;
            border: 1px solid #e9dfe2;
            border-radius: 8px;
            background: #f5e8e9;
            color: #684354;
            font-family: inherit;
            font-size: 0.78rem;
            white-space: nowrap;
            box-shadow: none;
        }

        .password-input-wrap .password-toggle:hover {
            background: #ead5da;
            color: #482e3d;
            transform: none;
        }

        .password-feedback {
            min-height: 20px;
            margin: 7px 0 0;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .feedback-error {
            color: #a33e4b;
        }

        .feedback-success {
            color: #397755;
        }

        .feedback-neutral {
            color: #82777d;
        }

        .auth-submit {
            width: 100%;
            margin-top: 12px;
            padding: 14px;
            border-radius: 10px;
            background: linear-gradient(120deg, #684354, #493044);
            color: #ffffff;
            font-size: 0.97rem;
            font-weight: 600;
            box-shadow: 0 8px 20px rgba(72, 46, 61, 0.16);
        }

        .auth-submit:hover {
            background: linear-gradient(120deg, #80576b, #684354);
        }

        .auth-footer {
            margin-top: 23px;
            text-align: center;
            color: #82777d;
            font-size: 0.9rem;
        }

        .auth-footer a {
            color: #684354;
            font-weight: 700;
        }

        .auth-error {
            margin-bottom: 18px;
            padding: 13px 16px;
            border: 1px solid #e7b8bf;
            border-radius: 9px;
            background: #fff0f1;
            color: #a33e4b;
            font-size: 0.88rem;
        }

        @media (max-width: 760px) {

            .auth-layout {
                grid-template-columns: 1fr;
            }

            .auth-intro {
                padding: 30px;
            }

            .auth-brand {
                margin-bottom: 24px;
            }

            .auth-decoration {
                margin-top: 20px;
            }

            .auth-form-panel {
                padding: 30px 24px;
            }
        }

        @media (max-width: 420px) {

            body.auth-page {
                padding: 12px;
            }

            .auth-form-panel {
                padding: 25px 17px;
            }

            .password-input-wrap {
                grid-template-columns: 1fr;
            }

            .password-input-wrap .password-toggle {
                padding: 9px;
            }
        }
    </style>
</head>

<body class="auth-page">

<main class="auth-layout">

    <section class="auth-intro">

        <div class="auth-brand">
            Glow<span>Well</span>
        </div>

        <p class="auth-eyebrow">
            BEAUTY • WELLNESS • SELF-CARE
        </p>

        <h1>Your journey to a beautiful glow begins here.</h1>

        <p>
            Create your GlowWell account and discover
            treatments designed to help you relax,
            refresh and feel your best.
        </p>

        <div class="auth-decoration">
            Your beauty, our passion.
        </div>

    </section>

    <section class="auth-form-panel">

        <h2>Create Your Account</h2>

        <p class="auth-subtitle">
            Join GlowWell Beauty Clinic today.
        </p>

        <?php if (!empty($errors)): ?>

            <div class="auth-error" role="alert">

                <strong>Please correct the following:</strong>

                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>

            </div>

        <?php endif; ?>

        <form
            method="POST"
            action="register.php"
            class="auth-form"
            id="registration-form"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrf_token()) ?>"
            >

            <div class="auth-field">
                <label for="first_name">First Name</label>

                <input
                    type="text"
                    id="first_name"
                    name="first_name"
                    maxlength="50"
                    autocomplete="given-name"
                    value="<?= e($first_name) ?>"
                    required
                >
            </div>

            <div class="auth-field">
                <label for="last_name">Last Name</label>

                <input
                    type="text"
                    id="last_name"
                    name="last_name"
                    maxlength="50"
                    autocomplete="family-name"
                    value="<?= e($last_name) ?>"
                    required
                >
            </div>

            <div class="auth-field">
                <label for="email">Email Address</label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    maxlength="100"
                    autocomplete="email"
                    value="<?= e($email) ?>"
                    required
                >
            </div>

            <div class="auth-field">
                <label for="phone">Phone Number</label>

                <input
                    type="tel"
                    id="phone"
                    name="phone"
                    maxlength="20"
                    autocomplete="tel"
                    value="<?= e($phone) ?>"
                    required
                >
            </div>

            <div class="auth-field">
                <label for="password">Password</label>

                <div class="password-input-wrap">

                    <input
                        type="password"
                        id="password"
                        name="password"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >

                    <button
                        type="button"
                        class="password-toggle"
                        data-password-target="password"
                        aria-pressed="false"
                    >
                        Show
                    </button>

                </div>
            </div>

            <div class="auth-field">
                <label for="confirm_password">Confirm Password</label>

                <div class="password-input-wrap">

                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >

                    <button
                        type="button"
                        class="password-toggle"
                        data-password-target="confirm_password"
                        aria-pressed="false"
                    >
                        Show
                    </button>

                </div>

                <p
                    id="password-feedback"
                    class="password-feedback"
                    aria-live="polite"
                ></p>
            </div>

            <button type="submit" class="auth-submit">
                Create Account
            </button>

        </form>

        <p class="auth-footer">
            Already have an account?
            <a href="login.php">Log in here</a>.
        </p>

    </section>

</main>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const password = document.getElementById('password');
    const confirmation = document.getElementById('confirm_password');

    const feedback = document.getElementById('password-feedback');

    const form = document.getElementById('registration-form');

    // Show and hide password fields.
    document.querySelectorAll('[data-password-target]').forEach(function (button) {

        button.addEventListener('click', function () {

            const target = document.getElementById(
                button.dataset.passwordTarget
            );

            if (!target) {
                return;
            }

            const show = target.type === 'password';

            target.type = show ? 'text' : 'password';

            button.textContent = show ? 'Hide' : 'Show';

            button.setAttribute('aria-pressed', String(show));

        });

    });

    // Live password validation.
    function checkPasswords() {

        confirmation.setCustomValidity('');

        if (password.value === '' && confirmation.value === '') {

            feedback.textContent = '';
            feedback.className = 'password-feedback';

            return true;
        }

        if (password.value.length < 8) {

            feedback.textContent =
                'Your password must contain at least 8 characters.';

            feedback.className =
                'password-feedback feedback-error';

            return false;
        }

        if (confirmation.value === '') {

            feedback.textContent =
                'Please confirm your password.';

            feedback.className =
                'password-feedback feedback-neutral';

            return false;
        }

        if (password.value !== confirmation.value) {

            feedback.textContent =
                'Your passwords do not match.';

            feedback.className =
                'password-feedback feedback-error';

            confirmation.setCustomValidity(
                'Your passwords do not match.'
            );

            return false;
        }

        feedback.textContent =
            'Your passwords match.';

        feedback.className =
            'password-feedback feedback-success';

        return true;
    }

    password.addEventListener('input', checkPasswords);

    confirmation.addEventListener('input', checkPasswords);

    form.addEventListener('submit', function (event) {

        if (!checkPasswords()) {

            event.preventDefault();

            confirmation.reportValidity();

        }

    });

});
</script>

</body>
</html>
