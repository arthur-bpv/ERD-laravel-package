<?php

namespace App\Livewire;

// Trait do WireFlow que adiciona ao componente Livewire todos os "helpers"
// servidor->cliente (flowFitView, flowFocusNode, etc.) e o tratamento dos
// eventos do canvas (@node-click, @connect...).
// Doc: https://artisanflow.dev/docs/wireflow  (seção "WithWireFlow trait")
use ArtisanFlow\WireFlow\Concerns\WithWireFlow;

// Atributo do Livewire que define qual layout Blade envolve o componente.
// Doc: https://livewire.laravel.com/docs/components#setting-a-custom-layout
use Livewire\Attributes\Layout;

// Classe base de todo componente Livewire (estado em propriedades públicas,
// ciclo de vida mount/render, ações via wire:*).
// Doc: https://livewire.laravel.com/docs/components
use Livewire\Component;

// #[Layout('layouts.app')] -> renderiza este componente dentro de
// resources/views/layouts/app.blade.php (o layout com @livewireScriptConfig).
#[Layout('layouts.app')]
class Organograma extends Component
{
    // Ativa os métodos flow* e o roteamento de eventos do canvas.
    // Doc: https://artisanflow.dev/docs/wireflow
    use WithWireFlow;

    // --- ESTADO DO COMPONENTE (Livewire) ---
    // Propriedades públicas são automaticamente sincronizadas entre servidor
    // e cliente e ficam disponíveis no Blade. As duas primeiras alimentam o
    // <x-flow>; as duas últimas controlam o painel lateral de detalhes.
    // Doc: https://livewire.laravel.com/docs/properties
    public array $nodes = [];          // os "quadros" (colaboradores) do organograma
    public array $edges = [];          // as ligações/hierarquia entre os quadros
    public ?string $selectedNode = null; // id do nó atualmente selecionado
    public ?array $selectedData = null;  // dados do nó selecionado (mostrados no painel)

    /**
     * mount() — hook de ciclo de vida do Livewire, roda UMA vez quando o
     * componente é criado (equivalente a um "construtor" do componente).
     * Aqui montamos os dados iniciais do diagrama.
     * Doc: https://livewire.laravel.com/docs/lifecycle-hooks#mount
     */
    public function mount(): void
    {
        // Estilo das arestas (edges). 'stroke' = cor da linha, 'strokeWidth' = espessura.
        // 'markerEnd' com type 'arrowclosed' desenha a setinha na ponta da linha.
        // Doc de edges/markers: https://artisanflow.dev/docs/alpineflow/features/edges
        $edgeStyle = ['stroke' => '#3b82f6', 'strokeWidth' => 2];          // linhas do CEO p/ diretoria
        $edgeMarker = ['type' => 'arrowclosed', 'color' => '#3b82f6'];
        $edgeSubStyle = ['stroke' => '#4f46e5', 'strokeWidth' => 1.5];     // linhas diretoria p/ time
        $edgeSubMarker = ['type' => 'arrowclosed', 'color' => '#4f46e5'];

        // NÓS: cada item precisa de 'id' único e 'position' {x, y} em pixels.
        // O conteúdo de 'data' é livre — aqui usamos label/role/dept/avatar, que
        // são lidos no template do nó (<x-slot:node>) via node.data.*.
        // Doc: https://artisanflow.dev/docs/alpineflow  (estrutura de nodes)
        $this->nodes = [
            [
                'id' => 'ceo', 
                'position' => [
                    'x' => 310, 
                    'y' => 20
                ], 
                'data' => [
                    'label' => 'Arthur Silva', 
                    'role' => 'CEO', 
                    'dept' => 'Executivo', 
                    'avatar' => 'AS',
            ]],

            [
                'id' => 'cto', 
                'position' => [
                    'x' => 60, 
                    'y' => 200
                ], 
                'data' => [
                    'label' => 'Maria Santos', 
                    'role' => 'CTO', 
                    'dept' => 'Tecnologia', 
                    'avatar' => 'MS',
            ]],

            [
                'id' => 'cfo', 
                'position' => [
                    'x' => 310, 
                    'y' => 200
                ], 
                'data' => [
                    'label' => 'João Lima',
                    'role' => 'CFO', 
                    'dept' => 'Financeiro', 
                    'avatar' => 'JL',
            ]],

            [
                'id' => 'coo', 
                'position' => [
                    'x' => 560, 
                    'y' => 200
                ], 
                'data' => [
                    'label' => 'Ana Costa', 
                    'role' => 'COO', 
                    'dept' => 'Operações', 
                    'avatar' => 'AC',
            ]],

            [
                'id' => 'dev1', 
                'position' => [
                    'x' => -20,
                     'y' => 400
                ], 
                'data' => [
                    'label' => 'Pedro Alves', 
                    'role' => 'Dev Senior', 
                    'dept' => 'Backend', 
                    'avatar' => 'PA',
            ]],

            [
                'id' => 'dev2', 
                'position' => [
                    'x' => 150, 
                    'y' => 400
                ], 
                'data' => [
                    'label' => 'Lucas Mendes', 
                    'role' => 'Dev Frontend', 
                    'dept' => 'Frontend', 
                    'avatar' => 'LM',
            ]],

            [
                'id' => 'sales', 
                'position' => [
                    'x' => 470, 
                    'y' => 400
                ], 
                'data' => [
                    'label' => 'Carla Rocha', 
                    'role' => 'Head Vendas', 
                    'dept' => 'Comercial', 
                    'avatar' => 'CR',
            ]],

            [
                'id' => 'mkt', 
                'position' => [
                    'x' => 650, 
                    'y' => 400
                ], 
                'data' => [
                    'label' => 'Bruno Dias', 
                    'role' => 'Head Mkt', 
                    'dept' => 'Marketing', 
                    'avatar' => 'BD',
            ]],
        ];

        // ARESTAS: ligam 'source' (origem) a 'target' (destino) por id de nó.
        // 'type' => 'smoothstep' = linha em "degraus" arredondados (boa p/ organograma).
        // Doc: https://artisanflow.dev/docs/alpineflow/features/edges
        $this->edges = [
            [
                'id' => 'e1', 
                'source' => 'ceo', 
                'target' => 'cto', 
                'type' => 'smoothstep', 
                'style' => $edgeStyle, 
                'markerEnd' => $edgeMarker
            ],
            [
                'id' => 'e2', 
                'source' => 'ceo', 
                'target' => 'cfo', 
                'type' => 'smoothstep', 
                'style' => $edgeStyle, 
                'markerEnd' => $edgeMarker
            ],
            [
                'id' => 'e3', 
                'source' => 'ceo', 
                'target' => 'coo', 
                'type' => 'smoothstep', 
                'style' => $edgeStyle, 
                'markerEnd' => $edgeMarker
            ],
            [
                'id' => 'e4', 
                'source' => 'cto', 
                'target' => 'dev1', 
                'type' => 'smoothstep', 
                'style' => $edgeSubStyle, 
                'markerEnd' => $edgeSubMarker
            ],
            [
                'id' => 'e5', 
                'source' => 'cto', 
                'target' => 'dev2', 
                'type' => 'smoothstep', 
                'style' => $edgeSubStyle, 
                'markerEnd' => $edgeSubMarker
            ],
            [
                'id' => 'e6', 
                'source' => 'coo', 
                'target' => 'sales', 
                'type' => 'smoothstep', 
                'style' => $edgeSubStyle, 
                'markerEnd' => $edgeSubMarker
            ],
            [
                'id' => 'e7', 
                'source' => 'coo', 
                'target' => 'mkt', 
                'type' => 'smoothstep', 
                'style' => $edgeSubStyle, 
                'markerEnd' => $edgeSubMarker
            ],
        ];
    }

