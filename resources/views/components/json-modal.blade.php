@props([
    'title',
    'downloadAction' => 'downloadJson',
])

{{--
    Modal de JSON compartilhado pelo quadro ER e pelo Relacional.

    Existia uma versão só no ER, escrita com utilitários do Tailwind e com o
    botão "Copiar" no cabeçalho — enquanto o Relacional usava `.rel-modal` e
    não tinha botão nenhum. Como o conteúdo é o mesmo (o JSON do modelo), a
    apresentação também precisa ser: este componente é a única cópia.

    O `x-data` mora no invólucro do modal (e não no rodapé) porque o `x-ref` do
    `<pre>` é irmão do rodapé, e `$refs` só enxerga a árvore do próprio
    elemento. O texto copiado vem do `<pre>` via `textContent` — e não de
    `$wire.jsonPreview` — para não depender de a property computada
    `getJsonPreviewProperty` estar exposta no payload do Livewire.
--}}
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
            <button type="button" wire:click="{{ $downloadAction }}" wire:loading.attr="disabled"
                wire:target="{{ $downloadAction }}">Baixar .json</button>
        </div>
    </div>
</div>