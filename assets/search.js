function attachDeferredSearch(input, options = {}) {
    if (!input || !input.form) {
        return;
    }

    const delay = options.delay || 1100;
    const minLength = options.minLength || 2;
    const focusParam = options.focusParam || 'search_focus';
    const currentValue = input.value.trim();
    let timer = null;

    const submit = () => {
        input.value = input.value.trim();
        const marker = input.form.querySelector(`input[name="${focusParam}"]`) || document.createElement('input');
        marker.type = 'hidden';
        marker.name = focusParam;
        marker.value = '1';
        if (!marker.parentElement) {
            input.form.appendChild(marker);
        }
        if (typeof input.form.requestSubmit === 'function') {
            input.form.requestSubmit();
            return;
        }
        input.form.submit();
    };

    input.form.addEventListener('submit', () => {
        input.value = input.value.trim();
    });

    const url = new URL(window.location.href);
    if (url.searchParams.get(focusParam) === '1') {
        input.focus();
        input.setSelectionRange(input.value.length, input.value.length);
    }

    input.addEventListener('input', () => {
        window.clearTimeout(timer);
        const value = input.value.trim();

        if (value === currentValue) {
            return;
        }
        if (value !== '' && value.length < minLength) {
            return;
        }

        timer = window.setTimeout(submit, delay);
    });
}
