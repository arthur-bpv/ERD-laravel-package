import assert from 'node:assert/strict';
import test from 'node:test';

import { relationalSelfLoopPath } from '../../resources/js/erd/relational-self-loop.js';

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
