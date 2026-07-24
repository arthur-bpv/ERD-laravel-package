<div class="flex h-screen flex-col bg-slate-100 font-sans text-slate-800">

    {{-- ===================== HEADER ===================== --}}
    <header class="flex flex-wrap items-center gap-4 border-b border-slate-200 bg-white px-6 py-3 shadow-sm">
        <div class="flex items-center gap-2">
            <span class="text-xl">🗂️</span>
            <h1 class="text-lg font-bold">Modelador de Esquema</h1>
            <span class="hidden text-sm text-slate-400 sm:inline">— entidades, atributos e relações (pé de galinha)</span>
        </div>

        {{-- criar entidade (fica no DOM do Livewire, wire:model/wire:click funcionam) --}}
        <form wire:submit.prevent="createEntity" class="ml-auto flex items-center gap-2">
            <input
                type="text"
                wire:model="newEntityName"
                placeholder="nome_da_tabela"
                class="w-44 rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            >
            <button
                type="submit"
                class="flex items-center gap-1.5 rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-indigo-700"
            >
                <span class="text-base leading-none">+</span> Nova entidade
            </button>
        </form>
    </header>

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
            default-edge-type="smoothstep"
            :config="[
                // Toda relação criada arrastando já nasce em pé de galinha (1:N).
                'defaultEdgeOptions' => [
                    'type' => 'smoothstep',
                    'color' => '#64748b',
                    'strokeWidth' => 1.6,
                    'markerStart' => 'cf-many',
                    'markerEnd' => 'cf-one-one',
                ],
            ]"
            @node-drag-end="onNodeDragEnd"
            style="width: 100%; height: 100%;"
        >
            <x-slot:node>
                {{-- ================= ENTIDADE (tabela) ================= --}}
                <div class="er-node" :data-id="node.id">

                    {{-- cabeçalho: nome da entidade --}}
                    <div class="er-head">
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
                        <button
                            class="er-head-del nodrag"
                            title="Excluir entidade"
                            @click="if (window.confirm('Excluir a entidade \'' + node.data.name + '\'?')) $wire.deleteEntity(node.id)"
                        >✕</button>
                    </div>

                    {{-- linhas: atributos (colunas) --}}
                    <div class="er-rows">
                        <template x-for="attr in node.data.attributes" :key="attr.id">
                            <div class="er-row" :class="{ 'is-pk': attr.key === 'PK' }">

                                {{-- handle de destino (recebe relação), à esquerda da linha --}}
                                <div class="er-handle er-handle-l" x-flow-handle:target.left="'t:' + attr.id"></div>

                                {{-- coluna curta de chave (PK/FK/UQ) --}}
                                <button
                                    class="er-key nodrag"
                                    :class="'k-' + (attr.key || 'none').toLowerCase()"
                                    @click="$wire.cycleKey(node.id, attr.id)"
                                    title="Clique para alternar PK / FK / UQ"
                                >
                                    <span x-show="attr.key === 'PK'">🔑</span>
                                    <span x-show="attr.key && attr.key !== 'PK'" x-text="attr.key"></span>
                                    <span x-show="!attr.key" class="er-key-empty">•</span>
                                </button>

                                {{-- nome do atributo --}}
                                <span class="er-attr-name" x-text="attr.name"></span>

                                {{-- tipo do atributo --}}
                                <span class="er-attr-type" x-text="attr.type"></span>

                                {{-- excluir atributo --}}
                                <button
                                    class="er-attr-del nodrag"
                                    title="Remover coluna"
                                    @click="$wire.removeAttribute(node.id, attr.id)"
                                >✕</button>

                                {{-- handle de origem (inicia relação), à direita da linha --}}
                                <div class="er-handle er-handle-r" x-flow-handle:source.right="'s:' + attr.id"></div>
                            </div>
                        </template>
                    </div>

                    {{-- rodapé: criar atributo DENTRO da caixa da entidade --}}
                    <div class="er-foot nodrag" x-data="{ open: false, n: '', t: 'varchar', k: '' }">
                        <button class="er-add-toggle" @click="open = !open" x-text="open ? '− cancelar' : '+ atributo'"></button>

                        <div x-show="open" x-cloak class="er-add-form" @keydown.enter.prevent="
                            if (n.trim()) { $wire.addAttribute(node.id, n.trim(), t, k); n=''; k=''; open=false; }
                        ">
                            <input class="er-add-input" x-model="n" placeholder="nome" @pointerdown.stop>
                            <select class="er-add-select" x-model="t" @pointerdown.stop>
                                <option>bigint</option>
                                <option>int</option>
                                <option>varchar</option>
                                <option>text</option>
                                <option>boolean</option>
                                <option>timestamp</option>
                                <option>decimal</option>
                                <option>uuid</option>
                            </select>
                            <select class="er-add-select er-add-key" x-model="k" @pointerdown.stop>
                                <option value="">—</option>
                                <option value="PK">PK</option>
                                <option value="FK">FK</option>
                                <option value="UQ">UQ</option>
                            </select>
                            <button class="er-add-confirm" @click="if (n.trim()) { $wire.addAttribute(node.id, n.trim(), t, k); n=''; k=''; open=false; }">ok</button>
                        </div>
                    </div>
                </div>
            </x-slot:node>

            {{-- ================= EDITOR DE RELAÇÃO (estilo ERDPlus) ================= --}}
            {{-- Clique numa aresta → o painel aparece e edita a cardinalidade de cada ponta. --}}
            <x-flow-panel position="top-right" class="er-edge-editor" x-data="erdEdgeEditor"
                          x-effect="e = ($flow?.edges || []).find(x => x.selected) || null">
                <template x-if="e">
                    <div>
                        <div class="er-ee-head">
                            <span>Relação</span>
                            <button class="er-ee-close" title="Fechar" @click="$flow.deselectAll(); e = null">✕</button>
                        </div>
                        <div class="er-ee-path">
                            <span class="er-ee-tag" x-text="e.source"></span>
                            <span class="er-ee-arrow">→</span>
                            <span class="er-ee-tag" x-text="e.target"></span>
                        </div>

                        {{-- ponta ORIGEM (filho / FK) = markerStart --}}
                        <div class="er-ee-end">
                            <div class="er-ee-label">Origem <em x-text="'(' + e.source + ')'"></em></div>
                            <div class="er-ee-opts">
                                <template x-for="o in options" :key="'s'+o.m">
                                    <button
                                        class="er-ee-opt"
                                        :class="{ 'is-active': e.markerStart === o.m }"
                                        :title="o.t"
                                        @click="setEnd('markerStart', o.m)"
                                        x-html="o.s"
                                    ></button>
                                </template>
                            </div>
                        </div>

                        {{-- ponta DESTINO (pai / PK) = markerEnd --}}
                        <div class="er-ee-end">
                            <div class="er-ee-label">Destino <em x-text="'(' + e.target + ')'"></em></div>
                            <div class="er-ee-opts">
                                <template x-for="o in options" :key="'t'+o.m">
                                    <button
                                        class="er-ee-opt"
                                        :class="{ 'is-active': e.markerEnd === o.m }"
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
            </x-flow-panel>

            {{-- ================= LEGENDA (pé de galinha) ================= --}}
            <x-flow-panel position="bottom-left" class="er-legend">
                <div class="er-legend-title">Cardinalidade (IE / pé de galinha)</div>
                <div class="er-legend-grid">
                    <div><span class="er-sym">&#8214;</span> um e só um</div>
                    <div><span class="er-sym">&#9711;&#8739;</span> zero ou um</div>
                    <div><span class="er-sym">&lt;</span> muitos</div>
                    <div><span class="er-sym">&#8739;&lt;</span> um ou muitos</div>
                    <div><span class="er-sym">&#9711;&lt;</span> zero ou muitos</div>
                </div>
                <div class="er-legend-hint">
                    Arraste do ● (direita de uma coluna) até o ● (esquerda de outra) para criar uma relação.
                    <strong>Clique numa linha de relação para editar a cardinalidade.</strong>
                </div>
            </x-flow-panel>
        </x-flow>
    </div>

    {{-- ===================== ESTILO ERD ===================== --}}
    @push('styles')
    @endpush
    <style>
        [x-cloak] { display: none !important; }

        /* ---- caixa da entidade ---- */
        .er-node {
            width: 232px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            box-shadow: 0 4px 14px rgba(15, 23, 42, .10);
            font-size: 12.5px;
            overflow: hidden;
            user-select: none;
        }
        .flow-node-selected .er-node { border-color: #6366f1; box-shadow: 0 0 0 2px rgba(99,102,241,.35); }

        /* ---- cabeçalho ---- */
        .er-head {
            display: flex; align-items: center; gap: 6px;
            background: linear-gradient(180deg, #4f46e5, #4338ca);
            color: #fff; padding: 7px 9px; font-weight: 700; letter-spacing: .02em;
        }
        .er-head-icon { opacity: .8; }
        .er-head-name { flex: 1; cursor: text; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .er-head-del {
            border: 0; background: rgba(255,255,255,.15); color: #fff;
            width: 18px; height: 18px; border-radius: 5px; cursor: pointer; line-height: 1; font-size: 11px;
        }
        .er-head-del:hover { background: rgba(239,68,68,.9); }

        /* ---- linhas / atributos ---- */
        .er-rows { display: flex; flex-direction: column; }
        .er-row {
            position: relative;
            display: grid;
            grid-template-columns: 26px 1fr auto 16px;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-top: 1px solid #eef2f7;
        }
        .er-row:hover { background: #f8fafc; }
        .er-row.is-pk { background: #fefce8; }
        .er-row.is-pk:hover { background: #fef9c3; }

        /* coluna curta de chave */
        .er-key {
            width: 24px; height: 20px; padding: 0; border: 1px solid transparent;
            border-radius: 5px; background: transparent; cursor: pointer;
            font-size: 10px; font-weight: 800; line-height: 1; color: #64748b;
            display: flex; align-items: center; justify-content: center;
        }
        .er-key:hover { border-color: #cbd5e1; background: #fff; }
        .er-key.k-pk { color: #b45309; }
        .er-key.k-fk { color: #2563eb; }
        .er-key.k-uq { color: #7c3aed; }
        .er-key-empty { color: #cbd5e1; }

        .er-attr-name { font-weight: 600; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .er-row.is-pk .er-attr-name { text-decoration: underline; text-decoration-color: #f59e0b; }
        .er-attr-type {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 10.5px; color: #94a3b8; white-space: nowrap;
        }
        .er-attr-del {
            border: 0; background: transparent; color: #cbd5e1; cursor: pointer;
            font-size: 10px; opacity: 0; transition: opacity .12s;
        }
        .er-row:hover .er-attr-del { opacity: 1; }
        .er-attr-del:hover { color: #ef4444; }

        /* ---- rodapé (adicionar atributo) ---- */
        .er-foot { padding: 5px 10px 8px; border-top: 1px solid #eef2f7; background: #fbfdff; }
        .er-add-toggle {
            border: 0; background: transparent; color: #4f46e5; cursor: pointer;
            font-size: 11px; font-weight: 700; padding: 2px 0;
        }
        .er-add-toggle:hover { text-decoration: underline; }
        .er-add-form { display: grid; grid-template-columns: 1fr; gap: 4px; margin-top: 5px; }
        .er-add-input, .er-add-select {
            width: 100%; border: 1px solid #cbd5e1; border-radius: 5px;
            padding: 3px 6px; font-size: 11px; background: #fff;
        }
        .er-add-form { grid-template-columns: 1fr 1fr; }
        .er-add-input { grid-column: 1 / -1; }
        .er-add-confirm {
            grid-column: 1 / -1; border: 0; border-radius: 5px; padding: 4px;
            background: #16a34a; color: #fff; font-size: 11px; font-weight: 700; cursor: pointer;
        }
        .er-add-confirm:hover { background: #15803d; }

        /* ---- handles de conexão ---- */
        .er-handle {
            position: absolute; top: 50%; transform: translateY(-50%);
            width: 11px; height: 11px; border-radius: 50%;
            background: #fff; border: 2px solid #94a3b8;
            opacity: 0; transition: opacity .12s, transform .12s; cursor: crosshair; z-index: 2;
        }
        .er-handle-l { left: -6px; }
        .er-handle-r { right: -6px; }
        .er-row:hover .er-handle,
        .er-node:hover .er-handle { opacity: 1; }
        .er-handle:hover { border-color: #4f46e5; transform: translateY(-50%) scale(0.5); opacity: 1; }
        .flow-handle-valid { border-color: #16a34a !important; opacity: 1 !important; }
        .flow-container {
        --flow-edge-stroke: #58a6ff;
        --flow-edge-stroke-width-selected: 0.5 !important;
        }
        .flow-handle-invalid { border-color: #ef4444 !important; opacity: 1 !important; }

        /* ---- editor de relação (ERDPlus-like) ---- */
        .er-edge-editor {
            width: 250px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px;
            box-shadow: 0 10px 30px rgba(15,23,42,.18); font-size: 12px; color: #334155; overflow: hidden;
        }
        .er-ee-head {
            display: flex; align-items: center; justify-content: space-between;
            background: #4f46e5; color: #fff; padding: 8px 12px; font-weight: 800; letter-spacing: .02em;
        }
        .er-ee-close {
            border: 0; background: rgba(255,255,255,.18); color: #fff; width: 50px; height: 50px;
            border-radius: 6px; cursor: pointer; line-height: 1; font-size: 11px;
        }
        .er-ee-close:hover { background: rgba(239,68,68,.9); }
        .er-ee-path {
            display: flex; align-items: center; gap: 6px; padding: 8px 12px; border-bottom: 1px solid #eef2f7;
        }
        .er-ee-tag {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 11px; font-weight: 700;
            background: #eef2ff; color: #4338ca; padding: 2px 7px; border-radius: 5px;
            max-width: 90px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .er-ee-arrow { color: #94a3b8; }
        .er-ee-end { padding: 8px 12px; border-bottom: 1px solid #f1f5f9; }
        .er-ee-label { font-size: 10.5px; font-weight: 700; color: #64748b; margin-bottom: 5px; text-transform: uppercase; letter-spacing: .04em; }
        .er-ee-label em { font-style: normal; color: #94a3b8; font-weight: 500; text-transform: none; letter-spacing: 0; }
        .er-ee-opts { display: grid; grid-template-columns: repeat(6, 1fr); gap: 4px; }
        .er-ee-opt {
            height: 30px; border: 1px solid #e2e8f0; border-radius: 7px; background: #f8fafc;
            cursor: pointer; font-weight: 800; font-size: 13px; color: #475569; line-height: 1;
            display: flex; align-items: center; justify-content: center; transition: all .12s;
        }
        .er-ee-opt:hover { border-color: #a5b4fc; background: #eef2ff; color: #4338ca; }
        .er-ee-opt.is-active { border-color: #4f46e5; background: #4f46e5; color: #fff; box-shadow: 0 2px 6px rgba(79,70,229,.35); }
        .er-ee-actions { display: flex; gap: 6px; padding: 10px 12px; }
        .er-ee-btn {
            flex: 1; border: 1px solid #e2e8f0; border-radius: 7px; background: #fff; cursor: pointer;
            padding: 6px; font-size: 11px; font-weight: 700; color: #334155;
        }
        .er-ee-btn:hover { background: #f1f5f9; }
        .er-ee-danger { color: #dc2626; border-color: #fecaca; }
        .er-ee-danger:hover { background: #fef2f2; }

        /* aresta selecionada mais evidente */
        .flow-edge-selected path {
             stroke:rgb(70, 150, 229) !important; 
             stroke-width: 2px !important;

        }

        /* ---- legenda ---- */
        .er-legend {
            background: rgba(255,255,255,.95); border: 1px solid #e2e8f0; border-radius: 10px;
            padding: 10px 12px; box-shadow: 0 4px 14px rgba(15,23,42,.10); font-size: 11.5px; color: #334155;
            max-width: 260px;
        }
        .er-legend-title { font-weight: 800; margin-bottom: 6px; color: #0f172a; }
        .er-legend-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2px 12px; }
        .er-sym { display: inline-block; width: 20px; font-weight: 800; color: #475569; }
        .er-legend-hint { margin-top: 8px; padding-top: 6px; border-top: 1px solid #eef2f7; color: #64748b; font-size: 10.5px; }
    </style>
</div>
