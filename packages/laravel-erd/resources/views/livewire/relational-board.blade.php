<main data-structure-editable="{{ $sourceDiagramId === null ? 'true' : 'false' }}" class="relational-board flex h-screen flex-col overflow-hidden bg-slate-950 text-slate-100"
x-data="{
    dialect: @js($dialect),
    catalog: @js(\Makermind\Erd4dev\Support\DataTypeCatalog::forJs()),
    typeKind(type) { return this.catalog[this.dialect].kinds[type] ?? null; },
    shortcutsCleanup: null,
    destroy() { this.shortcutsCleanup?.destroy(); },
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
    dialectNotice: '',
    dialectTimer: null,
    showDialectNotice(message) {
        this.dialectNotice = message;
        window.clearTimeout(this.dialectTimer);
        this.dialectTimer = window.setTimeout(() => this.dialectNotice = '', 7000);
    },
    typeGroups: [
        { label: 'Integers', types: ['tinyint', 'smallint', 'integer', 'bigint'] },
        { label: 'Decimals', types: ['decimal', 'numeric', 'float', 'double', 'money'] },
        { label: 'Text', types: ['char', 'varchar', 'nchar', 'nvarchar', 'text', 'mediumtext', 'longtext'] },
        { label: 'Date and time', types: ['date', 'time', 'datetime', 'timestamp'] },
        { label: 'Binary', types: ['binary', 'varbinary', 'blob'] },
        { label: 'Other', types: ['boolean', 'uuid', 'json', 'xml', 'enum', 'custom'] },
    ],
}"
    x-init="shortcutsCleanup = window.installBoardShortcuts($el, $wire, 'relational'); $nextTick(() => window.installRelationalConnections($el.querySelector('.flow-container')))"
    @relational-saved.window="showNotice('Changes saved to the relational model only.')"
    @relational-regenerated.window="showNotice('Relational model regenerated from ER.')"
    @relational-edit-rejected.window="showNotice($event.detail.message, 'warning')"
    @relational-dialect-warning.window="showDialectNotice($event.detail.message)">
    <x-erd::board-header mode="relational" :diagram-name="$diagramName" :dialect="$dialect" :has-draft-notice="(bool) ($draftPath || $draftError)" :source-diagram-id="$sourceDiagramId"
        :source-diagram-name="$sourceDiagramName" :tables-count="count($tables)"
        :columns-count="collect($tables)->sum(fn (array $table) => count($table['columns']))"
        :foreign-keys-count="count($foreignKeys)" />

    @if ($isOutdated)
        {{-- The ER source changed after generation. Show drift and reuse the existing regeneration action and confirmation. --}}
        <aside class="rel-drift" role="status" aria-live="polite">
            <span class="rel-drift-icon" aria-hidden="true">!</span>

            <div class="rel-drift-body">
                <strong>This relational model is outdated</strong>
                <p>ER model <b>{{ $sourceDiagramName }}</b> has changed since the last generation. Regenerate to apply its changes.</p>
                <details>
                    <summary>{{ count($drift) }} {{ count($drift) === 1 ? 'pending change' : 'pending changes' }}</summary>
                    <ul>
                        @foreach (array_slice($drift, 0, 12) as $change)
                            <li wire:key="drift-{{ md5($change) }}">{{ $change }}</li>
                        @endforeach
                        @if (count($drift) > 12)
                            <li class="rel-drift-more">and {{ count($drift) - 12 }} more…</li>
                        @endif
                    </ul>
                </details>
                @if ($isCustomized)
                    <p class="rel-drift-note">Manual edits to this board will also be replaced.</p>
                @endif
            </div>

            <button type="button" class="rel-drift-action" wire:click="regenerate"
                wire:confirm="Regenerating replaces all manual edits with the current ER model. Continue?"
                wire:loading.attr="disabled" wire:target="regenerate">
                <span wire:loading.remove wire:target="regenerate">Regenerate from ER</span>
                <span wire:loading wire:target="regenerate">Regenerating…</span>
            </button>
        </aside>
    @endif

    <div x-show="guideOpen" x-cloak class="rel-guide" @keydown.escape.window="guideOpen = false">
        <p><kbd>Ctrl/Cmd + S</kbd> saves. @if ($sourceDiagramId === null) <kbd>Delete</kbd> removes the selection. Use <strong>New table</strong> to edit table names and columns directly on the board. Configure FKs in <strong>Relationships</strong>. @endif</p>
        @if ($sourceDiagramId)
        <div>
            <span class="rel-guide-step">1</span>
            <p><strong>ER describes the domain</strong><small>Entities, attributes, roles and cardinalities.</small></p>
        </div>
        <span class="rel-guide-arrow">→</span>
        <div>
            <span class="rel-guide-step">2</span>
            <p><strong>Relational implements</strong><small>PKs, FKs, nullability and junction tables.</small></p>
        </div>
        <span class="rel-guide-rule">1:N puts the FK on the many side · N:N creates a junction table · 1:1 preserves uniqueness</span>
        @else
            <p>Imported SQL or database snapshot. Edit column types, sizes and nullability on the board. Changes are saved only to this independent relational model.</p>
        @endif
        <button type="button" @click="guideOpen = false" aria-label="Close guide">✕</button>
    </div>

    @if ($sourceDiagramId === null)
        @include('erd::livewire.partials.relational-structure-editor')
    @endif

    <div x-show="$wire.showDraftNotice" x-cloak x-effect="if ($wire.showDraftNotice) $nextTick(() => $refs.draftClose.focus())" class="rel-modal rel-draft-overlay" role="dialog" aria-modal="true" aria-labelledby="draft-title"
        @click.self="$wire.showDraftNotice = false" @keydown.escape.window="$wire.showDraftNotice = false">
        <div class="rel-modal-card">
            <header><strong id="draft-title">{{ $draftError ? 'Could not generate the draft' : 'Blueprint draft generated' }}</strong>
                <button type="button" @click="$wire.showDraftNotice = false" x-ref="draftClose" aria-label="Close draft notification">✕</button>
            </header>
            <div class="rel-structure-body">
                @if ($draftError)<p role="alert">{{ $draftError }}</p>@endif
                @if ($draftPath)
                    <div role="status"><p>File saved to <code class="rel-wrap-path">{{ $draftPath }}</code>.</p></div>
                    <p>Track the directory in version control: <code>blueprints/</code>. After reviewing the draft, generate models and migrations with:</p>
                    <pre class="rel-wrap-path">php artisan blueprint:build {{ $draftPath }} --only=models,migrations</pre>
                    <button type="button" class="board-toolbar-button" x-data="{ copied: false, failed: false }" @click="window.copyBoardText(@js('php artisan blueprint:build '.$draftPath.' --only=models,migrations')).then(() => copied = true).catch(() => failed = true)" x-text="failed ? 'Select and copy the command above' : (copied ? 'Command copied' : 'Copy command')">Copy command</button>
                    <p class="rel-structure-help">The database was not changed. You are responsible for running migrations.</p>
                    @if ($draftWarnings)
                        <details><summary>Review {{ count($draftWarnings) }} warnings</summary><ul class="list-disc pl-5">@foreach ($draftWarnings as $warning)<li>{{ $warning }}</li>@endforeach</ul></details>
                    @endif
                @endif
            </div>
        </div>
    </div>

    @if ($sourceDiagramId === null)
        <div x-show="$wire.showTableCreator" x-cloak class="rel-modal" role="dialog" aria-modal="true" aria-labelledby="table-creator-title"
            @click.self="$wire.showTableCreator = false" @keydown.escape.window="$wire.showTableCreator = false">
            <form wire:submit="createBoardTable" class="rel-modal-card">
                <header><strong id="table-creator-title">New table</strong><button type="button" @click="$wire.showTableCreator = false" aria-label="Close new table dialog">✕</button></header>
                <div class="rel-structure-body">
                    <label>Table name<input wire:model="newTableName" required maxlength="80" class="rel-inline-input" aria-label="New table name"></label>
                    @error('newTableName')<p role="alert">{{ $message }}</p>@enderror
                    <p class="rel-structure-help">The table starts with an id primary key. Edit its columns directly on the board.</p>
                </div>
                <div class="rel-modal-actions"><button type="submit" wire:loading.attr="disabled">Create table</button></div>
            </form>
        </div>
    @endif

    <x-erd::json-modal title="Relational model JSON" />

    <div x-show="$wire.showSql" x-cloak class="rel-modal"
        x-data="{
            copySql() {
                return window.copyBoardText(this.$refs.sqlBox.textContent);
            },
        }"
        @click.self="$wire.showSql = false" @keydown.escape.window="$wire.showSql = false">
        <div class="rel-modal-card">
            <header>
                <strong>Relational model SQL · {{ \Makermind\Erd4dev\Support\DataTypeCatalog::DIALECTS[$dialect]['label'] }}</strong>
                <button type="button" @click="$wire.showSql = false" aria-label="Close SQL">✕</button>
            </header>
            @if ($sqlError)
                <p role="alert" class="p-4 text-amber-300">{{ $sqlError }}</p>
            @else
                <pre x-ref="sqlBox">{{ $sqlPreview }}</pre>
                <div class="rel-modal-actions">
                    <button type="button" @click="copySql().then(() => showNotice('SQL copied.')).catch(() => showNotice('Could not copy SQL.', 'warning'))">Copy</button>
                    <button type="button" wire:click="downloadSql" wire:loading.attr="disabled" wire:target="downloadSql">Download .sql</button>
                </div>
            @endif
        </div>
    </div>

    <div x-show="notice" x-cloak x-transition class="rel-notice" :class="{ 'is-warning': noticeKind === 'warning' }" role="status" aria-live="polite">
        <span x-text="noticeKind === 'warning' ? '!' : '✓'"></span>
        <p x-text="notice"></p>
    </div>

    <div x-show="dialectNotice" x-cloak x-transition class="rel-notice rel-notice-stack is-warning" role="status" aria-live="polite">
    <span>!</span>
    <p x-text="dialectNotice"></p>
    </div>


    @if ($warnings)
        <aside class="z-10 border-b border-amber-400/15 bg-amber-400/5 px-5 py-2 text-xs text-amber-200">
            <details>
                <summary class="cursor-pointer font-medium">{{ count($warnings) }} decisions require manual review</summary>
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
                                <span x-text="node.data.editable ? 'table' : (node.data.kind === 'associative' ? 'associative table' : (node.data.kind === 'multivalued' ? 'multivalued attribute' : 'relationship'))"></span>
                                @if ($sourceDiagramId === null)
                                    <input class="rel-inline-input rel-table-name" :value="node.data.name" maxlength="80" required aria-label="Table name" @pointerdown.stop @click.stop @keydown.stop @change="const input = $event.target; $wire.renameTable(node.id, input.value).then(() => input.value = node.data.name)">
                                @else
                                    <strong x-text="node.data.name"></strong>
                                @endif
                            </div>
                            @if ($sourceDiagramId === null)
                                <button type="button" class="rel-inline-action nodrag" aria-label="Remove table" @pointerdown.stop @click.stop="if (confirm('Remove the table, its columns and relationships?')) $wire.deleteSelection([node.id])">✕</button>
                            @endif
                        </header>
                        <template x-for="(key, index) in (node.data.uniqueKeys ?? []).filter(key => key.length > 1)" :key="'uq-' + index">
                            <div class="px-3 text-xs text-amber-300 truncate" style="height: 24px; line-height: 24px"
                                :title="'Unicidade composta: ' + key.join(', ')" x-text="'UNIQUE (' + key.join(', ') + ')'"></div>
                        </template>
                        <div class="rel-columns">
                            <template x-for="column in node.data.columns" :key="column.id">
                                <div class="rel-column relative" :data-board-table="node.id" :data-board-column="column.id" x-data="{
                                    enumEditorOpen: false,
                                    enumDraft: '',
                                    addEnumValue() {
                                        const value = this.enumDraft.trim();
                                        const values = [...(column.enumValues ?? [])];
                                        if (!value || values.includes(value)) return;
                                        values.push(value);
                                        column.enumValues = values;
                                        this.enumDraft = '';
                                        $wire.updateColumnEnumValues(node.id, column.id, values);
                                    },
                                    removeEnumValue(index) {
                                        const values = (column.enumValues ?? []).filter((_, valueIndex) => valueIndex !== index);
                                        column.enumValues = values;
                                        $wire.updateColumnEnumValues(node.id, column.id, values);
                                    },
                                }">
                                    <div class="rel-col-handle rel-col-handle-top" aria-hidden="true" x-flow-handle:source.top="'col-' + column.id + '-top'"></div>
                                    <div class="rel-col-handle rel-col-handle-top" aria-hidden="true" x-flow-handle:target.top="'col-' + column.id + '-top'"></div>
                                    <div class="rel-col-handle rel-col-handle-right" aria-hidden="true" x-flow-handle:source.right="'col-' + column.id + '-right'"></div>
                                    <div class="rel-col-handle rel-col-handle-right" aria-hidden="true" x-flow-handle:target.right="'col-' + column.id + '-right'"></div>
                                    <div class="rel-col-handle rel-col-handle-bottom" aria-hidden="true" x-flow-handle:source.bottom="'col-' + column.id + '-bottom'"></div>
                                    <div class="rel-col-handle rel-col-handle-bottom" aria-hidden="true" x-flow-handle:target.bottom="'col-' + column.id + '-bottom'"></div>
                                    <div class="rel-col-handle rel-col-handle-left" aria-hidden="true" x-flow-handle:source.left="'col-' + column.id + '-left'"></div>
                                    <div class="rel-col-handle rel-col-handle-left" aria-hidden="true" x-flow-handle:target.left="'col-' + column.id + '-left'"></div>
                                    @if ($sourceDiagramId === null)
                                        <select class="rel-key-control nodrag" :value="column.key.includes('PK') ? 'PK' : (column.key.includes('UQ') ? 'UQ' : '')" :title="column.key || 'No key'" aria-label="Column key"
                                            @pointerdown.stop @click.stop @change="const input = $event.target; $wire.setColumnKey(node.id, column.id, input.value).then(() => input.value = column.key.includes('PK') ? 'PK' : (column.key.includes('UQ') ? 'UQ' : ''))">
                                            <option value="">—</option><option value="PK">PK</option><option value="UQ">UQ</option>
                                        </select>
                                        <div class="rel-name-editor nodrag">
                                            <input class="rel-inline-input" :value="column.name" maxlength="80" aria-label="Column name" @pointerdown.stop @click.stop @keydown.stop @change="const input = $event.target; $wire.renameColumn(node.id, column.id, input.value).then(() => input.value = column.name)">
                                            <button type="button" class="rel-inline-action" aria-label="Remove column" @pointerdown.stop @click.stop="if (confirm('Remove this column and its relationships?')) $wire.deleteColumn(node.id, column.id)">×</button>
                                            <small x-show="column.key.includes('FK')" title="Foreign key configured in Relationships">FK</small>
                                        </div>
                                    @else
                                        <span class="rel-key" :class="{ 'is-pk': column.key.includes('PK'), 'is-fk': column.key.includes('FK') }" x-text="column.key || '—'"></span>
                                        <span class="rel-column-name nodrag" x-text="column.name"></span>
                                    @endif
                                    <div class="rel-column-definition nodrag">
                                        <select class="rel-column-type"
                                            @pointerdown.stop @click.stop
                                            @change="column.type = $event.target.value; if (typeKind(column.type) === 'length') column.length ??= (['char', 'nchar', 'binary'].includes(column.type) ? 1 : 255); if (typeKind(column.type) === 'decimal') { column.precision ??= 10; column.scale ??= 2; } enumEditorOpen = column.type === 'enum'; $wire.updateColumnType(node.id, column.id, column.type)"
                                            :title="catalog[dialect].types[column.type]" aria-label="Column type">
                                            <template x-for="group in typeGroups" :key="dialect + group.label">
                                                <optgroup :label="group.label" x-show="group.types.some(type => type in catalog[dialect].types)">
                                                    <template x-for="type in group.types.filter(type => type in catalog[dialect].types)" :key="dialect + type">
                                                        <option :value="type" :selected="type === column.type" x-text="catalog[dialect].types[type]"></option>
                                                    </template>
                                                </optgroup>
                                            </template>
                                        </select>

                                        <button x-show="column.type === 'enum'" x-cloak type="button" class="rel-enum-toggle"
                                            @pointerdown.stop @click.stop="enumEditorOpen = !enumEditorOpen"
                                            :aria-expanded="enumEditorOpen.toString()"
                                            title="Edit allowed values">
                                            <span x-text="enumEditorOpen ? '−' : '+'"></span>
                                            <span>values</span>
                                            <small x-text="(column.enumValues ?? []).length"></small>
                                        </button>

                                        <input x-show="column.type === 'custom'" type="text"
                                            placeholder="ex: GEOMETRY"
                                            x-model="column.customType"
                                            @pointerdown.stop @click.stop @keydown.enter.stop.prevent="$event.target.blur()"
                                            @input.debounce.350ms="$wire.updateColumnCustomType(node.id, column.id, column.customType ?? '')"
                                            @change="$wire.updateColumnCustomType(node.id, column.id, column.customType ?? '')"
                                            aria-label="Custom type" title="Enter a SQL type (e.g. GEOMETRY, MONEY, VARCHAR(20))">

                                        <input x-show="typeKind(column.type) === 'length'" class="rel-size-input" type="number" min="1" step="1" inputmode="numeric"
                                            :max="catalog[dialect].limits[column.type]"
                                            x-model.number="column.length"
                                            @pointerdown.stop @click.stop @change="if ($event.target.validity.valid) $wire.updateColumnSize(node.id, column.id, $event.target.value)"
                                            aria-label="Character limit" title="Character limit">

                                        <div x-show="typeKind(column.type) === 'decimal'" class="rel-decimal-size" title="Precision and scale">
                                            <input type="number" min="1" step="1" inputmode="numeric" :max="catalog[dialect].precision" x-model.number="column.precision"
                                                @pointerdown.stop @click.stop @change="if ($event.target.validity.valid) $wire.updateColumnSize(node.id, column.id, $event.target.value, column.scale ?? 2)" aria-label="Precision" title="Precision">
                                            <span>,</span>
                                            <input type="number" min="0" step="1" inputmode="numeric" :max="Math.min(column.precision ?? 10, 30)" x-model.number="column.scale"
                                                @pointerdown.stop @click.stop @change="if ($event.target.validity.valid) $wire.updateColumnSize(node.id, column.id, column.precision ?? 10, $event.target.value)" aria-label="Scale" title="Scale">
                                        </div>
                                    </div>
                                    <div x-show="column.type === 'enum' && enumEditorOpen" x-cloak x-transition.opacity
                                        class="rel-enum-editor nodrag" @pointerdown.stop @click.stop>
                                        <label :for="'enum-value-' + column.id">
                                            Allowed values
                                            <small>add one value at a time</small>
                                        </label>
                                        <div class="rel-enum-entry">
                                            <input class="rel-enum-input" type="text"
                                                :id="'enum-value-' + column.id"
                                                x-model="enumDraft"
                                                @keydown.enter.prevent="addEnumValue()"
                                                placeholder="Ex.: pendente"
                                                aria-label="New allowed value">
                                            <button type="button" class="rel-enum-add" @click="addEnumValue()">Add</button>
                                        </div>
                                        <div class="rel-enum-list" x-show="(column.enumValues ?? []).length" x-cloak>
                                            <template x-for="(value, index) in (column.enumValues ?? [])" :key="column.id + '-' + value + '-' + index">
                                                <span class="rel-enum-value">
                                                    <span :title="JSON.stringify(value)" x-text="value === '' ? '(empty)' : value"></span>
                                                    <button type="button" @click="removeEnumValue(index)" :aria-label="'Remove ' + value" title="Remove valor">🗑</button>
                                                </span>
                                            </template>
                                        </div>
                                    </div>
                                    <button type="button" class="rel-null nodrag" :class="{ 'is-active': column.nullable }"
                                        @pointerdown.stop @click.stop="$wire.toggleColumnNullable(node.id, column.id)"
                                        :title="column.key.includes('PK') ? 'PK cannot accept NULL' : 'Toggle nullability'">NULL</button>
                                </div>
                            </template>

                        </div>
                        @if ($sourceDiagramId === null)
                            <form class="rel-add-column nodrag" x-data="{ name: '' }" @pointerdown.stop @click.stop @keydown.stop
                                @submit.prevent="$wire.addColumn(node.id, name).then(() => name = '')">
                                <input x-model="name" required maxlength="80" placeholder="New column" aria-label="New column name">
                                <button type="submit" aria-label="Add column">+</button>
                            </form>
                        @endif
                    </article>
                </x-slot:node>

                <x-erd::board-background-picker model="relational model" />
            </x-flow>
        @else
            <div class="flex h-full items-center justify-center p-8 text-center">
                <div>
                    <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-cyan-400/10 font-bold text-cyan-300">SQL</div>
                    <h2 class="mt-5 text-lg font-semibold text-white">{{ $sourceDiagramId ? 'The ER model is still empty' : 'No tables in the model' }}</h2>
                    <p class="mt-2 text-sm text-slate-500">{{ $sourceDiagramId ? 'Add entities to the conceptual model and regenerate this stage.' : 'Use New table to get started.' }}</p>
                </div>
            </div>
        @endif
    </section>
</main>
