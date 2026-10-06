import assert from 'node:assert/strict';
import test from 'node:test';

import { relationalSelfLoopPath } from '../../resources/js/erd/relational-self-loop.js';
import { installRelationalConnections, refreshRelationalConnections } from '../../resources/js/erd/relational-connections.js';

test('routes a recursive FK between two rows on the right side', () => {
    const result = relationalSelfLoopPath({
        sourceX: 290,
        sourceY: 125,
        sourcePosition: 'right',
        targetX: 290,
        targetY: 25,
        targetPosition: 'right',
    });

    assert.equal(result.path, 'M290,125 L318,125 Q334,125 334,109 L334,41 Q334,25 318,25 L290,25');
    assert.equal(result.labelPosition.x, 334);
    assert.equal(result.labelPosition.y, 75);
    assert.doesNotMatch(result.path, /NaN/);
});

test('routes recursive FKs on either lateral side without crossing the table', () => {
    const cases = [
        ['right', { x: 290, y: 125 }, { x: 290, y: 25 }],
        ['left', { x: 0, y: 125 }, { x: 0, y: 25 }],
    ];

    for (const [position, source, target] of cases) {
        const result = relationalSelfLoopPath({
            sourceX: source.x,
            sourceY: source.y,
            sourcePosition: position,
            targetX: target.x,
            targetY: target.y,
            targetPosition: position,
        });

        assert.match(result.path, /^M[-\d.]+,[-\d.]+ L/);
        assert.match(result.path, / Q/);
        assert.doesNotMatch(result.path, /NaN/);
        assert.ok(Number.isFinite(result.labelPosition.x));
        assert.ok(Number.isFinite(result.labelPosition.y));
    }
});

test('keeps a dragged recursive FK attached to its column rows', () => {
    globalThis.CSS = { escape: (value) => value };
    const attributes = {};
    const path = {
        getAttribute: (name) => attributes[name],
        setAttribute: (name, value) => { attributes[name] = value; },
        parentElement: { querySelector: () => null },
    };
    const label = { style: {} };
    const handles = {
        'col-parent-right': { y: 360 },
        'col-id-right': { y: 260 },
    };
    const node = {
        querySelector(selector) {
            const id = selector.match(/data-flow-handle-id="([^"]+)"/)?.[1];
            const handle = handles[id];
            return handle && {
                dataset: { flowHandlePosition: 'right' },
                getBoundingClientRect: () => ({ left: 680, top: handle.y, width: 2, height: 2 }),
            };
        },
    };
    const container = {
        querySelector: (selector) => selector.includes('data-flow-node-id') ? node : label,
        getBoundingClientRect: () => ({ left: 100, top: 50 }),
    };
    const flow = {
        viewport: { x: 20, y: 10, zoom: 2 },
        edges: [{ id: 'recursive', type: 'relational-self-loop', source: 'staff', target: 'staff', sourceHandle: 'col-parent-right', targetHandle: 'col-id-right', markerEnd: 'arrowclosed' }],
        getEdgePathElement: () => path,
    };

    refreshRelationalConnections(container, flow, 'staff');

    assert.match(attributes.d, /^M280\.5,150\.5 /);
    assert.match(attributes.d, / L286\.5,100\.5$/);
    assert.equal(label.style.top, '125.5px');

    handles['col-parent-right'].y += 80;
    handles['col-id-right'].y += 80;
    refreshRelationalConnections(container, flow, 'staff');

    assert.match(attributes.d, /^M280\.5,190\.5 /);
    assert.match(attributes.d, / L286\.5,140\.5$/);
    assert.equal(label.style.top, '165.5px');

    let onMutation;
    globalThis.MutationObserver = class {
        constructor(callback) { onMutation = callback; }
        observe() {}
        disconnect() {}
    };
    globalThis.window = { Alpine: { $data: () => flow } };
    const cleanup = installRelationalConnections(container);

    // WireFlow's later fast redraw used to overwrite the corrected loop.
    attributes.d = 'M0,0 L1,1';
    onMutation();
    assert.match(attributes.d, /^M280\.5,190\.5 /);

    // The ordinary FK path also needs its row handles after the same redraw.
    flow.edges = [{ ...flow.edges[0], type: 'smoothstep', target: 'manager' }];
    attributes.d = 'M0,0 L1,1';
    onMutation();
    assert.match(attributes.d, /^M280\.5,190\.5 /);
    assert.match(attributes.d, / L286\.5,140\.5$/);
    cleanup();
});
