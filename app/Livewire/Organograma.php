<?php

namespace App\Livewire;

use ArtisanFlow\WireFlow\Concerns\WithWireFlow;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Organograma extends Component
{
    use WithWireFlow;

    public array $nodes = [];
    public array $edges = [];
    public ?string $selectedNode = null;
    public ?array $selectedData = null;

    public function mount(): void
    {
        $edgeStyle = ['stroke' => '#3b82f6', 'strokeWidth' => 2];
        $edgeMarker = ['type' => 'arrowclosed', 'color' => '#3b82f6'];
        $edgeSubStyle = ['stroke' => '#4f46e5', 'strokeWidth' => 1.5];
        $edgeSubMarker = ['type' => 'arrowclosed', 'color' => '#4f46e5'];

        $this->nodes = [
            ['id' => 'ceo', 'position' => ['x' => 310, 'y' => 20], 'data' => [
                'label' => 'Arthur Silva', 'role' => 'CEO', 'dept' => 'Executivo', 'avatar' => 'AS',
            ]],
            ['id' => 'cto', 'position' => ['x' => 60, 'y' => 200], 'data' => [
                'label' => 'Maria Santos', 'role' => 'CTO', 'dept' => 'Tecnologia', 'avatar' => 'MS',
            ]],
            ['id' => 'cfo', 'position' => ['x' => 310, 'y' => 200], 'data' => [
                'label' => 'João Lima', 'role' => 'CFO', 'dept' => 'Financeiro', 'avatar' => 'JL',
            ]],
            ['id' => 'coo', 'position' => ['x' => 560, 'y' => 200], 'data' => [
                'label' => 'Ana Costa', 'role' => 'COO', 'dept' => 'Operações', 'avatar' => 'AC',
            ]],
            ['id' => 'dev1', 'position' => ['x' => -20, 'y' => 400], 'data' => [
                'label' => 'Pedro Alves', 'role' => 'Dev Senior', 'dept' => 'Backend', 'avatar' => 'PA',
            ]],
            ['id' => 'dev2', 'position' => ['x' => 150, 'y' => 400], 'data' => [
                'label' => 'Lucas Mendes', 'role' => 'Dev Frontend', 'dept' => 'Frontend', 'avatar' => 'LM',
            ]],
            ['id' => 'sales', 'position' => ['x' => 470, 'y' => 400], 'data' => [
                'label' => 'Carla Rocha', 'role' => 'Head Vendas', 'dept' => 'Comercial', 'avatar' => 'CR',
            ]],
            ['id' => 'mkt', 'position' => ['x' => 650, 'y' => 400], 'data' => [
                'label' => 'Bruno Dias', 'role' => 'Head Mkt', 'dept' => 'Marketing', 'avatar' => 'BD',
            ]],
        ];

        $this->edges = [
            ['id' => 'e1', 'source' => 'ceo', 'target' => 'cto', 'type' => 'smoothstep', 'style' => $edgeStyle, 'markerEnd' => $edgeMarker],
            ['id' => 'e2', 'source' => 'ceo', 'target' => 'cfo', 'type' => 'smoothstep', 'style' => $edgeStyle, 'markerEnd' => $edgeMarker],
            ['id' => 'e3', 'source' => 'ceo', 'target' => 'coo', 'type' => 'smoothstep', 'style' => $edgeStyle, 'markerEnd' => $edgeMarker],
            ['id' => 'e4', 'source' => 'cto', 'target' => 'dev1', 'type' => 'smoothstep', 'style' => $edgeSubStyle, 'markerEnd' => $edgeSubMarker],
            ['id' => 'e5', 'source' => 'cto', 'target' => 'dev2', 'type' => 'smoothstep', 'style' => $edgeSubStyle, 'markerEnd' => $edgeSubMarker],
            ['id' => 'e6', 'source' => 'coo', 'target' => 'sales', 'type' => 'smoothstep', 'style' => $edgeSubStyle, 'markerEnd' => $edgeSubMarker],
            ['id' => 'e7', 'source' => 'coo', 'target' => 'mkt', 'type' => 'smoothstep', 'style' => $edgeSubStyle, 'markerEnd' => $edgeSubMarker],
        ];
    }

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

    public function fitView(): void
    {
        $this->flowFitView();
    }

    public function closePanel(): void
    {
        $this->selectedNode = null;
        $this->selectedData = null;
    }

    public function render()
    {
        return view('livewire.organograma');
    }
}
