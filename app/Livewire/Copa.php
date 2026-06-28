<?php

namespace App\Livewire;

// Importação da Trait da biblioteca WireFlow para controle de foco e câmera do grafo
use ArtisanFlow\WireFlow\Concerns\WithWireFlow;
use Livewire\Attributes\Layout;
use Livewire\Component;

// Define que este componente Livewire utilizará o layout padrão 'layouts.app'
#[Layout('layouts.app')]
class Copa extends Component
{
    // Habilita o uso dos métodos internos do WireFlow (como flowFitView e flowFocusNode)
    use WithWireFlow;

    // Propriedades públicas que armazenam os dados que o front-end (x-flow) precisa renderizar
    public array $nodes = [];           // Guarda todos os nós (caixas dos jogos/confrontos)
    public array $edges = [];           // Guarda todas as linhas/arestas de conexão entre os jogos
    public ?string $selectedNode = null; // Armazena o ID do nó que foi clicado atualmente
    public ?array $selectedData = null;  // Armazena os dados internos (times/placares) do nó selecionado

    /**
     * Método Mount: Executado automaticamente uma única vez quando o componente é carregado.
     * Ideal para inicializar os dados da árvore do torneio.
     */
    public function mount(): void
    {
        // Estilização padrão para as linhas (arestas) que ligam as fases da copa
        $edgeStyle = ['stroke' => '#3b82f6', 'strokeWidth' => 2]; 

        // 1. CONFIGURAÇÃO DE TODOS OS NÓS (CONFRONTOS) DA COPA DO MUNDO
        // Cada item possui um ID único, coordenadas X e Y na tela, e dados do placar/times
        $this->nodes = [
            // ================= CHAVE DA ESQUERDA =================
            // Oitavas de Final (Coluna 1: X = 50)
            ['id' => 'jogo-74', 'position' => ['x' => 50, 'y' => 30], 'data' => ['title' => '29/06 Jogo 74', 'timeA' => 'Brasil', 'scoreA' => '3', 'timeB' => 'França', 'scoreB' => '1']],
            ['id' => 'jogo-75', 'position' => ['x' => 50, 'y' => 140], 'data' => ['title' => '29/06 Jogo 75', 'timeA' => 'Argentina', 'scoreA' => '3', 'timeB' => 'Nigéria', 'scoreB' => '1']],
            ['id' => 'jogo-77', 'position' => ['x' => 50, 'y' => 270], 'data' => ['title' => '30/06 Jogo 77', 'timeA' => 'Espanha', 'scoreA' => '2', 'timeB' => 'Japão', 'scoreB' => '1']],
            ['id' => 'jogo-78', 'position' => ['x' => 50, 'y' => 380], 'data' => ['title' => '30/06 Jogo 78', 'timeA' => 'Alemanha', 'scoreA' => '4', 'timeB' => 'Marrocos', 'scoreB' => '1']],
            ['id' => 'jogo-73', 'position' => ['x' => 50, 'y' => 510], 'data' => ['title' => '28/06 Jogo 73', 'timeA' => 'Colômbia', 'scoreA' => '4', 'timeB' => 'Equador', 'scoreB' => '0']],
            ['id' => 'jogo-81', 'position' => ['x' => 50, 'y' => 620], 'data' => ['title' => '01/07 Jogo 81', 'timeA' => 'Chile', 'scoreA' => '3', 'timeB' => 'Suécia', 'scoreB' => '0']],
            ['id' => 'jogo-84', 'position' => ['x' => 50, 'y' => 750], 'data' => ['title' => '02/07 Jogo 84', 'timeA' => 'México', 'scoreA' => '2', 'timeB' => 'Canadá', 'scoreB' => '1']],
            ['id' => 'jogo-82', 'position' => ['x' => 50, 'y' => 860], 'data' => ['title' => '01/07 Jogo 82', 'timeA' => 'Peru', 'scoreA' => '1', 'timeB' => 'Gana', 'scoreB' => '2']],

            // Quartas de Final (Coluna 2: X = 320)
            ['id' => 'jogo-89', 'position' => ['x' => 320, 'y' => 85], 'data' => ['title' => '04/07 Jogo 89', 'timeA' => 'Brasil', 'scoreA' => '3', 'timeB' => 'Argentina', 'scoreB' => '1']],
            ['id' => 'jogo-90', 'position' => ['x' => 320, 'y' => 325], 'data' => ['title' => '04/07 Jogo 90', 'timeA' => 'Espanha', 'scoreA' => '2', 'timeB' => 'Alemanha', 'scoreB' => '3']],
            ['id' => 'jogo-93', 'position' => ['x' => 320, 'y' => 565], 'data' => ['title' => '06/07 Jogo 93', 'timeA' => 'Colômbia', 'scoreA' => '1', 'timeB' => 'Chile', 'scoreB' => '3']],
            ['id' => 'jogo-94', 'position' => ['x' => 320, 'y' => 805], 'data' => ['title' => '06/07 Jogo 94', 'timeA' => 'México', 'scoreA' => '2', 'timeB' => 'Gana', 'scoreB' => '3']],

            // Semifinais de Lado (Coluna 3: X = 590)
            ['id' => 'jogo-97', 'position' => ['x' => 590, 'y' => 205], 'data' => ['title' => '09/07 Jogo 97', 'timeA' => 'Brasil', 'scoreA' => '7', 'timeB' => 'Alemanha', 'scoreB' => '0']],
            ['id' => 'jogo-98', 'position' => ['x' => 590, 'y' => 685], 'data' => ['title' => '10/07 Jogo 98', 'timeA' => 'Chile', 'scoreA' => '2', 'timeB' => 'Gana', 'scoreB' => '1']],

            // Finalistas do Lado Esquerdo (Coluna 4: X = 860)
            ['id' => 'jogo-101', 'position' => ['x' => 860, 'y' => 445], 'data' => ['title' => '14/07 Semifinal 1', 'timeA' => 'Brasil', 'scoreA' => '5', 'timeB' => 'Chile', 'scoreB' => '1']],

            // ================= CENTRO DO GRAFO =================
            // Grande Finalíssima (Coluna Central: X = 1130)
            ['id' => 'jogo-final', 'position' => ['x' => 1130, 'y' => 580], 'data' => ['title' => '19/07 FINALÍSSIMA', 'timeA' => 'Brasil', 'scoreA' => '6', 'timeB' => 'Colúmbia', 'scoreB' => '1']],

            // ================= CHAVE DA DIREITA =================
            // Finalistas do Lado Direito (Coluna 6: X = 1400)
            ['id' => 'jogo-102', 'position' => ['x' => 1400, 'y' => 445], 'data' => ['title' => '15/07 Semifinal 2', 'timeA' => 'Inglaterra', 'scoreA' => '1', 'timeB' => 'Colúmbia', 'scoreB' => '2']],

            // Semifinais de Lado (Coluna 7: X = 1670)
            ['id' => 'jogo-99', 'position' => ['x' => 1670, 'y' => 205], 'data' => ['title' => '12/07 Jogo 99', 'timeA' => 'Inglaterra', 'scoreA' => '2', 'timeB' => 'EUA', 'scoreB' => '1']],
            ['id' => 'jogo-100', 'position' => ['x' => 1670, 'y' => 685], 'data' => ['title' => '12/07 Jogo 100', 'timeA' => 'Senegal', 'scoreA' => '1', 'timeB' => 'Colúmbia', 'scoreB' => '2']],

            // Quartas de Final (Coluna 8: X = 1940)
            ['id' => 'jogo-91', 'position' => ['x' => 1940, 'y' => 85], 'data' => ['title' => '05/07 Jogo 91', 'timeA' => 'Inglaterra', 'scoreA' => '2', 'timeB' => 'Portugal', 'scoreB' => '1']],
            ['id' => 'jogo-92', 'position' => ['x' => 1940, 'y' => 325], 'data' => ['title' => '05/07 Jogo 92', 'timeA' => 'EUA', 'scoreA' => '2', 'timeB' => 'Bélgica', 'scoreB' => '0']],
            ['id' => 'jogo-95', 'position' => ['x' => 1940, 'y' => 565], 'data' => ['title' => '07/07 Jogo 95', 'timeA' => 'Senegal', 'scoreA' => '2', 'timeB' => 'Argélia', 'scoreB' => '0']],
            ['id' => 'jogo-96', 'position' => ['x' => 1940, 'y' => 805], 'data' => ['title' => '07/07 Jogo 96', 'timeA' => 'Colúmbia', 'scoreA' => '2', 'timeB' => 'Austrália', 'scoreB' => '1']],

            // Oitavas de Final (Coluna 9 Extrema Direita: X = 2210)
            ['id' => 'jogo-76', 'position' => ['x' => 2210, 'y' => 30], 'data' => ['title' => '29/06 Jogo 76', 'timeA' => 'Inglaterra', 'scoreA' => '2', 'timeB' => 'Itália', 'scoreB' => '1']],
            ['id' => 'jogo-79', 'position' => ['x' => 2210, 'y' => 140], 'data' => ['title' => '30/06 Jogo 79', 'timeA' => 'Portugal', 'scoreA' => '2', 'timeB' => 'Holanda', 'scoreB' => '1']],
            ['id' => 'jogo-80', 'position' => ['x' => 2210, 'y' => 270], 'data' => ['title' => '01/07 Jogo 80', 'timeA' => 'Uruguai', 'scoreA' => '1', 'timeB' => 'EUA', 'scoreB' => '2']],
            ['id' => 'jogo-83', 'position' => ['x' => 2210, 'y' => 380], 'data' => ['title' => '02/07 Jogo 83', 'timeA' => 'Bélgica', 'scoreA' => '2', 'timeB' => 'Croácia', 'scoreB' => '0']],
            ['id' => 'jogo-86', 'position' => ['x' => 2210, 'y' => 510], 'data' => ['title' => '01/07 Jogo 86', 'timeA' => 'Senegal', 'scoreA' => '2', 'timeB' => 'Egito', 'scoreB' => '1']],
            ['id' => 'jogo-85', 'position' => ['x' => 2210, 'y' => 620], 'data' => ['title' => '02/07 Jogo 85', 'timeA' => 'Marrocos', 'scoreA' => '2', 'timeB' => 'Argélia', 'scoreB' => '3']],
            ['id' => 'jogo-88', 'position' => ['x' => 2210, 'y' => 750], 'data' => ['title' => '03/07 Jogo 88', 'timeA' => 'Colúmbia', 'scoreA' => '2', 'timeB' => 'Coreia Sul', 'scoreB' => '1']],
            ['id' => 'jogo-87', 'position' => ['x' => 2210, 'y' => 860], 'data' => ['title' => '02/07 Jogo 87', 'timeA' => 'Austrália', 'scoreA' => '2', 'timeB' => 'Camarões', 'scoreB' => '1']],
        ];

        // 2. MAPEAMENTO DE CONEXÕES (EDGES) ENTRE AS FASES
        $this->edges = [
            // --- CONEXÕES CHAVE ESQUERDA ---
            ['id' => 'e-74-89', 'source' => 'jogo-74', 'target' => 'jogo-89', 'type' => 'smoothstep', 'style' => $edgeStyle],
            ['id' => 'e-75-89', 'source' => 'jogo-75', 'target' => 'jogo-89', 'type' => 'smoothstep', 'style' => $edgeStyle],
            ['id' => 'e-77-90', 'source' => 'jogo-77', 'target' => 'jogo-90', 'type' => 'smoothstep', 'style' => $edgeStyle],
            ['id' => 'e-78-90', 'source' => 'jogo-78', 'target' => 'jogo-90', 'type' => 'smoothstep', 'style' => $edgeStyle],
            ['id' => 'e-73-93', 'source' => 'jogo-73', 'target' => 'jogo-93', 'type' => 'smoothstep', 'style' => $edgeStyle],
            ['id' => 'e-81-93', 'source' => 'jogo-81', 'target' => 'jogo-93', 'type' => 'smoothstep', 'style' => $edgeStyle],
            ['id' => 'e-84-94', 'source' => 'jogo-84', 'target' => 'jogo-94', 'type' => 'smoothstep', 'style' => $edgeStyle],
            ['id' => 'e-82-94', 'source' => 'jogo-82', 'target' => 'jogo-94', 'type' => 'smoothstep', 'style' => $edgeStyle],

            ['id' => 'e-89-97', 'source' => 'jogo-89', 'target' => 'jogo-97', 'type' => 'smoothstep', 'style' => $edgeStyle],
            ['id' => 'e-90-97', 'source' => 'jogo-90', 'target' => 'jogo-97', 'type' => 'smoothstep', 'style' => $edgeStyle],
            ['id' => 'e-93-98', 'source' => 'jogo-93', 'target' => 'jogo-98', 'type' => 'smoothstep', 'style' => $edgeStyle],
            ['id' => 'e-94-98', 'source' => 'jogo-94', 'target' => 'jogo-98', 'type' => 'smoothstep', 'style' => $edgeStyle],

            ['id' => 'e-97-101', 'source' => 'jogo-97', 'target' => 'jogo-101', 'type' => 'smoothstep', 'style' => $edgeStyle],
            ['id' => 'e-98-101', 'source' => 'jogo-98', 'target' => 'jogo-101', 'type' => 'smoothstep', 'style' => $edgeStyle],
            ['id' => 'e-101-final', 'source' => 'jogo-101', 'target' => 'jogo-final', 'type' => 'smoothstep', 'style' => $edgeStyle],

            // --- CONEXÕES CHAVE DIREITA ---
            ['id' => 'e-76-91', 'source' => 'jogo-76', 'target' => 'jogo-91', 'type' => 'smoothstep', 'style' => $edgeStyle],
            ['id' => 'e-79-91', 'source' => 'jogo-79', 'target' => 'jogo-91', 'type' => 'smoothstep', 'style' => $edgeStyle],
            ['id' => 'e-80-92', 'source' => 'jogo-80', 'target' => 'jogo-92', 'type' => 'smoothstep', 'style' => $edgeStyle],
            ['id' => 'e-83-92', 'source' => 'jogo-83', 'target' => 'jogo-92', 'type' => 'smoothstep', 'style' => $edgeStyle],
            ['id' => 'e-86-95', 'source' => 'jogo-86', 'target' => 'jogo-95', 'type' => 'smoothstep', 'style' => $edgeStyle],
            ['id' => 'e-85-95', 'source' => 'jogo-85', 'target' => 'jogo-95', 'type' => 'smoothstep', 'style' => $edgeStyle],
            ['id' => 'e-88-96', 'source' => 'jogo-88', 'target' => 'jogo-96', 'type' => 'smoothstep', 'style' => $edgeStyle],
            ['id' => 'e-87-96', 'source' => 'jogo-87', 'target' => 'jogo-96', 'type' => 'smoothstep', 'style' => $edgeStyle],

            ['id' => 'e-91-99', 'source' => 'jogo-91', 'target' => 'jogo-99', 'type' => 'smoothstep', 'style' => $edgeStyle],
            ['id' => 'e-92-99', 'source' => 'jogo-92', 'target' => 'jogo-99', 'type' => 'smoothstep', 'style' => $edgeStyle],
            ['id' => 'e-95-100', 'source' => 'jogo-95', 'target' => 'jogo-100', 'type' => 'smoothstep', 'style' => $edgeStyle],
            ['id' => 'e-96-100', 'source' => 'jogo-96', 'target' => 'jogo-100', 'type' => 'smoothstep', 'style' => $edgeStyle],

            ['id' => 'e-99-102', 'source' => 'jogo-99', 'target' => 'jogo-102', 'type' => 'smoothstep', 'style' => $edgeStyle],
            ['id' => 'e-100-102', 'source' => 'jogo-100', 'target' => 'jogo-102', 'type' => 'smoothstep', 'style' => $edgeStyle],
            ['id' => 'e-102-final', 'source' => 'jogo-102', 'target' => 'jogo-final', 'type' => 'smoothstep', 'style' => $edgeStyle],
        ];
    }

