document.addEventListener('DOMContentLoaded', function () {
    const toggleButtons = document.querySelectorAll(
        '[data-password-toggle]'
    );

    toggleButtons.forEach(function (button) {
        const inputId = button.getAttribute('aria-controls');
        const passwordInput = document.getElementById(inputId);

        if (!passwordInput) {
            return;
        }

        button.addEventListener('click', function () {
            const isHidden = passwordInput.type === 'password';

            passwordInput.type = isHidden ? 'text' : 'password';
            button.textContent = isHidden ? 'Hide' : 'Show';

            button.setAttribute(
                'aria-label',
                isHidden ? 'Hide password' : 'Show password'
            );

            button.setAttribute('aria-pressed', String(isHidden));
        });
    });
});
