import { Alpine } from '../../../vendor/livewire/livewire/dist/livewire.esm';
import { installRelationshipBalloons } from './relationship-balloons';
import { selfRelationshipPortPositions } from './self-relationship-ports';

/*
 * -------------------------------------------------------------------------
 * Reconcile edges created by dragging.
 *
 * AlpineFlow creates a temporary edge before it emits `connect`. The event
 * does not include that edge's generated ID, so the server cannot remove it.
 *
 * An orphaned temporary edge blocks later connections between the same pair
 * of entities and survives server side cascade deletion.
 *
 * Remove the temporary edge immediately. WireFlow's `@connect` sends the
 * connection to the server, which stores it and returns the definitive edge.
 *
 * `$flow` has no `.on()` method. The canvas dispatches `flow-*` CustomEvents
 * on `.flow-container`; WireFlow's Blade `@connect` bridge is separate.
 * -------------------------------------------------------------------------
 */
// Two animation frames let the browser render between removal and addition.
function afterTwoAnimationFrames(fn) {
    requestAnimationFrame(() => requestAnimationFrame(fn));
}

document.addEventListener('alpine:init', () => {
    Alpine.data('erdCanvas', () => ({
        selfOffsets: {},
        balloonsCleanup: null,

        init() {
            const container = this.$el.closest('.flow-container');
            if (!container) return;
            this.balloonsCleanup = installRelationshipBalloons(container, () => this.$flow, this.$wire);

            container.addEventListener('flow-connect', () => {
                const flow = this.$flow;
                if (!flow) return;

                // Temporary edges use `e-{source}-{target}-{timestamp}-{counter}`.
                // Definitive edges use server IDs such as `r1`.
                const temporaryEdges = flow.edges
                    .filter((edge) => /^e-.+-\d+-\d+$/.test(edge.id))
                    .map((edge) => edge.id);

                if (temporaryEdges.length) flow.removeEdges(temporaryEdges);
            });

            container.addEventListener('flow-node-drag-start', (ev) => {
                const entity = ev.detail?.node;
                if (!entity || entity.data?.kind) return;

                this.captureSelfRelationshipOffsets(entity);
            });

            container.addEventListener('flow-node-drag', (ev) => {
                const node = ev.detail?.node;
                if (!node) return;

                if (node.data?.isSelf) {
                    const entity = this.$flow?.getNode(node.data.from);
                    if (entity) this.updateSelfRelationshipGeometry(entity, node);
                    return;
                }

                if (!node.data?.kind) this.moveSelfRelationshipsWithEntity(node, ev.detail?.position);
            });

            container.addEventListener('flow-node-drag-end', (ev) => {
                const entity = ev.detail?.node;
                if (entity && !entity.data?.kind) delete this.selfOffsets[entity.id];
            });

            container.addEventListener('erd-node-resized', (ev) => {
                const entity = this.$flow?.getNode(ev.detail?.nodeId);
                if (!entity || entity.data?.kind) return;

                for (const relationId of this.selfRelationshipIds(entity)) {
                    const diamond = this.$flow.getNode(`relation-${relationId}`);
                    if (diamond) this.updateSelfRelationshipGeometry(entity, diamond);
                }
            });

            /*
             * Redraw an edge after a server side cardinality or direction change.
             *
             * The server sends the complete edge payload. The client waits
             * between removal and addition because same tick updates can reuse
             * the SVG group without refreshing marker-start or marker-end.
             */
            this.$wire.on('erd-rebuild-edge', ({ edges, removeIds = [], select }) => {
                const flow = this.$flow;
                if (!flow) return;

                flow.removeEdges([...new Set([...removeIds, ...edges.map((edge) => edge.id)])]);
                afterTwoAnimationFrames(() => {
                    if (edges.length) flow.addEdges(edges);
                    if (select && edges.length) flow.selectedEdges?.add(edges[0].id);
                });
            });
            this.$wire.on('erd-sync-relation', ({ removeEdgeIds, removeNodeIds, nodes, edges, relationId }) => {
                const flow = this.$flow;
                if (!flow) return;
                flow.removeEdges(removeEdgeIds);
                flow.removeNodes(removeNodeIds);
                afterTwoAnimationFrames(() => {
                    if (nodes.length) flow.addNodes(nodes);
                    if (edges.length) flow.addEdges(edges);
                    container.dispatchEvent(new CustomEvent('erd-relation-synced', {
                        detail: { relationId, relationship: nodes.find((node) => node.data?.kind === 'relationship')?.data, edges },
                    }));
                });
            });
        },

        destroy() {
            this.balloonsCleanup?.();
        },

        selfRelationshipIds(entity) {
            const flow = this.$flow;
            if (!flow) return [];

            return flow.nodes
                .filter((node) => node.data?.isSelf && node.data.from === entity.id)
                .map((node) => node.data.relationId);
        },

        captureSelfRelationshipOffsets(entity) {
            const flow = this.$flow;
            if (!flow) return;

            this.selfOffsets[entity.id] = this.selfRelationshipIds(entity)
                .map((relationId) => {
                    const diamond = flow.getNode(`relation-${relationId}`);
                    if (!diamond) return null;

                    return {
                        relationId,
                        x: diamond.position.x - entity.position.x,
                        y: diamond.position.y - entity.position.y,
                    };
                })
                .filter(Boolean);
        },

        moveSelfRelationshipsWithEntity(entity, position = entity.position) {
            const flow = this.$flow;
            if (!flow) return;

            for (const offset of this.selfOffsets[entity.id] ?? []) {
                const diamond = flow.getNode(`relation-${offset.relationId}`);
                if (!diamond) continue;

                diamond.position.x = position.x + offset.x;
                diamond.position.y = position.y + offset.y;
                this.updateSelfRelationshipGeometry(entity, diamond, position);
            }
        },

        updateSelfRelationshipGeometry(entity, diamond, entityPosition = entity.position) {
            const flow = this.$flow;
            if (!flow) return;

            const relationId = diamond.data?.relationId;
            const prefix = `relation-${relationId}-port-`;
            const ports = {
                entityOut: flow.getNode(`${prefix}entity-out`),
                entityIn: flow.getNode(`${prefix}entity-in`),
                diamondOut: flow.getNode(`${prefix}diamond-out`),
                diamondIn: flow.getNode(`${prefix}diamond-in`),
            };
            if (Object.values(ports).some((port) => !port)) return;

            const positions = selfRelationshipPortPositions(entity, diamond, entityPosition);
            for (const [role, position] of Object.entries(positions)) {
                ports[role].position.x = position.x;
                ports[role].position.y = position.y;
            }
        },

    }));
});
