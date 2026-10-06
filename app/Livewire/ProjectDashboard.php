<?php

namespace App\Livewire;

use App\Models\Diagram;
use App\Services\RelationalCopy;
use App\Services\RelationalDrift;
use App\Support\ErDiagramImport;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    public ?int $editingProjectId = null;

    public string $editingProjectName = '';

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
     * Usa `isOutdated` e não `report` porque aqui a lista de mudanças não é
     * usada — e ela custa um transform do ER por projeto a cada render.
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
                && $drift->isOutdated($project->data ?? [], $project->relationalDiagram->data ?? []))
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

    public function createRelational(int $sourceDiagramId, RelationalCopy $copy): void
    {
        $source = Diagram::query()
            ->whereKey($sourceDiagramId)
            ->where('type', Diagram::TYPE_ENTITY_RELATIONSHIP)
            ->firstOrFail();

        $this->redirectRoute('boards.relational', $copy->findOrCreate($source), navigate: true);
    }

    public function startRename(int $projectId): void
    {
        $project = Diagram::query()
            ->whereKey($projectId)
            ->where('type', Diagram::TYPE_ENTITY_RELATIONSHIP)
            ->firstOrFail();

        $this->editingProjectId = $project->id;
        $this->editingProjectName = $project->name;
        $this->resetValidation('editingProjectName');
    }

    public function cancelRename(): void
    {
        $this->editingProjectId = null;
        $this->editingProjectName = '';
        $this->resetValidation('editingProjectName');
    }

    public function renameProject(): void
    {
        if ($this->editingProjectId === null) {
            return;
        }

        $this->editingProjectName = trim($this->editingProjectName);
        $this->validate(['editingProjectName' => 'required|string|max:120']);

        $project = Diagram::query()
            ->whereKey($this->editingProjectId)
            ->where('type', Diagram::TYPE_ENTITY_RELATIONSHIP)
            ->with('relationalDiagram')
            ->firstOrFail();

        DB::transaction(function () use ($project): void {
            $oldName = $project->name;
            $project->update(['name' => $this->editingProjectName]);

            if ($project->relationalDiagram?->name === $oldName.' — Relacional') {
                $project->relationalDiagram->update(['name' => $this->editingProjectName.' — Relacional']);
            }
        });

        $this->cancelRename();
        unset($this->projects);
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
