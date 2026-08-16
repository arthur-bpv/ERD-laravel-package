<div class="flex h-screen flex-col bg-slate-100 font-sans text-slate-800">

    {{-- ===================== HEADER ===================== --}}
    <header class="flex flex-wrap items-center gap-4 border-b border-slate-200 bg-white px-6 py-3 shadow-sm">
        <div class="flex items-center gap-2">
            <span class="text-xl">🗂️</span>
            <h1 class="text-lg font-bold">Modelador Entidade-Relacionamento</h1>
            <span class="hidden text-sm text-slate-400 sm:inline">— entidades, atributos e relacionamentos</span>
        </div>

        {{-- criar entidade (fica no DOM do Livewire, wire:model/wire:click funcionam) --}}
        <form wire:submit.prevent="createEntity" class="ml-auto flex items-center gap-2">
            <input
                type="text"
                wire:model="newEntityName"
                placeholder="nome_da_entidade"
                class="w-44 rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            >
            <button
                type="submit"
                class="flex items-center gap-1.5 rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-indigo-700"
            >
                <span class="text-base leading-none">+</span> Nova entidade
            </button>
        </form>

            <button
                wire:click="save"
                @saved.window="window.alert('✅ Diagrama salvo com sucesso!')"
                class="rounded-md bg-slate-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-800"
            >
                💾 Salvar
            </button>
            <button
        wire:click="toggleJson"
        class="rounded-md bg-slate-200 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-300"
    >
        { } Ver JSON
    </button>
    </header>
    {{-- ================= MODAL: JSON DO DIAGRAMA ================= --}}
<div
    x-show="$wire.showJson"
    x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-6"
    @click.self="$wire.toggleJson()"
    @keydown.escape.window="$wire.showJson = false"
>
    <div class="flex max-h-[80vh] w-full max-w-2xl flex-col rounded-lg bg-white shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <h2 class="text-sm font-bold">JSON do diagrama</h2>
            <div class="flex items-center gap-2">
                <button
                    x-data
                    @click="navigator.clipboard.writeText($refs.jsonBox.innerText)"
                    class="rounded-md bg-indigo-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-indigo-700"
                >
                    Copiar
                </button>
                <button wire:click="toggleJson" class="text-slate-400 hover:text-slate-600">✕</button>
            </div>
        </div>
        <pre x-ref="jsonBox" class="overflow-auto p-4 text-xs leading-relaxed text-slate-700">{{ $this->jsonPreview }}</pre>
    </div>
