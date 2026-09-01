<?php

namespace App\Livewire;

use App\Models\Diagram;
use App\Services\ErToRelationalTransformer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.app')]
class ProjectDashboard extends Component
{
    #[Validate('required|string|max:120')]
    public string $projectName = '';

    #[Computed]
    public function projects(): Collection
    {
        return Diagram::query()
            ->where('type', Diagram::TYPE_ENTITY_RELATIONSHIP)
            ->with('relationalDiagram:id,name,type,source_diagram_id,updated_at')
            ->latest('updated_at')
            ->get(['id', 'name', 'type', 'updated_at']);
    }

    public function createProject(): void
    {
        $this->validate();

        $diagram = Diagram::create([
            'name' => trim($this->projectName),
            'type' => Diagram::TYPE_ENTITY_RELATIONSHIP,
            'data' => [],
        ]);

        $this->redirectRoute('boards.er', $diagram, navigate: true);
    }

    public function createRelational(int $sourceDiagramId, ErToRelationalTransformer $transformer): void
    {
        $source = Diagram::query()
            ->whereKey($sourceDiagramId)
            ->where('type', Diagram::TYPE_ENTITY_RELATIONSHIP)
            ->firstOrFail();

        $diagram = Diagram::firstOrCreate(
            ['source_diagram_id' => $source->id],
            [
                'name' => $source->name.' — Relacional',
                'type' => Diagram::TYPE_RELATIONAL,
                'data' => $transformer->transform($source->data ?? []),
            ],
        );

        $this->redirectRoute('boards.relational', $diagram, navigate: true);
    }

    public function deleteProject(int $projectId): void
    {
        Diagram::query()
            ->whereKey($projectId)
            ->where('type', Diagram::TYPE_ENTITY_RELATIONSHIP)
            ->firstOrFail()
            ->delete();
    }

    public function render(): View
    {
        return view('livewire.project-dashboard');
    }
}
