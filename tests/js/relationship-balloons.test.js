import test from 'node:test';
import assert from 'node:assert/strict';
import { installRelationshipBalloons, setRelationshipAttributeFollowing } from '../../resources/js/erd/relationship-balloons.js';

test('enables smooth following without affecting manual dragging', () => {
    const classes = new Set();
    const container = {
        querySelector: () => ({
            classList: {
                toggle(name, enabled) {
                    enabled ? classes.add(name) : classes.delete(name);
                },
            },
        }),
    };

    setRelationshipAttributeFollowing(container, 'relation-r1-attr-a1', true);
    assert.equal(classes.has('er-relation-attribute-following'), true);

    setRelationshipAttributeFollowing(container, 'relation-r1-attr-a1', false);
    assert.equal(classes.has('er-relation-attribute-following'), false);
});

test('keeps relationship attributes aligned with the label and saves a manual offset', () => {
    const previousObserver = globalThis.MutationObserver;
    const previousAnimationFrame = globalThis.requestAnimationFrame;
    const frames = [];
    const listeners = new Map();
    const classes = new Set();
    let disconnected = false;

    globalThis.requestAnimationFrame = (callback) => frames.push(callback);
    globalThis.MutationObserver = class {
        observe() {}
        disconnect() { disconnected = true; }
    };

    try {
        const anchor = { position: { x: 0, y: 0 } };
        const attribute = {
            id: 'relation-r1-attr-a1',
            data: { kind: 'relationship-attribute', relationId: 'r1', attrId: 'a1', offsetX: 12, offsetY: -8 },
            position: { x: 0, y: 0 },
        };
        const label = {
            dataset: { flowEdgeId: 'r1' },
            style: { left: '120px', top: '80px' },
            classList: { contains: () => false, toggle() {} },
        };
        const container = {
            querySelector: (selector) => selector === '.flow-viewport'
                ? null
                : { classList: { toggle: (name, enabled) => enabled ? classes.add(name) : classes.delete(name) } },
            querySelectorAll: () => [label],
            addEventListener: (name, callback) => listeners.set(name, callback),
        };
        const flow = {
            edges: [{ id: 'r1', data: { relationId: 'r1' } }],
            nodes: [attribute],
            getNode: (id) => id === 'relation-r1-attribute-anchor' ? anchor : null,
        };
        const saved = [];
        const cleanup = installRelationshipBalloons(container, () => flow, {
            onRelationAttributeDragEnd: (...args) => saved.push(args),
        });

        frames.shift()();
        assert.deepEqual(anchor.position, { x: 120, y: 80 });
        assert.deepEqual(attribute.position, { x: 132, y: 72 });
        assert.equal(classes.has('er-relation-attribute-following'), true);

        listeners.get('flow-node-drag-start')({ detail: { node: attribute } });
        attribute.position = { x: 151, y: 93 };
        label.style.left = '140px';
        listeners.get('flow-node-drag-end')({ detail: { node: attribute } });

        assert.deepEqual(saved, [['r1', 'a1', { x: 31, y: 13 }]]);
        assert.deepEqual({ x: attribute.data.offsetX, y: attribute.data.offsetY }, { x: 31, y: 13 });
        cleanup();
        assert.equal(disconnected, true);
    } finally {
        globalThis.MutationObserver = previousObserver;
        globalThis.requestAnimationFrame = previousAnimationFrame;
    }
});
