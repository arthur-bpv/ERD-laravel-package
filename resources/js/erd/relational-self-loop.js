const LOOP_GAP = 72;
const CORNER_RADIUS = 16;

const outwardVector = (position) => ({
    top: { x: 0, y: -1 },
    right: { x: 1, y: 0 },
    bottom: { x: 0, y: 1 },
    left: { x: -1, y: 0 },
}[position] ?? { x: 0, y: 0 });

const midpoint = (from, to) => ({
    x: (from.x + to.x) / 2,
    y: (from.y + to.y) / 2,
});

const roundedPolyline = (points) => {
    let path = `M${points[0].x},${points[0].y}`;

    for (let index = 1; index < points.length - 1; index += 1) {
        const previous = points[index - 1];
        const current = points[index];
        const next = points[index + 1];
        const previousDistance = Math.hypot(previous.x - current.x, previous.y - current.y);
        const nextDistance = Math.hypot(next.x - current.x, next.y - current.y);
        const radius = Math.min(CORNER_RADIUS, previousDistance / 2, nextDistance / 2);
        const beforeCorner = {
            x: current.x + ((previous.x - current.x) / previousDistance) * radius,
            y: current.y + ((previous.y - current.y) / previousDistance) * radius,
        };
        const afterCorner = {
            x: current.x + ((next.x - current.x) / nextDistance) * radius,
            y: current.y + ((next.y - current.y) / nextDistance) * radius,
        };

        path += ` L${beforeCorner.x},${beforeCorner.y}`;
        path += ` Q${current.x},${current.y} ${afterCorner.x},${afterCorner.y}`;
    }

    const end = points.at(-1);

    return `${path} L${end.x},${end.y}`;
};

/**
 * Routes a recursive FK around the outside of its table. ArtisanFlow supplies
 * fresh endpoint coordinates after every node movement, so the loop follows
 * the table without storing absolute waypoints.
 */
export function relationalSelfLoopPath({
    sourceX,
    sourceY,
    sourcePosition,
    targetX,
    targetY,
    targetPosition,
}) {
    const source = { x: sourceX, y: sourceY };
    const target = { x: targetX, y: targetY };
    const sourceVector = outwardVector(sourcePosition);
    const targetVector = outwardVector(targetPosition);
    const sourceOutside = {
        x: source.x + sourceVector.x * LOOP_GAP,
        y: source.y + sourceVector.y * LOOP_GAP,
    };
    const targetOutside = {
        x: target.x + targetVector.x * LOOP_GAP,
        y: target.y + targetVector.y * LOOP_GAP,
    };
    const corner = sourceVector.x !== 0
        ? { x: sourceOutside.x, y: targetOutside.y }
        : { x: targetOutside.x, y: sourceOutside.y };

    const firstOuterLength = Math.hypot(
        corner.x - sourceOutside.x,
        corner.y - sourceOutside.y,
    );
    const secondOuterLength = Math.hypot(
        targetOutside.x - corner.x,
        targetOutside.y - corner.y,
    );
    const labelPosition = firstOuterLength >= secondOuterLength
        ? midpoint(sourceOutside, corner)
        : midpoint(corner, targetOutside);

    return {
        path: roundedPolyline([source, sourceOutside, corner, targetOutside, target]),
        labelPosition,
        labelOffsetX: Math.abs(target.x - source.x) / 2,
        labelOffsetY: Math.abs(target.y - source.y) / 2,
    };
}
