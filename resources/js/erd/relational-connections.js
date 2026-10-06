import { getSmoothStepPath } from '../../../vendor/getartisanflow/wireflow/dist/alpineflow.bundle.esm.js';
import { relationalSelfLoopPath } from './relational-self-loop.js';

function handlePoint(container, nodeId, handleId, viewport) {
    const node = container.querySelector(`[data-flow-node-id="${CSS.escape(nodeId)}"]`);
    const handle = node?.querySelector(`[data-flow-handle-id="${CSS.escape(handleId)}"]`);
    if (!handle) return null;

    const bounds = handle.getBoundingClientRect();
    const canvas = container.getBoundingClientRect();
    const zoom = viewport.zoom || 1;

    return {
        x: (bounds.left + bounds.width / 2 - canvas.left - viewport.x) / zoom,
        y: (bounds.top + bounds.height / 2 - canvas.top - viewport.y) / zoom,
        position: handle.dataset.flowHandlePosition,
    };
}

export function refreshRelationalConnections(container, flow, nodeId = null) {
    for (const edge of flow.edges) {
        if (edge.type !== 'relational-self-loop' && edge.type !== 'smoothstep') continue;
        if (nodeId !== null && edge.source !== nodeId && edge.target !== nodeId) continue;

        const source = handlePoint(container, edge.source, edge.sourceHandle, flow.viewport);
        const target = handlePoint(container, edge.target, edge.targetHandle, flow.viewport);
        if (!source || !target) continue;

        // Keep the arrowhead outside the target row, as WireFlow does for markers.
        const markerOffset = edge.markerEnd ? 6 : 0;
        const direction = target.position === 'right' ? 1 : -1;
        const endpoints = {
            sourceX: source.x,
            sourceY: source.y,
            sourcePosition: source.position,
            targetX: target.x + direction * markerOffset,
            targetY: target.y,
            targetPosition: target.position,
        };
        const result = edge.type === 'relational-self-loop'
            ? relationalSelfLoopPath(endpoints)
            : getSmoothStepPath(endpoints);

        const path = flow.getEdgePathElement(edge.id);
        if (!path) continue;
        if (path.getAttribute('d') !== result.path) path.setAttribute('d', result.path);
        const hitPath = path.parentElement?.querySelector('path:first-child');
        if (hitPath && hitPath !== path && hitPath.getAttribute('d') !== result.path) {
            hitPath.setAttribute('d', result.path);
        }

        const label = container.querySelector(`[data-flow-edge-id="${CSS.escape(edge.id)}"].flow-edge-label:not(.flow-edge-label-start):not(.flow-edge-label-end)`);
        if (label) {
            label.style.left = `${result.labelPosition.x}px`;
            label.style.top = `${result.labelPosition.y}px`;
        }
    }
}

export function installRelationalConnections(container) {
    if (!container) return;
    const flow = window.Alpine.$data(container);
    if (!flow) return;

    // WireFlow's fast redraw writes a generic path after the drag event. Its
    // path omits column handles and custom edge types. Observe that write so
    // our correction runs in the same frame, after WireFlow has moved the node.
    const observer = new MutationObserver(() => refreshRelationalConnections(container, flow));
    observer.observe(container, { subtree: true, childList: true, attributes: true, attributeFilter: ['d'] });
    refreshRelationalConnections(container, flow);

    return () => observer.disconnect();
}
