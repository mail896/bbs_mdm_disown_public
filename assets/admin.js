const mailTemplate = window.disownAdminConfig?.mailTemplate || "";
const mailSubject = window.disownAdminConfig?.mailSubject || "";

const searchInput = document.getElementById('searchInput');
const selectAllRequests = document.getElementById('selectAllRequests');
['sendPrivateEmail', 'sendSchoolEmail', 'previewPrivateEmailInput', 'previewSchoolEmailInput'].forEach((id) => {
    const element = document.getElementById(id);
    if (element) {
        element.addEventListener('input', updateMailRecipients);
        element.addEventListener('change', updateMailRecipients);
    }
});

document.querySelectorAll('.bulk-select').forEach((checkbox) => {
    checkbox.addEventListener('change', updateBulkSelectionStatus);
});

if (selectAllRequests) {
    selectAllRequests.addEventListener('change', () => {
        document.querySelectorAll('.bulk-select').forEach((checkbox) => {
            checkbox.checked = selectAllRequests.checked;
        });
        updateBulkSelectionStatus();
    });
}

if (searchInput) {
    attachDeferredSearch(searchInput);
}

function getSelectedBulkRows() {
    return Array.from(document.querySelectorAll('.bulk-select:checked'));
}

function getBulkStep(checkbox) {
    if (checkbox.dataset.jamf !== '1') {
        return 'jamf';
    }
    if (checkbox.dataset.asm !== '1') {
        return 'asm';
    }
    return 'mail';
}

function getSelectedBulkStep(selectedRows) {
    if (selectedRows.length === 0) {
        return '';
    }

    const steps = Array.from(new Set(selectedRows.map(getBulkStep)));
    return steps.length === 1 ? steps[0] : 'mixed';
}

function getFallbackBulkStep() {
    const container = document.getElementById('bulkLastIdsInput');
    return container ? container.dataset.lastBulkStep || '' : '';
}

function setBulkButtonActive(button, active) {
    if (!button) {
        return;
    }
    button.disabled = !active;
    button.classList.toggle('button-primary', active);
    button.classList.toggle('button-secondary', !active);
}

function setBulkButtonState(step, fallbackStep) {
    const jamfButton = document.getElementById('bulkJamfButton');
    const copyButton = document.getElementById('bulkCopyButton');
    const asmButton = document.getElementById('bulkAsmButton');
    const mailButton = document.getElementById('bulkMailButton');
    const hasAsmList = step === 'asm' || fallbackStep === 'asm';

    setBulkButtonActive(jamfButton, step === 'jamf');
    setBulkButtonActive(copyButton, hasAsmList);
    setBulkButtonActive(asmButton, hasAsmList);
    setBulkButtonActive(mailButton, step === 'mail' || fallbackStep === 'mail');
}

function updateBulkSelectionStatus() {
    const selectedRows = getSelectedBulkRows();
    const status = document.getElementById('bulkSelectionStatus');
    const fallbackIds = Array.from(document.querySelectorAll('[data-last-bulk-id]'));
    const fallbackStep = getFallbackBulkStep();
    const step = getSelectedBulkStep(selectedRows);
    if (status) {
        if (selectedRows.length > 0) {
            if (step === 'mixed') {
                status.textContent = `${selectedRows.length} Anträge ausgewählt: bitte nur denselben nächsten Schritt auswählen`;
            } else {
                const stepLabels = {
                    jamf: 'Jamf',
                    asm: 'ASM/ADE',
                    mail: 'Mail'
                };
                status.textContent = selectedRows.length === 1
                    ? `1 Antrag ausgewählt · nächster Schritt: ${stepLabels[step]}`
                    : `${selectedRows.length} Anträge ausgewählt · nächster Schritt: ${stepLabels[step]}`;
            }
        } else if (fallbackIds.length > 0) {
            const fallbackLabel = fallbackStep === 'mail' ? 'Mail' : 'ASM/ADE';
            status.textContent = fallbackIds.length === 1
                ? `Letzte Bulk-Auswahl: 1 Antrag für ${fallbackLabel} bereit`
                : `Letzte Bulk-Auswahl: ${fallbackIds.length} Anträge für ${fallbackLabel} bereit`;
        } else {
            status.textContent = '0 Anträge ausgewählt';
        }
    }
    setBulkButtonState(step, selectedRows.length === 0 && fallbackIds.length > 0 ? fallbackStep : '');

    if (selectAllRequests) {
        const selectableRows = Array.from(document.querySelectorAll('.bulk-select'));
        selectAllRequests.checked = selectableRows.length > 0 && selectedRows.length === selectableRows.length;
        selectAllRequests.indeterminate = selectedRows.length > 0 && selectedRows.length < selectableRows.length;
    }

    updateBulkAsmListFromSelection();
}

