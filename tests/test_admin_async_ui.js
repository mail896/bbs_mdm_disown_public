'use strict';

import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

let submissions = 0;
const elements = new Map();
for (const id of ['privateRecipientRow', 'sendPrivateEmail', 'sendSchoolEmail',
    'previewPrivateEmailInput', 'previewSchoolEmailInput', 'previewSubjectInput',
    'previewBodyInput', 'sendSubjectInput', 'sendDeviceInput', 'sendSerialInput',
    'sendRequestIdInput', 'sendBodyInput', 'mailPreview', 'sendToInput', 'sendMailForm']) {
    elements.set(id, { value: '', checked: false, disabled: false, dataset: {},
        classList: { add() {}, remove() {}, toggle() {} },
        addEventListener() {}, scrollIntoView() {}, submit() { submissions++; } });
}
const context = vm.createContext({
    window: { disownAdminConfig: {
        mailTemplate: 'STANDARD {{name}} {{serial}}', mailSubject: 'Release',
    } },
    document: { getElementById: id => elements.get(id) || null, querySelectorAll: () => [] },
});
vm.runInContext(fs.readFileSync(new URL('../assets/admin.js', import.meta.url), 'utf8'), context);

assert.equal(context.getBulkStep({ dataset: { jamf: '0', asm: '0' } }), 'jamf');
assert.equal(context.getBulkStep({ dataset: { jamf: '1', asm: '0' } }), 'asm');
assert.equal(context.getBulkStep({ dataset: { jamf: '0', asm: '1' } }), 'mail');
assert.equal(context.getBulkStep({ dataset: { jamf: '1', asm: '1' } }), 'mail');

const row = { id: '123', name: 'Test Person', serial: 'TEST123', device: 'Test iPad',
    email: 'school@example.invalid', privateEmail: 'private@example.invalid', jamfVerified: '0' };
context.showMailPreview({ dataset: row });
assert.equal(elements.get('previewBodyInput').value, 'STANDARD Test Person TEST123');
assert.equal(elements.get('sendSerialInput').value, 'TEST123');
assert.equal(elements.get('sendToInput').value, 'private@example.invalid, school@example.invalid');
assert.equal(submissions, 0, 'Opening pending preview never sends mail');
context.showMailPreview({ dataset: { ...row, jamfVerified: '1' } });
assert.equal(elements.get('previewBodyInput').value, 'STANDARD Test Person TEST123');
assert.equal(submissions, 0, 'Verified preview also never sends mail');
elements.get('previewBodyInput').value = 'Admin-reviewed message';
context.sendMail();
assert.equal(submissions, 1, 'Only explicit send action submits');
assert.equal(elements.get('sendBodyInput').value, 'Admin-reviewed message');
console.log('OK: 12 JavaScript assertions; fake DOM only, no network or mail.');
