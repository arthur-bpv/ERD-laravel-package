@props(['title'])

{{-- Shared JSON modal for ER and relational boards. x-data wraps the pre and footer so Alpine refs can read displayed text. Copy uses pre.textContent; download calls the shared downloadJson action. --}}
<div x-show="$wire.showJson" x-cloak class="rel-modal"
     x-data="{
         copied: false,
         timer: null,
         copyJson() {
             navigator.clipboard.writeText(this.$refs.jsonBox.textContent);
             this.copied = true;
             window.clearTimeout(this.timer);
             this.timer = window.setTimeout(() => this.copied = false, 2000);
         },
     }"
     @click.self="$wire.showJson = false" @keydown.escape.window="$wire.showJson = false">
    <div class="rel-modal-card">
        <header>
            <strong>{{ $title }}</strong>
            <button type="button" @click="$wire.showJson = false" aria-label="Fechar JSON">✕</button>
        </header>
        <pre x-ref="jsonBox">{{ $this->jsonPreview }}</pre>
        <div class="rel-modal-actions">
            <button type="button" @click="copyJson()" x-text="copied ? 'Copiado!' : 'Copiar'"></button>
            <button type="button" wire:click="downloadJson" wire:loading.attr="disabled"
                wire:target="downloadJson">Baixar .json</button>
        </div>
    </div>
</div>