function submitBulkAction(action) {
    let selectedRows = getSelectedBulkRows();
    const step = getSelectedBulkStep(selectedRows);
    const fallbackIds = Array.from(document.querySelectorAll('[data-last-bulk-id]')).map((input) => input.value);
    const fallbackStep = getFallbackBulkStep();
    const actionCount = selectedRows.length > 0 ? selectedRows.length : fallbackIds.length;

    if (selectedRows.length > 0 && step === 'mixed') {
        alert('Bitte wählen Sie nur Anträge mit demselben nächsten Schritt aus.');
        return;
    }

    const expectedActions = {
        jamf: 'bulk_jamf_unenroll',
        asm: 'bulk_asm_release',
        mail: 'bulk_mail_send'
    };
    if (selectedRows.length > 0 && expectedActions[step] !== action) {
        alert('Diese Aktion passt nicht zum nächsten Schritt der Auswahl.');
        return;
    }

    const fallbackActions = {
        asm: 'bulk_asm_release',
        mail: 'bulk_mail_send'
    };
    if (selectedRows.length === 0 && !(fallbackIds.length > 0 && fallbackActions[fallbackStep] === action)) {
        alert('Für diese Bulk-Aktion ist in der aktuellen Auswahl kein passender Antrag vorhanden.');
        return;
    }

    const idsContainer = document.getElementById('bulkIdsInput');
    idsContainer.innerHTML = '';
    const ids = selectedRows.length > 0
        ? selectedRows.map((checkbox) => checkbox.value)
        : fallbackIds;
    ids.forEach((id) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'bulk_ids[]';
        input.value = id;
        idsContainer.appendChild(input);
    });

    document.getElementById('bulkActionInput').value = action;
    showBulkWorking(action, actionCount);
    window.setTimeout(() => {
        document.getElementById('bulkActionForm').submit();
    }, 80);
}

