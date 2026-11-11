
document.addEventListener('DOMContentLoaded', function () {

    // =========================================
    // 1. SHOW AND HIDE PASSWORDS
    // =========================================

    const toggleButtons = document.querySelectorAll(
        '[data-toggle-password]'
    );

    toggleButtons.forEach(function (button) {

        button.addEventListener('click', function () {

            const fieldId = button.getAttribute(
                'data-toggle-password'
            );

            const passwordField = document.getElementById(fieldId);

            if (!passwordField) {
                return;
            }

            const isHidden = passwordField.type === 'password';

            passwordField.type = isHidden ? 'text' : 'password';

            button.textContent = isHidden
                ? 'Hide password'
                : 'Show password';

            button.setAttribute(
                'aria-pressed',
                String(isHidden)
            );

        });

    });


    // =========================================
    // 2. LIVE PASSWORD CONFIRMATION
    // =========================================

    const registrationForm = document.querySelector(
        '[data-registration-form]'
    );

    if (!registrationForm) {
        return;
    }

    const password = document.getElementById('password');
    const confirmation = document.getElementById('confirm_password');
    const feedback = document.getElementById('password-feedback');

    if (!password || !confirmation || !feedback) {
        return;
    }

    function validatePasswords() {

        confirmation.setCustomValidity('');

        // Both fields are empty.
        if (password.value === '' && confirmation.value === '') {
            feedback.textContent = '';
            feedback.className = 'password-feedback';
            return true;
        }

        // Check minimum password length.
        if (password.value.length < 8) {

            feedback.textContent =
                'Your password must contain at least 8 characters.';

            feedback.className =
                'password-feedback feedback-error';

            return false;
        }

        // Confirmation has not been entered.
        if (confirmation.value === '') {

            feedback.textContent =
                'Please confirm your password.';

            feedback.className =
                'password-feedback feedback-neutral';

            return false;
        }

        // Passwords do not match.
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

        // Passwords match.
        feedback.textContent = 'Your passwords match.';

        feedback.className =
            'password-feedback feedback-success';

        return true;
    }

    // Check whenever either password field changes.
    password.addEventListener('input', validatePasswords);

    confirmation.addEventListener('input', validatePasswords);

    // Stop form submission when passwords do not match.
    registrationForm.addEventListener('submit', function (event) {

        if (!validatePasswords()) {

            event.preventDefault();

            confirmation.reportValidity();
        }

    });

});
