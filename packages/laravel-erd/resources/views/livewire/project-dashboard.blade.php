<main class="min-h-screen bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
    <div class="mx-auto max-w-7xl px-6 py-10 lg:px-10 lg:py-14">
        <div class="mb-7 flex justify-end">
            <button type="button"
                x-data="{ dark: document.documentElement.classList.contains('dark') }"
                @erd-theme-changed.window="dark = $event.detail.theme === 'dark'"
                @click="window.setErdTheme(dark ? 'light' : 'dark')"
                :aria-pressed="dark.toString()"
                :aria-label="dark ? 'Switch to light theme' : 'Switch to dark theme'"
                class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-indigo-300 hover:text-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-400 dark:border-white/15 dark:bg-slate-900 dark:text-slate-200 dark:hover:border-indigo-400 dark:hover:text-indigo-200">
                <span aria-hidden="true" x-text="dark ? '☀' : '☾'"></span>
                <span x-text="dark ? 'Light theme' : 'Dark theme'"></span>
            </button>
        </div>
        <header class="flex flex-col gap-8 border-b border-slate-200 pb-10 dark:border-white/10 lg:flex-row lg:items-end lg:justify-between">
            <div class="max-w-3xl">
                <p class="mb-3 text-xs font-semibold uppercase tracking-[0.24em] text-indigo-700 dark:text-indigo-300">Modeling workspace</p>
                <h1 class="text-4xl font-semibold tracking-tight text-slate-950 dark:text-white sm:text-5xl">From concept to database, one step at a time.</h1>
                <p class="mt-4 max-w-2xl text-base leading-7 text-slate-600 dark:text-slate-400">
                    Start with an ER model and convert it to a linked relational model, or import SQL or a database connection as an independent editable relational snapshot.
                </p>
            </div>

            <div class="w-full space-y-3 lg:max-w-md">
                <form wire:submit="createProject" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/5 dark:shadow-none">
                    <label for="project-name" class="text-sm font-medium text-slate-800 dark:text-slate-200">New project</label>
                    <div class="mt-2 flex gap-2">
                        <input id="project-name" wire:model="projectName" type="text" placeholder="E.g. library system"
                            class="min-w-0 flex-1 rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 outline-none placeholder:text-slate-400 focus:border-indigo-400 dark:border-white/10 dark:bg-slate-900 dark:text-white dark:placeholder:text-slate-600">
                        <button type="submit" class="rounded-xl bg-indigo-500 px-4 py-3 text-sm font-semibold text-white transition hover:bg-indigo-400 disabled:opacity-60" wire:loading.attr="disabled" wire:target="createProject">
                            Create ER
                        </button>
                    </div>
                    @error('projectName') <p class="mt-2 text-xs text-rose-700 dark:text-rose-300">{{ $message }}</p> @enderror
                </form>

                <button
                    type="button"
                    wire:click="toggleImport('json')"
                    class="flex w-full items-center justify-between rounded-2xl border border-dashed border-indigo-300 bg-indigo-50 px-4 py-3 text-left text-sm font-semibold text-indigo-800 transition hover:border-indigo-400 hover:bg-indigo-100 dark:border-indigo-300/25 dark:bg-indigo-400/[0.06] dark:text-indigo-200 dark:hover:border-indigo-300/50 dark:hover:bg-indigo-400/10"
                >
                    <span><span aria-hidden="true">↑</span> Import JSON project</span>
                    <span class="text-xs font-normal text-slate-600 dark:text-slate-500">ER model JSON</span>
                </button>
                <button type="button" wire:click="toggleImport('sql')"
                    class="flex w-full items-center justify-between rounded-2xl border border-dashed border-cyan-300 bg-cyan-50 px-4 py-3 text-left text-sm font-semibold text-cyan-800 transition hover:border-cyan-400 hover:bg-cyan-100 dark:border-cyan-300/25 dark:bg-cyan-400/[0.06] dark:text-cyan-200 dark:hover:border-cyan-300/50 dark:hover:bg-cyan-400/10">
                    <span><span aria-hidden="true">↑</span> Import SQL project</span>
                    <span class="text-xs font-normal text-slate-600 dark:text-slate-500">Database schema script</span>
                </button>
                <button type="button" wire:click="toggleImport('database')" class="flex w-full items-center justify-between rounded-2xl border border-dashed border-cyan-300 bg-cyan-50 px-4 py-3 text-left text-sm font-semibold text-cyan-800 dark:border-cyan-300/25 dark:bg-cyan-400/10 dark:text-cyan-200">
                    Import database connection
                </button>
            </div>
        </header>

        <div
            x-show="$wire.showImport"
            x-cloak
            x-transition.opacity
            x-data="{ readingImportFile: false }"
            class="er-import-overlay"
            role="dialog"
            aria-modal="true"
            aria-labelledby="import-project-title"
            @click.self="$wire.showImport = false"
            @keydown.escape.window="$wire.showImport = false"
        >
            <div class="er-import-dialog">
                <div class="er-import-heading">
                    <div>
                        <p class="er-import-kicker">New project</p>
                        <h2 id="import-project-title">{{ match ($importFormat) { 'sql' => 'Import SQL schema', 'database' => 'Import database connection', default => 'Import ER diagram' } }}</h2>
                    </div>
                    <button type="button" wire:click="$set('showImport', false)" aria-label="Close import">✕</button>
                </div>

                <p>The file will be validated and saved as an independent project. Existing projects will not be changed.</p>

                <label class="er-import-field">
                    <span>Project name</span>
                    <input wire:model="importProjectName" type="text" maxlength="120" placeholder="E.g. imported system">
                </label>
                @error('importProjectName') <p class="er-import-error" role="alert">{{ $message }}</p> @enderror

                @if ($importFormat === 'database')
                    <p>Import an editable schema snapshot from a configured Laravel connection. Only metadata is read; editing the board does not change the source database.</p>
                    <label class="er-import-field">
                        <span>Project connection</span>
                        <select wire:model="importConnection" class="rounded-lg border p-2 dark:bg-slate-900">
                            <option value="">Select a connection</option>
                            @foreach ($this->databaseConnections as $name => $driver)
                                <option value="{{ $name }}">{{ $name }} ({{ $driver }})</option>
                            @endforeach
                        </select>
                    </label>
                    <p>Configure the connection and credentials in your Laravel project before importing.</p>
                @elseif ($importFormat === 'sql')
                    <p>Tables, columns and keys are imported as an editable relational model. Data, routines, defaults, checks and automatic generation are omitted. Single and composite PRIMARY, UNIQUE and FOREIGN keys are recognized in CREATE TABLE and ALTER TABLE statements; UNIQUE indexes are also imported.</p>
                    <label class="er-import-drop">
                        <span>Select SQL file</span>
                        <small>Maximum 2 MB</small>
                        <input type="file" accept=".sql,text/sql,application/sql,text/plain" aria-label="Project SQL file"
                            @change="
                                const file = $event.target.files?.[0];
                                if (!file) return;
                                if (file.size > 2000000) {
                                    $wire.set('importError', 'The file exceeds the 2 MB limit.');
                                    $event.target.value = '';
                                    return;
                                }
                                readingImportFile = true;
                                $wire.set('importError', '');
                                file.text()
                                    .then(text => $wire.set('importSql', text))
                                    .catch(() => $wire.set('importError', 'Could not read the SQL file.'))
                                    .finally(() => readingImportFile = false);
                            ">
                    </label>
                    <details class="er-import-advanced">
                        <summary>Or paste SQL manually</summary>
                        <textarea wire:model.live.debounce.500ms="importSql" rows="8" placeholder="CREATE TABLE ..." aria-label="SQL to import"></textarea>
                    </details>
                    <p role="status">
                        @if ($this->detectedSqlDialect)
                            Detected database: <strong>{{ \Makermind\Erd4dev\Support\DataTypeCatalog::DIALECTS[$this->detectedSqlDialect]['label'] }}</strong>
                        @else
                            Database not identified. Generic SQL may match multiple databases; select one below.
                        @endif
                    </p>
                    <label class="er-import-field">
                        <span>Database</span>
                        <select wire:model="importDialect" class="rounded-lg border border-slate-300 bg-white p-2 text-slate-900 dark:border-white/15 dark:bg-slate-900 dark:text-white">
                            <option value="">Detect automatically</option>
                            @foreach (\Makermind\Erd4dev\Support\DataTypeCatalog::DIALECTS as $value => $config)
                                <option value="{{ $value }}">{{ $config['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                @else
                    <label class="er-import-drop">
                        <span>Select JSON file</span>
                        <small>Maximum 2 MB</small>
                        <input
                            type="file"
                            accept=".json,application/json"
                            aria-label="Project JSON file"
                            @change="
                                const file = $event.target.files?.[0];
                                if (!file) return;
                                if (file.size > 2000000) {
                                    $wire.set('importError', 'The file exceeds the 2 MB limit.');
                                    $event.target.value = '';
                                    return;
                                }
                                readingImportFile = true;
                                $wire.set('importError', '');
                                file.text()
                                    .then(text => $wire.set('importJson', text))
                                    .catch(() => $wire.set('importError', 'Could not read the JSON file.'))
                                    .finally(() => readingImportFile = false);
                            "
                        >
                    </label>
                    <details class="er-import-advanced">
                        <summary>Or paste JSON manually</summary>
                        <textarea wire:model="importJson" rows="8" placeholder='{"entities":[],"relations":[]}' aria-label="JSON to import"></textarea>
                    </details>

                @endif

                @if ($importError)
                    <p class="er-import-error" role="alert">{{ $importError }}</p>
                @endif

                @if ($importFormat === 'json')
                    <div class="er-import-example">
                        <button type="button" wire:click="createAnalysisProject">Create conversion example project</button>
                        <a href="{{ route('erd.assets', ['file' => 'examples/marketplace-er.json']) }}" download>Marketplace</a>
                        <a href="{{ route('erd.assets', ['file' => 'examples/clinic-er.json']) }}" download>Clinic</a>
                        <a href="{{ route('erd.assets', ['file' => 'examples/er-conversion-cases.json']) }}" download>Conversion matrix</a>
                    </div>

                @endif
                <p x-show="readingImportFile" x-cloak class="text-xs text-indigo-700 dark:text-indigo-300" role="status">Reading file…</p>
                <button type="button" wire:click="{{ match ($importFormat) { 'sql' => 'importSqlProject', 'database' => 'importDatabaseProject', default => 'importProject' } }}" wire:loading.attr="disabled" wire:target="importProject,importSqlProject,importDatabaseProject"
                    :disabled="readingImportFile">
                    <span wire:loading.remove wire:target="importProject,importSqlProject,importDatabaseProject">Create imported project</span>
                    <span wire:loading wire:target="importProject,importSqlProject,importDatabaseProject">Importing…</span>
                </button>
            </div>
        </div>

        <section class="py-10">
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-slate-950 dark:text-white">Your projects</h2>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-500">ER-to-relational projects and imported relational models.</p>
                </div>
                <span class="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs text-slate-600 dark:border-white/10 dark:bg-transparent dark:text-slate-400">{{ $this->projects->count() }} projects</span>
            </div>

            @if ($this->projects->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center dark:border-white/15 dark:bg-white/[0.03]">
                    <div class="mx-auto flex size-12 items-center justify-center rounded-2xl bg-indigo-100 text-xl text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300">ER</div>
                    <h3 class="mt-5 text-lg font-medium text-slate-950 dark:text-white">Start with a conceptual model</h3>
                    <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-600 dark:text-slate-500">Name your project above to start mapping entities and relationships.</p>
                </div>
            @else
                <div class="grid gap-5 xl:grid-cols-2">
                    @foreach ($this->projects as $project)
                        <article wire:key="project-{{ $project->id }}" class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-slate-900/80 dark:shadow-2xl dark:shadow-black/10">
                            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 px-6 py-5 dark:border-white/10 sm:flex-nowrap">
                                <div class="min-w-0 w-full flex-1 sm:w-auto">
                                    @if ($editingProjectId === $project->id)
                                        <form wire:submit="renameProject" class="space-y-2">
                                            <label for="rename-project-{{ $project->id }}" class="block text-xs font-medium text-slate-700 dark:text-slate-300">Project name</label>
                                            <input id="rename-project-{{ $project->id }}" wire:model="editingProjectName" type="text" maxlength="120"
                                                class="w-full rounded-lg border border-indigo-400 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:ring-2 focus:ring-indigo-400/40 dark:bg-slate-950 dark:text-white">
                                            @error('editingProjectName') <p class="text-xs text-rose-700 dark:text-rose-300" role="alert">{{ $message }}</p> @enderror
                                            <div class="flex gap-2">
                                                <button type="submit" wire:loading.attr="disabled" wire:target="renameProject"
                                                    class="rounded-lg bg-indigo-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-400 disabled:opacity-60">Save name</button>
                                                <button type="button" wire:click="cancelRename"
                                                    class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50 dark:border-white/15 dark:text-slate-300 dark:hover:bg-white/5">Cancel</button>
                                            </div>
                                        </form>
                                    @else
                                        <h3 class="truncate text-lg font-semibold text-slate-950 dark:text-white">{{ $project->name }}</h3>
                                        <p class="mt-1 text-xs text-slate-600 dark:text-slate-500">Updated {{ $project->updated_at->locale('en')->diffForHumans() }}</p>
                                        @if (\Makermind\Erd4dev\Support\DataTypeCatalog::has($project->data['dialect'] ?? ''))
                                            <p class="mt-2 text-xs font-medium text-cyan-800 dark:text-cyan-300">Database: {{ \Makermind\Erd4dev\Support\DataTypeCatalog::DIALECTS[$project->data['dialect']]['label'] }}</p>
                                        @endif
                                    @endif
                                </div>
                                <div class="flex w-full items-center justify-end gap-2 sm:w-auto">
                                    <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-800 dark:bg-emerald-400/10 dark:text-emerald-300">In progress</span>
                                    @if ($editingProjectId !== $project->id)
                                        <button type="button" wire:click="startRename({{ $project->id }})"
                                            aria-label="Rename project {{ $project->name }}" title="Rename project"
                                            class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:border-indigo-400 hover:text-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-300 dark:border-white/15 dark:text-slate-300 dark:hover:border-indigo-300/40 dark:hover:text-indigo-200">
                                            Rename
                                        </button>
                                    @endif
                                    <button
                                        type="button"
                                        wire:click="deleteProject({{ $project->id }})"
                                        wire:confirm="Delete project '{{ $project->name }}' and its models? This cannot be undone."
                                        wire:loading.attr="disabled"
                                        wire:target="deleteProject({{ $project->id }})"
                                        aria-label="Delete project {{ $project->name }}"
                                        title="Delete project"
                                        class="grid size-8 place-items-center rounded-lg border border-rose-200 bg-rose-50 text-sm text-rose-700 transition hover:border-rose-300 hover:bg-rose-100 focus:outline-none focus:ring-2 focus:ring-rose-300 disabled:opacity-50 dark:border-rose-400/15 dark:bg-rose-400/5 dark:text-rose-300 dark:hover:border-rose-300/35 dark:hover:bg-rose-400/15"
                                    >
                                        🗑
                                    </button>
                                </div>
                            </div>

                            @if ($project->type === \Makermind\Erd4dev\Models\Diagram::TYPE_RELATIONAL)
                                <div class="p-5">
                                    <a wire:navigate href="{{ route('erd.boards.relational', $project) }}" class="block rounded-2xl border border-cyan-200 bg-cyan-50 p-5 dark:border-cyan-400/20 dark:bg-cyan-400/10">
                                        <strong class="block text-base">Relational model</strong>
                                        <span class="mt-1 block text-sm">{{ ($project->data['origin']['type'] ?? 'sql') === 'database' ? 'Imported from database' : 'Imported from SQL' }} · independent editing · no ER model</span>
                                        <span class="mt-5 block text-sm font-medium text-cyan-800 dark:text-cyan-300">Open board →</span>
                                    </a>
                                </div>
                            @else
                            <div class="grid gap-3 p-5 sm:grid-cols-[1fr_auto_1fr] sm:items-stretch">
                                <a wire:navigate href="{{ route('erd.boards.er', $project) }}" class="group rounded-2xl border border-indigo-200 bg-indigo-50 p-5 transition hover:border-indigo-300 hover:bg-indigo-100 dark:border-indigo-400/20 dark:bg-indigo-400/10 dark:hover:border-indigo-300/40 dark:hover:bg-indigo-400/15">
                                    <span class="text-xs font-semibold uppercase tracking-widest text-indigo-700 dark:text-indigo-300">Step 1</span>
                                    <strong class="mt-6 block text-base text-slate-950 dark:text-white">ER model</strong>
                                    <span class="mt-1 block text-sm text-slate-600 dark:text-slate-400">Entities, attributes and relationships</span>
                                    <span class="mt-5 block text-sm font-medium text-indigo-700 dark:text-indigo-300">Open board →</span>
                                </a>

                                <div class="hidden items-center text-slate-400 dark:text-slate-700 sm:flex">→</div>

                                @if ($project->relationalDiagram)
                                    <a wire:navigate href="{{ route('erd.boards.relational', $project->relationalDiagram) }}" class="group rounded-2xl border border-cyan-200 bg-cyan-50 p-5 transition hover:border-cyan-300 hover:bg-cyan-100 dark:border-cyan-400/20 dark:bg-cyan-400/10 dark:hover:border-cyan-300/40 dark:hover:bg-cyan-400/15">
                                        <span class="text-xs font-semibold uppercase tracking-widest text-cyan-800 dark:text-cyan-300">Step 2</span>
                                        @if (in_array($project->id, $this->outdatedRelational, true))
                                            <span class="mt-3 inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-2.5 py-1 text-[11px] font-semibold text-amber-800 dark:bg-amber-400/15 dark:text-amber-300"
                                                title="The ER model changed after the last generation. Regenerate from the relational board.">
                                                <span aria-hidden="true">!</span> Outdated
                                            </span>
                                        @endif
                                        <strong class="mt-6 block text-base text-slate-950 dark:text-white">Relational model</strong>
                                        <span class="mt-1 block text-sm text-slate-600 dark:text-slate-400">Linked to ER model</span>
                                        <span class="mt-5 block text-sm font-medium text-cyan-800 dark:text-cyan-300">Open board →</span>
                                    </a>
                                @else
                                    <button wire:click="createRelational({{ $project->id }})" wire:loading.attr="disabled" wire:target="createRelational({{ $project->id }})"
                                        class="rounded-2xl border border-dashed border-slate-300 p-5 text-left transition hover:border-cyan-400 hover:bg-cyan-50 disabled:opacity-60 dark:border-white/15 dark:hover:border-cyan-300/40 dark:hover:bg-cyan-400/5">
                                        <span class="text-xs font-semibold uppercase tracking-widest text-slate-600 dark:text-slate-500">Step 2</span>
                                        <strong class="mt-6 block text-base text-slate-800 dark:text-slate-200">Create relational model</strong>
                                        <span class="mt-1 block text-sm text-slate-600 dark:text-slate-500">Available from this ER model</span>
                                        <span class="mt-5 block text-sm font-medium text-cyan-800 dark:text-cyan-300">Continue project →</span>
                                    </button>
                                @endif
                            </div>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</main>
