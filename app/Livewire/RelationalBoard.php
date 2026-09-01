<?php

namespace App\Livewire;

use App\Models\Diagram;
use App\Services\ErToRelationalTransformer;
use ArtisanFlow\WireFlow\Concerns\WithWireFlow;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class RelationalBoard extends Component
{
    use WithWireFlow;

    #[Locked]
    public int $diagramId;

    #[Locked]
    public int $sourceDiagramId;

    #[Locked]
    public string $diagramName;

    #[Locked]
    public string $sourceDiagramName;

    #[Locked]
    public array $tables = [];

    #[Locked]
    public array $foreignKeys = [];

    #[Locked]
    public array $warnings = [];

    public function mount(Diagram $diagram, ErToRelationalTransformer $transformer): void
    {
        abort_unless($diagram->type === Diagram::TYPE_RELATIONAL && $diagram->source_diagram_id, 404);

        $source = $diagram->sourceDiagram()->firstOrFail();
        abort_unless($source->type === Diagram::TYPE_ENTITY_RELATIONSHIP, 404);

        $this->diagramId = $diagram->id;
        $this->sourceDiagramId = $source->id;
        $this->diagramName = $diagram->name;
        $this->sourceDiagramName = $source->name;

        $data = $diagram->data;
        if (! isset($data['tables'], $data['foreignKeys'])) {
            $data = $transformer->transform($source->data ?? []);
            $diagram->update(['data' => $data]);
        }

        $this->fillFromData($data);
    }

    public function regenerate(ErToRelationalTransformer $transformer): void
    {
        $source = Diagram::query()
            ->whereKey($this->sourceDiagramId)
            ->where('type', Diagram::TYPE_ENTITY_RELATIONSHIP)
            ->firstOrFail();

        $data = $transformer->transform($source->data ?? []);

        DB::transaction(function () use ($data) {
            Diagram::query()
                ->whereKey($this->diagramId)
                ->where('type', Diagram::TYPE_RELATIONAL)
                ->where('source_diagram_id', $this->sourceDiagramId)
                ->firstOrFail()
                ->update(['data' => $data]);
        });

        $this->fillFromData($data);
        $this->dispatch('relational-regenerated');
        $this->redirectRoute('boards.relational', ['diagram' => $this->diagramId], navigate: true);
    }

    public function onNodeDragEnd(string $nodeId, array $position): void
    {
        if (! is_numeric($position['x'] ?? null) || ! is_numeric($position['y'] ?? null)) {
            return;
        }

        foreach ($this->tables as &$table) {
            if ($table['id'] !== $nodeId) {
                continue;
            }

            $table['x'] = max(-10000, min(10000, (int) round($position['x'])));
            $table['y'] = max(-10000, min(10000, (int) round($position['y'])));
            break;
        }
        unset($table);

        $this->persistCurrentData();
    }

    public function buildNodes(): array
    {
        return array_map(fn (array $table) => [
            'id' => $table['id'],
            'position' => ['x' => $table['x'], 'y' => $table['y']],
            'data' => [
                'name' => $table['name'],
                'kind' => $table['kind'],
                'columns' => $table['columns'],
                'primaryKey' => $table['primaryKey'],
            ],
        ], $this->tables);
    }

    public function buildEdges(): array
    {
        return array_map(fn (array $foreignKey) => [
            'id' => $foreignKey['id'],
            'source' => $foreignKey['fromTable'],
            'target' => $foreignKey['toTable'],
            'type' => 'floating',
            'pathType' => 'smoothstep',
            'label' => $foreignKey['cardinality'] ?? 'N:1',
            'color' => '#38bdf8',
            'strokeWidth' => 1.6,
            'markerStart' => ['type' => $foreignKey['sourceCard'] ?? 'cf-zero-many', 'color' => '#38bdf8', 'offset' => 0],
            'markerEnd' => ['type' => $foreignKey['targetCard'] ?? 'cf-one-one', 'color' => '#38bdf8', 'offset' => 0],
            'class' => 'relational-cardinality',
        ], $this->foreignKeys);
    }

    public function render(): View
    {
        return view('livewire.relational-board', [
            'nodes' => $this->buildNodes(),
            'edges' => $this->buildEdges(),
        ]);
    }

    private function fillFromData(array $data): void
    {
        $this->tables = array_values($data['tables'] ?? []);
        $this->foreignKeys = array_values($data['foreignKeys'] ?? []);
        $this->warnings = array_values($data['warnings'] ?? []);
    }

    private function persistCurrentData(): void
    {
        $diagram = Diagram::query()
            ->whereKey($this->diagramId)
            ->where('type', Diagram::TYPE_RELATIONAL)
            ->where('source_diagram_id', $this->sourceDiagramId)
            ->firstOrFail();

        $data = $diagram->data ?? [];
        $data['tables'] = $this->tables;
        $data['foreignKeys'] = $this->foreignKeys;
        $data['warnings'] = $this->warnings;
        $diagram->update(['data' => $data]);
    }
}
