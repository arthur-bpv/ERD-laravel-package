@props(['title'])

<div x-show="$wire.showJson" x-cloak class="rel-modal" role="dialog" aria-modal="true" aria-label="{{ $title }}"
     x-data="{
         copyJson() {
             return window.copyBoardText(this.$refs.jsonBox.textContent);
         },
     }"
     @click.self="$wire.showJson = false" @keydown.escape.window="$wire.showJson = false">
    <div class="rel-modal-card">
        <header>
            <strong>{{ $title }}</strong>
            <button type="button" @click="$wire.showJson = false" aria-label="Close JSON">✕</button>
        </header>
        <pre x-ref="jsonBox">{{ $this->jsonPreview }}</pre>
        <div class="rel-modal-actions">
            <button type="button" @click="copyJson().then(() => showNotice('JSON copied.')).catch(() => showNotice('Could not copy JSON.', 'warning'))">Copy</button>
            <button type="button" wire:click="downloadJson" wire:loading.attr="disabled"
                wire:target="downloadJson">Download .json</button>
        </div>
    </div>
</div>
