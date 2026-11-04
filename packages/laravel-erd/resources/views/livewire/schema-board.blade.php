<div class="er-board flex h-screen min-h-0 flex-col overflow-hidden bg-slate-100 font-sans text-slate-800"
    x-data="{
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
    }"
    x-init="shortcutsCleanup = window.installBoardShortcuts($el, $wire, 'er')"
    @saved.window="showNotice('Diagram saved.')">

    <x-erd::board-header mode="er" :diagram-name="$diagramName" :relational-diagram-id="$relationalDiagramId"
        :relational-outdated="$relationalIsOutdated"
        :entities-count="count($entities)" :relations-count="count($relations)" />

    @if ($diagramName === 'ER to relational conversion examples')
        <div class="er-analysis-guide">
            <strong>Cases R01–R32:</strong> A and B show the cardinality at each end. Odd cases have no relationship attribute; even cases marked “+” include one. N:N cases show a junction rectangle. Below the matrix are self-relationships, composite keys, UNIQUE keys and parallel relationships.
        </div>
    @endif

    <div x-show="guideOpen" x-cloak class="er-guide" @keydown.escape.window="guideOpen = false">
        <p><kbd>Ctrl/Cmd + S</kbd> saves. <kbd>Delete</kbd> removes the selection. Select a column row and leave its text field to delete it.</p>
        <div class="er-guide-intro">
            <span class="er-guide-step">1</span>
            <p><strong>Model your domain</strong><small>Create entities, identifiers and attributes.</small></p>
        </div>
        <span class="er-guide-arrow">→</span>
        <div class="er-guide-intro">
            <span class="er-guide-step">2</span>
            <p><strong>Define relationships</strong><small><kbd>Alt</kbd> + drag connects entities.</small></p>
        </div>
        <div class="er-guide-cardinalities" aria-label="Cardinalidades">
            <span><b>&#8214;</b> 1:1</span>
            <span><b>&#9711;&#8739;</b> 0:1</span>
            <span><b>&#8739;&lt;</b> 1:N</span>
            <span><b>&#9711;&lt;</b> 0:N</span>
        </div>
        <button type="button" @click="guideOpen = false" aria-label="Close guide">✕</button>
    </div>
    {{-- Diagram JSON modal. --}}
    {{-- The ER and relational boards share this component and its download footer. --}}
    <x-erd::json-modal title="Diagram JSON" />

    {{-- Floating notice uses the same lifecycle as the relational board. --}}
    <div x-show="notice" x-cloak x-transition class="er-notice" :class="{ 'is-warning': noticeKind === 'warning' }" role="status" aria-live="polite">
        <span x-text="noticeKind === 'warning' ? '!' : '✓'"></span>
        <p x-text="notice"></p>
    </div>

    {{-- Canvas. --}}
    <div class="relative min-h-0 flex-1 overflow-hidden">

        {{-- wire:ignore protects AlpineFlow DOM. Server state reaches it through WireFlow commands, without :sync. --}}
        <x-flow
            wire:ignore
            :nodes="$nodes"
            :edges="$edges"
            :controls="true"
            :minimap="true"
            background="dots"
            default-edge-type="floating"
            :config="[
                // Alt drag can start anywhere on an entity; AlpineFlow finds
                // the nearest handle without one handle per attribute.
                'easyConnect' => true,
                'easyConnectKey' => 'alt',

                // Loose mode allows connections between handles on any side.
                'connectionMode' => 'loose',

                // Snap radius applies on drop, independent of floating edge
                // routing. 160px covers a typical 232px wide entity box.
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
                {{-- A recursive relationship is a separate node outside its entity, keeping role edges clear of table content. --}}
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
                                const name = window.prompt('Rename relationship', node.data.name);
                                if (name) $wire.renameRelation(node.data.relationId, name);
                            "
                            title="Double-click to rename"
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
                const name = window.prompt('Rename attribute', node.data.name);
                if (name) $wire.renameRelationAttribute(node.data.relationId, node.data.attrId, name);
            "
            title="Double-click to rename"
        ></span>

        <button
            class="er-relation-attr-del nodrag"
            type="button"
            aria-label="Remove relationship attribute"
            title="Remove attribute"
            @click.stop="$wire.removeRelationAttribute(node.data.relationId, node.data.attrId)"
        >✕</button>
    </div>
