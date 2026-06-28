{{-- =================================================================================== --}}
{{-- CONTÊINER RAIZ DA INTERFACE                                                         --}}
{{-- =================================================================================== --}}
{{-- 1. height: 100vh -> Ocupa exatamente a altura total visível do navegador.           --}}
{{-- 2. display: flex; flex-direction: column -> Empilha o cabeçalho e a área do grafo.  --}}
{{-- 3. background: #0b111e -> Fundo azul escuro profundo para realçar os tons neon.     --}}
<div style="height:100vh; display:flex; flex-direction:column; background:#0b111e; font-family:ui-sans-serif,system-ui,sans-serif;">

    {{-- =================================================================================== --}}
    {{-- BARRA DE MENU SUPERIOR (HEADER)                                                     --}}
    {{-- =================================================================================== --}}
    {{-- flex-shrink: 0 -> Impede que o cabeçalho seja esmagado pelo crescimento do grafo.  --}}
    {{-- justify-content: space-between -> Separa os títulos (esquerda) do botão (direita).  --}}
    <div style="display:flex; align-items:center; justify-content:space-between; padding:14px 24px; background:#0f172a; border-bottom:1px solid #1e293b; flex-shrink:0;">
        <div>
            <div style="display:flex; align-items:center; gap:10px;">
                {{-- EFEITO GLOW LARANJA: box-shadow simula um pequeno LED brilhante ao lado do título --}}
                <div style="width:8px; height:8px; border-radius:50%; background:#f97316; box-shadow:0 0 8px #f97316;"></div>
                <h1 style="color:#f1f5f9; font-size:16px; font-weight:700; margin:0;">Chaveamento da Copa do Mundo</h1>
            </div>
            <p style="color:#475569; font-size:12px; margin:2px 0 0 18px;">Fase Final Eliminatória</p>
        </div>
        <div>
            {{-- EVENTO LIVEWIRE & SIMULAÇÃO DE HOVER INLINE --}}
            {{-- 1. wire:click="fitView" -> Dispara o método no servidor para centralizar a câmera do grafo. --}}
            {{-- 2. onmouseover/onmouseout -> Substitutos em JavaScript para simular o efeito :hover do CSS --}}
            <button wire:click="fitView" style="padding:8px 16px; background:#1e293b; border:1px solid #334155; border-radius:6px; color:#94a3b8; font-size:12px; cursor:pointer; font-weight:600; transition: all 0.2s;" onmouseover="this.style.borderColor='#f97316';this.style.color='#f1f5f9'" onmouseout="this.style.borderColor='#334155';this.style.color='#94a3b8'">
                Enquadrar Tela
            </button>
        </div>
    </div>

    {{-- =================================================================================== --}}
    {{-- ESPAÇO DE TRABALHO DO GRAFO (CANVAS)                                                --}}
    {{-- =================================================================================== --}}
    {{-- flex: 1 -> Estica esta área para capturar todo o espaço restante abaixo do cabeçalho. --}}
    {{-- position: relative -> Permite que o Painel Lateral absoluto flutue fixado na direita. --}}
    <div style="flex:1; display:flex; overflow:hidden; position:relative;">
        
        <style>
            /* ============================================================================== */
            /* SEÇÃO DE CSS CUSTOMIZADO (INLINE STYLE TAG)                                    */
            /* ============================================================================== */

            /* TRUQUE PLACAR: Remove as setas de aumentar/diminuir dos campos numéricos */
            input::-webkit-outer-spin-button, input::-webkit-inner-spin-button { 
                -webkit-appearance: none; 
                margin: 0; 
            }
            input[type=number] { 
                appearance: textfield; 
            }

            /* TRATAMENTO DE FUNDO: Tira os estilos padrão da biblioteca. O !important garante que
               a cor escura sólida #0f172a cubra os pontinhos (dots) de fundo do canvas, impedindo
               que o grid pontilhado vaze por dentro das caixas dos jogos. */
            .flow-node, 
            .flow-node-content, 
            .vue-flow__node, 
            .vue-flow__node-default, 
            .react-flow__node, 
            .nd-node {
                background: #0f172a !important; 
                border: none !important;
                padding: 0 !important;
                box-shadow: none !important;
                border-radius: 8px !important;
            }

            /* Margem de segurança básica entre as caixas */
            .flow-node, .vue-flow__node {
                margin: 20px 40px !important;
            }

            /* LINHAS AZUL NEON: Estiliza os paths SVG das curvas de conexão */
            .vue-flow__edge-path, 
            .react-flow__edge-path, 
            .flow-edge-path {
                stroke: #00f2fe !important; /* Aplica a cor Ciano/Neon nas conexões */
                stroke-width: 2.5 !important; /* Deixa as linhas levemente mais grossas */
                /* filter drop-shadow: Indispensável para criar a aura de luz acesa (Glow) */
                filter: drop-shadow(0px 0px 3px rgba(0, 242, 254, 0.6)) !important; 
            }

            /* Garante que triângulos/setas indicativas nas pontas das linhas herdem a cor neon */
            .vue-flow__edge-marker,
            .react-flow__edge-marker {
                fill: #00f2fe !important;
            }
        </style>

        {{-- COMPONENTE PRINCIPAL DO GRAFO --}}
        {{-- CORREÇÃO AQUI: Alteramos os valores de distância para afastar os cards que estavam colados! --}}
        {{-- :node-distance-x="320" -> Alarga o espaço entre rodadas, dando mais elegância às curvas. --}}
        {{-- :node-distance-y="180" -> Dobra a distância vertical antiga (90). Os cards das oitavas não vão mais se encostar. --}}
        <x-flow
            :nodes="$nodes"
            :edges="$edges"
            background="dots"
            :minimap="false"
            :controls="false"
            default-edge-type="smoothstep"
            class="dark"
            :node-distance-x="320" 
            :node-distance-y="180"
            style="flex:1; background:#0b111e; --flow-container-height:100%;"
            @node-click="onNodeClick"
        >
            {{-- CONFIGURAÇÃO DOS SLOTS DE JOGOS (CUSTOM NODE) --}}
            <x-slot:node>
                {{-- CONECTORES ESQUERDOS (HANDLES) --}}
                {{-- Alinhados via position: absolute. O box-shadow ciano simula o ponto de energia de onde nascem as linhas. --}}
                <div x-flow-handle:target.left style="position:absolute; left:-6px; top:35%; width:12px; height:12px; background:#00f2fe; border:2px solid #0f172a; border-radius:50%; cursor:pointer; z-index:10; box-shadow: 0 0 8px #00f2fe;"></div>
                <div x-flow-handle:source.left style="position:absolute; left:-6px; top:65%; width:12px; height:12px; background:#00f2fe; border:2px solid #0f172a; border-radius:50%; cursor:pointer; z-index:10; box-shadow: 0 0 8px #00f2fe;"></div>

                {{-- CARD DO CONFRONTO INDIVIDUAL --}}
                {{-- :style -> Escuta o Alpine.js. Quando o nó é clicado (node.selected), ativa uma borda laranja viva e efeito neon --}}
                <div style="background:#0f172a; border:2px solid #1e293b; border-radius:8px; padding:12px; width:220px; color:#f1f5f9; cursor:move; position:relative; transition: border-color 0.2s;" :style="node.selected ? 'border-color:#f97316; box-shadow:0 0 10px rgba(249,115,22,0.4)' : ''">
                    
                    {{-- CABEÇALHO DO CARD (DATA DA PARTIDA E ID DO JOGO) --}}
                    {{-- x-text -> Mapeia dinamicamente as propriedades do JSON injetado pela biblioteca --}}
                    <div style="display:flex; justify-content:space-between; align-items:center; font-size:11px; color:#64748b; font-weight:600; margin-bottom:10px;">
                        <span style="color:#94a3b8;" x-text="node.data.title || 'Data Indefinida'"></span>
                        <span style="background:#1e293b; padding:2px 6px; border-radius:4px; font-size:10px; color:#94a3b8;" x-text="node.id.replace('jogo-', '')"></span>
                    </div>

                    {{-- LINHA DO TIME A --}}
                    {{-- background #1e293b -> Cria o contraste de bloco sobreposto em relação ao fundo geral do card --}}
                    <div style="display:flex; align-items:center; justify-content:space-between; background:#1e293b; border:1px solid #334155; border-radius:6px; padding:6px 10px; margin-bottom:8px;">
                        {{-- TRATAMENTO DE STRINGS ACIMA DE 130PX: Se o país tiver nome grande, o CSS aplica '...' sem estourar o layout --}}
                        <span style="font-size:13px; font-weight:500; color:#e2e8f0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:130px;" 
                              x-text="node.data.timeA || 'A definir'"></span>
                        
                        {{-- INPUT DO PLACAR A --}}
                        <input type="number" 
                               :value="node.data.scoreA" 
                               style="width:36px; height:24px; background:#0f172a; border:1px solid #334155; border-radius:4px; text-align:center; color:#f97316; font-size:13px; font-weight:700; outline:none;" 
                               readonly>
                    </div>

                    {{-- LINHA DO TIME B --}}
                    {{-- Estrutura espelhada idêntica à do Time A para preservar a simetria perfeita da interface --}}
                    <div style="display:flex; align-items:center; justify-content:space-between; background:#1e293b; border:1px solid #334155; border-radius:6px; padding:6px 10px;">
                        <span style="font-size:13px; font-weight:500; color:#e2e8f0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:130px;" 
                              x-text="node.data.timeB || 'A definir'"></span>
                        
                        <input type="number" 
                               :value="node.data.scoreB" 
                               style="width:36px; height:24px; background:#0f172a; border:1px solid #334155; border-radius:4px; text-align:center; color:#f97316; font-size:13px; font-weight:700; outline:none;" 
                               readonly>
                    </div>
                </div>

                {{-- CONECTORES DIREITOS (HANDLES ESPELHADOS) --}}
                {{-- Modificados com right: -6px para direcionar a saída das linhas para o próximo confronto à direita --}}
                <div x-flow-handle:target.right style="position:absolute; right:-6px; top:35%; width:12px; height:12px; background:#00f2fe; border:2px solid #0f172a; border-radius:50%; cursor:pointer; z-index:10; box-shadow: 0 0 8px #00f2fe;"></div>
                <div x-flow-handle:source.right style="position:absolute; right:-6px; top:65%; width:12px; height:12px; background:#00f2fe; border:2px solid #0f172a; border-radius:50%; cursor:pointer; z-index:10; box-shadow: 0 0 8px #00f2fe;"></div>
            </x-slot:node>
        </x-flow>

        {{-- =================================================================================== --}}
        {{-- PAINEL LATERAL INFORMATIVO (DRAWER)                                                 --}}
        {{-- =================================================================================== --}}
        {{-- @if($selectedData) -> Condicional reativa do Livewire. O painel só brota na tela se um jogo estiver ativo --}}
        {{-- box-shadow: -10px... -> Cria uma sombra projetada para a esquerda, simulando que o painel está fisicamente acima do mapa --}}
        @if($selectedData)
            <div style="width:320px; background:#0f172a; border-left:1px solid #1e293b; padding:20px; display:flex; flex-direction:column; gap:16px; z-index:50; box-shadow:-10px 0 25px -5px rgba(0,0,0,0.5);">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="color:#f1f5f9; margin:0; font-size:14px; font-weight:700;" x-text="$wire.selectedData.title"></h3>
                    {{-- &times; -> Entidade HTML usada para renderizar um caractere de fechar (X) geometricamente limpo --}}
                    <button wire:click="closePanel" style="background:none; border:none; color:#64748b; font-size:18px; cursor:pointer;">&times;</button>
                </div>
                <hr style="border:0; border-top:1px solid #1e293b; margin:0;">
                <div style="color:#94a3b8; font-size:13px; display:flex; flex-direction:column; gap:10px;">
                    <div><strong>Resultado do Confronto:</strong></div>
                    
                    {{-- EXIBIÇÃO DO PLACAR DO TIME A NO PAINEL DETALHADO --}}
                    <div style="background:#1e293b; padding:12px; border-radius:6px; color:#e2e8f0; display:flex; justify-content:space-between; border:1px solid #334155;">
                        <span style="font-weight:600;" x-text="$wire.selectedData.timeA"></span>
                        <span style="color:#f97316; font-weight:700;" x-text="$wire.selectedData.scoreA"></span>
                    </div>
                    
                    {{-- EXIBIÇÃO DO PLACAR DO TIME B NO PAINEL DETALHADO --}}
                    <div style="background:#1e293b; padding:12px; border-radius:6px; color:#e2e8f0; display:flex; justify-content:space-between; border:1px solid #334155;">
                        <span style="font-weight:600;" x-text="$wire.selectedData.timeB"></span>
                        <span style="color:#f97316; font-weight:700;" x-text="$wire.selectedData.scoreB"></span>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>