<main class="min-h-screen bg-slate-950 text-slate-100">
    <div class="mx-auto max-w-7xl px-6 py-10 lg:px-10 lg:py-14">
        <header class="flex flex-col gap-8 border-b border-white/10 pb-10 lg:flex-row lg:items-end lg:justify-between">
            <div class="max-w-3xl">
                <p class="mb-3 text-xs font-semibold uppercase tracking-[0.24em] text-indigo-300">Workspace de modelagem</p>
                <h1 class="text-4xl font-semibold tracking-tight text-white sm:text-5xl">Do conceito ao banco, sem pular etapas.</h1>
                <p class="mt-4 max-w-2xl text-base leading-7 text-slate-400">
                    Cada projeto começa no modelo entidade-relacionamento. Quando ele estiver maduro, avance para o modelo relacional vinculado à mesma ideia.
                </p>
            </div>

            <form wire:submit="createProject" class="w-full rounded-2xl border border-white/10 bg-white/5 p-4 lg:max-w-md">
                <label for="project-name" class="text-sm font-medium text-slate-200">Novo projeto</label>
                <div class="mt-2 flex gap-2">
                    <input id="project-name" wire:model="projectName" type="text" placeholder="Ex.: Sistema da biblioteca"
                        class="min-w-0 flex-1 rounded-xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none placeholder:text-slate-600 focus:border-indigo-400">
                    <button type="submit" class="rounded-xl bg-indigo-500 px-4 py-3 text-sm font-semibold text-white transition hover:bg-indigo-400 disabled:opacity-60" wire:loading.attr="disabled" wire:target="createProject">
                        Criar ER
                    </button>
                </div>
                @error('projectName') <p class="mt-2 text-xs text-rose-300">{{ $message }}</p> @enderror
            </form>
        </header>

        <section class="py-10">
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-white">Seus projetos</h2>
                    <p class="mt-1 text-sm text-slate-500">O progresso segue ER → Relacional.</p>
                </div>
                <span class="rounded-full border border-white/10 px-3 py-1 text-xs text-slate-400">{{ $this->projects->count() }} projetos</span>
            </div>

            @if ($this->projects->isEmpty())
                <div class="rounded-3xl border border-dashed border-white/15 bg-white/[0.03] px-6 py-16 text-center">
                    <div class="mx-auto flex size-12 items-center justify-center rounded-2xl bg-indigo-500/15 text-xl text-indigo-300">ER</div>
                    <h3 class="mt-5 text-lg font-medium text-white">Comece pelo modelo conceitual</h3>
                    <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">Dê um nome ao projeto acima. O primeiro board será criado pronto para você mapear entidades e relacionamentos.</p>
                </div>
            @else
                <div class="grid gap-5 xl:grid-cols-2">
                    @foreach ($this->projects as $project)
                        <article wire:key="project-{{ $project->id }}" class="overflow-hidden rounded-3xl border border-white/10 bg-slate-900/80 shadow-2xl shadow-black/10">
                            <div class="flex items-start justify-between gap-4 border-b border-white/10 px-6 py-5">
                                <div>
                                    <h3 class="text-lg font-semibold text-white">{{ $project->name }}</h3>
                                    <p class="mt-1 text-xs text-slate-500">Atualizado {{ $project->updated_at->diffForHumans() }}</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="rounded-full bg-emerald-400/10 px-3 py-1 text-xs font-medium text-emerald-300">Em andamento</span>
                                    <button
                                        type="button"
                                        wire:click="deleteProject({{ $project->id }})"
                                        wire:confirm="Excluir o projeto '{{ $project->name }}' e seu modelo relacional? Esta ação não pode ser desfeita."
                                        wire:loading.attr="disabled"
                                        wire:target="deleteProject({{ $project->id }})"
                                        aria-label="Excluir projeto {{ $project->name }}"
                                        title="Excluir projeto"
                                        class="grid size-8 place-items-center rounded-lg border border-rose-400/15 bg-rose-400/5 text-sm text-rose-300 transition hover:border-rose-300/35 hover:bg-rose-400/15 focus:outline-none focus:ring-2 focus:ring-rose-300 disabled:opacity-50"
                                    >
                                        🗑
                                    </button>
                                </div>
                            </div>

                            <div class="grid gap-3 p-5 sm:grid-cols-[1fr_auto_1fr] sm:items-stretch">
                                <a wire:navigate href="{{ route('boards.er', $project) }}" class="group rounded-2xl border border-indigo-400/20 bg-indigo-400/10 p-5 transition hover:border-indigo-300/40 hover:bg-indigo-400/15">
                                    <span class="text-xs font-semibold uppercase tracking-widest text-indigo-300">Etapa 1</span>
                                    <strong class="mt-6 block text-base text-white">Modelo ER</strong>
                                    <span class="mt-1 block text-sm text-slate-400">Entidades, atributos e relações</span>
                                    <span class="mt-5 block text-sm font-medium text-indigo-300">Abrir board →</span>
                                </a>

                                <div class="hidden items-center text-slate-700 sm:flex">→</div>

                                @if ($project->relationalDiagram)
                                    <a wire:navigate href="{{ route('boards.relational', $project->relationalDiagram) }}" class="group rounded-2xl border border-cyan-400/20 bg-cyan-400/10 p-5 transition hover:border-cyan-300/40 hover:bg-cyan-400/15">
                                        <span class="text-xs font-semibold uppercase tracking-widest text-cyan-300">Etapa 2</span>
                                        <strong class="mt-6 block text-base text-white">Modelo relacional</strong>
                                        <span class="mt-1 block text-sm text-slate-400">Referenciado ao modelo ER</span>
                                        <span class="mt-5 block text-sm font-medium text-cyan-300">Abrir board →</span>
                                    </a>
                                @else
                                    <button wire:click="createRelational({{ $project->id }})" wire:loading.attr="disabled" wire:target="createRelational({{ $project->id }})"
                                        class="rounded-2xl border border-dashed border-white/15 p-5 text-left transition hover:border-cyan-300/40 hover:bg-cyan-400/5 disabled:opacity-60">
                                        <span class="text-xs font-semibold uppercase tracking-widest text-slate-500">Etapa 2</span>
                                        <strong class="mt-6 block text-base text-slate-200">Criar modelo relacional</strong>
                                        <span class="mt-1 block text-sm text-slate-500">Disponível a partir deste ER</span>
                                        <span class="mt-5 block text-sm font-medium text-cyan-300">Continuar projeto →</span>
                                    </button>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</main>
