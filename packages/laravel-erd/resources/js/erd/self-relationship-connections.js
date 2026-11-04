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
    const labelPosition = { x: (longest.a.x + longest.b.x) / 2, y: (longest.a.y + longest.b.y) / 2 };
    const bends = points.slice(1, -1).map((point, i) => ({ point, radius: i === 0 ? 8 : 20 }));
    if (reverse) { points.reverse(); bends.reverse(); }
    let path = `M${points[0].x},${points[0].y}`;
    for (let i = 1; i < points.length - 1; i++) {
        const [a, b, c] = [points[i - 1], points[i], points[i + 1]];
        const before = Math.hypot(b.x - a.x, b.y - a.y);
        const after = Math.hypot(c.x - b.x, c.y - b.y);
        const radius = Math.min(bends[i - 1].radius, before / 2, after / 2);
        path += ` L${b.x + (a.x - b.x) * radius / before},${b.y + (a.y - b.y) * radius / before}`;
        path += ` Q${b.x},${b.y} ${b.x + (c.x - b.x) * radius / after},${b.y + (c.y - b.y) * radius / after}`;
    }
    path += ` L${points.at(-1).x},${points.at(-1).y}`;
    return { path, labelPosition };
}

export function refreshSelfRelationshipConnections(container, flow) {
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
        const diamondSide = diamondPort.data.role === 'diamondOut' ? 'right' : 'left';
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
