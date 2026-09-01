<main class="relational-board flex h-screen flex-col overflow-hidden bg-slate-950 text-slate-100">
    <header class="z-20 flex flex-wrap items-center gap-4 border-b border-white/10 bg-slate-950/95 px-5 py-3 backdrop-blur">
        <a wire:navigate href="{{ route('dashboard') }}" class="rounded-lg px-2 py-1.5 text-sm text-slate-400 transition hover:bg-white/5 hover:text-white">← Projetos</a>
        <div class="min-w-0">
            <div class="flex items-center gap-2">
                <span class="rounded-md bg-cyan-400/10 px-2 py-1 text-[10px] font-bold uppercase tracking-widest text-cyan-300">Relacional</span>
                <h1 class="truncate text-sm font-semibold text-white">{{ $diagramName }}</h1>
            </div>
            <p class="mt-0.5 text-xs text-slate-500">Derivado de {{ $sourceDiagramName }}</p>
        </div>
        <div class="ml-auto flex items-center gap-2">
            <span class="hidden text-xs text-slate-500 sm:inline">{{ count($tables) }} relações · {{ count($foreignKeys) }} chaves estrangeiras</span>
            <button wire:click="regenerate" wire:confirm="Regenerar substitui o modelo lógico atual pelas regras aplicadas ao ER. Continuar?"
                wire:loading.attr="disabled" wire:target="regenerate"
                class="rounded-lg border border-cyan-400/20 bg-cyan-400/10 px-3 py-2 text-xs font-semibold text-cyan-200 transition hover:bg-cyan-400/15 disabled:opacity-50">
                <span wire:loading.remove wire:target="regenerate">↻ Regenerar do ER</span>
                <span wire:loading wire:target="regenerate">Transformando…</span>
            </button>
        </div>
    </header>

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
                :config="['connectionMode' => 'loose', 'nodesDraggable' => true, 'nodesConnectable' => false, 'elementsSelectable' => true]"
                @node-drag-end="onNodeDragEnd" style="width: 100%; height: 100%;">
                <x-slot:node>
                    <article class="rel-node">
                        <header class="rel-node-head">
                            <div>
                                <span x-text="node.data.kind === 'associative' ? 'relação associativa' : (node.data.kind === 'multivalued' ? 'atributo multivalorado' : 'relação')"></span>
                                <strong x-text="node.data.name"></strong>
                            </div>
                            <span class="rel-node-count" x-text="node.data.columns.length"></span>
                        </header>
                        <div class="rel-columns">
                            <template x-for="column in node.data.columns" :key="column.id">
                                <div class="rel-column">
                                    <span class="rel-key" :class="{ 'is-pk': column.key.includes('PK'), 'is-fk': column.key.includes('FK') }" x-text="column.key || '—'"></span>
                                    <span class="rel-column-name" x-text="column.name"></span>
                                    <span class="rel-column-type" x-text="column.type"></span>
                                    <span x-show="column.nullable" class="rel-null">NULL</span>
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
