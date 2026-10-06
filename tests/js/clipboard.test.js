import assert from 'node:assert/strict';
import test from 'node:test';

import { copyBoardText } from '../../resources/js/erd/clipboard.js';

test('copies SQL with Clipboard API and falls back when it is unavailable or rejected', async () => {
    const originalNavigator = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
    const originalDocument = Object.getOwnPropertyDescriptor(globalThis, 'document');
    const copied = [];
    let field;

    Object.defineProperty(globalThis, 'document', {
        configurable: true,
        value: {
            body: { appendChild: (element) => { field = element; } },
            createElement: () => ({ style: {}, focus() {}, select() {}, remove() {} }),
            execCommand: () => { copied.push(field.value); return true; },
        },
    });

    try {
        Object.defineProperty(globalThis, 'navigator', {
            configurable: true,
            value: { clipboard: { writeText: async (value) => { copied.push(value); } } },
        });
        await copyBoardText('CREATE TABLE staff;');
        assert.deepEqual(copied, ['CREATE TABLE staff;']);

        Object.defineProperty(globalThis, 'navigator', { configurable: true, value: {} });
        await copyBoardText('SELECT * FROM staff;');
        assert.deepEqual(copied, ['CREATE TABLE staff;', 'SELECT * FROM staff;']);

        Object.defineProperty(globalThis, 'navigator', {
            configurable: true,
            value: { clipboard: { writeText: async () => { throw new Error('Denied'); } } },
        });
        await copyBoardText('ALTER TABLE staff;');
        assert.equal(copied.at(-1), 'ALTER TABLE staff;');

        globalThis.document.execCommand = () => false;
        await assert.rejects(copyBoardText('DROP TABLE staff;'), /rejected/);
    } finally {
        if (originalNavigator) Object.defineProperty(globalThis, 'navigator', originalNavigator);
        else delete globalThis.navigator;
        if (originalDocument) Object.defineProperty(globalThis, 'document', originalDocument);
        else delete globalThis.document;
    }
});
