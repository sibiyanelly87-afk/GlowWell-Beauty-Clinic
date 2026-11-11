
<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

$errors = [];
$email = '';
$flash = get_flash();

// Process login submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    // Verify CSRF protection.
    if (!verify_csrf_token($token)) {
        $errors[] = 'Invalid form submission. Please refresh and try again.';
    }

    // Validate email and password.
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($password === '') {
        $errors[] = 'Please enter your password.';
    }

    // Authenticate user.
    if (empty($errors)) {

        try {

            $sql = "SELECT
                        user_id,
                        first_name,
                        last_name,
                        email,
                        password_hash,
                        role,
                        account_status
                    FROM users
                    WHERE email = :email
                    LIMIT 1";

            $stmt = $pdo->prepare($sql);
            $stmt->execute(['email' => $email]);

            $user = $stmt->fetch();

            if (
                $user &&
                password_verify($password, $user['password_hash']) &&
                $user['account_status'] === 'active'
            ) {

                // Regenerate session ID.
                session_regenerate_id(true);

                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name'] = $user['last_name'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];

                // Generate a new CSRF token.
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                // Redirect according to account role.
                if ($user['role'] === 'admin') {

                    header('Location: admin/dashboard.php');

                } elseif ($user['role'] === 'staff') {

                    header('Location: staff/dashboard.php');

                } else {

                    header('Location: index.php');

                }

                exit;

            } else {

                $errors[] =
                    'Incorrect email or password, or your account is inactive.';
            }

        } catch (PDOException $e) {

            error_log('Login error: ' . $e->getMessage());

            $errors[] =
                'Unable to process your login. Please try again later.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Login | GlowWell Beauty Clinic</title>

    <link rel="stylesheet" href="assets/css/style.css">

    <style>
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
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 55px 45px;
            background: #ffffff;
        }

        .auth-form-panel h2 {
            margin: 0 0 5px;
            color: #482e3d;
            font-family: Georgia, serif;
            font-size: 2rem;
        }

        .auth-subtitle {
            margin: 0 0 28px;
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
            margin-bottom: 20px;
        }

        .auth-field label {
            display: block;
            margin-bottom: 7px;
            color: #482e3d;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .auth-field input {
            display: block;
            width: 100%;
            margin: 0;
            padding: 14px;
            border: 1px solid #e9dfe2;
            border-radius: 9px;
            background: #fdfaf9;
            color: #393238;
            font-family: inherit;
            font-size: 0.95rem;
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
            padding: 0 14px;
            border: 1px solid #e9dfe2;
            border-radius: 8px;
            background: #f5e8e9;
            color: #684354;
            font-family: inherit;
            font-size: 0.8rem;
            box-shadow: none;
        }

        .password-input-wrap .password-toggle:hover {
            background: #ead5da;
            color: #482e3d;
            transform: none;
        }

        .auth-submit {
            width: 100%;
            margin-top: 8px;
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
            margin-top: 25px;
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

        .auth-success {
            margin-bottom: 18px;
            padding: 13px 16px;
            border: 1px solid #b8d8c3;
            border-radius: 9px;
            background: #eef8f1;
            color: #397755;
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
                padding: 35px 25px;
            }
        }

        @media (max-width: 420px) {

            body.auth-page {
                padding: 12px;
            }

            .auth-form-panel {
                padding: 27px 18px;
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

        <h1>Welcome back. Your moment of care awaits.</h1>

        <p>
            Sign in to explore your treatments,
            manage your appointments and continue
            your GlowWell wellness journey.
        </p>

        <div class="auth-decoration">
            Your beauty, our passion.
        </div>

    </section>

    <section class="auth-form-panel">

        <h2>Welcome Back</h2>

        <p class="auth-subtitle">
            Enter your details to access your account.
        </p>

        <?php if ($flash): ?>

            <div class="auth-success" role="status">
                <?= e($flash['message']) ?>
            </div>

        <?php endif; ?>

        <?php if (!empty($errors)): ?>

            <div class="auth-error" role="alert">

                <strong>Please check the following:</strong>

                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>

            </div>

        <?php endif; ?>

        <form method="POST" action="login.php" class="auth-form">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrf_token()) ?>"
            >

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

                <label for="password">Password</label>

                <div class="password-input-wrap">

                    <input
                        type="password"
                        id="password"
                        name="password"
                        autocomplete="current-password"
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

            <button type="submit" class="auth-submit">
                Log In
            </button>

        </form>

        <p class="auth-footer">
            Don't have an account?
            <a href="register.php">Create an account</a>.
        </p>

    </section>

</main>

<script>
document.addEventListener('DOMContentLoaded', function () {

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

});
</script>

</body>
</html>
