import assert from 'node:assert/strict';
import test from 'node:test';

import { relationalSelfLoopPath } from '../../resources/js/erd/relational-self-loop.js';

test('routes a right-to-top self relationship outside the table', () => {
    const result = relationalSelfLoopPath({
        sourceX: 290,
        sourceY: 75,
        sourcePosition: 'right',
        targetX: 145,
        targetY: 0,
        targetPosition: 'top',
    });

    assert.equal(result.path, 'M290,75 L346,75 Q362,75 362,59 L362,-56 Q362,-72 346,-72 L161,-72 Q145,-72 145,-56 L145,0');
    assert.ok(result.labelPosition.x > 290 || result.labelPosition.y < 0);
});

test('rotates the external loop for every recursive handle pair', () => {
    const cases = [
        ['right', 'top', { x: 290, y: 75 }, { x: 145, y: 0 }],
        ['bottom', 'right', { x: 145, y: 150 }, { x: 290, y: 75 }],
        ['left', 'bottom', { x: 0, y: 75 }, { x: 145, y: 150 }],
        ['top', 'left', { x: 145, y: 0 }, { x: 0, y: 75 }],
    ];

    for (const [sourcePosition, targetPosition, source, target] of cases) {
        const result = relationalSelfLoopPath({
            sourceX: source.x,
            sourceY: source.y,
            sourcePosition,
            targetX: target.x,
            targetY: target.y,
            targetPosition,
        });

        assert.match(result.path, /^M[-\d.]+,[-\d.]+ L/);
        assert.match(result.path, / Q/);
        assert.ok(Number.isFinite(result.labelPosition.x));
        assert.ok(Number.isFinite(result.labelPosition.y));
    }
});
