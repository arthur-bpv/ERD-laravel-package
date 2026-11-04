import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

test('uses the full table height after resizing and after a server estimate replaces measured dimensions', async () => {
    const source = await readFile(new URL('../../packages/laravel-erd/resources/js/erd/node-measurement.js', import.meta.url), 'utf8');
    let directive, resize, reconcile, cleanup;
    let frame;
    let disconnected = false;
    const events = [];
    const node = { id: 'staff', dimensions: { width: 232, height: 157.5 } };
    const element = { offsetWidth: 232, offsetHeight: 172, dispatchEvent: (event) => events.push(event) };
    new Function('document', 'window', 'ResizeObserver', 'requestAnimationFrame', 'cancelAnimationFrame', 'CustomEvent', source)(
        { addEventListener: (_, callback) => callback() },
        { Alpine: { directive: (_, callback) => { directive = callback; } } },
        class { constructor(callback) { resize = callback; } observe() {} disconnect() { disconnected = true; } },
        (callback) => { frame = callback; return 1; },
        () => { frame = undefined; },
        class { constructor(type, options) { this.type = type; Object.assign(this, options); } },
    );
    directive(element, { expression: 'node' }, {
        evaluateLater: () => (callback) => callback(node),
        effect: (callback) => { reconcile = callback; callback(); },
        cleanup: (callback) => { cleanup = callback; },
    });
    const flush = () => { const callback = frame; frame = undefined; callback(); };
    flush();
    assert.equal(node.dimensions.height, 172, 'includes the attribute footer');
    assert.equal(events.at(-1).detail.dimensions.height, 172);
    assert.equal(events.at(-1).bubbles, true);

    node.dimensions = { width: 232, height: 157.5 };
    reconcile();
    flush();
    assert.equal(node.dimensions.height, 172, 'restores the actual size even without a DOM resize');

    for (const height of [220, 172]) {
        element.offsetHeight = height;
        resize();
        flush();
        assert.equal(node.dimensions.height, height, 'follows opening and closing the form');
        assert.equal(events.at(-1).detail.dimensions.height, height);
    }
    reconcile();
    cleanup();
    assert.equal(frame, undefined);
    assert.equal(disconnected, true);
});
