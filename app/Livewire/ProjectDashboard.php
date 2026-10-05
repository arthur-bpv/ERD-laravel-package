<?php

namespace App\Livewire;

use App\Models\Diagram;
use App\Services\ErToRelationalTransformer;
use App\Services\RelationalDrift;
use App\Support\ErDiagramImport;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.app')]
class ProjectDashboard extends Component
{
    #[Validate('required|string|max:120')]
    public string $projectName = '';

    #[Validate('required|string|max:120')]
    public string $importProjectName = 'Projeto importado';

    public bool $showImport = false;

    public string $importJson = '';

    public string $importError = '';

    #[Computed]
    public function projects(): Collection
    {
        return Diagram::query()
            ->where('type', Diagram::TYPE_ENTITY_RELATIONSHIP)
            ->with('relationalDiagram:id,name,type,source_diagram_id,updated_at,data')
            ->latest('updated_at')
            ->get(['id', 'name', 'type', 'updated_at', 'data']);
    }

    /**
     * IDs dos projetos cuja cópia Relacional já não corresponde ao ER.
     *
     * Só sinaliza: o quadro Relacional é quem oferece a regeneração, porque só
     * ele conhece as edições manuais que ela substituiria.
     *
     * É uma computed property no formato legado (`get...Property`) porque,
     * ao contrário de `#[Computed]`, ela aceita a dependência injetada.
     *
     * @return array<int, int>
     */
    public function getOutdatedRelationalProperty(RelationalDrift $drift): array
    {
        return $this->projects
            ->filter(fn (Diagram $project): bool => $project->relationalDiagram !== null
                && $drift->report($project->data ?? [], $project->relationalDiagram->data ?? [])['outdated'])
            ->pluck('id')
            ->all();
    }

    public function createProject(): void
    {
        $this->validateOnly('projectName');

        $diagram = Diagram::create([
            'name' => trim($this->projectName),
            'type' => Diagram::TYPE_ENTITY_RELATIONSHIP,
            'data' => [],
        ]);

        $this->redirectRoute('boards.er', $diagram, navigate: true);
    }

    public function toggleImport(): void
    {
        $this->showImport = ! $this->showImport;
        $this->importError = '';
        $this->resetValidation('importProjectName');
    }

    public function importProject(): void
    {
        $this->validateOnly('importProjectName');

        try {
            $data = ErDiagramImport::parse($this->importJson);
        } catch (InvalidArgumentException $exception) {
            $this->importError = $exception->getMessage();

            return;
        }

        $diagram = Diagram::create([
            'name' => trim($this->importProjectName),
            'type' => Diagram::TYPE_ENTITY_RELATIONSHIP,
            'data' => $data,
        ]);

        $this->redirectRoute('boards.er', $diagram, navigate: true);
    }

    public function createAnalysisProject(): void
    {
        $diagram = Diagram::create([
            'name' => 'Análise de alternativas ER → relacional',
            'type' => Diagram::TYPE_ENTITY_RELATIONSHIP,
            'data' => ErDiagramImport::parse(
                file_get_contents(public_path('examples/er-conversion-cases.json')),
            ),
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
