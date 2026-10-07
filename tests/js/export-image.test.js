import assert from 'node:assert/strict';
import test from 'node:test';

globalThis.window = {};
const { exportImageSize } = await import('../../resources/js/erd/export-image.js');

test('image export grows with the diagram bounds and caps the canvas size', () => {
    assert.deepEqual(exportImageSize([]), { width: 2560, height: 1440 });
    assert.deepEqual(exportImageSize([
        { position: { x: 0, y: 0 }, dimensions: { width: 380, height: 200 } },
    ]), { width: 2560, height: 1440 });

    assert.deepEqual(exportImageSize([
        { position: { x: -200, y: 100 }, dimensions: { width: 380, height: 200 } },
        { position: { x: 1800, y: 1700 }, dimensions: { width: 380, height: 200 } },
    ]), { width: 3570, height: 2700 });

    assert.deepEqual(exportImageSize([
        { position: { x: 0, y: 0 }, dimensions: { width: 5000, height: 5000 } },
    ]), { width: 4096, height: 4096 });
});
