{{--
  VIEW DO COMPONENTE LIVEWIRE "DiagramaBanco".
  Diagrama Entidade-Relacionamento (notação de Chen) — SOMENTE DESIGN — feito com
  os componentes do ArtisanFlow (WireFlow + AlpineFlow).

  Mapa rápido das formas (todas são SHAPES nativos do AlpineFlow, escolhidos no PHP
  pela chave 'shape' do node — ver App\Livewire\DiagramaBanco):
    • Retângulo  -> ENTIDADE        (node sem 'shape')
    • Losango ◇  -> RELACIONAMENTO  ('shape' => 'diamond')
    • Círculo ◯  -> ATRIBUTO        ('shape' => 'circle')

  Docs: WireFlow   https://artisanflow.dev/docs/wireflow
        AlpineFlow https://artisanflow.dev/docs/alpineflow
        Shapes     https://artisanflow.dev/docs/alpineflow/nodes/shapes
        Edges      https://artisanflow.dev/docs/alpineflow/edges/types
--}}

<div style="height:100vh;display:flex;flex-direction:column;background:#030712;font-family:ui-sans-serif,system-ui,sans-serif;">

    {{-- CSS local só para detalhes do DER que não existem como variável nativa:
         sublinhar a PK e centralizar bem o rótulo dentro de losango/círculo. --}}
    <style>
        .erd-node-label { font-size:12px; font-weight:600; text-align:center; line-height:1.2; }
        .erd-pk { text-decoration:underline; text-underline-offset:3px; }
        /* Cor da "borda" das formas recortadas (losango/círculo) no tema escuro. */
        .erd-canvas { --flow-shape-border-color:#334155; }
    </style>

    {{-- HEADER: título + contadores + botão Fit View. --}}
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 24px;background:#0f172a;border-bottom:1px solid #1e293b;flex-shrink:0;">
        <div>
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:8px;height:8px;border-radius:50%;background:#a855f7;box-shadow:0 0 8px #a855f7;"></div>
                <h1 style="color:#f1f5f9;font-size:16px;font-weight:700;margin:0;">Diagrama de Banco (DER · notação de Chen)</h1>
            </div>
            <p style="color:#475569;font-size:12px;margin:2px 0 0 18px;">{{ count($nodes) }} nós · {{ count($edges) }} ligações · somente design</p>
        </div>

        {{-- wire:click="fitView" -> método fitView() no PHP -> flowFitView() (enquadra tudo). --}}
        <button
            wire:click="fitView"
            style="display:flex;align-items:center;gap:6px;padding:7px 14px;background:#1e293b;border:1px solid #334155;border-radius:8px;color:#94a3b8;font-size:12px;cursor:pointer;transition:all 0.15s;"
            onmouseover="this.style.borderColor='#a855f7';this.style.color='#a855f7'"
            onmouseout="this.style.borderColor='#334155';this.style.color='#94a3b8'"
        >
            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/>
            </svg>
            Fit View
        </button>
    </div>

    {{-- ÁREA PRINCIPAL: legenda (topo) + canvas + painel lateral de detalhes. --}}
    <div style="flex:1;display:flex;overflow:hidden;position:relative;">

        {{-- LEGENDA flutuante: explica as 3 formas e as 3 cardinalidades. --}}
        <div style="position:absolute;top:12px;left:12px;z-index:10;background:rgba(15,23,42,0.92);border:1px solid #1e293b;border-radius:10px;padding:12px 14px;display:flex;flex-direction:column;gap:8px;font-size:11px;color:#cbd5e1;backdrop-filter:blur(4px);">
            <div style="color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;font-size:10px;">Notação</div>
            <div style="display:flex;align-items:center;gap:8px;"><span style="display:inline-block;width:18px;height:12px;background:#8b5cf6;border-radius:2px;"></span> Entidade (retângulo)</div>
            <div style="display:flex;align-items:center;gap:8px;"><span style="display:inline-block;width:13px;height:13px;background:#f59e0b;transform:rotate(45deg);"></span> Relacionamento (losango)</div>
            <div style="display:flex;align-items:center;gap:8px;"><span style="display:inline-block;width:13px;height:13px;background:#3b82f6;border-radius:50%;"></span> Atributo (círculo) — <u>PK</u> sublinhada</div>
            <div style="height:1px;background:#1e293b;margin:2px 0;"></div>
            <div style="display:flex;align-items:center;gap:8px;"><span style="width:18px;height:2px;background:#10b981;display:inline-block;"></span> 1 : 1</div>
            <div style="display:flex;align-items:center;gap:8px;"><span style="width:18px;height:2px;background:#3b82f6;display:inline-block;"></span> 1 : N (seta no lado "N")</div>
            <div style="display:flex;align-items:center;gap:8px;"><span style="width:18px;height:2px;background:#a855f7;display:inline-block;"></span> N : M</div>
        </div>

        {{--
          <x-flow> — o canvas do WireFlow.
          Props relevantes aqui (doc: https://artisanflow.dev/docs/wireflow):
            :nodes / :edges     -> dados vindos do componente PHP
            background="dots"   -> fundo pontilhado
            :minimap/:controls  -> minimapa + botões de zoom/pan
            :fit-view-on-init   -> enquadra tudo ao carregar
            class="dark erd-canvas" -> tema escuro nativo + nossa var de borda das formas
            @node-click         -> dispara onNodeClick() no PHP

          As variáveis --flow-node-* abaixo definem o visual PADRÃO dos nós (cor de fundo,
          texto, borda). As ENTIDADES/RELACIONAMENTOS/ATRIBUTOS recebem cor própria via
          classes de status (flow-node-primary/warning/info) definidas no PHP, que se
          sobrepõem a estas no que for cor de status.
          Doc das variáveis: https://artisanflow.dev/docs/alpineflow/theming/css-variables
        --}}
        <x-flow
            :nodes="$nodes"
            :edges="$edges"
            background="dots"
            :minimap="true"
            :controls="true"
            :fit-view-on-init="true"
            class="dark erd-canvas"
            style="flex:1;background:#030712;--flow-container-height:100%;
                   --flow-node-bg:#1e293b;
                   --flow-node-color:#f1f5f9;
                   --flow-node-border:1px solid #334155;
                   --flow-node-hover-border-color:#a855f7;
                   --flow-node-selected-border-color:#a855f7;"
            @node-click="onNodeClick"
        >
            {{--
              <x-slot:node> = TEMPLATE único de TODOS os nós. O AlpineFlow aplica o
              clip-path da forma (diamond/circle) no elemento .flow-node que ENVOLVE
              este conteúdo — então aqui basta os handles + o rótulo centralizado.

              Handles em 4 lados (ocultos): pontos de conexão. Como os edges usam
              'type'=>'floating', a linha gruda na BORDA da forma independentemente do
              lado do handle — por isso podemos deixá-los hidden e ainda assim conectar
              em qualquer direção. Doc: https://artisanflow.dev/docs/alpineflow/handles
            --}}
            <x-slot:node>
                <x-flow-handle type="target" position="top"    :hidden="true" />
                <x-flow-handle type="target" position="left"   :hidden="true" />
                <x-flow-handle type="source" position="bottom" :hidden="true" />
                <x-flow-handle type="source" position="right"  :hidden="true" />

                {{-- Rótulo: x-text lê node.data.label; :class sublinha quando é PK
                     (node.data.pk = true, definido no PHP p/ atributos chave). --}}
                <span
                    class="erd-node-label"
                    :class="{ 'erd-pk': node.data.pk }"
                    x-text="node.data.label"
                ></span>
            </x-slot:node>
        </x-flow>

        {{-- PAINEL LATERAL: só aparece quando há node selecionado (preenchido em onNodeClick). --}}
        @if($selectedData)
        <div style="width:260px;background:#0f172a;border-left:1px solid #1e293b;display:flex;flex-direction:column;flex-shrink:0;overflow-y:auto;">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:16px;border-bottom:1px solid #1e293b;">
                <span style="color:#475569;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;">Detalhes do nó</span>
                <button
                    wire:click="closePanel"
                    style="color:#475569;background:none;border:none;cursor:pointer;padding:2px;border-radius:4px;"
                    onmouseover="this.style.color='#94a3b8'" onmouseout="this.style.color='#475569'"
                >
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div style="padding:20px 16px;display:flex;flex-direction:column;gap:14px;">
                <div>
                    <div style="color:#334155;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:4px;">Nome</div>
                    <div style="color:#f1f5f9;font-size:15px;font-weight:700;">{{ $selectedData['label'] }}</div>
                </div>
                <div>
                    <div style="color:#334155;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:4px;">Tipo (forma)</div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <div style="width:6px;height:6px;border-radius:50%;background:#a855f7;flex-shrink:0;"></div>
                        <span style="color:#cbd5e1;font-size:13px;text-transform:capitalize;">{{ $selectedData['kind'] ?? '—' }}</span>
                    </div>
                </div>
                @if(!empty($selectedData['pk']))
                <div style="color:#10b981;font-size:12px;font-weight:600;">🔑 Chave primária (PK)</div>
                @endif
            </div>
        </div>
        @endif

    </div>
</div>
