<div class="er-board flex h-screen min-h-0 flex-col overflow-hidden bg-slate-100 font-sans text-slate-800"
    x-data="{
        guideOpen: false,
        notice: '',
        noticeKind: 'success',
        noticeTimer: null,
        showNotice(message, kind = 'success') {
            this.notice = message;
            this.noticeKind = kind;
            window.clearTimeout(this.noticeTimer);
            this.noticeTimer = window.setTimeout(() => this.notice = '', 2800);
        },
    }"
    @saved.window="showNotice('Diagrama salvo.')">

    <x-board-header mode="er" :diagram-name="$diagramName" :relational-diagram-id="$relationalDiagramId"
        :relational-outdated="$relationalIsOutdated"
        :entities-count="count($entities)" :relations-count="count($relations)" />

    @if ($diagramName === 'Análise de alternativas ER → relacional')
        <div class="er-analysis-guide">
            <strong>Casos R01–R32:</strong> A e B mostram as cardinalidades de cada ponta; números ímpares não têm atributo e pares com “+” têm um balão. Os casos N:N exibem o retângulo associativo. Abaixo da matriz estão os casos recursivos, chave composta, UQ, entidade fraca, atributo multivalorado e relacionamento ternário.
        </div>
    @endif

    <div x-show="guideOpen" x-cloak class="er-guide" @keydown.escape.window="guideOpen = false">
        <div class="er-guide-intro">
            <span class="er-guide-step">1</span>
            <p><strong>Modele o domínio</strong><small>Crie entidades, identificadores e atributos.</small></p>
        </div>
        <span class="er-guide-arrow">→</span>
        <div class="er-guide-intro">
            <span class="er-guide-step">2</span>
            <p><strong>Defina as relações</strong><small><kbd>Alt</kbd> + arrastar conecta entidades.</small></p>
        </div>
        <div class="er-guide-cardinalities" aria-label="Cardinalidades">
            <span><b>&#8214;</b> 1:1</span>
            <span><b>&#9711;&#8739;</b> 0:1</span>
            <span><b>&#8739;&lt;</b> 1:N</span>
            <span><b>&#9711;&lt;</b> 0:N</span>
        </div>
        <button type="button" @click="guideOpen = false" aria-label="Fechar guia">✕</button>
    </div>
    {{-- ================= MODAL: JSON DO DIAGRAMA ================= --}}
    {{-- Mesmo componente usado pelo quadro Relacional, com o mesmo rodapé de copiar/baixar. --}}
    <x-json-modal title="JSON do diagrama" />

    {{-- Aviso flutuante: mesma marcação e mesmo ciclo do quadro Relacional. --}}
    <div x-show="notice" x-cloak x-transition class="er-notice" :class="{ 'is-warning': noticeKind === 'warning' }" role="status" aria-live="polite">
        <span x-text="noticeKind === 'warning' ? '!' : '✓'"></span>
        <p x-text="notice"></p>
    </div>

    {{-- ===================== CANVAS ===================== --}}
    <div class="relative min-h-0 flex-1 overflow-hidden">

        {{-- wire:ignore: o morph do Livewire não pode destruir o DOM do AlpineFlow.
             Sem :sync — o estado é do servidor e as mudanças chegam por comandos WireFlow. --}}
        <x-flow
            wire:ignore
            :nodes="$nodes"
            :edges="$edges"
            :controls="true"
            :minimap="true"
            background="dots"
            default-edge-type="floating"
            :config="[
                // easyConnect: segurando Alt dá para arrastar de QUALQUER ponto do
                // corpo da entidade — o AlpineFlow acha o handle mais próximo. É o
                // que dispensa ter um ponto de conexão fixo por coluna.
                'easyConnect' => true,
                'easyConnectKey' => 'alt',

                // loose: qualquer handle conecta em qualquer handle, então a rigidez
                // origem-à-direita / destino-à-esquerda deixa de existir.
                'connectionMode' => 'loose',

                // Raio de detecção do handle mais próximo no SOLTAR (drop), não
                // tem relação com o traçado da linha (esse é calculado à parte,
                // pela borda da entidade, por causa do tipo `floating`).
                // 60px só alcança bem perto do pontinho de 13px em si — soltar
                // no meio de uma caixa de 232px de largura ficava fora do raio
                // e a conexão simplesmente não se formava. 160px cobre soltar
                // em qualquer ponto do corpo de uma entidade típica.
                'connectionSnapRadius' => 160,

                'connectionLineType' => 'smoothstep',
                'connectionLineStyle' => [
                    'stroke' => '#6366f1',
                    'strokeWidth' => 1.6,
                    'strokeDasharray' => '6 3',
                ],
            ]"
            @connect="onConnect"
            @node-drag-end="onNodeDragEnd"
            style="width: 100%; height: 100%;"
        >
            <x-slot:node>
                {{-- Autorrelacionamento: é um nó real e externo à entidade. Assim,
                     cada papel ocupa uma linha independente e nenhuma curva
                     atravessa o conteúdo da tabela. --}}
                <template x-if="node.data.kind === 'relationship'">
                    <div class="er-relationship" :class="{ 'is-associative': node.data.associative, 'is-self': node.data.isSelf }" :data-id="node.id">
                        <div
                            class="er-diamond"
                            :class="{ 'is-incomplete': !node.data.complete }"
                            x-text="node.data.name"
                            @click.stop="$dispatch('erd-open-relation', {
                                relationId: node.data.relationId,
                                relationship: node.data,
                                x: $event.clientX,
                                y: $event.clientY,
                            })"
                            @dblclick.stop="
                                const nome = window.prompt('Renomear relacionamento', node.data.name);
                                if (nome) $wire.renameRelation(node.data.relationId, nome);
                            "
                            title="Duplo clique para renomear"
                        ></div>
                    </div>
                </template>

                <template x-if="node.data.kind === 'relationship-port'">
                    <div class="er-relationship-port nodrag" aria-hidden="true"></div>
                </template>

                <template x-if="node.data.kind === 'relationship-attribute-anchor'">
                    <div class="er-relationship-attribute-anchor nodrag" aria-hidden="true"></div>
                </template>

                <template x-if="node.data.kind === 'relationship-attribute'">
    <div class="er-relation-attr" :data-id="node.id">
        <span
            class="er-relation-attr-name nodrag"
            x-text="node.data.name"
            @dblclick.stop="
                const nome = window.prompt('Renomear atributo', node.data.name);
                if (nome) $wire.renameRelationAttribute(node.data.relationId, node.data.attrId, nome);
            "
            title="Duplo clique para renomear"
        ></span>

        <button
            class="er-relation-attr-del nodrag"
            type="button"
            aria-label="Remover atributo do relacionamento"
            title="Remover atributo"
            @click.stop="$wire.removeRelationAttribute(node.data.relationId, node.data.attrId)"
        >✕</button>
    </div>
