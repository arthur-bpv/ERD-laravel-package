@props([
    'mode',
    'dialect' => 'mysql',
    'hasDraftNotice' => false,
    'diagramName',
    'relationalDiagramId' => null,
    'relationalOutdated' => false,
    'sourceDiagramId' => null,
    'sourceDiagramName' => null,
    'entitiesCount' => 0,
    'relationsCount' => 0,
    'tablesCount' => 0,
    'columnsCount' => 0,
    'foreignKeysCount' => 0,
])

@php($isEr = $mode === 'er')
<header class="board-toolbar board-toolbar--{{ $mode }}">
    <div class="board-toolbar-main">
        <div class="board-toolbar-left">
        <div class="board-toolbar-brand">
            <span class="board-toolbar-logo" aria-hidden="true">{{ $isEr ? 'ER' : 'RM' }}</span>
            <div class="board-toolbar-title">
                <h1>{{ $isEr ? 'ER editor' : 'Relational model' }}</h1>
                <p title="{{ $diagramName }}">{{ $diagramName }}</p>
            </div>
        </div>

            @unless ($isEr)
                <label class="board-dialect">
                    <span>Database</span>
                    <select x-model="dialect" @change="$wire.setDialect($event.target.value)" aria-label="Target database">
                        @foreach (\Makermind\Erd4dev\Support\DataTypeCatalog::DIALECTS as $key => $info)
                            <option value="{{ $key }}" @selected($dialect === $key)>{{ $info['label'] }}</option>
                        @endforeach
                    </select>
                </label>
            @endunless
        </div>

        <nav class="board-model-tabs" aria-label="Project models">
            @if ($isEr)
                <a class="is-active" aria-current="page">ER model</a>
                @if ($relationalDiagramId)
                    <button type="button" wire:click="convertToRelational" wire:loading.attr="disabled" wire:target="convertToRelational"
                        @if ($relationalOutdated) title="The relational model has not been regenerated since the last ER changes" @endif>
                        Relational model
                        @if ($relationalOutdated)
                            <span class="board-tab-flag" aria-label="Relational model is outdated">!</span>
                        @endif
                    </button>
                @else
                    <button type="button" wire:click="convertToRelational" wire:loading.attr="disabled" wire:target="convertToRelational">Relational model</button>
                @endif
            @else
                @if ($sourceDiagramId)
                    <a wire:navigate href="{{ route('erd.boards.er', $sourceDiagramId) }}">ER model</a>
                @endif
                <a class="is-active" aria-current="page">Relational model</a>
            @endif
        </nav>

        <div class="board-toolbar-actions">
            <button type="button" class="board-toolbar-button" wire:click="organizeBoard" wire:loading.attr="disabled" wire:target="organizeBoard" title="Arrange board">
                <span wire:loading.remove wire:target="organizeBoard">Arrange</span>
                <span wire:loading wire:target="organizeBoard">Arranging…</span>
            </button>
            @if (! $isEr && ! $sourceDiagramId)
                <button type="button" class="board-toolbar-button" wire:click="openTableCreator">New table</button>
            @endif
            @if (! $isEr && ! $sourceDiagramId)
                <button type="button" class="board-toolbar-button" wire:click="openStructureEditor">Relationships</button>
            @endif
            {{-- Both boards use this save button and display confirmation through their own saved event. --}}
            <button type="button" data-board-save class="board-toolbar-button board-toolbar-save" title="Save (Ctrl+S / Cmd+S)" aria-keyshortcuts="Control+s Meta+s" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Save</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
            <details class="board-toolbar-menu" @click.outside="$el.open = false">
                <summary>More <span aria-hidden="true">⋯</span></summary>
                <div class="board-menu-panel">
                    <div class="board-menu-context">
                        @if ($isEr)
                            <strong>{{ $diagramName }}</strong>
                            <span>{{ $entitiesCount }} entities · {{ $relationsCount }} relationships</span>
                        @else
                            <strong>{{ $sourceDiagramName }}</strong>
                            <span>{{ $tablesCount }} tables · {{ $columnsCount }} columns · {{ $foreignKeysCount }} FKs</span>
                        @endif
                    </div>
                    @if (! $isEr && ! $sourceDiagramId)
                        <button type="button" class="board-menu-compact" wire:click="openTableCreator" @click="$el.closest('details').open = false">New table</button>
                        <button type="button" class="board-menu-compact" wire:click="openStructureEditor" @click="$el.closest('details').open = false">Relationships</button>
                    @endif
                    <button type="button" class="board-menu-compact" wire:click="organizeBoard" @click="$el.closest('details').open = false">Arrange</button>
                    <button type="button" class="board-menu-save" wire:click="save" @click="$el.closest('details').open = false">Save</button>
                    @unless ($isEr)
                        <button type="button" wire:click="openSqlPreview" wire:loading.attr="disabled" wire:target="openSqlPreview" @click="$el.closest('details').open = false">Generate SQL</button>
                        <button type="button" wire:click="generateBlueprintDraft" wire:loading.attr="disabled" wire:target="generateBlueprintDraft" @click="$el.closest('details').open = false">Generate Blueprint draft</button>
                    @endunless
                    <div class="board-menu-label">Export image</div>
                    <button type="button" data-export-name="{{ $diagramName }}" @click="window.exportBoardImage($el, 'png'); $el.closest('details').open = false" title="High-resolution PNG for printing">Download high-resolution PNG</button>
                    <button type="button" data-export-name="{{ $diagramName }}" @click="window.exportBoardImage($el, 'jpg'); $el.closest('details').open = false">Download JPG</button>
                    <button type="button" @click="guideOpen = !guideOpen; $el.closest('details').open = false">Model guide</button>
                    @if (! $isEr && $hasDraftNotice)
                        <button type="button" wire:click="$set('showDraftNotice', true)" @click="$el.closest('details').open = false">Last draft</button>
                    @endif
                    <button type="button" wire:click="toggleJson" @click="$el.closest('details').open = false">View JSON</button>
                    <button type="button"
                        x-data="{ dark: document.documentElement.classList.contains('dark') }"
                        @erd-theme-changed.window="dark = $event.detail.theme === 'dark'"
                        @click="dark = !dark; window.setErdTheme(dark ? 'dark' : 'light'); $el.closest('details').open = false"
                        :aria-pressed="dark.toString()"
                        :aria-label="dark ? 'Switch to light theme' : 'Switch to dark theme'">
                        <span x-text="dark ? 'Light theme' : 'Dark theme'"></span>
                    </button>
                    @if ($isEr)
                        @if ($relationalDiagramId)
                            <button type="button" class="board-menu-special" wire:click="convertToRelational" wire:loading.attr="disabled" wire:target="convertToRelational">Open relational model</button>
                        @else
                            <button type="button" class="board-menu-special" wire:click="convertToRelational" wire:loading.attr="disabled" wire:target="convertToRelational">Convert to relational</button>
                        @endif
                        @if ($relationalOutdated)
                            <p class="board-menu-flag">The relational model is outdated. Regenerate it from the relational board when ready.</p>
                        @endif
                    @endif
                    @if (! $isEr && $sourceDiagramId)
                        <button type="button" class="board-menu-special" wire:click="regenerate"
                            wire:confirm="Regenerating replaces all manual edits with the current ER model. Continue?"
                            wire:loading.attr="disabled" wire:target="regenerate">Regenerate from ER</button>
                    @endif
                    <a wire:navigate href="{{ route('erd.dashboard') }}">Back to projects</a>
                </div>
            </details>
        </div>
    </div>

    @if ($isEr)
        <div class="board-toolbar-workflow">
            <form wire:submit.prevent="createEntity" class="board-create-form">
                <label for="new-entity-name">New entity</label>
                <input id="new-entity-name" type="text" wire:model="newEntityName" placeholder="E.g. order">
                <button type="submit"><span aria-hidden="true">＋</span> Add</button>
            </form>
        </div>
    @endif
</header>
