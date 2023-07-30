import './bootstrap';

document.querySelector('button#fetch')
    ?.addEventListener('click', async (evt) => {
        const form = evt.target.closest('form');
        const api = form.dataset.api;
        const data = new FormData(form);

        if (!data.get('url')) {
            return;
        }

        const textarea = form.querySelector('#csp');
        const response = await fetch(form.dataset.api, {
            method: 'post',
            body: data
        });
        textarea.value = await response.text();
    });