</template>

                {{-- Entity node. --}}
                <template x-if="!node.data.kind">
                <div class="er-node" :data-id="node.id" x-erd-measure-node="node">

                    {{-- Source handles sit at the middle of each side; target handles sit at corners. Separate positions prevent overlapping hit targets. Target connectability follows data.canBeParent. The library's drop fallback requires target handles even with loose connection mode. --}}
                    {{-- Declare handles individually because Alpine directive position modifiers cannot be supplied by x-for. --}}
                    <div class="er-anchor er-anchor-top"    x-flow-handle:source.top="'top'"></div>
                    <div class="er-anchor er-anchor-right"  x-flow-handle:source.right="'right'"></div>
                    <div class="er-anchor er-anchor-bottom" x-flow-handle:source.bottom="'bottom'"></div>
                    <div class="er-anchor er-anchor-left"   x-flow-handle:source.left="'left'"></div>
                    {{-- Structural handles for recursive relationships are separate from manual connection targets. --}}
                    <div class="er-self-anchor er-self-anchor-top" x-flow-handle:target.top="'self-top'"></div>
                    <div class="er-self-anchor er-self-anchor-right" x-flow-handle:target.right="'self-right'"></div>
                    <div class="er-self-anchor er-self-anchor-bottom" x-flow-handle:target.bottom="'self-bottom'"></div>
                    <div class="er-self-anchor er-self-anchor-left" x-flow-handle:target.left="'self-left'"></div>

                    <div class="er-anchor er-anchor-tl" x-flow-handle:target.top.left="'tl'"     x-flow-handle-connectable.end="node.data.canBeParent"></div>
                    <div class="er-anchor er-anchor-tr" x-flow-handle:target.top.right="'tr'"    x-flow-handle-connectable.end="node.data.canBeParent"></div>
                    <div class="er-anchor er-anchor-bl" x-flow-handle:target.bottom.left="'bl'"  x-flow-handle-connectable.end="node.data.canBeParent"></div>
                    <div class="er-anchor er-anchor-br" x-flow-handle:target.bottom.right="'br'" x-flow-handle-connectable.end="node.data.canBeParent"></div>

                    {{-- Entity header and name. --}}
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
                                :aria-label="'Rename entity ' + node.data.name"
                                title="Click to rename"
                            ></button>
                            <input
                                x-show="editing"
                                x-ref="entityName"
                                x-model="draft"
                                class="er-head-name-input nodrag"
                                aria-label="Entity name"
                                @pointerdown.stop
                                @click.stop
                                @keydown.enter.stop.prevent="$event.target.blur()"
                                @keydown.escape.stop.prevent="draft = node.data.name; editing = false"
                                @blur="if (draft.trim() && draft.trim() !== node.data.name) $wire.renameEntity(node.id, draft.trim()); editing = false"
                            >
                        </div>
                        <button
                            class="er-head-self nodrag"
                            title="Create self-relationship"
                            @click.stop="$wire.createSelfRelation(node.id)"
                        >↻</button>
                        <button
                            class="er-head-del nodrag"
                            :data-board-delete-entity="node.id"
                            title="Delete entity"
                            @click="if (window.confirm('Delete entity \'' + node.data.name + '\'?')) $wire.deleteEntity(node.id)"
                        >✕</button>
                    </div>

                    {{-- An entity without an identifier cannot be a relationship target. --}}
                    <div class="er-warn" x-show="!node.data.canBeParent" x-cloak>
                        no PK/UQ — cannot be referenced
                    </div>

                    {{-- Attribute rows. --}}
                    <div class="er-rows">
                        <template x-for="attr in node.data.attributes" :key="attr.id">
                            <div class="er-row" :data-board-table="node.id" :data-board-column="attr.id"
                                 :class="{
                                     'is-pk': attr.key === 'PK',
                                     'is-used': node.data.usedAttrs.includes(attr.id),
                                 }">

                                {{-- Narrow key column (PK/FK/UQ). --}}
                                <button
                                    class="er-key nodrag"
                                    :class="'k-' + (attr.key || 'none').toLowerCase()"
                                    @click="$wire.cycleKey(node.id, attr.id)"
                                    title="Click to cycle: no key → PK → UQ (unique)"
                                    :aria-label="'Key for ' + attr.name + ': ' + (attr.key || 'none') + '. Click to cycle between none, PK and UQ.'"
                                >
                                    <span x-show="attr.key === 'PK'">🔑</span>
                                    <span x-show="attr.key === 'UQ'">UQ</span>
                                    <span x-show="attr.key !== 'PK' && attr.key !== 'UQ'" class="er-key-empty">•</span>
                                </button>

                                {{-- Attribute name. --}}
                                <input
                                    type="text"
                                    class="er-attr-name-input nodrag"
                                    x-data="{ draft: attr.name }"
                                    x-model="draft"
                                    :aria-label="'Attribute name ' + attr.name"
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



                                {{-- Delete attribute. --}}
                                <button
                                    class="er-attr-del nodrag"
                                    title="Remove column"
                                    @click="$wire.removeAttribute(node.id, attr.id)"
                                >✕</button>
                            </div>
                        </template>
                    </div>

                    {{-- Create an attribute inside the entity box. --}}
                    <div class="er-foot nodrag" x-data="{ open: false, n: '', k: '' }">
                        <button class="er-add-toggle" @click="open = !open" x-text="open ? '− cancel' : '+ attribute'"></button>

                        <div x-show="open" x-cloak class="er-add-form" @keydown.enter.prevent="
                            if (n.trim()) { $wire.addAttribute(node.id, n.trim(), 'varchar', k); n=''; k=''; open=false; }
                        ">
                            <input class="er-add-input" x-model="n" placeholder="name" @pointerdown.stop>
                            <select class="er-add-select er-add-key" x-model="k" @pointerdown.stop>
                                <option value="">—</option>
                                <option value="PK">PK</option>
                                <option value="UQ">UQ (unique)</option>
                            </select>
                            <button class="er-add-confirm" @click="if (n.trim()) { $wire.addAttribute(node.id, n.trim(), 'varchar', k); n=''; k=''; open=false; }">ok</button>
                        </div>
                    </div>
                </div>
                </template>
            </x-slot:node>

            {{-- This hidden panel hosts erdCanvas inside x-flow so it can access the flow store and discard temporary edges. The server creates definitive relationships. --}}
            <x-flow-panel position="top-left" class="er-hidden-host" x-data="erdCanvas"></x-flow-panel>
            <x-erd::board-background-picker model="ER model" />

            {{-- Relationship editor. --}}
            {{-- Open near a left click on a relationship. The library context menu only handles right clicks, so the editor listens for edge-click events. --}}
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
                            <span>Relationship</span>
                            <button class="er-ee-close" title="Close" @click="close()">✕</button>
                        </div>
                        <div class="er-ee-tabs" role="tablist" aria-label="Manage relationship">
                            <button type="button" role="tab" :aria-selected="tab === 'relationship'" :class="{ 'is-active': tab === 'relationship' }" @click="tab = 'relationship'">Relationship</button>
                            <button type="button" role="tab" :aria-selected="tab === 'attributes'" :class="{ 'is-active': tab === 'attributes' }" @click="tab = 'attributes'">Attributes <span x-text="attributes.length"></span></button>
                        </div>
                        <div x-show="tab === 'relationship'">

                        {{-- Name displayed inside the diamond. --}}
                        <button class="er-ee-name nodrag" @click="$refs.relationName.focus(); $refs.relationName.select()" title="Edit name">
                            <span class="er-ee-diamond" x-text="e.data?.relationName || e.label || 'unnamed'"></span>
                        </button>
                        <form class="er-ee-rename" @submit.prevent="rename()">
                            <label for="relation-name-editor">Name</label>
                            <input
                                id="relation-name-editor"
                                x-ref="relationName"
                                x-model="relationName"
                                @keydown.escape.stop.prevent="close()"
                                placeholder="Relationship name"
                            >
                            <button type="submit">Apply</button>
                        </form>

                        <form x-show="isSelf" x-cloak class="er-ee-roles" @submit.prevent="updateRoles()">
                            <div class="er-ee-roles-title">Self-relationship roles</div>
                            <label>
                                <span>Source role</span>
                                <input x-model="fromRole" maxlength="80" placeholder="Ex.: subordinado">
                            </label>
                            <label>
                                <span>Target role</span>
                                <input x-model="toRole" maxlength="80" placeholder="Ex.: supervisor">
                            </label>
                            <button type="submit">Apply roles</button>
                        </form>


                        {{-- Source end (child/FK) uses markerStart. --}}
                        <div class="er-ee-end">
                            <div class="er-ee-label" x-text="isSelf ? roleLabel(e.data?.fromRole, 'Source') : (e.data?.sourceName || e.source)">
                            </div>
                            <div class="er-ee-opts">
                                <template x-for="o in options" :key="'s'+o.m">
                                    <button
                                        class="er-ee-opt"
                                        :class="{ 'is-active': markerType(markerFor('markerStart')) === o.m }"
                                        :title="o.t"
                                        @click="setEnd('markerStart', o.m)"
                                        x-text="o.s"
                                    ></button>
                                </template>
                            </div>
                        </div>

                        {{-- Target end (parent/PK) uses markerEnd. --}}
                        <div class="er-ee-end">
                            <div class="er-ee-label" x-text="isSelf ? roleLabel(e.data?.toRole, 'Target') : (e.data?.targetName || e.target)">
                            </div>
                            <div class="er-ee-opts">
                                <template x-for="o in options" :key="'t'+o.m">
                                    <button
                                        class="er-ee-opt"
                                        :class="{ 'is-active': markerType(markerFor('markerEnd')) === o.m }"
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
                                x-text="isSelf ? '⇄ Swap roles' : '⇄ Reverse direction'"
                            ></button>
                            <button class="er-ee-btn er-ee-danger" @click="remove()">🗑 Delete</button>
                        </div>
                        </div>
                        <div x-show="tab === 'attributes'" class="er-ee-attributes">
                            <p>Attributes describe each occurrence of this relationship.</p>
                            <template x-for="attribute in attributes" :key="attribute.id">
                                <div class="er-ee-attribute">
                                    <span x-text="attribute.name"></span>
                                    <button type="button" @click="renameAttribute(attribute)" title="Rename attribute">✎</button>
                                    <button type="button" @click="removeAttribute(attribute)" title="Delete attribute">✕</button>
                                </div>
                            </template>
                            <form class="er-ee-add-attribute" @submit.prevent="addAttribute()">
                                <input x-model="attributeName" maxlength="80" placeholder="Attribute name" aria-label="Attribute name">
                                <button type="submit">+ Add</button>
                            </form>
                        </div>
                        <p class="er-ee-feedback" x-show="feedback" x-text="feedback" role="status" aria-live="polite"></p>
                    </div>
                </template>
            </div>

        </x-flow>
    </div>


</div>
