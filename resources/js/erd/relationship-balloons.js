// A aresta floating continua responsável pelos handles, percurso e cardinalidade.
// Este módulo apenas decora o label N:N e posiciona os atributos em relação a ele.
export function setRelationshipAttributeFollowing(container, nodeId, following) {
    const escapedId = globalThis.CSS?.escape
        ? globalThis.CSS.escape(nodeId)
        : String(nodeId).replaceAll('"', '\\"');
    const element = container.querySelector(`[data-flow-node-id="${escapedId}"]`);
    element?.classList.toggle('er-relation-attribute-following', following);
}

export function installRelationshipBalloons(container, getFlow, wire) {
    const viewport = container.querySelector('.flow-viewport') || container;
    let scheduled = false;
    let draggedAttributeId = null;

    function sync() {
        scheduled = false;
        const flow = getFlow();
        if (!flow) return;
        const labels = new Map([...viewport.querySelectorAll('.flow-edge-label:not(.flow-edge-label-start):not(.flow-edge-label-end)')]
            .map((element) => [element.dataset.flowEdgeId, element]));

        for (const edge of flow.edges) {
            if (edge.data?.isAttributeLink || !edge.data?.relationId) continue;
            const relationId = edge.data.relationId;
            const label = labels.get(edge.id);
            if (label && label.classList.contains('er-associative-label') !== Boolean(edge.data.associative)) {
                label.classList.toggle('er-associative-label', Boolean(edge.data.associative));
            }

            const anchor = flow.getNode(`relation-${relationId}-attribute-anchor`);
            if (!anchor || !label) continue;
            const x = Number.parseFloat(label.style.left);
            const y = Number.parseFloat(label.style.top);
            if (!Number.isFinite(x) || !Number.isFinite(y)) continue;
            if (anchor.position.x !== x) anchor.position.x = x;
            if (anchor.position.y !== y) anchor.position.y = y;
            positionAttributes(flow, relationId, anchor);
        }
    }

    function positionAttributes(flow, relationId, anchor) {
        for (const node of flow.nodes) {
            if (node.data?.kind !== 'relationship-attribute' || node.data.relationId !== relationId) continue;
            const following = node.id !== draggedAttributeId;
            setRelationshipAttributeFollowing(container, node.id, following);
            if (!following) continue;
            const x = anchor.position.x + Number(node.data.offsetX || 0);
            const y = anchor.position.y + Number(node.data.offsetY || 0);
            if (node.position.x !== x) node.position.x = x;
            if (node.position.y !== y) node.position.y = y;
        }
    }

    function schedule() {
        if (scheduled) return;
        scheduled = true;
        requestAnimationFrame(sync);
    }

    const observer = new MutationObserver(schedule);
    observer.observe(viewport, { subtree: true, childList: true, attributes: true, attributeFilter: ['style', 'class'] });
    schedule();

    container.addEventListener('flow-node-drag-start', (event) => {
        if (event.detail?.node?.data?.kind === 'relationship-attribute') {
            draggedAttributeId = event.detail.node.id;
            setRelationshipAttributeFollowing(container, draggedAttributeId, false);
        }
    });
    container.addEventListener('flow-node-drag', (event) => {
        const node = event.detail?.node;
        if (!node) return;
        if (node.data?.kind === 'relationship' && node.data.isSelf) {
            positionAttributes(getFlow(), node.data.relationId, node);
        } else if (!node.data?.kind) {
            schedule();
        }
    });
    container.addEventListener('flow-node-drag-end', (event) => {
        const node = event.detail?.node;
        if (node?.data?.kind === 'relationship-attribute') {
            const relationId = node.data.relationId;
            const anchor = getFlow()?.getNode(`relation-${relationId}-attribute-anchor`)
                || getFlow()?.getNode(`relation-${relationId}`);
            if (!anchor) return;
            const offset = {
                x: Math.round(node.position.x - anchor.position.x),
                y: Math.round(node.position.y - anchor.position.y),
            };
            node.data.offsetX = offset.x;
            node.data.offsetY = offset.y;
            draggedAttributeId = null;
            wire.onRelationAttributeDragEnd(relationId, node.data.attrId, offset);
            schedule();
        } else {
            schedule();
        }
    });
    container.addEventListener('erd-node-resized', schedule);
    container.addEventListener('erd-relation-synced', schedule);
    return () => observer.disconnect();
}
