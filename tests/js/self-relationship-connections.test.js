import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { selfRelationshipPortPositions } from '../../packages/laravel-erd/resources/js/erd/self-relationship-ports.js';
import { selfRelationshipPath } from '../../packages/laravel-erd/resources/js/erd/self-relationship-connections.js';

test('restores rounded tip connections after the engine overwrites a path, then settles', async () => {
    const source = await readFile(new URL('../../packages/laravel-erd/resources/js/erd/self-relationship-connections.js', import.meta.url), 'utf8');
    const { installSelfRelationshipConnections, selfRelationshipPath } = new Function('CSS', 'selfRelationshipPortPositions', `${source.replace(/^import .*;\n/gm, '').replace(/export /g, '')}; return { installSelfRelationshipConnections, selfRelationshipPath };`)({ escape: (value) => value }, selfRelationshipPortPositions);
    const route = selfRelationshipPath({ x: 284, y: 62 }, { x: 320, y: 200 }, 'right', 'top');
    assert.match(route.path, /Q/);
    assert.doesNotMatch(route.path, /NaN|Infinity/);
    let refresh;
    let disconnected = false;
    const previousObserver = globalThis.MutationObserver;
    globalThis.MutationObserver = class {
        constructor(callback) { refresh = callback; }
        observe() {}
        disconnect() { disconnected = true; }
    };
    try {
        const entity = { id: 'staff', position: { x: 100, y: 200 }, dimensions: { width: 232, height: 84 } };
        const diamond = { id: 'relation-r1', position: { x: 156, y: 40 }, data: { from: 'staff' } };
        const entityOut = { id: 'out', position: { x: 270, y: 199 }, data: { role: 'entityOut' } };
        const diamondOut = { id: 'tip', position: { x: 283, y: 61 }, data: { role: 'diamondOut' } };
        const nodes = [entity, diamond, entityOut, diamondOut];
        let pathValue = 'old path';
        let writes = 0;
        const path = { getAttribute: () => pathValue, setAttribute: (_, value) => { pathValue = value; writes++; } };
        const flow = {
            edges: [{ id: 'r1:out', source: 'out', target: 'tip', data: { isSelf: true, relationId: 'r1' } }],
            getNode: (id) => nodes.find((node) => node.id === id), getEdgePathElement: () => path,
        };
        const cleanup = installSelfRelationshipConnections({ querySelector: () => null }, () => flow);
        assert.match(pathValue, /Q/, 'leg has rounded bends');
        assert.match(pathValue, /L284,62$/, 'leg ends at the right tip');
        pathValue = 'engine overwrite';
        refresh();
        assert.equal(writes, 2);
        refresh();
        assert.equal(writes, 2, 'unchanged paths must not trigger a mutation loop');
        entity.position = { x: 400, y: 500 };
        refresh();
        const moved = selfRelationshipPortPositions(entity, diamond);
        assert.deepEqual(entityOut.position, moved.entityOut, 'stale ports follow the current entity without a drag event');
        assert.ok(pathValue.startsWith(`M${moved.entityOut.x + 1},${moved.entityOut.y + 1}`));
        cleanup();
        assert.equal(disconnected, true);
    } finally {
        globalThis.MutationObserver = previousObserver;
    }
});

for (const side of ['top', 'bottom']) {
    test(`routes a horizontal relationship through the diamond ${side} tip`, () => {
        const route = selfRelationshipPath({ x: 600, y: 100 }, { x: 332, y: 212 }, side, 'right');
        assert.ok(route.path.startsWith('M600,100 L600,'), 'departs vertically from the diamond');
        assert.match(route.path, /L332,212$/);
        assert.doesNotMatch(route.path, /NaN|Infinity/);
    });
}
