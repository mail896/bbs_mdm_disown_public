const kukSearchForm = document.getElementById('kukSearchForm');
const kukSearchInput = document.getElementById('q');

function submitKukSearch() {
    if (!kukSearchForm) {
        return;
    }
    if (typeof kukSearchForm.requestSubmit === 'function') {
        kukSearchForm.requestSubmit();
        return;
    }
    kukSearchForm.submit();
}

if (kukSearchInput) {
    attachDeferredSearch(kukSearchInput);
}

const kukMailFields = {
    preview: document.getElementById('kukMailPreview'),
    toText: document.getElementById('kukPreviewEmailText'),
    subjectText: document.getElementById('kukPreviewSubjectText'),
    introText: document.getElementById('kukPreviewIntroText'),
    detailsText: document.getElementById('kukPreviewDetailsText'),
    outro: document.getElementById('kukPreviewOutroInput'),
    sendTo: document.getElementById('kukSendToInput'),
    sendSubject: document.getElementById('kukSendSubjectInput'),
    sendBody: document.getElementById('kukSendBodyInput'),
    sendSerial: document.getElementById('kukSendSerialInput'),
    sendDevice: document.getElementById('kukSendDeviceInput'),
    sendKind: document.getElementById('kukSendKindInput')
};

document.querySelectorAll('.kuk-mail-trigger').forEach((button) => {
    button.addEventListener('click', () => {
        if (!kukMailFields.preview) {
            return;
        }

        kukMailFields.toText.textContent = button.dataset.to || '';
        kukMailFields.subjectText.textContent = button.dataset.subject || '';
        kukMailFields.introText.textContent = button.dataset.intro || '';
        kukMailFields.detailsText.textContent = button.dataset.details || '';
        kukMailFields.outro.value = button.dataset.outro || '';
        kukMailFields.sendSerial.value = button.dataset.serial || '';
        kukMailFields.sendDevice.value = button.dataset.device || '';
        kukMailFields.sendKind.value = button.dataset.kind || 'inactivity';

        updateKukMailRecipients();
        kukMailFields.sendSubject.value = kukMailFields.subjectText.textContent;
        kukMailFields.sendBody.value = composeKukMailBody();
        kukMailFields.preview.classList.remove('hidden');
        kukMailFields.preview.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
});

function updateKukMailRecipients() {
    if (!kukMailFields.sendTo) {
        return;
    }
    kukMailFields.sendTo.value = (kukMailFields.toText.textContent || '').trim();
}

function composeKukMailBody() {
    return [
        kukMailFields.introText.textContent || '',
        kukMailFields.detailsText.textContent || '',
        kukMailFields.outro.value || ''
    ].map((part) => part.trim()).filter((part) => part !== '').join('\n\n');
}

function sendKukMail() {
    updateKukMailRecipients();
    kukMailFields.sendSubject.value = (kukMailFields.subjectText.textContent || '').trim();
    kukMailFields.sendBody.value = composeKukMailBody();
    document.getElementById('kukSendMailForm').submit();
}

function hideKukMailPreview() {
    if (kukMailFields.preview) {
        kukMailFields.preview.classList.add('hidden');
    }
}
