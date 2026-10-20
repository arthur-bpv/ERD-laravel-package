import { Alpine } from '../../../vendor/livewire/livewire/dist/livewire.esm';

document.addEventListener('alpine:init', () => {
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
            { m: 'cf-one-one', s: '‖', t: 'um e só um' },
            { m: 'cf-zero-one', s: '○|', t: 'zero ou um' },
            { m: 'cf-one-many', s: '|<', t: 'um ou muitos' },
            { m: 'cf-zero-many', s: '○<', t: 'zero ou muitos' },
        ],

        open: false,
        panelX: 0,
        panelY: 0,
        activeEdgeId: null,
        activeRelationId: null,
        activeRelationship: null,
        relationName: '',
        fromRole: '',
        toRole: '',
        feedback: '',
        tab: 'relationship',
        attributeName: '',

        // Ver nota em erdCanvas: $flow não tem .on() — os eventos chegam como
        // CustomEvent `flow-<evento>` despachados no elemento `.flow-container`.
        init() {
            const container = this.$el.closest('.flow-container');
            if (!container) return;

            container.addEventListener('flow-edge-click', (ev) => {
                const { edge, event } = ev.detail;
                const selectedEdge = edge.data?.isAttributeLink
                    ? this.$flow?.edges.find((item) => this.relationId(item) === this.relationId(edge) && !item.data?.isAttributeLink) ?? edge
                    : edge;
                this.activeEdgeId = selectedEdge.id;
                this.activeRelationId = this.relationId(edge);
                this.activeRelationship = null;
                this.relationName = selectedEdge.data?.relationName || selectedEdge.label || '';
                this.fromRole = selectedEdge.data?.fromRole || '';
                this.toRole = selectedEdge.data?.toRole || '';
                this.feedback = '';
                this.tab = 'relationship';
                this._positionAt(event.clientX, event.clientY);
                this.open = true;
            });

            container.addEventListener('erd-open-relation', (ev) => {
                const { relationId, relationship, x, y } = ev.detail;

                // O AlpineFlow também identifica o losango como node. Abrir
                // na microtask garante que o flow-node-click do mesmo gesto
                // termine antes de exibirmos o editor da relação.
                queueMicrotask(() => {
                    const edge = this.$flow?.edges.find(
                        (item) => this.relationId(item) === relationId,
                    );
                    this.activeEdgeId = edge?.id ?? null;
                    this.activeRelationId = relationId;
                    this.activeRelationship = relationship;
                    this.relationName = relationship.name || relationship.relationName || '';
                    this.fromRole = relationship.fromRole || '';
                    this.toRole = relationship.toRole || '';
                    this.feedback = '';
                    this.tab = 'relationship';
                    this._positionAt(x, y);
                    this.open = true;
                });
            });

            // clicar no canvas vazio ou em outra entidade fecha o painel
            container.addEventListener('flow-pane-click', () => this.close());
            container.addEventListener('flow-node-click', () => this.close());
            container.addEventListener('erd-relation-synced', (ev) => {
                if (this.activeRelationId !== ev.detail.relationId) return;
                const main = ev.detail.edges.find((edge) => !edge.data?.isAttributeLink);
                this.activeEdgeId = main?.id ?? null;
                this.activeRelationship = ev.detail.relationship ?? null;
                if (this.activeRelationship) this.activeRelationship.relationId = ev.detail.relationId;
            });
            this.$wire.on('erd-swap-rejected', ({ relationId, message }) => {
                if (this.activeRelationId === relationId) this.feedback = message;
            });
        },

        // Ancora perto do cursor, mas sem deixar o painel estourar a borda
        // direita/inferior da tela (250x~340px é o tamanho real do painel).
        _positionAt(x, y) {
            const PANEL_W = 280;
            const PANEL_H = 420;

            this.panelX = Math.min(x + 12, window.innerWidth - PANEL_W - 12);
            this.panelY = Math.min(y + 12, window.innerHeight - PANEL_H - 12);
        },

        close() {
            this.open = false;
            this.activeEdgeId = null;
            this.activeRelationId = null;
            this.activeRelationship = null;
            this.relationName = '';
            this.fromRole = '';
            this.toRole = '';
            this.feedback = '';
        },

        /**
         * A relação aberta no painel.
         *
         * Guardamos só o id porque trocar um marcador recria a aresta — um
         * retrato do objeto ficaria velho no segundo clique. Relemos o
         * estado vivo do $flow a cada acesso.
         */
        get e() {
            if (!this.activeEdgeId) {
                if (!this.activeRelationship) return null;

                return {
                    id: this.activeRelationship.relationId,
                    source: this.activeRelationship.from,
                    target: this.activeRelationship.to,
                    markerStart: { type: this.activeRelationship.childCard },
                    markerEnd: { type: this.activeRelationship.parentCard },
                    data: {
                        relationId: this.activeRelationship.relationId,
                        relationName: this.activeRelationship.name,
                        sourceName: this.activeRelationship.sourceName,
                        targetName: this.activeRelationship.targetName,
                        isSelf: Boolean(this.activeRelationship.isSelf),
                        fromRole: this.activeRelationship.fromRole,
                        toRole: this.activeRelationship.toRole,
                        attributes: this.activeRelationship.attributes || [],
                    },
                };
            }

            return this.$flow?.edges.find((edge) => edge.id === this.activeEdgeId) ?? null;
        },

        // markerStart/markerEnd chegam como objeto ({type, offset, color}),
        // porque é assim que se controla o recuo da linha. Para comparar com
        // a paleta precisamos só do tipo.
        tipo(marker) {
            return typeof marker === 'string' ? marker : marker?.type;
        },

        relationId(edge) {
            return edge?.data?.relationId ?? edge?.id;
        },

        get isSelf() {
            return Boolean(this.e?.data?.isSelf);
        },

        roleLabel(role, fallback) {
            if (role === 'papel_origem') return 'Origem';
            if (role === 'papel_destino') return 'Destino';
            return role || fallback;
        },

        get attributes() {
            return this.e?.data?.attributes || [];
        },

        addAttribute() {
            const name = this.attributeName.trim();
            if (!name || !this.e) return;
            this.$wire.addRelationAttribute(this.relationId(this.e), name,);
            this.attributeName = '';
            this.feedback = 'Atributo adicionado.';
        },

        renameAttribute(attribute) {
            const name = window.prompt('Renomear atributo', attribute.name)?.trim();
            if (name && this.e) this.$wire.renameRelationAttribute(this.relationId(this.e), attribute.id, name);
        },

        removeAttribute(attribute) {
            if (this.e) this.$wire.removeRelationAttribute(this.relationId(this.e), attribute.id);
        },

        markerFor(campo) {
            const edge = this.e;
            if (!edge) return null;

            const relationId = this.relationId(edge);
            const relationEdge = this.$flow?.edges.find(
                (item) => this.relationId(item) === relationId && item[campo],
            );

            return relationEdge?.[campo] ?? null;
        },


        // campo = 'markerStart' (lado filho/FK) | 'markerEnd' (lado pai/PK).
        // Não fecha o painel: o usuário costuma testar mais de uma opção
        // seguida antes de decidir.
        setEnd(campo, marker) {
            const edge = this.e;
            if (!edge || this.tipo(this.markerFor(campo)) === marker) return;

            if (!this.activeEdgeId && this.activeRelationship) {
                this.activeRelationship[campo === 'markerStart' ? 'childCard' : 'parentCard'] = marker;
            }
            this.$wire.setCardinality(this.relationId(edge), campo === 'markerStart' ? 'child' : 'parent', marker);
        },

        rename() {
            const edge = this.e;
            if (!edge) return;

            const nome = this.relationName.trim();
            if (!nome) return;

            const relationId = this.relationId(edge);
            if (this.activeRelationship) {
                this.activeRelationship.name = nome;
                this.activeRelationship.relationName = nome;
            }
            if (edge.data) edge.data.relationName = nome;
            if (!edge.data?.isSelf && 'label' in edge) edge.label = nome;
            this.$wire.renameRelation(relationId, nome);
            this.feedback = 'Nome atualizado.';
        },

        updateRoles() {
            const edge = this.e;
            const fromRole = this.fromRole.trim();
            const toRole = this.toRole.trim();
            if (!edge || !this.isSelf) return;

            if (!fromRole || !toRole) {
                this.feedback = 'Informe os dois papéis.';
                return;
            }
            if (fromRole.toLocaleLowerCase() === toRole.toLocaleLowerCase()) {
                this.feedback = 'Os papéis precisam ter nomes diferentes.';
                return;
            }

            if (this.activeRelationship) {
                this.activeRelationship.fromRole = fromRole;
                this.activeRelationship.toRole = toRole;
            }
            this.$wire.renameSelfRelationRoles(this.relationId(edge), fromRole, toRole);
            this.feedback = 'Papéis atualizados.';
        },

        swap() {
            const edge = this.e;
            if (!edge) return;

            if (this.activeRelationship) {
                const relation = this.activeRelationship;
                [relation.from, relation.to] = [relation.to, relation.from];
                [relation.fromAttr, relation.toAttr] = [relation.toAttr, relation.fromAttr];
                [relation.childCard, relation.parentCard] = [relation.parentCard, relation.childCard];
                [relation.fromRole, relation.toRole] = [relation.toRole, relation.fromRole];
                [relation.sourceName, relation.targetName] = [relation.targetName, relation.sourceName];
            }

            this.$wire.swapRelation(this.relationId(edge));
            this.feedback = this.isSelf
                ? 'Papéis do autorrelacionamento trocados.'
                : 'Origem e destino invertidos.';
        },

        remove() {
            const edge = this.e;
            this.close();
            if (edge) this.$wire.deleteRelation(this.relationId(edge));
        },
    }));
});
