import { selfRelationshipPortPositions } from './self-relationship-ports.js';

function entitySide(port, entity) {
    const x = port.position.x + 1;
    const y = port.position.y + 1;
    const width = entity.dimensions?.width || 232;
    const height = entity.dimensions?.height || (84 + (entity.data?.attributes?.length || 0) * 24.5);
    return [
        ['top', Math.abs(y - entity.position.y)],
        ['bottom', Math.abs(y - entity.position.y - height)],
        ['left', Math.abs(x - entity.position.x)],
        ['right', Math.abs(x - entity.position.x - width)],
    ].sort((a, b) => a[1] - b[1])[0][0];
}

/** Orthogonal legs with a tighter departure and a broader return bend. */
export function selfRelationshipPath(start, end, diamondSide, side, reverse = false) {
    // Route in one coordinate frame and transform points before rendering.
    const vertical = diamondSide === 'top' || diamondSide === 'bottom';
    const toCanvas = ({ x, y }) => vertical ? { x: y, y: x } : { x, y };
    const coordinate = (point) => {
        const { x, y } = toCanvas(point);
        return `${x},${y}`;
    };
    if (vertical) {
        start = toCanvas(start);
        end = toCanvas(end);
        const rotatedSide = { top: 'left', bottom: 'right', left: 'top', right: 'bottom' };
        diamondSide = rotatedSide[diamondSide];
        side = rotatedSide[side];
    }
    const direction = diamondSide === 'right' ? 1 : -1;
    const outerX = direction > 0 ? Math.max(start.x + 32, end.x + 24) : Math.min(start.x - 32, end.x - 24);
    let points;
    if (side === 'top' || side === 'bottom') {
        const approachY = end.y + (side === 'top' ? -30 : 30);
        points = [start, { x: outerX, y: start.y }, { x: outerX, y: approachY }, { x: end.x, y: approachY }, end];
    } else {
        points = [start, { x: outerX, y: start.y }, { x: outerX, y: end.y }, end];
    }
    // Remove coincident points so overlapping alignments never produce NaN.
    points = points.filter((point, i) => i === 0 || point.x !== points[i - 1].x || point.y !== points[i - 1].y);
    const segments = points.slice(1).map((point, i) => ({ a: points[i], b: point,
        length: Math.hypot(point.x - points[i].x, point.y - points[i].y) }));
    const longest = segments.reduce((best, segment) => segment.length > best.length ? segment : best);
    const labelPosition = toCanvas({ x: (longest.a.x + longest.b.x) / 2, y: (longest.a.y + longest.b.y) / 2 });
    // Keep the bend nearest the table square, in either edge direction.
    const radii = points.slice(1, -1).map((_, i) => i === points.length - 3 ? 0 : (i === 0 ? 8 : 20));
    if (reverse) { points.reverse(); radii.reverse(); }
    let path = `M${coordinate(points[0])}`;
    for (let i = 1; i < points.length - 1; i++) {
        const [a, b, c] = [points[i - 1], points[i], points[i + 1]];
        const before = Math.hypot(b.x - a.x, b.y - a.y);
        const after = Math.hypot(c.x - b.x, c.y - b.y);
        const radius = Math.min(radii[i - 1], before / 2, after / 2);
        if (radius === 0) {
            path += ` L${coordinate(b)}`;
            continue;
        }
        path += ` L${coordinate({ x: b.x + (a.x - b.x) * radius / before, y: b.y + (a.y - b.y) * radius / before })}`;
        path += ` Q${coordinate(b)} ${coordinate({ x: b.x + (c.x - b.x) * radius / after, y: b.y + (c.y - b.y) * radius / after })}`;
    }
    path += ` L${coordinate(points.at(-1))}`;
    return { path, labelPosition };
}

export function refreshSelfRelationshipConnections(container, flow) {
    // Read the live nodes after Flow's drag/snap writes, including server updates.
    // Never let an auxiliary port retain an old entity position.
    for (const edge of flow.edges) {
        if (!edge.data?.isSelf || edge.data?.isAttributeLink) continue;
        const source = flow.getNode(edge.source);
        const target = flow.getNode(edge.target);
        if (!source || !target) continue;
        const diamondPort = source.data.role === 'diamondIn' ? source : target;
        const entityPort = diamondPort === source ? target : source;
        const diamond = flow.getNode(`relation-${edge.data.relationId}`);
        const entity = diamond && flow.getNode(diamond.data.from);
        if (!entity) continue;
        const positions = selfRelationshipPortPositions(entity, diamond);
        Object.assign(entityPort.position, positions[entityPort.data.role]);
        Object.assign(diamondPort.position, positions[diamondPort.data.role]);
        const verticalTips = positions.diamondOut.x === positions.diamondIn.x;
        const diamondSide = diamondPort.data.role === 'diamondOut'
            ? (verticalTips ? 'bottom' : 'right')
            : (verticalTips ? 'top' : 'left');
        const side = entitySide(entityPort, entity);
        const result = selfRelationshipPath(
            { x: diamondPort.position.x + 1, y: diamondPort.position.y + 1 },
            { x: entityPort.position.x + 1, y: entityPort.position.y + 1 },
            diamondSide, side, source === entityPort,
        );
        const path = flow.getEdgePathElement(edge.id);
        if (!path) continue;
        if (path.getAttribute('d') !== result.path) path.setAttribute('d', result.path);
        const hitPath = path.parentElement?.querySelector('path:first-child');
        if (hitPath && hitPath !== path && hitPath.getAttribute('d') !== result.path) hitPath.setAttribute('d', result.path);
        const label = container.querySelector(`[data-flow-edge-id="${CSS.escape(edge.id)}"].flow-edge-label:not(.flow-edge-label-start):not(.flow-edge-label-end)`);
        if (label) {
            label.style.left = `${result.labelPosition.x}px`;
            label.style.top = `${result.labelPosition.y}px`;
        }
    }
}

export function installSelfRelationshipConnections(container, getFlow) {
    // Flow also redraws after dispatching drag events. Correct that later write
    // using the updated ports, rather than waiting for a selection to redraw.
    const refresh = () => {
        const flow = getFlow();
        if (flow) refreshSelfRelationshipConnections(container, flow);
    };
    const observer = new MutationObserver(refresh);
    observer.observe(container, { subtree: true, childList: true, attributes: true, attributeFilter: ['d'] });
    refresh();
    return () => observer.disconnect();
}
