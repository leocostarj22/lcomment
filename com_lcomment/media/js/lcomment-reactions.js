document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.lcomment-reaction-form').forEach((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();

            const container = form.closest('.lcomment-reactions');

            if (container && container.dataset.pending === '1') {
                return;
            }

            if (container) {
                container.dataset.pending = '1';
            }

            clearError(container);

            fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                body: new FormData(form),
                headers: {
                    'X-LComment-Ajax': '1',
                },
            })
                .then((response) => response.json().then((data) => ({ ok: response.ok, data })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        showError(container, data.error);
                        return;
                    }

                    updateReactions(container, data);
                })
                .catch(() => {
                    // Network error, or a response body that wasn't valid
                    // JSON (e.g. an expired CSRF token returns HTML): leave
                    // the DOM as it was and let the user retry — the server
                    // is still the source of truth, nothing is guessed here.
                })
                .finally(() => {
                    if (container) {
                        delete container.dataset.pending;
                    }
                });
        });
    });
});

function updateReactions(container, data) {
    if (!container) {
        return;
    }

    container.querySelectorAll('.lcomment-reaction-form').forEach((form) => {
        const type = form.querySelector('input[name="reaction_type"]').value;
        const button = form.querySelector('.lcomment-reaction-button');
        const isMine = data.mine === type;
        const count = (data.counts && data.counts[type]) || 0;

        button.setAttribute('aria-pressed', isMine ? 'true' : 'false');
        form.classList.toggle('lcomment-reaction-active', isMine);

        let countEl = button.querySelector('.lcomment-reaction-count');

        if (count > 0) {
            if (!countEl) {
                countEl = document.createElement('span');
                countEl.className = 'lcomment-reaction-count';
                button.appendChild(countEl);
            }

            countEl.textContent = String(count);
        } else if (countEl) {
            countEl.remove();
        }
    });
}

function showError(container, message) {
    if (!container || !message) {
        return;
    }

    let errorEl = container.nextElementSibling;

    if (!errorEl || !errorEl.classList.contains('lcomment-reaction-error')) {
        errorEl = document.createElement('div');
        errorEl.className = 'lcomment-reaction-error';
        container.insertAdjacentElement('afterend', errorEl);
    }

    errorEl.textContent = message;
}

function clearError(container) {
    if (!container) {
        return;
    }

    const errorEl = container.nextElementSibling;

    if (errorEl && errorEl.classList.contains('lcomment-reaction-error')) {
        errorEl.remove();
    }
}
