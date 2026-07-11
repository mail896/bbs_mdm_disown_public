const adeSearchForm = document.getElementById('adeSearchForm');
const adeSearchInput = document.getElementById('q');
const adeDaysSelect = document.getElementById('daysSelect');

function submitAdeSearch() {
    if (!adeSearchForm) {
        return;
    }
    if (typeof adeSearchForm.requestSubmit === 'function') {
        adeSearchForm.requestSubmit();
        return;
    }
    adeSearchForm.submit();
}

if (adeSearchInput) {
    attachDeferredSearch(adeSearchInput);
}

if (adeDaysSelect) {
    adeDaysSelect.addEventListener('change', submitAdeSearch);
}
