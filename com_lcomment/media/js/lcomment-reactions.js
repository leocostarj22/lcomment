document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.lcomment-reaction-form').forEach((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();

            const container = form.closest('.lcomment-reactions');

            fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                body: new FormData(form),
                headers: {
                    'X-LComment-Ajax': '1',
                },
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('Request failed');
                    }

                    return response.json();
                })
                .then((data) => updateReactions(container, data))
                .catch(() => {
                    // Network error or rejected request: leave the DOM as it
                    // was and let the user retry — the server is still the
                    // source of truth, nothing is guessed on failure here.
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
