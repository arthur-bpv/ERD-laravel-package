import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { selfRelationshipPortPositions } from '../../packages/laravel-erd/resources/js/erd/self-relationship-ports.js';

test('flushes recursive connection nodes and paths during dragging, including the final position', async () => {
    const source = await readFile(new URL('../../packages/laravel-erd/resources/js/erd/canvas-controller.js', import.meta.url), 'utf8');
    const listeners = new Map();
    let createCanvas;
    const document = { addEventListener: (_, callback) => callback() };
    const window = { Alpine: { data: (_, factory) => { createCanvas = factory; } } };
    const controller = source.replace(/^import .*;\n/gm, '');
    new Function('document', 'window', 'installRelationshipBalloons', 'selfRelationshipPortPositions', 'installSelfRelationshipConnections', 'refreshSelfRelationshipConnections', controller)(
        document, window, () => () => {}, selfRelationshipPortPositions, () => () => {}, () => {},
    );

    const entity = { id: 'staff', position: { x: 100, y: 200 }, data: {} };
    const diamond = {
        id: 'relation-r1', position: { x: 100, y: 40 },
        data: { kind: 'relationship', isSelf: true, from: 'staff', relationId: 'r1' },
    };
    const ports = ['entity-out', 'entity-in', 'diamond-out', 'diamond-in'].map((role) => ({
        id: `relation-r1-port-${role}`, position: { x: 0, y: 0 }, data: { kind: 'port' },
    }));
    const nodes = [entity, diamond, ...ports];
    const calls = [];
    const flow = {
        nodes, getNode: (id) => nodes.find((node) => node.id === id),
        _flushNodePositions: (ids) => calls.push(['nodes', [...ids]]),
        _refreshEdgePaths: (ids) => calls.push(['edges', [...ids]]),
    };
    const container = { addEventListener: (name, callback) => listeners.set(name, callback) };
    const canvas = Object.assign(createCanvas(), {
        $el: { closest: () => container }, $flow: flow, $wire: { on() {} },
    });
    canvas.init();
    const emit = (name, node, position = node.position) => listeners.get(`flow-node-${name}`)({ detail: { node, position } });
    emit('drag-start', entity);
    entity.position = { x: 300, y: 350 };
    emit('drag', entity);
    assert.deepEqual(diamond.position, { x: 300, y: 190 });
    const expected = selfRelationshipPortPositions(entity, diamond);
    assert.deepEqual(ports.map((port) => port.position), Object.values(expected));
    assert.deepEqual(calls, [['nodes', nodes.map((node) => node.id)], ['edges', nodes.map((node) => node.id)]]);

    entity.position = { x: 310, y: 360 };
    emit('drag-end', entity);
    assert.deepEqual(diamond.position, { x: 310, y: 200 });
    assert.equal(calls.length, 4);
    assert.equal(canvas.selfOffsets.staff, undefined);

    diamond.position = { x: 500, y: 100 };
    emit('drag', diamond);
    assert.deepEqual(ports.map((port) => port.position), Object.values(selfRelationshipPortPositions(entity, diamond)));
    assert.equal(calls.length, 6);
});
