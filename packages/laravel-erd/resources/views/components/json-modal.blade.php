@props(['title'])

{{-- Shared JSON modal for ER and relational boards. x-data wraps the pre and footer so Alpine refs can read displayed text. Copy uses the shared HTTP-compatible helper and pre.textContent; download calls the shared downloadJson action. --}}
<div x-show="$wire.showJson" x-cloak class="rel-modal" role="dialog" aria-modal="true" aria-label="{{ $title }}"
     x-data="{
         copied: false,
         copying: false,
         copyError: false,
         timer: null,
         async copyJson() {
             this.copying = true;
             this.copied = false;
             this.copyError = false;
             window.clearTimeout(this.timer);
             try {
                 await window.copyBoardText(this.$refs.jsonBox.textContent);
                 this.copied = true;
                 this.timer = window.setTimeout(() => this.copied = false, 2000);
             } catch {
                 this.copyError = true;
                 const range = document.createRange();
                 range.selectNodeContents(this.$refs.jsonBox);
                 const selection = window.getSelection();
                 selection.removeAllRanges();
                 selection.addRange(range);
             } finally {
                 this.copying = false;
             }
         },
         destroy() { window.clearTimeout(this.timer); },
     }"
     @click.self="$wire.showJson = false" @keydown.escape.window="$wire.showJson = false">
    <div class="rel-modal-card">
        <header>
            <strong>{{ $title }}</strong>
            <button type="button" @click="$wire.showJson = false" aria-label="Close JSON">✕</button>
        </header>
        <pre x-ref="jsonBox">{{ $this->jsonPreview }}</pre>
        <p x-show="copyError" x-cloak role="alert" class="px-4 pb-3 text-sm">The browser blocked copying. The JSON is selected: press Ctrl+C or Cmd+C, or download the file.</p>
        <div class="rel-modal-actions">
            <button type="button" @click="copyJson()" :disabled="copying" x-text="copying ? 'Copying…' : (copied ? 'Copied!' : 'Copy')"></button>
            <button type="button" wire:click="downloadJson" wire:loading.attr="disabled"
                wire:target="downloadJson">Download .json</button>
        </div>
    </div>
</div>