function showBulkWorking(action, count) {
    const message = document.getElementById('bulkWorkingMessage');
    const texts = {
        bulk_jamf_unenroll: `${count} Gerät(e) werden in Jamf abgemeldet. Bitte warten und die Seite nicht neu laden.`,
        bulk_asm_release: `${count} Gerät(e) werden automatisch per ASM/ADE Release Broker freigegeben. Bitte warten und die Seite nicht neu laden.`,
        bulk_mail_send: `${count} vorbereitete Mail(s) werden versendet. Bitte warten und die Seite nicht neu laden.`
    };
    if (message) {
        message.textContent = texts[action] || 'Bulk-Aktion läuft. Bitte warten und die Seite nicht neu laden.';
        message.classList.remove('hidden');
        message.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    document.querySelectorAll('.bulk-toolbar button, .bulk-select, #selectAllRequests').forEach((element) => {
        element.disabled = true;
    });
}

function showSingleWorking(text) {
    const message = document.getElementById('bulkWorkingMessage');
    if (message) {
        message.textContent = text || 'Aktion läuft. Bitte warten und die Seite nicht neu laden.';
        message.classList.remove('hidden');
        message.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    document.querySelectorAll('.action-form button, .asm-release-actions button').forEach((element) => {
        element.disabled = true;
    });
}

function updateBulkAsmListFromSelection() {
    const selectedRows = getSelectedBulkRows();
    const textarea = document.getElementById('bulkAsmListText');
    const panel = document.getElementById('bulkAsmList');
    if (!textarea || !panel) {
        return;
    }
    const step = getSelectedBulkStep(selectedRows);

    if (selectedRows.length === 0) {
        if (textarea.dataset.serverList !== '1') {
            textarea.value = '';
            panel.classList.add('hidden');
        }
        return;
    }

    if (step !== 'asm') {
        textarea.dataset.serverList = '0';
        textarea.value = '';
        panel.classList.add('hidden');
        return;
    }

    const serials = selectedRows
        .map((checkbox) => checkbox.dataset.serial || '')
        .filter(Boolean);
    textarea.dataset.serverList = '0';
    textarea.value = Array.from(new Set(serials)).join(', ');
    panel.classList.remove('hidden');
}

function hideBulkAsmList() {
    document.getElementById('bulkAsmList').classList.add('hidden');
}

function copyBulkAsmList() {
    const textarea = document.getElementById('bulkAsmListText');
    if (!textarea.value.trim()) {
        alert('Es gibt aktuell keine Seriennummernliste zum Kopieren.');
        return;
    }
    textarea.focus();
    textarea.select();
    copyTextToClipboard(textarea.value, true);
}

function setBulkCopyStatus(text) {
    const status = document.getElementById('bulkCopyStatus');
    if (status) {
        status.textContent = text;
    }
}

function autoCopyServerBulkAsmList() {
    const textarea = document.getElementById('bulkAsmListText');
    if (!textarea || textarea.dataset.autoCopy !== '1' || !textarea.value.trim()) {
        return;
    }

    textarea.focus();
    textarea.select();
    copyTextToClipboard(textarea.value, true);
    textarea.dataset.autoCopy = '0';
}

function copyTextToClipboard(text, showStatus) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text)
            .then(() => {
                if (showStatus) {
                    setBulkCopyStatus('Liste kopiert.');
                }
            })
            .catch(() => {
                if (document.execCommand('copy')) {
                    if (showStatus) {
                        setBulkCopyStatus('Liste kopiert.');
                    }
                } else if (showStatus) {
                    setBulkCopyStatus('Liste bereit zum Kopieren.');
                }
            });
        return;
    }

    if (document.execCommand('copy')) {
        if (showStatus) {
            setBulkCopyStatus('Liste kopiert.');
        }
    } else if (showStatus) {
        setBulkCopyStatus('Liste bereit zum Kopieren.');
    }
}

function toggleTemplateEditor() {
    const editor = document.getElementById('templateEditor');
    editor.classList.toggle('hidden');
}