</div>

    {{-- ===================== CANVAS ===================== --}}
    <div class="relative flex-1 overflow-hidden">

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
                {{-- ================= ENTIDADE ================= --}}
                <div class="er-node" :data-id="node.id">

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

                    <div class="er-anchor er-anchor-tl" x-flow-handle:target.top.left="'tl'"     x-flow-handle-connectable.end="node.data.canBeParent"></div>
                    <div class="er-anchor er-anchor-tr" x-flow-handle:target.top.right="'tr'"    x-flow-handle-connectable.end="node.data.canBeParent"></div>
                    <div class="er-anchor er-anchor-bl" x-flow-handle:target.bottom.left="'bl'"  x-flow-handle-connectable.end="node.data.canBeParent"></div>
                    <div class="er-anchor er-anchor-br" x-flow-handle:target.bottom.right="'br'" x-flow-handle-connectable.end="node.data.canBeParent"></div>

                    {{-- cabeçalho: nome da entidade --}}
                    <div class="er-head" :class="{ 'is-orphan': !node.data.canBeParent }">
                        <span class="er-head-icon">▦</span>
                        <span
                            class="er-head-name"
                            x-text="node.data.name"
                            @dblclick="
                                const nome = window.prompt('Renomear entidade', node.data.name);
                                if (nome) $wire.renameEntity(node.id, nome);
                            "
                            title="Duplo clique para renomear"
                        ></span>
                        <span class="er-head-rels" x-show="node.data.relCount > 0"
                              x-text="node.data.relCount"
                              title="Relacionamentos ligados a esta entidade"></span>
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
                                    title="Clique para alternar PK / FK / UQ"
                                >
                                    <span x-show="attr.key === 'PK'">🔑</span>
                                    <span x-show="attr.key !== 'PK'" class="er-key-empty">•</span>
                                </button>

                                {{-- nome do atributo --}}
                                <span
                                    class="er-attr-name"
                                    x-text="attr.name"
                                    @dblclick="
                                        const nome = window.prompt('Renomear atributo', attr.name);
                                        if (nome) $wire.renameAttribute(node.id, attr.id, nome);
                                    "
                                    title="Duplo clique para renomear"
                                ></span>



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
                            if (n.trim()) { $wire.addAttribute(node.id, n.trim(), k); n=''; k=''; open=false; }
                        ">
                            <input class="er-add-input" x-model="n" placeholder="nome" @pointerdown.stop>
                            <select class="er-add-select er-add-key" x-model="k" @pointerdown.stop>
                                <option value="">—</option>
                                <option value="PK">PK</option>
                            </select>
                            <button class="er-add-confirm" @click="if (n.trim()) { $wire.addAttribute(node.id, n.trim(), k); n=''; k=''; open=false; }">ok</button>
                        </div>
                    </div>
                </div>
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
                :style="`left:${panelX}px; top:${panelY}px;`"
                @click.outside="close()"
                @keydown.escape.window="close()"
            >
                <template x-if="e">
                    <div>
                        <div class="er-ee-head">
                            <span>Relacionamento</span>
                            <button class="er-ee-close" title="Fechar" @click="close()">✕</button>
                        </div>

                        {{-- nome que aparece dentro do losango --}}
                        <button class="er-ee-name nodrag" @click="rename()" title="Clique para renomear">
                            <span class="er-ee-diamond" x-text="e.label || 'sem nome'"></span>
                        </button>

                        <div class="er-ee-path">
                            <span class="er-ee-tag" x-text="e.source"></span>
                            <span class="er-ee-arrow">→</span>
                            <span class="er-ee-tag" x-text="e.target"></span>
                        </div>

                        {{-- ponta ORIGEM (filho / FK) = markerStart --}}
                        <div class="er-ee-end">
                            <div class="er-ee-label">
                                Origem <em x-text="'(' + e.source + '.' + (e.labelStart || '?') + ')'"></em>
                            </div>
                            <div class="er-ee-opts">
                                <template x-for="o in options" :key="'s'+o.m">
                                    <button
                                        class="er-ee-opt"
                                        :class="{ 'is-active': tipo(e.markerStart) === o.m }"
                                        :title="o.t"
                                        @click="setEnd('markerStart', o.m)"
                                        x-html="o.s"
                                    ></button>
                                </template>
                            </div>
                        </div>

                        {{-- ponta DESTINO (pai / PK) = markerEnd --}}
                        <div class="er-ee-end">
                            <div class="er-ee-label">
                                Destino <em x-text="'(' + e.target + '.' + (e.labelEnd || '?') + ')'"></em>
                            </div>
                            <div class="er-ee-opts">
                                <template x-for="o in options" :key="'t'+o.m">
                                    <button
                                        class="er-ee-opt"
                                        :class="{ 'is-active': tipo(e.markerEnd) === o.m }"
                                        :title="o.t"
                                        @click="setEnd('markerEnd', o.m)"
                                        x-html="o.s"
                                    ></button>
                                </template>
                            </div>
                        </div>

                        <div class="er-ee-actions">
                            <button class="er-ee-btn" @click="swap()">⇄ Inverter</button>
                            <button class="er-ee-btn er-ee-danger" @click="remove()">🗑 Excluir</button>
                        </div>
                    </div>
                </template>
            </div>

            {{-- ================= LEGENDA ================= --}}
            {{--
                Vai no canto inferior DIREITO e começa recolhida.

                Em bottom-left ela caía exatamente sobre os controles de zoom do
                AlpineFlow (que ficam ali por padrão), e aberta o tempo todo
                cobria parte do diagrama.
            --}}
            <x-flow-panel position="bottom-right" class="er-legend" x-data="{ aberta: false }">
                <button class="er-legend-toggle" @click="aberta = !aberta">
                    <span x-text="aberta ? '✕' : '?'"></span>
                    <span x-show="!aberta">ajuda</span>
                </button>

                <div x-show="aberta" x-cloak class="er-legend-body">
                    <div class="er-legend-title">Cardinalidade (IE / pé de galinha)</div>
                    <div class="er-legend-grid">
                        <div><span class="er-sym">&#8214;</span> um e só um</div>
                        <div><span class="er-sym">&#9711;&#8739;</span> zero ou um</div>
                        <div><span class="er-sym">&#8739;&lt;</span> um ou muitos</div>
                        <div><span class="er-sym">&#9711;&lt;</span> zero ou muitos</div>
                    </div>
                    <div class="er-legend-hint">
                        Segure <kbd>Alt</kbd> e arraste de qualquer ponto de uma entidade até
                        outra para criar o relacionamento.
                        <strong>Clique com o botão direito na linha para editar nome e cardinalidade.</strong>
                    </div>
                </div>
            </x-flow-panel>
        </x-flow>
    </div>
    

</div>