    /**
     * Gerencia o clique em um nó do grafo.
     */
    public function onNodeClick(string $nodeId, array $node): void
    {
        if ($this->selectedNode === $nodeId) {
            $this->selectedNode = null;
            $this->selectedData = null;
            return;
        }

        $this->selectedNode = $nodeId;

        foreach ($this->nodes as $n) {
            if ($n['id'] === $nodeId) {
                $this->selectedData = $n['data'];
                break;
            }
        }

        $this->flowFocusNode($nodeId, 400, 0.4);
    }

    /**
     * Move a câmera do grafo para centralizar e enquadrar todo o chaveamento na tela.
     */
    public function fitView(): void
    {
        $this->flowFitView();
    }

    /**
     * Força o fechamento manual do painel lateral de detalhes (Geralmente acionado no botão 'X').
     */
    public function closePanel(): void
    {
        $this->selectedNode = null;
        $this->selectedData = null;
    }

    /**
     * Renderiza a view do blade correta.
     * ALTERADO: 'livewire.organograma' para 'livewire.copa' para achar o arquivo renomeado!
     */
    public function render()
    {
        // Certifique-se de referenciar a view com a inicial maiúscula se o arquivo ficou Copa.blade.php
        // O Laravel aceita minúsculo na busca da view, mas para evitar qualquer problema de cache:
        return view('livewire.Copa'); 
    }
}
