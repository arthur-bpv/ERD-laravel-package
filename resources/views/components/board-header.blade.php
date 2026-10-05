@props([
    'mode',
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
        <div class="board-toolbar-brand">
            <span class="board-toolbar-logo" aria-hidden="true">{{ $isEr ? 'ER' : 'RE' }}</span>
            <div class="board-toolbar-title">
                <h1>{{ $isEr ? 'Modelador ER' : 'Modelo Relacional' }}</h1>
                <p title="{{ $diagramName }}">{{ $diagramName }}</p>
            </div>
        </div>

        <nav class="board-model-tabs" aria-label="Modelos do projeto">
            @if ($isEr)
                <a class="is-active" aria-current="page">Modelo ER</a>
                @if ($relationalDiagramId)
                    <a wire:navigate href="{{ route('boards.relational', $relationalDiagramId) }}"
                        @if ($relationalOutdated) title="O modelo relacional não foi regerado após as últimas mudanças deste ER" @endif>
                        Modelo Relacional
                        @if ($relationalOutdated)
                            <span class="board-tab-flag" aria-label="Modelo relacional desatualizado">!</span>
                        @endif
                    </a>
                @else
                    <button type="button" wire:click="convertToRelational" wire:loading.attr="disabled" wire:target="convertToRelational">Modelo Relacional</button>
                @endif
            @else
                <a wire:navigate href="{{ route('boards.er', $sourceDiagramId) }}">Modelo ER</a>
                <a class="is-active" aria-current="page">Modelo Relacional</a>
            @endif
        </nav>

        <div class="board-toolbar-actions">
            @unless ($isEr)
                <label class="board-dialect">
                    <span>Banco</span>
                    <select x-model="dialect" @change="$wire.setDialect($event.target.value)" aria-label="Banco de dados de destino">
                        <template x-for="(info, key) in catalog" :key="key">
                            <option :value="key" x-text="info.label"></option>
                        </template>
                    </select>
                </label>
            @endunless
            <button type="button" class="board-toolbar-button" wire:click="organizeBoard" wire:loading.attr="disabled" wire:target="organizeBoard" title="Organizar quadro">
                <span wire:loading.remove wire:target="organizeBoard">Organizar</span>
                <span wire:loading wire:target="organizeBoard">Organizando…</span>
            </button>
            <details class="board-toolbar-menu board-toolbar-export" @click.outside="$el.open = false">
                <summary>Exportar imagem</summary>
                <div class="board-menu-panel">
                    <button type="button" data-export-name="{{ $diagramName }}" @click="window.exportBoardImage($el, 'png'); $el.closest('details').open = false">Baixar PNG</button>
                    <button type="button" data-export-name="{{ $diagramName }}" @click="window.exportBoardImage($el, 'jpg'); $el.closest('details').open = false">Baixar JPG</button>
                </div>
            </details>
            @unless ($isEr)
                <button type="button" class="board-toolbar-button" wire:click="openSqlPreview" wire:loading.attr="disabled" wire:target="openSqlPreview">
                    <span wire:loading.remove wire:target="openSqlPreview">Gerar SQL</span>
                    <span wire:loading wire:target="openSqlPreview">Gerando…</span>
                </button>
            @endunless
            <button type="button" class="board-toolbar-button board-toolbar-save" wire:click="save" wire:loading.attr="disabled" wire:target="save"
                @if ($isEr) @saved.window="window.alert('✅ Diagrama salvo com sucesso!')" @endif>
                <span wire:loading.remove wire:target="save">Salvar</span>
                <span wire:loading wire:target="save">Salvando…</span>
            </button>
            <details class="board-toolbar-menu" @click.outside="$el.open = false">
                <summary>Mais <span aria-hidden="true">⋯</span></summary>
                <div class="board-menu-panel">
                    <div class="board-menu-context">
                        @if ($isEr)
                            <strong>{{ $diagramName }}</strong>
                            <span>{{ $entitiesCount }} entidades · {{ $relationsCount }} relacionamentos</span>
                        @else
                            <strong>{{ $sourceDiagramName }}</strong>
                            <span>{{ $tablesCount }} tabelas · {{ $columnsCount }} colunas · {{ $foreignKeysCount }} FKs</span>
                        @endif
                    </div>
                    <button type="button" @click="guideOpen = !guideOpen; $el.closest('details').open = false">Guia do modelo</button>
                    <button type="button" wire:click="toggleJson" @click="$el.closest('details').open = false">Ver JSON</button>
                    <button type="button"
                        x-data="{ dark: document.documentElement.classList.contains('dark') }"
                        @erd-theme-changed.window="dark = $event.detail.theme === 'dark'"
                        @click="dark = !dark; window.setErdTheme(dark ? 'dark' : 'light'); $el.closest('details').open = false"
                        :aria-pressed="dark.toString()"
                        :aria-label="dark ? 'Ativar tema claro' : 'Ativar tema escuro'">
                        <span x-text="dark ? 'Tema claro' : 'Tema escuro'"></span>
                    </button>
                    @if ($isEr)
                        @if ($relationalDiagramId)
                            <a class="board-menu-special" wire:navigate href="{{ route('boards.relational', $relationalDiagramId) }}">Abrir modelo relacional</a>
                        @else
                            <button type="button" class="board-menu-special" wire:click="convertToRelational" wire:loading.attr="disabled" wire:target="convertToRelational">Converter para relacional</button>
                        @endif
                        @if ($relationalOutdated)
                            <p class="board-menu-flag">O modelo relacional está desatualizado. Ele só é regerado por você, no quadro relacional.</p>
                        @endif
                    @endif
                    @unless ($isEr)
                        <button type="button" class="board-menu-special" wire:click="regenerate"
                            wire:confirm="Regenerar substitui todas as edições manuais deste modelo Relacional pelos dados atuais do ER. Continuar?"
                            wire:loading.attr="disabled" wire:target="regenerate">Regenerar do ER</button>
                    @endunless
                    <a wire:navigate href="{{ route('dashboard') }}">Voltar aos projetos</a>
                </div>
            </details>
        </div>
    </div>

    @if ($isEr)
        <div class="board-toolbar-workflow">
            <form wire:submit.prevent="createEntity" class="board-create-form">
                <label for="new-entity-name">Nova entidade</label>
                <input id="new-entity-name" type="text" wire:model="newEntityName" placeholder="Ex.: pedido">
                <button type="submit"><span aria-hidden="true">＋</span> Adicionar</button>
            </form>
        </div>
    @endif
</header>
