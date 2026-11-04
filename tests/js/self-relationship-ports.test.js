import assert from 'node:assert/strict';
import test from 'node:test';

import { selfRelationshipPortPositions } from '../../packages/laravel-erd/resources/js/erd/self-relationship-ports.js';

test('keeps both recursive paths attached when the entity and diamond move together', () => {
    const entity = { position: { x: 100, y: 200 }, dimensions: { width: 232, height: 84 } };
    const diamond = { position: { x: 100, y: 40 }, dimensions: { width: 120, height: 44 } };
    const before = selfRelationshipPortPositions(entity, diamond);

    assert.equal(before.entityOut.y, 199);
    assert.equal(before.entityIn.y, 199);
    assert.notEqual(before.entityOut.x, before.entityIn.x);
    assert.notDeepEqual(before.diamondOut, before.diamondIn);

    const moved = selfRelationshipPortPositions(
        entity,
        { ...diamond, position: { x: 140, y: 90 } },
        { x: 140, y: 250 },
    );
    for (const role of Object.keys(before)) {
        assert.deepEqual(moved[role], { x: before[role].x + 40, y: before[role].y + 50 });
    }
});

test('anchors recursive paths to the left and right tips of the visible diamond', () => {
    const entity = { position: { x: 100, y: 200 } };
    const diamond = { position: { x: 156, y: 40 }, dimensions: { width: 120, height: 44 } };
    const ports = selfRelationshipPortPositions(entity, diamond);
    assert.deepEqual(ports.diamondOut, { x: 283, y: 61 });
    assert.deepEqual(ports.diamondIn, { x: 147, y: 61 });
});

for (const [direction, position] of Object.entries({
    right: { x: 500, y: 220 }, left: { x: -300, y: 220 },
    above: { x: 156, y: -100 }, below: { x: 156, y: 500 },
})) {
    test(`selects diamond tips and keeps entity ports attached with the diamond ${direction}`, () => {
        const entity = { position: { x: 100, y: 200 }, dimensions: { width: 232, height: 182 } };
        const diamond = { position, dimensions: { width: 120, height: 44 } };
        const ports = selfRelationshipPortPositions(entity, diamond);
        if (direction === 'right' || direction === 'left') {
            assert.equal(ports.diamondOut.x, ports.diamondIn.x);
            assert.equal(ports.diamondOut.y + 1, position.y + 50);
            assert.equal(ports.diamondIn.y + 1, position.y - 6);
            assert.equal(ports.entityOut.x + 1, direction === 'right' ? 332 : 100);
            assert.equal(ports.entityIn.x, ports.entityOut.x);
        } else {
            assert.equal(ports.diamondOut.y, ports.diamondIn.y);
            assert.equal(ports.diamondOut.x + 1, position.x + 128);
            assert.equal(ports.diamondIn.x + 1, position.x - 8);
            assert.equal(ports.entityOut.y + 1, direction === 'above' ? 200 : 382);
            assert.equal(ports.entityIn.y, ports.entityOut.y);
        }
    });
}
