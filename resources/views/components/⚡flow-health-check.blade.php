<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use ArtisanFlow\WireFlow\Concerns\WithWireFlow;

new #[Layout('layouts.app')] class extends Component
{
    use WithWireFlow;

    // [LIVEWIRE] estado no servidor
    public int $count = 0;

    // [WIREFLOW <-> ALPINEFLOW] nodes/edges entrelaçados (sync) com o diagrama
    public array $nodes = [
        ['id' => '1', 'position' => ['x' => 40,  'y' => 60],  'data' => ['label' => 'Livewire']],
        ['id' => '2', 'position' => ['x' => 280, 'y' => 60],  'data' => ['label' => 'AlpineFlow']],
    ];

    public array $edges = [
        ['id' => 'e1-2', 'source' => '1', 'target' => '2'],
    ];

    // [LIVEWIRE] ação no servidor → re-render
    public function increment(): void
    {
        $this->count++;
    }

    // [WIREFLOW] contador próprio: não dependemos de count($nodes), que pode
    // estar momentaneamente dessincronizado pelo entangle e gerar IDs repetidos.
    public int $seq = 2;

    // [WIREFLOW] ação no servidor que ALTERA o diagrama no cliente (prova a ponte).
    //
    // IMPORTANTE: usamos flowAddNodes()/flowConnect() do trait — NÃO empurramos
    // direto em $this->nodes[]. Empurrar no array só sincroniza o DADO via entangle:
    // o AlpineFlow até desenha o node, mas nunca o registra no seu store de interação,
    // então ele "nasce sem deixar mexer" (não arrasta). flowAddNodes dispara o evento
    // flow:addNodes, e o AlpineFlow cria o node pelo seu próprio pipeline — totalmente
    // arrastável/selecionável. Em modo :sync o node volta para $this->nodes pelo entangle.
    public function addNode(): void
    {
        $id = (string) (++$this->seq);

        $this->flowAddNodes([
            [
                'id' => $id,
                'position' => ['x' => 160, 'y' => 200],
                'data' => ['label' => "Node servidor #{$id}"],
            ],
        ]);

        $this->flowConnect('2', $id, edgeId: "e2-{$id}");
    }
};
?>

<div class="mx-auto max-w-3xl space-y-6 p-8 font-sans text-gray-800">

    <h1 class="text-2xl font-bold">✅ Health Check — Livewire · Alpine · AlpineFlow · Wireflow</h1>

    {{-- ============================================================= --}}
    {{-- 1) LIVEWIRE: estado no servidor reage a um clique             --}}
    {{-- ============================================================= --}}
    <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
        <h2 class="mb-2 font-semibold">1. Livewire (servidor)</h2>
        <button wire:click="increment"
                class="rounded bg-indigo-600 px-3 py-1.5 text-white hover:bg-indigo-700">
            Incrementar
        </button>
        <span class="ml-3">Contador no servidor: <strong>{{ $count }}</strong></span>
        @if ($count > 0)
            <p class="mt-1 text-sm text-green-600">→ Livewire OK (o número subiu sem recarregar a página).</p>
        @endif
    </section>

    {{-- ============================================================= --}}
    {{-- 2) ALPINE: reatividade 100% no cliente                        --}}
    {{-- ============================================================= --}}
    <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm" x-data="{ aberto: false }">
        <h2 class="mb-2 font-semibold">2. Alpine (cliente)</h2>
        <button @click="aberto = !aberto"
                class="rounded bg-emerald-600 px-3 py-1.5 text-white hover:bg-emerald-700">
            Alternar
        </button>
        <p x-show="aberto" x-cloak class="mt-2 text-sm text-green-600">
            → Alpine OK (este texto aparece/some via x-show, sem ir ao servidor).
        </p>
    </section>

    {{-- ============================================================= --}}
    {{-- 3) ALPINEFLOW + 4) WIREFLOW: diagrama (JS) e ponte servidor   --}}
    {{-- ============================================================= --}}
    <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
        <h2 class="mb-2 font-semibold">3 + 4. AlpineFlow (diagrama/JS+CSS) &amp; Wireflow (ponte servidor)</h2>

        <button wire:click="addNode"
                class="mb-3 rounded bg-rose-600 px-3 py-1.5 text-white hover:bg-rose-700">
            Adicionar node pelo servidor
        </button>

        {{-- :sync entrelaça $nodes/$edges do Livewire com o AlpineFlow.
             wire:ignore impede que o morph do Livewire (em cada re-render) destrua
             o DOM que o AlpineFlow gerencia. Os dados continuam sincronizando pelo
             $wire.entangle() do modo sync — que é reativo e independe do morph. --}}
        <x-flow wire:ignore :nodes="$nodes" :edges="$edges" :sync="true"
                style="width: 100%; height: 320px; border: 1px solid #e5e7eb; border-radius: 8px;">
            <x-slot:node>
                <div x-flow-handle:target.top></div>
                <div class="rounded bg-slate-800 px-3 py-2 text-sm text-white shadow"
                     x-text="node.data.label"></div>
                <div x-flow-handle:source.bottom></div>
            </x-slot:node>
        </x-flow>

        <p class="mt-2 text-sm text-gray-500">
            → Se você vê as caixas conectadas por linhas (com o estilo do tema), o CSS e o JS do AlpineFlow
            carregaram. Clicar em “Adicionar node” prova a ponte Wireflow (servidor → diagrama).
        </p>
    </section>

</div>
