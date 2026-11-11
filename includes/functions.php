
<?php
// Start a secure session if one is not already active.
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => false // Set to true when using HTTPS.
    ]);

    session_start();
}

// Set the local timezone.
date_default_timezone_set('Africa/Johannesburg');

// Safely display data in HTML.
function e($value): string {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

// Generate a CSRF protection token.
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

// Verify a submitted CSRF token.
function verify_csrf_token($token): bool {
    return isset($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

// Store a one-time feedback message.
function set_flash($type, $message): void {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

// Retrieve and clear a feedback message.
function get_flash(): ?array {
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $message = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $message;
}
