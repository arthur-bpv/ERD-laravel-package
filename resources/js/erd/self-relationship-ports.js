function rectangleBorderPoint(center, halfWidth, halfHeight, direction) {
    const tx = Math.abs(direction.x) > 0.0001
        ? halfWidth / Math.abs(direction.x)
        : Number.MAX_VALUE;
    const ty = Math.abs(direction.y) > 0.0001
        ? halfHeight / Math.abs(direction.y)
        : Number.MAX_VALUE;
    const scale = Math.min(tx, ty);

    return { x: center.x + (direction.x * scale), y: center.y + (direction.y * scale) };
}

function diamondBorderPoint(center, halfWidth, halfHeight, direction) {
    const scale = 1 / Math.max(
        0.0001,
        (Math.abs(direction.x) / halfWidth) + (Math.abs(direction.y) / halfHeight),
    );

    return { x: center.x + (direction.x * scale), y: center.y + (direction.y * scale) };
}

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
    const length = Math.max(1, Math.hypot(dx, dy));
    const unit = { x: dx / length, y: dy / length };
    const perpendicular = { x: -unit.y, y: unit.x };
    const spread = 0.55;

    const points = {
        entityOut: rectangleBorderPoint(
            entityCenter, entityWidth / 2, entityHeight / 2,
            { x: unit.x + (perpendicular.x * spread), y: unit.y + (perpendicular.y * spread) },
        ),
        entityIn: rectangleBorderPoint(
            entityCenter, entityWidth / 2, entityHeight / 2,
            { x: unit.x - (perpendicular.x * spread), y: unit.y - (perpendicular.y * spread) },
        ),
        diamondOut: diamondBorderPoint(diamondCenter, diamondWidth / 2, diamondHeight / 2, perpendicular),
        diamondIn: diamondBorderPoint(
            diamondCenter, diamondWidth / 2, diamondHeight / 2,
            { x: -perpendicular.x, y: -perpendicular.y },
        ),
    };

    return Object.fromEntries(Object.entries(points).map(([role, point]) => [role, {
        x: Math.round(point.x) - 1,
        y: Math.round(point.y) - 1,
    }]));
}