</template>

                {{-- ================= ENTIDADE ================= --}}
                <template x-if="!node.data.kind">
                <div class="er-node" :data-id="node.id" x-erd-measure-node="node">

                    {{--
                        8 pontos de conexão: 4 `source` nos lados (iniciam o
                        arrasto) + 4 `target` nos cantos (recebem o solto).

                        Duas tentativas anteriores erraram aqui:

                        1ª: um par source+target empilhado exatamente na mesma
                        posição em cada lado. O `target` vinha depois no DOM e
                        ficava por cima, roubando todo clique — e um handle
                        `target` sozinho só reage quando já existe uma conexão
                        pendente (clique-para-completar). Sem isso, o clique
                        atravessava pro node e arrastava a entidade inteira.

                        2ª: um único handle `source` por lado, apostando que
                        `connectionMode: 'loose'` bastaria para ele também
                        servir de destino. A busca de proximidade (Vt, usada
                        durante o arrasto) até respeita loose e acha handles
                        `source` como alvo — mas o CÓDIGO DE SOLTAR tem um
                        fallback (`elementFromPoint(...).closest('[data-flow-
                        handle-type="target"]')`) fixo em "target", ignorando
                        loose. Sem handle target nenhum, essa via de finalizar
                        a conexão nunca encontra nada.

                        A saída é manter os dois tipos, só que em posições
                        DIFERENTES: `source` no meio de cada lado (top/right/
                        bottom/left), `target` nos 4 cantos (a diretiva aceita
                        dois modificadores de posição juntos — `.top.left`
                        vira o canto "top-left"). Nada mais se sobrepõe.

                        `connectable.end` só entra nos `target`: lê
                        node.data.canBeParent — entidade sem PK/UQ recusa a
                        conexão antes mesmo de ir ao servidor.
                    --}}
                    {{-- escritos um a um porque o modificador de posição (.top, .right...)
                         faz parte do nome da diretiva — Alpine não aceita modificador
                         vindo de variável, então um x-for não daria conta. --}}
                    <div class="er-anchor er-anchor-top"    x-flow-handle:source.top="'top'"></div>
                    <div class="er-anchor er-anchor-right"  x-flow-handle:source.right="'right'"></div>
                    <div class="er-anchor er-anchor-bottom" x-flow-handle:source.bottom="'bottom'"></div>
                    <div class="er-anchor er-anchor-left"   x-flow-handle:source.left="'left'"></div>
                    {{-- Âncora estrutural do autorrelacionamento. Não participa
                         do gesto manual e fixa a saída no vértice superior direito. --}}
                    <div class="er-self-anchor er-self-anchor-top" x-flow-handle:target.top="'self-top'"></div>
                    <div class="er-self-anchor er-self-anchor-right" x-flow-handle:target.right="'self-right'"></div>
                    <div class="er-self-anchor er-self-anchor-bottom" x-flow-handle:target.bottom="'self-bottom'"></div>
                    <div class="er-self-anchor er-self-anchor-left" x-flow-handle:target.left="'self-left'"></div>

                    <div class="er-anchor er-anchor-tl" x-flow-handle:target.top.left="'tl'"     x-flow-handle-connectable.end="node.data.canBeParent"></div>
                    <div class="er-anchor er-anchor-tr" x-flow-handle:target.top.right="'tr'"    x-flow-handle-connectable.end="node.data.canBeParent"></div>
                    <div class="er-anchor er-anchor-bl" x-flow-handle:target.bottom.left="'bl'"  x-flow-handle-connectable.end="node.data.canBeParent"></div>
                    <div class="er-anchor er-anchor-br" x-flow-handle:target.bottom.right="'br'" x-flow-handle-connectable.end="node.data.canBeParent"></div>

                    {{-- cabeçalho: nome da entidade --}}
                    <div class="er-head" :class="{ 'is-orphan': !node.data.canBeParent }">
                        <span class="er-head-icon">▦</span>
                        <div
                            class="er-head-name-wrap nodrag"
                            x-data="{ editing: false, draft: '' }"
                            @dblclick.stop="draft = node.data.name; editing = true; $nextTick(() => { $refs.entityName.focus(); $refs.entityName.select(); })"
                        >
                            <button
                                x-show="!editing"
                                class="er-head-name nodrag"
                                x-text="node.data.name"
                                @click.stop="draft = node.data.name; editing = true; $nextTick(() => { $refs.entityName.focus(); $refs.entityName.select(); })"
                                :aria-label="'Renomear entidade ' + node.data.name"
                                title="Clique para renomear"
                            ></button>
                            <input
                                x-show="editing"
                                x-ref="entityName"
                                x-model="draft"
                                class="er-head-name-input nodrag"
                                aria-label="Nome da entidade"
                                @pointerdown.stop
                                @click.stop
                                @keydown.enter.stop.prevent="$event.target.blur()"
                                @keydown.escape.stop.prevent="draft = node.data.name; editing = false"
                                @blur="if (draft.trim() && draft.trim() !== node.data.name) $wire.renameEntity(node.id, draft.trim()); editing = false"
                            >
                        </div>
                        <button
                            class="er-head-self nodrag"
                            title="Criar autorrelacionamento"
                            @click.stop="$wire.createSelfRelation(node.id)"
                        >↻</button>
                        <button
                            class="er-head-del nodrag"
                            title="Excluir entidade"
                            @click="if (window.confirm('Excluir a entidade \'' + node.data.name + '\'?')) $wire.deleteEntity(node.id)"
                        >✕</button>
                    </div>

                    {{-- aviso: sem identificador a entidade não pode receber relação --}}
                    <div class="er-warn" x-show="!node.data.canBeParent" x-cloak>
                        sem PK/UQ — não pode ser referenciada
                    </div>

                    {{-- linhas: atributos --}}
                    <div class="er-rows">
                        <template x-for="attr in node.data.attributes" :key="attr.id">
                            <div class="er-row"
                                 :class="{
                                     'is-pk': attr.key === 'PK',
                                     'is-used': node.data.usedAttrs.includes(attr.id),
                                 }">

                                {{-- coluna curta de chave (PK/FK/UQ) --}}
                                <button
                                    class="er-key nodrag"
                                    :class="'k-' + (attr.key || 'none').toLowerCase()"
                                    @click="$wire.cycleKey(node.id, attr.id)"
                                    title="Clique para alternar a chave primária"
                                >
                                    <span x-show="attr.key === 'PK'">🔑</span>
                                    <span x-show="attr.key !== 'PK'" class="er-key-empty">•</span>
                                </button>

                                {{-- nome do atributo --}}
                                <input
                                    type="text"
                                    class="er-attr-name-input nodrag"
                                    x-data="{ draft: attr.name }"
                                    x-model="draft"
                                    :aria-label="'Nome do atributo ' + attr.name"
                                    @pointerdown.stop
                                    @click.stop
                                    @keydown.enter.stop.prevent="$event.target.blur()"
                                    @keydown.escape.stop.prevent="draft = attr.name; $event.target.blur()"
                                    @blur="
                                        const name = draft.trim();
                                        if (name && name !== attr.name) {
                                            $wire.renameAttribute(node.id, attr.id, draft.trim());
                                        } else {
                                            draft = attr.name;
                                        }
                                    "
                                >



                                {{-- excluir atributo --}}
                                <button
                                    class="er-attr-del nodrag"
                                    title="Remover coluna"
                                    @click="$wire.removeAttribute(node.id, attr.id)"
                                >✕</button>
                            </div>
                        </template>
                    </div>

                    {{-- rodapé: criar atributo DENTRO da caixa da entidade --}}
                    <div class="er-foot nodrag" x-data="{ open: false, n: '', k: '' }">
                        <button class="er-add-toggle" @click="open = !open" x-text="open ? '− cancelar' : '+ atributo'"></button>

                        <div x-show="open" x-cloak class="er-add-form" @keydown.enter.prevent="
                            if (n.trim()) { $wire.addAttribute(node.id, n.trim(), 'varchar', k); n=''; k=''; open=false; }
                        ">
                            <input class="er-add-input" x-model="n" placeholder="nome" @pointerdown.stop>
                            <select class="er-add-select er-add-key" x-model="k" @pointerdown.stop>
                                <option value="">—</option>
                                <option value="PK">PK</option>
                            </select>
                            <button class="er-add-confirm" @click="if (n.trim()) { $wire.addAttribute(node.id, n.trim(), 'varchar', k); n=''; k=''; open=false; }">ok</button>
                        </div>
                    </div>
                </div>
                </template>
            </x-slot:node>

            {{--
                Painel invisível que só existe para hospedar o erdCanvas.

                Ele precisa estar DENTRO do <x-flow> porque se inscreve no store do
                AlpineFlow ($flow.on) para descartar a aresta provisória criada pelo
                arrasto — quem grava a relação de verdade é o servidor.
            --}}
            <x-flow-panel position="top-left" class="er-hidden-host" x-data="erdCanvas"></x-flow-panel>

            {{-- ================= EDITOR DE RELACIONAMENTO ================= --}}
            {{--
                Clique ESQUERDO sobre a relação abre este painel flutuante,
                ancorado no cursor.

                `x-flow-context-menu` (usado antes) só reage a botão direito —
                é assim que a biblioteca implementa esse componente, não dá pra
                trocar por prop. Em vez dele, ouvimos o evento nativo
                `edge-click` direto no store do AlpineFlow (mesmo mecanismo
                que erdCanvas já usa para `connect`) e controlamos a posição e
                a visibilidade do painel nós mesmos.
            --}}
            <div
                x-data="erdEdgeEditor"
                x-show="open"
                x-cloak
                class="er-edge-editor"
                :style="{ left: panelX + 'px', top: panelY + 'px' }"
                @click.stop
                @click.outside="close()"
                @keydown.escape.window="close()"
            >
                <template x-if="e">
                    <div>
                        <div class="er-ee-head">
                            <span>Relacionamento</span>
                            <button class="er-ee-close" title="Fechar" @click="close()">✕</button>
                        </div>
                        <div class="er-ee-tabs" role="tablist" aria-label="Gerenciar relacionamento">
                            <button type="button" role="tab" :aria-selected="tab === 'relationship'" :class="{ 'is-active': tab === 'relationship' }" @click="tab = 'relationship'">Relacionamento</button>
                            <button type="button" role="tab" :aria-selected="tab === 'attributes'" :class="{ 'is-active': tab === 'attributes' }" @click="tab = 'attributes'">Atributos <span x-text="attributes.length"></span></button>
                        </div>
                        <div x-show="tab === 'relationship'">

                        {{-- nome que aparece dentro do losango --}}
                        <button class="er-ee-name nodrag" @click="$refs.relationName.focus(); $refs.relationName.select()" title="Editar nome">
                            <span class="er-ee-diamond" x-text="e.data?.relationName || e.label || 'sem nome'"></span>
                        </button>
                        <form class="er-ee-rename" @submit.prevent="rename()">
                            <label for="relation-name-editor">Nome</label>
                            <input
                                id="relation-name-editor"
                                x-ref="relationName"
                                x-model="relationName"
                                @keydown.escape.stop.prevent="close()"
                                placeholder="Nome do relacionamento"
                            >
                            <button type="submit">Aplicar</button>
                        </form>

                        <form x-show="isSelf" x-cloak class="er-ee-roles" @submit.prevent="updateRoles()">
                            <div class="er-ee-roles-title">Papéis do auto-relacionamento</div>
                            <label>
                                <span>Papel de origem</span>
                                <input x-model="fromRole" maxlength="80" placeholder="Ex.: subordinado">
                            </label>
                            <label>
                                <span>Papel de destino</span>
                                <input x-model="toRole" maxlength="80" placeholder="Ex.: supervisor">
                            </label>
                            <button type="submit">Aplicar papéis</button>
                        </form>


                        {{-- ponta ORIGEM (filho / FK) = markerStart --}}
                        <div class="er-ee-end">
                            <div class="er-ee-label" x-text="isSelf ? roleLabel(e.data?.fromRole, 'Origem') : (e.data?.sourceName || e.source)">
                            </div>
                            <div class="er-ee-opts">
                                <template x-for="o in options" :key="'s'+o.m">
                                    <button
                                        class="er-ee-opt"
                                        :class="{ 'is-active': tipo(markerFor('markerStart')) === o.m }"
                                        :title="o.t"
                                        @click="setEnd('markerStart', o.m)"
                                        x-text="o.s"
                                    ></button>
                                </template>
                            </div>
                        </div>

                        {{-- ponta DESTINO (pai / PK) = markerEnd --}}
                        <div class="er-ee-end">
                            <div class="er-ee-label" x-text="isSelf ? roleLabel(e.data?.toRole, 'Destino') : (e.data?.targetName || e.target)">
                            </div>
                            <div class="er-ee-opts">
                                <template x-for="o in options" :key="'t'+o.m">
                                    <button
                                        class="er-ee-opt"
                                        :class="{ 'is-active': tipo(markerFor('markerEnd')) === o.m }"
                                        :title="o.t"
                                        @click="setEnd('markerEnd', o.m)"
                                        x-text="o.s"
                                    ></button>
                                </template>
                            </div>
                        </div>

                        <div class="er-ee-actions">
                            <button
                                class="er-ee-btn"
                                @click="swap()"
                                x-text="isSelf ? '⇄ Trocar papéis' : '⇄ Inverter direção'"
                            ></button>
                            <button class="er-ee-btn er-ee-danger" @click="remove()">🗑 Excluir</button>
                        </div>
                        </div>
                        <div x-show="tab === 'attributes'" class="er-ee-attributes">
                            <p>Os atributos descrevem cada ocorrência deste relacionamento.</p>
                            <template x-for="attribute in attributes" :key="attribute.id">
                                <div class="er-ee-attribute">
                                    <span x-text="attribute.name"></span>
                                    <button type="button" @click="renameAttribute(attribute)" title="Renomear atributo">✎</button>
                                    <button type="button" @click="removeAttribute(attribute)" title="Excluir atributo">✕</button>
                                </div>
                            </template>
                            <form class="er-ee-add-attribute" @submit.prevent="addAttribute()">
                                <input x-model="attributeName" maxlength="80" placeholder="Nome do atributo" aria-label="Nome do atributo">
                                <button type="submit">+ Adicionar</button>
                            </form>
                        </div>
                        <p class="er-ee-feedback" x-show="feedback" x-text="feedback" role="status" aria-live="polite"></p>
                    </div>
                </template>
            </div>

        </x-flow>
    </div>


</div>
