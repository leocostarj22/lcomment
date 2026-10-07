document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.lcomment-form').forEach((form) => {
        form.addEventListener('submit', () => {
            const button = form.querySelector('button[type="submit"]');

            if (button) {
                button.disabled = true;
            }
        });
    });
});
