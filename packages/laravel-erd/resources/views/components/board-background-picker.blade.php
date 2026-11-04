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
    <button type="button" class="board-background-trigger" aria-label="Change {{ $model }} background"
        aria-haspopup="true" :aria-expanded="open.toString()" title="Change background"
        @click="open = !open">▦ <span>Background</span></button>
    <div x-show="open" x-cloak class="board-background-options" role="group" aria-label="Background style">
        <button type="button" :aria-pressed="(background === 'dots').toString()"
            @click="choose('dots')">Dots</button>
        <button type="button" :aria-pressed="(background === 'none').toString()"
            @click="choose('none')">Solid</button>
        <button type="button" :aria-pressed="(background === 'lines').toString()"
            @click="choose('lines')">Grid</button>
        <button type="button" :aria-pressed="(background === 'cross').toString()"
            @click="choose('cross')">Crosses</button>
    </div>
</x-flow-panel>
