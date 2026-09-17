import test from 'node:test';
import assert from 'node:assert/strict';
import { setRelationshipAttributeFollowing } from '../../resources/js/erd/relationship-balloons.js';

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
