<?php

namespace App\Livewire;

use App\Models\Diagram;
use App\Services\RelationalDiagramCreator;
use App\Services\RelationalDiagramDriftAnalyzer;
use App\Support\ErDiagramImporter;
use App\Support\SqlDiagramImporter;
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

    public string $importFormat = 'json';

    public string $importSql = '';

    public string $importDialect = '';

    public string $importError = '';

    public ?int $editingProjectId = null;

    public string $editingProjectName = '';

    #[Computed]
    public function projects(): Collection
    {
        return Diagram::query()
            ->projects()
            ->with('relationalDiagram:id,name,type,source_diagram_id,updated_at,data')
            ->latest('updated_at')
            ->get(['id', 'name', 'type', 'updated_at', 'data']);
    }

    /**
     * IDs of projects whose relational copy no longer matches the ER source.
     *
     * The relational board offers regeneration because it knows which manual
     * edits would be replaced.
     *
     * isOutdated avoids transforming each project merely to build a change list.
     *
     * The legacy computed property form accepts dependency injection.
     *
     * @return array<int, int>
     */
    public function getOutdatedRelationalProperty(RelationalDiagramDriftAnalyzer $drift): array
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

    public function toggleImport(string $format = 'json'): void
    {
        $this->showImport = ! $this->showImport || $this->importFormat !== $format;
        $this->importFormat = $format === 'sql' ? 'sql' : 'json';
        $this->importError = '';
        $this->resetValidation('importProjectName');
    }

    #[Computed]
    public function detectedSqlDialect(): ?string
    {
        return SqlDiagramImporter::detectDialect($this->importSql);
    }

    public function importSqlProject(SqlDiagramImporter $importer): void
    {
        $this->validateOnly('importProjectName');
        $this->importError = '';

        try {
            $data = $importer->parse($this->importSql, $this->importDialect ?: null);
        } catch (InvalidArgumentException $exception) {
            $this->importError = $exception->getMessage();

            return;
        }

        $diagram = Diagram::create([
            'name' => trim($this->importProjectName),
            'type' => Diagram::TYPE_RELATIONAL,
            'data' => $data['relational'],
        ]);

        $this->redirectRoute('boards.relational', $diagram, navigate: true);
    }

    public function importProject(): void
    {
        $this->validateOnly('importProjectName');

        try {
            $data = ErDiagramImporter::parse($this->importJson);
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
            'data' => ErDiagramImporter::parse(
                file_get_contents(public_path('examples/er-conversion-cases.json')),
            ),
        ]);

        $this->redirectRoute('boards.er', $diagram, navigate: true);
    }

    public function createRelational(int $sourceDiagramId, RelationalDiagramCreator $copy): void
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
            ->projects()
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
            ->projects()
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
            ->projects()
            ->firstOrFail()
            ->delete();
    }

    public function render(): View
    {
        return view('livewire.project-dashboard');
    }
}
