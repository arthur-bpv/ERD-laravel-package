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
    {{-- Diagram JSON modal. --}}
    {{-- The ER and relational boards share this component and its download footer. --}}
    <x-json-modal title="JSON do diagrama" />

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

                    {{-- An entity without an identifier cannot be a relationship target. --}}
                    <div class="er-warn" x-show="!node.data.canBeParent" x-cloak>
                        sem PK/UQ — não pode ser referenciada
                    </div>

                    {{-- Attribute rows. --}}
                    <div class="er-rows">
                        <template x-for="attr in node.data.attributes" :key="attr.id">
                            <div class="er-row"
                                 :class="{
                                     'is-pk': attr.key === 'PK',
                                     'is-used': node.data.usedAttrs.includes(attr.id),
                                 }">

                                {{-- Narrow key column (PK/FK/UQ). --}}
                                <button
                                    class="er-key nodrag"
                                    :class="'k-' + (attr.key || 'none').toLowerCase()"
                                    @click="$wire.cycleKey(node.id, attr.id)"
                                    title="Clique para alternar: sem chave → PK → UQ (única)"
                                    :aria-label="'Chave de ' + attr.name + ': ' + (attr.key || 'nenhuma') + '. Clique para alternar entre nenhuma, PK e UQ.'"
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



                                {{-- Delete attribute. --}}
                                <button
                                    class="er-attr-del nodrag"
                                    title="Remover coluna"
                                    @click="$wire.removeAttribute(node.id, attr.id)"
                                >✕</button>
                            </div>
                        </template>
                    </div>

                    {{-- Create an attribute inside the entity box. --}}
                    <div class="er-foot nodrag" x-data="{ open: false, n: '', k: '' }">
                        <button class="er-add-toggle" @click="open = !open" x-text="open ? '− cancelar' : '+ atributo'"></button>

                        <div x-show="open" x-cloak class="er-add-form" @keydown.enter.prevent="
                            if (n.trim()) { $wire.addAttribute(node.id, n.trim(), 'varchar', k); n=''; k=''; open=false; }
                        ">
                            <input class="er-add-input" x-model="n" placeholder="nome" @pointerdown.stop>
                            <select class="er-add-select er-add-key" x-model="k" @pointerdown.stop>
                                <option value="">—</option>
                                <option value="PK">PK</option>
                                <option value="UQ">UQ (única)</option>
                            </select>
                            <button class="er-add-confirm" @click="if (n.trim()) { $wire.addAttribute(node.id, n.trim(), 'varchar', k); n=''; k=''; open=false; }">ok</button>
                        </div>
                    </div>
                </div>
                </template>
            </x-slot:node>

            {{-- This hidden panel hosts erdCanvas inside x-flow so it can access the flow store and discard temporary edges. The server creates definitive relationships. --}}
            <x-flow-panel position="top-left" class="er-hidden-host" x-data="erdCanvas"></x-flow-panel>
            <x-board-background-picker model="ER" />

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
                            <span>Relacionamento</span>
                            <button class="er-ee-close" title="Fechar" @click="close()">✕</button>
                        </div>
                        <div class="er-ee-tabs" role="tablist" aria-label="Gerenciar relacionamento">
                            <button type="button" role="tab" :aria-selected="tab === 'relationship'" :class="{ 'is-active': tab === 'relationship' }" @click="tab = 'relationship'">Relacionamento</button>
                            <button type="button" role="tab" :aria-selected="tab === 'attributes'" :class="{ 'is-active': tab === 'attributes' }" @click="tab = 'attributes'">Atributos <span x-text="attributes.length"></span></button>
                        </div>
                        <div x-show="tab === 'relationship'">

                        {{-- Name displayed inside the diamond. --}}
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


                        {{-- Source end (child/FK) uses markerStart. --}}
                        <div class="er-ee-end">
                            <div class="er-ee-label" x-text="isSelf ? roleLabel(e.data?.fromRole, 'Origem') : (e.data?.sourceName || e.source)">
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
                            <div class="er-ee-label" x-text="isSelf ? roleLabel(e.data?.toRole, 'Destino') : (e.data?.targetName || e.target)">
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
