@props(['model'])

<x-flow-panel position="top-right" class="board-background-panel"
    x-data="{
        open: false,
        background: 'dots',
        choose(pattern) {
            this.$flow.patchConfig({ background: pattern });
            this.background = pattern;
            this.open = false;
        },
    }"
    @keydown.escape.window="open = false" @click.outside="open = false">
    <button type="button" class="board-background-trigger" aria-label="Alterar fundo do modelo {{ $model }}"
        aria-haspopup="true" :aria-expanded="open.toString()" title="Alterar fundo"
        @click="open = !open">▦ <span>Fundo</span></button>
    <div x-show="open" x-cloak class="board-background-options" role="group" aria-label="Estilo do fundo">
        <button type="button" :aria-pressed="(background === 'dots').toString()"
            @click="choose('dots')">Pontilhado</button>
        <button type="button" :aria-pressed="(background === 'none').toString()"
            @click="choose('none')">Liso</button>
        <button type="button" :aria-pressed="(background === 'lines').toString()"
            @click="choose('lines')">Grade</button>
        <button type="button" :aria-pressed="(background === 'cross').toString()"
            @click="choose('cross')">Cruzes</button>
    </div>
</x-flow-panel>