    /**
     * onNodeClick() — handler do evento @node-click disparado pelo <x-flow>.
     * O WireFlow injeta a assinatura (string $nodeId, array $node) ao clicar
     * num quadro. Aqui implementamos um "toggle" de seleção + foco da câmera.
     * Doc (eventos do servidor): https://artisanflow.dev/docs/wireflow
     */
    public function onNodeClick(string $nodeId, array $node): void
    {
        // Clicou de novo no mesmo nó já selecionado -> desseleciona e sai.
        if ($this->selectedNode === $nodeId) {
            $this->selectedNode = null;
            $this->selectedData = null;
            return;
        }

        $this->selectedNode = $nodeId;

        // Procura nos nós o que foi clicado para alimentar o painel lateral.
        foreach ($this->nodes as $n) {
            if ($n['id'] === $nodeId) {
                $this->selectedData = $n['data'];
                break;
            }
        }

        // Helper do WithWireFlow: faz pan + zoom centralizando no nó.
        // Assinatura: flowFocusNode(id, duration?, padding?).
        // Doc: https://artisanflow.dev/docs/wireflow  (convenience methods)
        $this->flowFocusNode($nodeId, 400, 0.4);
    }

    /**
     * fitView() — acionado pelo botão "Fit View" (wire:click="fitView").
     * flowFitView() reposiciona o zoom para que TODOS os nós caibam na tela.
     * Doc: https://artisanflow.dev/docs/wireflow  (base methods)
     */
    public function fitView(): void
    {
        $this->flowFitView();
    }

    /**
     * closePanel() — acionado pelo "X" do painel (wire:click="closePanel").
     * Apenas limpa a seleção; o @if($selectedData) no Blade esconde o painel.
     */
    public function closePanel(): void
    {
        $this->selectedNode = null;
        $this->selectedData = null;
    }

    /**
     * render() — hook obrigatório do Livewire que devolve a view do componente.
     * Doc: https://livewire.laravel.com/docs/components#rendering-components
     */
    public function render()
    {
        return view('livewire.organograma');
    }
}
