import { Alpine } from '../../../vendor/livewire/livewire/dist/livewire.esm';
import { installRelationshipBalloons } from './relationship-balloons';

/*
 * -------------------------------------------------------------------------
 * Reconciliação de arestas criadas por arrasto.
 *
 * Quando o usuário arrasta uma conexão, o AlpineFlow já cria a aresta no
 * cliente com um id aleatório (`e-{origem}-{destino}-{timestamp}-{n}`) e só
 * DEPOIS emite o evento `connect` — e o payload desse evento NÃO inclui o id
 * gerado. Ou seja: o servidor nunca fica sabendo qual é o id daquela aresta.
 *
 * Isso quebrava a limpeza em cascata: ao excluir uma tabela ou coluna, o
 * `flowRemoveEdges()` do servidor só conhecia os ids do seed, então as
 * relações desenhadas à mão sobravam na tela apontando para o vazio. Pior:
 * como a aresta provisória nunca era removida, uma segunda tentativa de
 * conectar o MESMO par de entidades era rejeitada pela biblioteca como
 * conexão duplicada — na prática, "não dá para criar relação nenhuma".
 *
 * A correção é tirar a autoridade do cliente: assim que a aresta provisória
 * nasce, nós a removemos. O `@connect` do WireFlow leva a conexão para o
 * servidor, que grava a relação com id próprio e devolve a aresta definitiva
 * via flowAddEdges. O usuário só percebe um piscar de ~1 quadro.
 *
 * IMPORTANTE — `$flow` NÃO tem um método `.on()`. O flowCanvas não mantém
 * pub/sub próprio; ele despacha um `CustomEvent` no elemento raiz
 * (`.flow-container`) para cada evento interno, com o nome `flow-<evento>`
 * (ex.: "connect" vira `flow-connect`, "edge-click" vira `flow-edge-click`).
 * Isso é DIFERENTE do bridge `@connect="onConnect"` do Blade, que o WireFlow
 * escuta separadamente para acionar métodos do Livewire. Uma versão anterior
 * chamava `flow.on(...)`, que sempre falhava em silêncio (guard defensivo
 * engolia o erro) — o painel de cardinalidade nunca abria e esta limpeza
 * nunca rodava.
 * -------------------------------------------------------------------------
 */
// Dois requestAnimationFrame seguidos garantem que o navegador processou
// um ciclo completo de renderização entre a remoção e a adição — ver a nota
// grande em mutateRelation() (SchemaBoard.php) para o porquê disso importar.
function apósUmFrameDeVerdade(fn) {
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

                // Toda aresta provisória do AlpineFlow tem o formato
                // `e-{origem}-{destino}-{timestamp}-{contador}`. As
                // definitivas usam o id do servidor ("r1", "r2", ...).
                const provisorias = flow.edges
                    .filter((edge) => /^e-.+-\d+-\d+$/.test(edge.id))
                    .map((edge) => edge.id);

                if (provisorias.length) flow.removeEdges(provisorias);
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
             * Redesenho de aresta pedido pelo servidor (troca de
             * cardinalidade, inversão de sentido).
             *
             * O servidor manda o payload completo da aresta já pronto
             * (SchemaBoard::edgeFor) e só UM evento — quem decide COMO e
             * QUANDO fazer o remove+add é o cliente, porque só ele consegue
             * esperar um frame real do navegador entre as duas chamadas.
             * Chamar removeEdges()+addEdges() de volta a back no mesmo tick
             * (como fazíamos antes, via dois `flow:xxx` do WireFlow na
             * mesma resposta HTTP) reaproveita o <g> SVG existente sem
             * reavaliar marker-start/marker-end: o dado reativo
             * (`$flow.edges`) fica correto, mas o símbolo de cardinalidade
             * desenhado na tela continua sendo o antigo.
             */
        this.$wire.on('erd-rebuild-edge', ({ edges, removeIds = [], select }) => {
            const flow = this.$flow;
            if (!flow) return;

            flow.removeEdges([...new Set([...removeIds, ...edges.map((edge) => edge.id)])]);


            apósUmFrameDeVerdade(() => {
                if (edges.length) flow.addEdges(edges);
                if (select && edges.length) flow.selectedEdges?.add(edges[0].id);


            });
        });
        this.$wire.on('erd-sync-relation', ({ removeEdgeIds, removeNodeIds, nodes, edges, relationId }) => {
            const flow = this.$flow;
            if (!flow) return;
            flow.removeEdges(removeEdgeIds);
            flow.removeNodes(removeNodeIds);
            apósUmFrameDeVerdade(() => {
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
                entityOut: this.rectangleBorderPoint(
                    entityCenter, entityWidth / 2, entityHeight / 2,
                    { x: unit.x + (perpendicular.x * spread), y: unit.y + (perpendicular.y * spread) },
                ),
                entityIn: this.rectangleBorderPoint(
                    entityCenter, entityWidth / 2, entityHeight / 2,
                    { x: unit.x - (perpendicular.x * spread), y: unit.y - (perpendicular.y * spread) },
                ),
                diamondOut: this.diamondBorderPoint(
                    diamondCenter, diamondWidth / 2, diamondHeight / 2, perpendicular,
                ),
                diamondIn: this.diamondBorderPoint(
                    diamondCenter, diamondWidth / 2, diamondHeight / 2,
                    { x: -perpendicular.x, y: -perpendicular.y },
                ),
            };

            for (const [role, point] of Object.entries(points)) {
                ports[role].position.x = Math.round(point.x) - 1;
                ports[role].position.y = Math.round(point.y) - 1;
            }
        },

        rectangleBorderPoint(center, halfWidth, halfHeight, direction) {
            const tx = Math.abs(direction.x) > 0.0001
                ? halfWidth / Math.abs(direction.x)
                : Number.MAX_VALUE;
            const ty = Math.abs(direction.y) > 0.0001
                ? halfHeight / Math.abs(direction.y)
                : Number.MAX_VALUE;
            const scale = Math.min(tx, ty);

            return {
                x: center.x + (direction.x * scale),
                y: center.y + (direction.y * scale),
            };
        },

        diamondBorderPoint(center, halfWidth, halfHeight, direction) {
            const scale = 1 / Math.max(
                0.0001,
                (Math.abs(direction.x) / halfWidth) + (Math.abs(direction.y) / halfHeight),
            );

            return {
                x: center.x + (direction.x * scale),
                y: center.y + (direction.y * scale),
            };
        },
    }));
});
