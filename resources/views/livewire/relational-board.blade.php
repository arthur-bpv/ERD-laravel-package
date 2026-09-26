<main class="relational-board flex h-screen flex-col overflow-hidden bg-slate-950 text-slate-100"
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
    @relational-saved.window="showNotice('Alteração salva apenas no modelo Relacional.')"
    @relational-edit-rejected.window="showNotice($event.detail.message, 'warning')">
    <header class="rel-toolbar">
        <div class="rel-toolbar-top">
            <div class="rel-toolbar-brand">
                <span class="rel-toolbar-logo" aria-hidden="true">RE</span>
                <div>
                    <h1>Modelador Relacional</h1>
                    <p>{{ $diagramName }}</p>
                </div>
            </div>
            <a wire:navigate href="{{ route('dashboard') }}" class="rel-toolbar-back">← Projetos</a>

            <div class="rel-toolbar-actions">
                <button
                    type="button"
                    class="rel-theme-toggle"
                    x-data="{ dark: document.documentElement.classList.contains('dark') }"
                    @erd-theme-changed.window="dark = $event.detail.theme === 'dark'"
                    @click="dark = !dark; window.setErdTheme(dark ? 'dark' : 'light')"
                    :aria-pressed="dark.toString()"
                    :aria-label="dark ? 'Ativar tema claro' : 'Ativar tema escuro'"
                    :title="dark ? 'Ativar tema claro' : 'Ativar tema escuro'"
                >
                    <span aria-hidden="true" x-text="dark ? '☀' : '☾'"></span>
                    <span x-text="dark ? 'Claro' : 'Escuro'"></span>
                </button>
                <button type="button" @click="guideOpen = !guideOpen">? Guia</button>
                <button type="button" wire:click="toggleJson">{ } JSON</button>
                <button type="button" class="rel-toolbar-save" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">💾 Salvar</span>
                    <span wire:loading wire:target="save">Salvando…</span>
                </button>
            </div>
        </div>

        <nav class="rel-model-tabs" aria-label="Modelos do projeto">
            <a wire:navigate href="{{ route('boards.er', $sourceDiagramId) }}">Modelo ER</a>
            <a class="is-active" aria-current="page">Modelo Relacional</a>
        </nav>

        <div class="rel-toolbar-workflow">
            <div class="rel-model-summary">
                <span><strong>{{ count($tables) }}</strong> tabelas</span>
                <span><strong>{{ collect($tables)->sum(fn (array $table) => count($table['columns'])) }}</strong> colunas</span>
                <span><strong>{{ count($foreignKeys) }}</strong> FKs</span>
            </div>
            <div class="rel-toolbar-divider" aria-hidden="true"></div>
            <div class="rel-edit-status">
                <span class="rel-status-dot"></span>
                <div>
                    <strong>Edição independente</strong>
                    <small>{{ $isCustomized ? 'Possui ajustes manuais salvos' : 'Cópia lógica gerada do ER' }}</small>
                </div>
            </div>
            <div class="rel-source-name">Origem: <strong>{{ $sourceDiagramName }}</strong></div>
            <button class="rel-regenerate" wire:click="regenerate"
                wire:confirm="Regenerar substitui todas as edições manuais deste modelo Relacional pelos dados atuais do ER. Continuar?"
                wire:loading.attr="disabled" wire:target="regenerate">
                <span wire:loading.remove wire:target="regenerate">↻ Regenerar do ER</span>
                <span wire:loading wire:target="regenerate">Transformando…</span>
            </button>
        </div>
    </header>

    <div x-show="guideOpen" x-cloak class="rel-guide" @keydown.escape.window="guideOpen = false">
        <div>
            <span class="rel-guide-step">1</span>
            <p><strong>ER descreve o domínio</strong><small>Entidades, atributos, papéis e cardinalidades.</small></p>
        </div>
        <span class="rel-guide-arrow">→</span>
        <div>
            <span class="rel-guide-step">2</span>
            <p><strong>Relacional implementa</strong><small>PKs, FKs, nulabilidade e tabelas associativas.</small></p>
        </div>
        <span class="rel-guide-rule">1:N põe a FK no lado N · N:N cria tabela associativa · 1:1 preserva unicidade</span>
        <button type="button" @click="guideOpen = false" aria-label="Fechar guia">✕</button>
    </div>

    <div x-show="$wire.showJson" x-cloak class="rel-modal" @click.self="$wire.toggleJson()" @keydown.escape.window="$wire.showJson = false">
        <div class="rel-modal-card">
            <header><strong>JSON do modelo Relacional</strong><button wire:click="toggleJson">✕</button></header>
            <pre>{{ $this->jsonPreview }}</pre>
        </div>
    </div>

    <div x-show="notice" x-cloak x-transition class="rel-notice" :class="{ 'is-warning': noticeKind === 'warning' }" role="status" aria-live="polite">
        <span x-text="noticeKind === 'warning' ? '!' : '✓'"></span>
        <p x-text="notice"></p>
    </div>

    @if ($warnings)
        <aside class="z-10 border-b border-amber-400/15 bg-amber-400/5 px-5 py-2 text-xs text-amber-200">
            <details>
                <summary class="cursor-pointer font-medium">{{ count($warnings) }} decisões precisam de revisão manual</summary>
                <ul class="mt-2 space-y-1 pl-5 text-amber-100/75">
                    @foreach ($warnings as $warning)
                        <li wire:key="warning-{{ md5($warning) }}" class="list-disc">{{ $warning }}</li>
                    @endforeach
                </ul>
            </details>
        </aside>
    @endif

    <section class="relative flex-1 overflow-hidden">
        @if ($tables)
            <x-flow wire:ignore :nodes="$nodes" :edges="$edges" :controls="true" :minimap="true"
                background="dots" default-edge-type="floating"
                :config="[
                    'connectionMode' => 'loose',
                    'nodesDraggable' => true,
                    'nodesConnectable' => false,
                    'elementsSelectable' => true,
                    'edgeTypes' => [
                        'relational-self-loop' => \ArtisanFlow\WireFlow\View\Components\WireFlow::js('window.relationalSelfLoopPath'),
                    ],
                ]"
                @node-drag-end="onNodeDragEnd" style="width: 100%; height: 100%;">
                <x-slot:node>
                    <article class="rel-node">
                        {{-- Uma aresta floating entre um nó e ele mesmo colapsa em
                             um ponto. Estes handles alimentam a rota externa usada
                             pelos auto-relacionamentos. --}}
                        <div class="rel-relation-handle" aria-hidden="true" x-flow-handle:source.top="'relation-source-top'"></div>
                        <div class="rel-relation-handle" aria-hidden="true" x-flow-handle:source.right="'relation-source-right'"></div>
                        <div class="rel-relation-handle" aria-hidden="true" x-flow-handle:source.bottom="'relation-source-bottom'"></div>
                        <div class="rel-relation-handle" aria-hidden="true" x-flow-handle:source.left="'relation-source-left'"></div>
                        <div class="rel-relation-handle" aria-hidden="true" x-flow-handle:target.top="'relation-target-top'"></div>
                        <div class="rel-relation-handle" aria-hidden="true" x-flow-handle:target.right="'relation-target-right'"></div>
                        <div class="rel-relation-handle" aria-hidden="true" x-flow-handle:target.bottom="'relation-target-bottom'"></div>
                        <div class="rel-relation-handle" aria-hidden="true" x-flow-handle:target.left="'relation-target-left'"></div>
                        <header class="rel-node-head">
                            <div x-data="{ editing: false, draft: node.data.name }" class="rel-node-title nodrag">
                                <span x-text="node.data.kind === 'associative' ? 'relação associativa' : (node.data.kind === 'multivalued' ? 'atributo multivalorado' : 'relação')"></span>
                                <button x-show="!editing" type="button" x-text="node.data.name"
                                    @pointerdown.stop @click.stop="draft = node.data.name; editing = true; $nextTick(() => $refs.tableName.select())"
                                    title="Clique para renomear a tabela"></button>
                                <input x-show="editing" x-ref="tableName" x-model="draft" maxlength="80"
                                    @pointerdown.stop @click.stop @keydown.enter.stop.prevent="$event.target.blur()"
                                    @keydown.escape.stop.prevent="editing = false; draft = node.data.name"
                                    @blur="if (draft.trim() && draft.trim() !== node.data.name) $wire.renameTable(node.id, draft); editing = false">
                            </div>
                            <span class="rel-node-count" x-text="node.data.columns.length"></span>
                        </header>
                        <div class="rel-columns">
                            <template x-for="column in node.data.columns" :key="column.id">
                                <div class="rel-column" x-data="{ editing: false, draft: column.name }">
                                    <div class="rel-col-handle rel-col-handle-top" aria-hidden="true" x-flow-handle:source.top="'col-' + column.id + '-top'"></div>
                                    <div class="rel-col-handle rel-col-handle-top" aria-hidden="true" x-flow-handle:target.top="'col-' + column.id + '-top'"></div>
                                    <div class="rel-col-handle rel-col-handle-right" aria-hidden="true" x-flow-handle:source.right="'col-' + column.id + '-right'"></div>
                                    <div class="rel-col-handle rel-col-handle-right" aria-hidden="true" x-flow-handle:target.right="'col-' + column.id + '-right'"></div>
                                    <div class="rel-col-handle rel-col-handle-bottom" aria-hidden="true" x-flow-handle:source.bottom="'col-' + column.id + '-bottom'"></div>
                                    <div class="rel-col-handle rel-col-handle-bottom" aria-hidden="true" x-flow-handle:target.bottom="'col-' + column.id + '-bottom'"></div>
                                    <div class="rel-col-handle rel-col-handle-left" aria-hidden="true" x-flow-handle:source.left="'col-' + column.id + '-left'"></div>
                                    <div class="rel-col-handle rel-col-handle-left" aria-hidden="true" x-flow-handle:target.left="'col-' + column.id + '-left'"></div>
                                    <span class="rel-key" :class="{ 'is-pk': column.key.includes('PK'), 'is-fk': column.key.includes('FK') }" x-text="column.key || '—'"></span>
                                    <button x-show="!editing" type="button" class="rel-column-name nodrag" x-text="column.name"
                                        @pointerdown.stop @click.stop="draft = column.name; editing = true; $nextTick(() => $refs.columnName.select())"
                                        title="Clique para renomear"></button>
                                    <input x-show="editing" x-ref="columnName" x-model="draft" class="rel-column-name-input nodrag" maxlength="80"
                                        @pointerdown.stop @click.stop @keydown.enter.stop.prevent="$event.target.blur()"
                                        @keydown.escape.stop.prevent="editing = false; draft = column.name"
                                        @blur="if (draft.trim() && draft.trim() !== column.name) $wire.renameColumn(node.id, column.id, draft); editing = false">
                                    <div class="rel-column-definition nodrag">
                                        <select class="rel-column-type" :value="column.type || 'varchar'"
                                            @pointerdown.stop @click.stop @change="$wire.updateColumnType(node.id, column.id, $event.target.value)" title="Tipo da coluna">
                                            <option>bigint</option><option>integer</option><option>decimal</option><option>numeric</option><option>boolean</option>
                                            <option>char</option><option>varchar</option><option>text</option><option>date</option><option>datetime</option>
                                            <option>timestamp</option><option>json</option>
                                        </select>
                                        <input x-show="['char', 'varchar'].includes(column.type)" type="number" min="1"
                                            :max="column.type === 'char' ? 255 : 65535" :value="column.length ?? (column.type === 'char' ? 1 : 255)"
                                            @pointerdown.stop @click.stop @change="$wire.updateColumnSize(node.id, column.id, $event.target.value)"
                                            aria-label="Limite de caracteres" title="Limite de caracteres">
                                        <div x-show="['decimal', 'numeric'].includes(column.type)" class="rel-decimal-size" title="Precisão e escala">
                                            <input type="number" min="1" max="65" :value="column.precision ?? 10"
                                                @pointerdown.stop @click.stop @change="$wire.updateColumnSize(node.id, column.id, $event.target.value, column.scale ?? 2)" aria-label="Precisão">
                                            <span>,</span>
                                            <input type="number" min="0" max="30" :value="column.scale ?? 2"
                                                @pointerdown.stop @click.stop @change="$wire.updateColumnSize(node.id, column.id, column.precision ?? 10, $event.target.value)" aria-label="Escala">
                                        </div>
                                    </div>
                                    <button type="button" class="rel-null nodrag" :class="{ 'is-active': column.nullable }"
                                        @pointerdown.stop @click.stop="$wire.toggleColumnNullable(node.id, column.id)"
                                        :title="column.key.includes('PK') ? 'PK não pode aceitar NULL' : 'Alternar nulabilidade'">NULL</button>
                                    <button type="button" class="rel-column-remove nodrag"
                                        @pointerdown.stop @click.stop="if (window.confirm('Remover a coluna ' + column.name + '? FKs que dependem dela também serão removidas deste modelo Relacional.')) $wire.removeColumn(node.id, column.id)"
                                        title="Remover coluna do modelo Relacional">✕</button>
                                </div>
                            </template>
                        </div>
                    </article>
                </x-slot:node>
            </x-flow>
        @else
            <div class="flex h-full items-center justify-center p-8 text-center">
                <div>
                    <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-cyan-400/10 font-bold text-cyan-300">SQL</div>
                    <h2 class="mt-5 text-lg font-semibold text-white">O modelo ER ainda está vazio</h2>
                    <p class="mt-2 text-sm text-slate-500">Adicione entidades ao modelo conceitual e regenere esta etapa.</p>
                </div>
            </div>
        @endif
    </section>
</main>
