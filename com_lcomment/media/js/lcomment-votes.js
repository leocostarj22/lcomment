document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.lcomment-vote-form').forEach((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();

            const container = form.closest('.lcomment-votes');

            if (container && container.dataset.pending === '1') {
                return;
            }

            if (container) {
                container.dataset.pending = '1';
            }

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
                .then((data) => updateVotes(container, data))
                .catch(() => {
                    // Network error or rejected request: leave the DOM as it
                    // was and let the user retry — the server is still the
                    // source of truth, nothing is guessed on failure here.
                })
                .finally(() => {
                    if (container) {
                        delete container.dataset.pending;
                    }
                });
        });
    });
});

function updateVotes(container, data) {
    if (!container) {
        return;
    }

    container.querySelectorAll('.lcomment-vote-form').forEach((form) => {
        const type = form.querySelector('input[name="vote_type"]').value;
        const button = form.querySelector('.lcomment-vote-button');
        const isMine = data.mine === type;
        const count = (data.counts && data.counts[type]) || 0;

        button.setAttribute('aria-pressed', isMine ? 'true' : 'false');
        form.classList.toggle('lcomment-vote-active', isMine);
        button.querySelector('.lcomment-vote-count').textContent = String(count);
    });
}
