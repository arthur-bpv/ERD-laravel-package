import { Alpine } from '../../../vendor/livewire/livewire/dist/livewire.esm';

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
        init() {
            const container = this.$el.closest('.flow-container');
            if (!container) return;

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
            this.$wire.on('erd-rebuild-edge', ({ edge, select }) => {
                const flow = this.$flow;
                if (!flow) return;

                flow.removeEdges([edge.id]);
                apósUmFrameDeVerdade(() => {
                    flow.addEdges([edge]);
                    if (select) flow.selectedEdges?.add(edge.id);
                });
            });
        },
    }));

    /*
     * ---------------------------------------------------------------------
     * Editor de relacionamento: abre com o clique ESQUERDO sobre a relação,
     * flutuando perto do cursor.
     *
     * x-flow-context-menu (usado antes) só reage a botão direito — é assim
     * que o componente da biblioteca funciona, não existe prop para trocar o
     * gatilho. Em vez dele, ouvimos o evento nativo `edge-click` direto no
     * store do AlpineFlow (mesmo mecanismo que erdCanvas já usa para
     * `connect`) e controlamos posição/visibilidade do painel aqui.
     *
     * A troca de cardinalidade NÃO tenta prever o resultado no cliente:
     * quem recria a aresta com o marcador novo é sempre o servidor
     * (setCardinality → mutateRelation), como já é a autoridade de estado
     * declarada no SchemaBoard. Uma versão anterior aplicava a troca
     * otimisticamente no cliente e DEPOIS o servidor recriava a aresta de
     * novo — dois remove+add concorrentes na mesma aresta, e o resultado
     * visual dependia de qual dos dois "vencia" a corrida.
     * ---------------------------------------------------------------------
     */
    Alpine.data('erdEdgeEditor', () => ({
        // paleta de cardinalidade (pé de galinha / notação IE)
        options: [
            { m: 'cf-one-one', s: '&#8214;', t: 'um e só um' },
            { m: 'cf-zero-one', s: '&#9711;|', t: 'zero ou um' },
            { m: 'cf-one', s: '|', t: 'um' },
            { m: 'cf-many', s: '&lt;', t: 'muitos' },
            { m: 'cf-one-many', s: '|&lt;', t: 'um ou muitos' },
            { m: 'cf-zero-many', s: '&#9711;&lt;', t: 'zero ou muitos' },
        ],

        open: false,
        panelX: 0,
        panelY: 0,
        activeEdgeId: null,

        // Ver nota em erdCanvas: $flow não tem .on() — os eventos chegam como
        // CustomEvent `flow-<evento>` despachados no elemento `.flow-container`.
        init() {
            const container = this.$el.closest('.flow-container');
            if (!container) return;

            container.addEventListener('flow-edge-click', (ev) => {
                const { edge, event } = ev.detail;
                this.activeEdgeId = edge.id;
                this._positionAt(event.clientX, event.clientY);
                this.open = true;
            });

            // clicar no canvas vazio ou em outra entidade fecha o painel
            container.addEventListener('flow-pane-click', () => this.close());
            container.addEventListener('flow-node-click', () => this.close());
        },

        // Ancora perto do cursor, mas sem deixar o painel estourar a borda
        // direita/inferior da tela (250x~340px é o tamanho real do painel).
        _positionAt(x, y) {
            const PANEL_W = 250;
            const PANEL_H = 340;

            this.panelX = Math.min(x + 12, window.innerWidth - PANEL_W - 12);
            this.panelY = Math.min(y + 12, window.innerHeight - PANEL_H - 12);
        },

        close() {
            this.open = false;
            this.activeEdgeId = null;
        },

        /**
         * A relação aberta no painel.
         *
         * Guardamos só o id porque trocar um marcador recria a aresta — um
         * retrato do objeto ficaria velho no segundo clique. Relemos o
         * estado vivo do $flow a cada acesso.
         */
        get e() {
            if (!this.activeEdgeId) return null;

            return this.$flow?.edges.find((edge) => edge.id === this.activeEdgeId) ?? null;
        },

        // markerStart/markerEnd chegam como objeto ({type, offset, color}),
        // porque é assim que se controla o recuo da linha. Para comparar com
        // a paleta precisamos só do tipo.
        tipo(marker) {
            return typeof marker === 'string' ? marker : marker?.type;
        },

        // Colunas da entidade indicada — alimenta o <select> de cada ponta do
        // relacionamento. Lê direto do node vivo no $flow, não de uma cópia,
        // então continua correto mesmo depois de add/remove de atributo.
        attrsOf(entityId) {
            return this.$flow?.nodes.find((n) => n.id === entityId)?.data?.attributes ?? [];
        },

        /**
         * Troca qual coluna participa da ponta 'from' (FK) ou 'to' (referenciada).
         *
         * Quem garante que a coluna pertence à entidade certa é o servidor,
         * em setRelationAttr — se ele recusar, o próximo re-render do
         * Livewire devolve a aresta ao estado anterior.
         */
        setAttr(end, attrId) {
            const edge = this.e;
            if (!edge) return;

            this.$wire.setRelationAttr(edge.id, end, attrId);
        },

        // campo = 'markerStart' (lado filho/FK) | 'markerEnd' (lado pai/PK).
        // Não fecha o painel: o usuário costuma testar mais de uma opção
        // seguida antes de decidir.
        setEnd(campo, marker) {
            const edge = this.e;
            if (!edge || this.tipo(edge[campo]) === marker) return;

            this.$wire.setCardinality(edge.id, campo === 'markerStart' ? 'child' : 'parent', marker);
        },

        rename() {
            const edge = this.e;
            if (!edge) return;

            const nome = window.prompt('Nome do relacionamento', edge.label || '');
            this.close();
            if (nome !== null && nome.trim() !== '') this.$wire.renameRelation(edge.id, nome.trim());
        },

        swap() {
            const edge = this.e;
            this.close();
            if (edge) this.$wire.swapRelation(edge.id);
        },

        remove() {
            const edge = this.e;
            this.close();
            if (edge) this.$wire.deleteRelation(edge.id);
        },
    }));
});