function showDeviceCase(button) {
    const data = button.dataset || {};
    const card = document.getElementById('deviceCaseCard');
    if (!card) {
        return;
    }

    const caseId = data.caseId || '';
    const serial = data.serial || '';
    const updatedAt = data.updatedAt || '';

    document.getElementById('caseIdInput').value = caseId;
    document.getElementById('caseRequestIdInput').value = data.requestId || '0';
    document.getElementById('caseSourceInput').value = data.source || 'admin';
    document.getElementById('caseSerialInput').value = serial;
    document.getElementById('caseStatusInput').value = data.status || 'offen';
    document.getElementById('caseTitleInput').value = data.title || ('Klärfall ' + serial);
    document.getElementById('caseNoteInput').value = data.note || '';
    document.getElementById('caseResolutionInput').value = data.resolutionNote || '';
    document.getElementById('caseMeta').textContent = caseId
        ? 'Bestehender Klärfall zu ' + serial + (updatedAt ? ' · aktualisiert ' + updatedAt : '')
        : 'Neuer Klärfall zu ' + serial;
    const deleteButton = document.getElementById('deleteCaseButton');
    if (deleteButton) {
        deleteButton.classList.toggle('hidden', !caseId);
    }

    card.classList.remove('hidden');
    card.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function hideDeviceCase() {
    const card = document.getElementById('deviceCaseCard');
    if (card) {
        card.classList.add('hidden');
    }
}

document.querySelectorAll('.case-row-clickable').forEach((row) => {
    row.addEventListener('click', (event) => {
        if (event.target.closest('a, button, input, select, textarea, label')) {
            return;
        }
        showDeviceCase(row);
    });
});

function showMailPreview(button) {
    const data = button.dataset;
    let body = mailTemplate;
    const placeholders = {
        name: 'name',
        username: 'username',
        email: 'email',
        private_email: 'privateEmail',
        device_name: 'device',
        serial: 'serial'
    };

    Object.entries(placeholders).forEach(([placeholder, source]) => {
        const value = data[source] || '';
        body = body.replace(new RegExp(`{{${placeholder}}}`, 'g'), value);
    });

    const privateEmail = data.privateEmail || '';
    const schoolEmail = data.email || '';
    const privateRecipientRow = document.getElementById('privateRecipientRow');
    const sendPrivateEmail = document.getElementById('sendPrivateEmail');
    const sendSchoolEmail = document.getElementById('sendSchoolEmail');
    const privateEmailInput = document.getElementById('previewPrivateEmailInput');
    const schoolEmailInput = document.getElementById('previewSchoolEmailInput');

    privateRecipientRow.classList.remove('hidden');
    privateEmailInput.value = privateEmail;
    schoolEmailInput.value = schoolEmail;
    sendPrivateEmail.checked = privateEmail !== '';
    sendPrivateEmail.disabled = false;
    sendSchoolEmail.checked = schoolEmail !== '';
    sendSchoolEmail.disabled = false;
    document.getElementById('previewSubjectInput').value = mailSubject;
    document.getElementById('previewBodyInput').value = body;
    updateMailRecipients();
    document.getElementById('sendSubjectInput').value = mailSubject;
    document.getElementById('sendDeviceInput').value = data.device || '';
    document.getElementById('sendSerialInput').value = data.serial || '';
    document.getElementById('sendRequestIdInput').value = data.id || '0';
    document.getElementById('sendBodyInput').value = body;
    const preview = document.getElementById('mailPreview');
    preview.classList.remove('hidden');
    preview.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function sendMail() {
    updateMailRecipients();
    document.getElementById('sendSubjectInput').value = document.getElementById('previewSubjectInput').value.trim();
    document.getElementById('sendBodyInput').value = document.getElementById('previewBodyInput').value;
    document.getElementById('sendMailForm').submit();
}

function openAsmBeforeSubmit() {
    openAsmPortal();
}

function copyAsmLinkToClipboard() {
    const asmUrl = 'https://school.apple.com';
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(asmUrl).catch(() => {});
        return;
    }

    const tempInput = document.createElement('textarea');
    tempInput.value = asmUrl;
    tempInput.setAttribute('readonly', '');
    tempInput.style.position = 'fixed';
    tempInput.style.left = '-9999px';
    document.body.appendChild(tempInput);
    tempInput.select();
    try {
        document.execCommand('copy');
    } catch (error) {
        // Best effort only.
    }
    document.body.removeChild(tempInput);
}

function openAsmPortal() {
    const asmUrl = 'https://school.apple.com';
    const isFirefox = /Firefox\//.test(navigator.userAgent);

    if (isFirefox) {
        copyAsmLinkToClipboard();
        window.location.href = 'googlechrome://navigate?url=' + encodeURIComponent(asmUrl);
        alert('Apple School Manager wird in Chrome geöffnet. Falls Chrome nicht reagiert, ist der Link in der Zwischenablage und kann in Safari oder Chrome eingefügt werden.');
        return;
    }

    window.open(asmUrl, '_blank', 'noopener');
}

function updateMailRecipients() {
    const recipients = [];
    const sendPrivateEmail = document.getElementById('sendPrivateEmail');
    const sendSchoolEmail = document.getElementById('sendSchoolEmail');
    const privateEmail = document.getElementById('previewPrivateEmailInput').value.trim();
    const schoolEmail = document.getElementById('previewSchoolEmailInput').value.trim();

    sendPrivateEmail.disabled = false;
    sendSchoolEmail.disabled = false;
    if (privateEmail && !sendPrivateEmail.checked) {
        sendPrivateEmail.checked = true;
    }
    if (schoolEmail && !sendSchoolEmail.checked) {
        sendSchoolEmail.checked = true;
    }
    if (!privateEmail) {
        sendPrivateEmail.checked = false;
    }
    if (!schoolEmail) {
        sendSchoolEmail.checked = false;
    }

    if (sendPrivateEmail.checked && privateEmail) {
        recipients.push(privateEmail);
    }
    if (sendSchoolEmail.checked && schoolEmail) {
        recipients.push(schoolEmail);
    }

    document.getElementById('sendToInput').value = recipients.join(', ');
}

function hideMailPreview() {
    document.getElementById('mailPreview').classList.add('hidden');
}

updateBulkSelectionStatus();
autoCopyServerBulkAsmList();
