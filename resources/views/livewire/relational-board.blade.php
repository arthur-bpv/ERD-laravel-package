<main class="relational-board flex h-screen flex-col overflow-hidden bg-slate-950 text-slate-100"
    x-data="{
        dialect: @js($dialect),
        catalog: @js(\App\Support\DataTypeCatalog::forJs()),
        typeKind(type) { return this.catalog[this.dialect].kinds[type] ?? null; },
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
        typeGroups: [
            { label: 'Inteiros', types: ['tinyint', 'smallint', 'integer', 'bigint'] },
            { label: 'Decimais', types: ['decimal', 'numeric', 'float', 'double', 'money'] },
            { label: 'Texto', types: ['char', 'varchar', 'nchar', 'nvarchar', 'text', 'mediumtext', 'longtext'] },
            { label: 'Data e hora', types: ['date', 'time', 'datetime', 'timestamp'] },
            { label: 'Binário', types: ['binary', 'varbinary', 'blob'] },
            { label: 'Outros', types: ['boolean', 'uuid', 'json', 'xml'] },
        ],
    }"
    x-init="$nextTick(() => window.installRelationalConnections($el.querySelector('.flow-container')))"
    @relational-saved.window="showNotice('Alteração salva apenas no modelo Relacional.')"
    @relational-regenerated.window="showNotice('Modelo Relacional regenerado a partir do ER.')"
    @relational-edit-rejected.window="showNotice($event.detail.message, 'warning')">
    <x-board-header mode="relational" :diagram-name="$diagramName" :source-diagram-id="$sourceDiagramId"
        :source-diagram-name="$sourceDiagramName" :tables-count="count($tables)"
        :columns-count="collect($tables)->sum(fn (array $table) => count($table['columns']))"
        :foreign-keys-count="count($foreignKeys)" />

    @if ($isOutdated)
        {{-- Aviso de defasagem: o ER mudou depois da última geração e nada foi
             reescrito sozinho. O botão reaproveita o mesmo regenerate (e a mesma
             confirmação) que já existia no menu "Mais". --}}
        <aside class="rel-drift" role="status" aria-live="polite">
            <span class="rel-drift-icon" aria-hidden="true">!</span>

            <div class="rel-drift-body">
                <strong>Este modelo Relacional está desatualizado</strong>
                <p>O modelo ER <b>{{ $sourceDiagramName }}</b> mudou depois da última geração. Nada foi regravado — regenere para aplicar.</p>
                <details>
                    <summary>{{ count($drift) }} {{ count($drift) === 1 ? 'mudança pendente' : 'mudanças pendentes' }}</summary>
                    <ul>
                        @foreach (array_slice($drift, 0, 12) as $change)
                            <li wire:key="drift-{{ md5($change) }}">{{ $change }}</li>
                        @endforeach
                        @if (count($drift) > 12)
                            <li class="rel-drift-more">e mais {{ count($drift) - 12 }}…</li>
                        @endif
                    </ul>
                </details>
                @if ($isCustomized)
                    <p class="rel-drift-note">As edições manuais deste quadro também serão substituídas.</p>
                @endif
            </div>

            <button type="button" class="rel-drift-action" wire:click="regenerate"
                wire:confirm="Regenerar substitui todas as edições manuais deste modelo Relacional pelos dados atuais do ER. Continuar?"
                wire:loading.attr="disabled" wire:target="regenerate">
                <span wire:loading.remove wire:target="regenerate">Regenerar do ER</span>
                <span wire:loading wire:target="regenerate">Regenerando…</span>
            </button>
        </aside>
    @endif

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

    <x-json-modal title="JSON do modelo Relacional" />

    <div x-show="$wire.showSql" x-cloak class="rel-modal"
        x-data="{
            copySql() {
                return window.copyBoardText(this.$refs.sqlBox.textContent);
            },
        }"
        @click.self="$wire.showSql = false" @keydown.escape.window="$wire.showSql = false">
        <div class="rel-modal-card">
            <header>
                <strong>SQL do modelo Relacional · {{ \App\Support\DataTypeCatalog::DIALECTS[$dialect]['label'] }}</strong>
                <button type="button" @click="$wire.showSql = false" aria-label="Fechar SQL">✕</button>
            </header>
            @if ($sqlError)
                <p role="alert" class="p-4 text-amber-300">{{ $sqlError }}</p>
            @else
                <pre x-ref="sqlBox">{{ $sqlPreview }}</pre>
                <div class="rel-modal-actions">
                    <button type="button" @click="copySql().then(() => showNotice('SQL copiado.')).catch(() => showNotice('Não foi possível copiar o SQL.', 'warning'))">Copiar</button>
                    <button type="button" wire:click="downloadSql" wire:loading.attr="disabled" wire:target="downloadSql">Baixar .sql</button>
                </div>
            @endif
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

    <section class="relative min-h-0 flex-1 overflow-hidden">
        @if ($tables)
            <x-flow wire:ignore :nodes="$nodes" :edges="$edges" :controls="true" :minimap="true"
                background="dots" default-edge-type="floating"
                :config="[
                    'connectionMode' => 'loose',
                    'nodesDraggable' => true,
                    'nodesConnectable' => false,
                    'edgesReconnectable' => false,
                    'elementsSelectable' => true,
                    'edgeTypes' => [
                        'relational-self-loop' => \ArtisanFlow\WireFlow\View\Components\WireFlow::js('window.relationalSelfLoopPath'),
                    ],
                ]"
                @node-drag-end="onNodeDragEnd" style="width: 100%; height: 100%;">
                <x-slot:node>
                    <article class="rel-node">
                        <header class="rel-node-head">
                            <div class="rel-node-title nodrag">
                                <span x-text="node.data.kind === 'associative' ? 'relação associativa' : (node.data.kind === 'multivalued' ? 'atributo multivalorado' : 'relação')"></span>
                                <strong x-text="node.data.name"></strong>
                            </div>
                        </header>
                        <div class="rel-columns">
                            <template x-for="column in node.data.columns" :key="column.id">
                                <div class="rel-column relative">
                                    <div class="rel-col-handle rel-col-handle-top" aria-hidden="true" x-flow-handle:source.top="'col-' + column.id + '-top'"></div>
                                    <div class="rel-col-handle rel-col-handle-top" aria-hidden="true" x-flow-handle:target.top="'col-' + column.id + '-top'"></div>
                                    <div class="rel-col-handle rel-col-handle-right" aria-hidden="true" x-flow-handle:source.right="'col-' + column.id + '-right'"></div>
                                    <div class="rel-col-handle rel-col-handle-right" aria-hidden="true" x-flow-handle:target.right="'col-' + column.id + '-right'"></div>
                                    <div class="rel-col-handle rel-col-handle-bottom" aria-hidden="true" x-flow-handle:source.bottom="'col-' + column.id + '-bottom'"></div>
                                    <div class="rel-col-handle rel-col-handle-bottom" aria-hidden="true" x-flow-handle:target.bottom="'col-' + column.id + '-bottom'"></div>
                                    <div class="rel-col-handle rel-col-handle-left" aria-hidden="true" x-flow-handle:source.left="'col-' + column.id + '-left'"></div>
                                    <div class="rel-col-handle rel-col-handle-left" aria-hidden="true" x-flow-handle:target.left="'col-' + column.id + '-left'"></div>
                                    <span class="rel-key" :class="{ 'is-pk': column.key.includes('PK'), 'is-fk': column.key.includes('FK') }" x-text="column.key || '—'"></span>
                                    <span class="rel-column-name nodrag" x-text="column.name"></span>
                                    <div class="rel-column-definition nodrag">
                                        <select class="rel-column-type"
                                            @pointerdown.stop @click.stop
                                            @change="column.type = $event.target.value; if (typeKind(column.type) === 'length') column.length ??= (['char', 'nchar', 'binary'].includes(column.type) ? 1 : 255); if (typeKind(column.type) === 'decimal') { column.precision ??= 10; column.scale ??= 2; } $wire.updateColumnType(node.id, column.id, column.type)"
                                            :title="catalog[dialect].types[column.type]" aria-label="Tipo da coluna">
                                            <template x-for="group in typeGroups" :key="dialect + group.label">
                                                <optgroup :label="group.label" x-show="group.types.some(type => type in catalog[dialect].types)">
                                                    <template x-for="type in group.types.filter(type => type in catalog[dialect].types)" :key="dialect + type">
                                                        <option :value="type" :selected="type === column.type" x-text="catalog[dialect].types[type]"></option>
                                                    </template>
                                                </optgroup>
                                            </template>
                                        </select>

                                        <input x-show="typeKind(column.type) === 'length'" class="rel-size-input" type="number" min="1" step="1" inputmode="numeric"
                                            :max="catalog[dialect].limits[column.type]"
                                            x-model.number="column.length"
                                            @pointerdown.stop @click.stop @change="if ($event.target.validity.valid) $wire.updateColumnSize(node.id, column.id, $event.target.value)"
                                            aria-label="Limite de caracteres" title="Limite de caracteres">

                                        <div x-show="typeKind(column.type) === 'decimal'" class="rel-decimal-size" title="Precisão e escala">
                                            <input type="number" min="1" step="1" inputmode="numeric" :max="catalog[dialect].precision" x-model.number="column.precision"
                                                @pointerdown.stop @click.stop @change="if ($event.target.validity.valid) $wire.updateColumnSize(node.id, column.id, $event.target.value, column.scale ?? 2)" aria-label="Precisão" title="Precisão">
                                            <span>,</span>
                                            <input type="number" min="0" step="1" inputmode="numeric" :max="Math.min(column.precision ?? 10, 30)" x-model.number="column.scale"
                                                @pointerdown.stop @click.stop @change="if ($event.target.validity.valid) $wire.updateColumnSize(node.id, column.id, column.precision ?? 10, $event.target.value)" aria-label="Escala" title="Escala">
                                        </div>
                                    </div>
                                    <button type="button" class="rel-null nodrag" :class="{ 'is-active': column.nullable }"
                                        @pointerdown.stop @click.stop="$wire.toggleColumnNullable(node.id, column.id)"
                                        :title="column.key.includes('PK') ? 'PK não pode aceitar NULL' : 'Alternar nulabilidade'">NULL</button>
                                </div>
                            </template>

                        </div>
                    </article>
                </x-slot:node>

                <x-board-background-picker model="relacional" />
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
