import test from 'node:test';
import assert from 'node:assert/strict';
import { installBoardShortcuts, isTyping } from '../../resources/js/erd/keyboard-shortcuts.js';

test('typing targets include form controls and editable text', () => {
    assert.equal(isTyping({ closest: selector => selector.includes('select') ? {} : null }), true);
    assert.equal(isTyping({ closest: () => null }), false);
});

test('save shortcuts persist, deletion calls the server, and cleanup removes listeners', async () => {
    const previousWindow = globalThis.window;
    const previousDocument = globalThis.document;
    const root = new EventTarget();
    root.isConnected = true;
    root.querySelectorAll = () => [];
    root.querySelector = selector => selector === '[data-board-save]' ? { click() { saves++; } } : {};
    let saves = 0;
    let deletes = 0;
    const targetWindow = new EventTarget();
    targetWindow.confirm = () => true;
    targetWindow.Alpine = { $data: () => ({ selectedNodes: new Set(['table']), selectedEdges: new Set() }) };
    globalThis.window = targetWindow;
    globalThis.document = { activeElement: { blur() {} } };
    const cleanup = installBoardShortcuts(root, {
        async save() { assert.fail('the shortcut must click the editor save button'); },
        async deleteSelection(nodes) { assert.deepEqual(nodes, ['table']); deletes++; },
    }, 'relational');
    const press = async (key, options = {}) => {
        const event = new Event('keydown', { cancelable: true });
        Object.assign(event, { key, ...options });
        targetWindow.dispatchEvent(event);
        await new Promise(resolve => setImmediate(resolve));
        return event;
    };
    try {
        assert.equal(typeof cleanup, 'object', 'x-init must not receive a function that Alpine auto-invokes');
        assert.equal((await press('s', { ctrlKey: true })).defaultPrevented, true);
        await press('s', { metaKey: true });
        assert.equal(saves, 2);
        await press('Delete');
        assert.equal(deletes, 1);
        await press('Delete', { repeat: true });
        assert.equal(deletes, 1);
        targetWindow.closest = () => ({});
        await press('Delete');
        assert.equal(deletes, 1, 'typing must not delete a selection');
        targetWindow.closest = () => null;
        targetWindow.confirm = () => false;
        await press('Delete');
        assert.equal(deletes, 1, 'cancel must preserve the selection');
        cleanup.destroy();
        await press('s', { ctrlKey: true });
        assert.equal(saves, 2);
    } finally {
        cleanup.destroy();
        globalThis.window = previousWindow;
        globalThis.document = previousDocument;
    }
});
