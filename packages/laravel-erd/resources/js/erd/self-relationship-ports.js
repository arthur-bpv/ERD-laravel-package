/** Return canvas positions for the two paths of a recursive ER relationship. */
export function selfRelationshipPortPositions(entity, diamond, entityPosition = entity.position) {
    const entityWidth = entity.dimensions?.width || 232;
    const entityHeight = entity.dimensions?.height
        || (84 + ((entity.data?.attributes?.length || 0) * 24.5));
    const diamondWidth = diamond.dimensions?.width || 120;
    const diamondHeight = diamond.dimensions?.height || 44;
    const entityCenter = {
        x: entityPosition.x + (entityWidth / 2),
        y: entityPosition.y + (entityHeight / 2),
    };
    const diamondCenter = {
        x: diamond.position.x + (diamondWidth / 2),
        y: diamond.position.y + (diamondHeight / 2),
    };

    const dx = diamondCenter.x - entityCenter.x;
    const dy = diamondCenter.y - entityCenter.y;
    const inset = 12;
    const horizontal = Math.abs(dx) / (entityWidth / 2 + diamondWidth / 2)
        > Math.abs(dy) / (entityHeight / 2 + diamondHeight / 2);
    let entityOut, entityIn;
    if (horizontal) {
        const x = entityPosition.x + (dx > 0 ? entityWidth : 0);
        entityOut = { x, y: entityPosition.y + entityHeight - inset };
        entityIn = { x, y: entityPosition.y + inset };
    } else {
        const y = entityPosition.y + (dy > 0 ? entityHeight : 0);
        entityOut = { x: entityPosition.x + entityWidth - inset, y };
        entityIn = { x: entityPosition.x + inset, y };
    }
    const points = {
        entityOut, entityIn,
        diamondOut: { x: diamond.position.x + diamondWidth + 8, y: diamondCenter.y },
        diamondIn: { x: diamond.position.x - 8, y: diamondCenter.y },
    };

    return Object.fromEntries(Object.entries(points).map(([role, point]) => [role, {
        x: Math.round(point.x) - 1,
        y: Math.round(point.y) - 1,
    }]));
}
