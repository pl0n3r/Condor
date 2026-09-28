(() => {
    const tokenInput = document.getElementById('password-reset-token');
    const submit = document.getElementById('password-reset-submit');

    if (!(tokenInput instanceof HTMLInputElement) || !(submit instanceof HTMLButtonElement)) {
        return;
    }

    const fragment = new URLSearchParams(window.location.hash.slice(1));
    const token = fragment.get('token') || '';
    const valid = /^[a-f0-9]{64}$/i.test(token);

    tokenInput.value = valid ? token.toLowerCase() : '';
    submit.disabled = !valid;
    history.replaceState(null, '', window.location.pathname + window.location.search);
})();
