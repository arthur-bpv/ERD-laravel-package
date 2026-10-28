<?php

namespace App\Livewire;

use App\Livewire\Concerns\HandlesDiagramJsonExport;
use App\Models\Diagram;
use App\Services\ErToRelationalTransformer;
use App\Services\RelationalColumnUpdater;
use App\Services\RelationalDiagramDriftAnalyzer;
use App\Services\RelationalSqlGenerator;
use App\Services\RelationalStructureEditor;
use App\Support\BoardLayout;
use App\Support\DataTypeCatalog;
use App\Support\RelationalForeignKeyEdgeProjector;
use App\Support\RelationalTableNodeProjector;
use ArtisanFlow\WireFlow\Concerns\WithWireFlow;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.app')]
class RelationalBoard extends Component
{
    use HandlesDiagramJsonExport;
    use WithWireFlow;

    #[Locked]
    public string $dialect = 'mysql';

    /** Leave enough room to read names, types, and foreign keys. */
    private const LAYOUT_COLUMN_GAP = 130;

    private const LAYOUT_ROW_GAP = 40;

    #[Locked]
    public int $diagramId;

    #[Locked]
    public ?int $sourceDiagramId = null;

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

    #[Locked]
    public bool $isCustomized = false;

    /**
     * Fingerprint of the ER model at the last generation.
     *
     * Drift detection depends on the source ER, not manually edited tables.
     */
    #[Locked]
    public ?string $sourceFingerprint = null;

    public bool $showSql = false;

    #[Locked]
    public string $sqlPreview = '';

    #[Locked]
    public ?string $sqlError = null;

    public function mount(Diagram $diagram, ErToRelationalTransformer $transformer): void
    {
        abort_unless($diagram->type === Diagram::TYPE_RELATIONAL, 404);

        $source = $diagram->source_diagram_id ? $diagram->sourceDiagram()->firstOrFail() : null;
        abort_if($source && $source->type !== Diagram::TYPE_ENTITY_RELATIONSHIP, 404);

        $this->diagramId = $diagram->id;
        $this->sourceDiagramId = $source?->id;
        $this->diagramName = $diagram->name;
        $this->sourceDiagramName = $source?->name ?? $diagram->name;

        $data = $diagram->data;
        if ($source && ! isset($data['tables'], $data['foreignKeys'])) {
            $data = $transformer->transform($source->data ?? []);
            $diagram->update(['data' => $data]);
        }

        $this->sourceFingerprint = $data['sourceFingerprint'] ?? null;
        $this->fillFromData($data);
    }

    public function regenerate(ErToRelationalTransformer $transformer): void
    {
        abort_if($this->sourceDiagramId === null, 403);

        $oldTableIds = array_column($this->tables, 'id');
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

        $this->sourceFingerprint = $data['sourceFingerprint'] ?? null;
        $this->fillFromData($data);
        if ($oldTableIds !== [] && $this->tables !== []) {
            $this->syncCanvas($oldTableIds);
            $this->flowFitView();
        }
        $this->dispatch('relational-regenerated');
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
        $this->syncCanvas();
    }

    public function updateColumnType(string $tableId, string $columnId, string $type): void
    {
        $this->applyColumnEdit((new RelationalColumnUpdater)->type($this->tables, $this->dialect, $tableId, $columnId, $type));
    }

    public function updateColumnSize(
        string $tableId,
        string $columnId,
        int|string|null $size,
        int|string|null $scale = null,
    ): void {
        $this->applyColumnEdit((new RelationalColumnUpdater)->size($this->tables, $this->dialect, $tableId, $columnId, $size, $scale));
    }

    public function setDialect(string $dialect): void
    {
        if (! DataTypeCatalog::has($dialect) || $dialect === $this->dialect) {
            return;
        }

        $this->dialect = $dialect;
        $this->tables = (new RelationalColumnUpdater)->dialect($this->tables, $dialect);
        $this->persistDialectOnSource();
        $this->commitLogicalEdit();

        $this->dispatch(
            'relational-dialect-warning',
            message: 'Banco alterado para '.DataTypeCatalog::DIALECTS[$dialect]['label']
                .'. Alguns tipos podem ter sido ajustados, revise as colunas.',
        );
    }

    public function toggleColumnNullable(string $tableId, string $columnId): void
    {
        $tables = (new RelationalColumnUpdater)->toggleNullable($this->tables, $tableId, $columnId);
        if ($tables === null) {
            $this->dispatch('relational-edit-rejected', message: 'Uma chave primária não pode aceitar NULL.');

            return;
        }

        $this->applyColumnEdit($tables);
    }

    private function applyColumnEdit(?array $tables): void
    {
        if ($tables === null) {
            return;
        }

        $this->tables = $tables;
        $this->commitLogicalEdit();
    }

    public function openSqlPreview(RelationalSqlGenerator $generator): void
    {
        $this->showSql = true;
        $this->sqlError = null;
        $this->sqlPreview = '';

        try {
            $this->sqlPreview = $generator->generate($this->sqlData());
        } catch (InvalidArgumentException $exception) {
            $this->sqlError = $exception->getMessage();
        }
    }

    public function downloadSql(RelationalSqlGenerator $generator): StreamedResponse
    {
        $sql = $generator->generate($this->sqlData());

        return response()->streamDownload(
            static function () use ($sql): void {
                echo $sql;
            },
            'modelo-relacional-'.$this->dialect.'.sql',
            ['Content-Type' => 'application/sql; charset=UTF-8'],
        );
    }

    private function sqlData(): array
    {
        return [
            'projectName' => $this->sourceDiagramName,
            'dialect' => $this->dialect,
            'tables' => $this->tables,
            'foreignKeys' => $this->foreignKeys,
        ];
    }

    public bool $showStructureEditor = false;

    private function authorizeStructureEdit(): void
    {
        abort_unless($this->sourceDiagramId === null && Diagram::query()
            ->whereKey($this->diagramId)->where('type', Diagram::TYPE_RELATIONAL)
            ->whereNull('source_diagram_id')->exists(), 403);
    }

    public function openStructureEditor(): void
    {
        $this->authorizeStructureEdit();
        $this->showStructureEditor = true;
    }

    private function editStructure(callable $edit): void
    {
        $this->authorizeStructureEdit();
        $editor = new RelationalStructureEditor($this->tables, $this->foreignKeys);
        try {
            $edit($editor);
        } catch (InvalidArgumentException $exception) {
            $this->dispatch('relational-edit-rejected', message: $exception->getMessage());

            return;
        }
        $previous = array_column($this->tables, 'id');
        $this->tables = $editor->tables;
        $this->foreignKeys = $editor->foreignKeys;
        $this->persistCurrentData(markCustomized: true);
        if ($previous !== []) {
            $this->syncCanvas($previous);
        }
        $this->dispatch('relational-saved');
    }

    public function addTable(string $name): void
    {
        $this->editStructure(fn (RelationalStructureEditor $editor) => $editor->addTable($name, $this->dialect));
    }

    public function renameTable(string $tableId, string $name): void
    {
        $this->editStructure(fn (RelationalStructureEditor $editor) => $editor->renameTable($tableId, $name));
    }

    public function addColumn(string $tableId, string $name, string $type = 'varchar', string $key = ''): void
    {
        $this->editStructure(fn (RelationalStructureEditor $editor) => $editor->addColumn($tableId, $name, $type, $key, $this->dialect));
    }

    public function renameColumn(string $tableId, string $columnId, string $name): void
    {
        $this->editStructure(fn (RelationalStructureEditor $editor) => $editor->renameColumn($tableId, $columnId, $name));
    }

    public function addRelationship(string $name, string $fromTable, array $fromColumns, string $toTable, array $toColumns): void
    {
        $this->editStructure(fn (RelationalStructureEditor $editor) => $editor->addRelationship($name, $fromTable, $fromColumns, $toTable, $toColumns));
    }

    public function renameRelationship(string $id, string $name): void
    {
        $this->editStructure(fn (RelationalStructureEditor $editor) => $editor->renameRelationship($id, $name));
    }

    public function deleteRelationship(string $id): void
    {
        $this->authorizeStructureEdit();
        $this->removeForeignKeysWhere(fn (array $key): bool => $key['id'] === $id);
        $this->commitLogicalEdit();
    }

    public function deleteColumn(string $tableId, string $columnId): void
    {
        $this->authorizeStructureEdit();
        foreach ($this->tables as &$table) {
            if ($table['id'] !== $tableId) {
                continue;
            }
            $column = collect($table['columns'])->firstWhere('id', $columnId);
            if ($column === null) {
                return;
            }
            if (count($table['columns']) === 1) {
                $this->dispatch('relational-edit-rejected', message: 'A tabela precisa ter ao menos uma coluna. Exclua a tabela para removê-la.');

                return;
            }
            $table['columns'] = array_values(array_filter($table['columns'], fn (array $item): bool => $item['id'] !== $columnId));
            $table['primaryKey'] = array_values(array_diff($table['primaryKey'], [$column['name']]));
            $table['uniqueKeys'] = array_values(array_filter($table['uniqueKeys'] ?? [], fn (array $key): bool => ! in_array($column['name'], $key, true)));
            unset($table);
            $this->removeForeignKeysWhere(fn (array $key): bool => ($key['fromTable'] === $tableId && (($key['fromColumnId'] ?? '') === $columnId || $key['fromColumn'] === $column['name']))
                || ($key['toTable'] === $tableId && (($key['toColumnId'] ?? '') === $columnId || $key['toColumn'] === $column['name'])));
            $this->commitLogicalEdit();

            return;
        }
    }

    public function deleteSelection(array $tableIds, array $edgeIds = []): void
    {
        $this->authorizeStructureEdit();
        $previous = array_column($this->tables, 'id');
        $this->tables = array_values(array_filter($this->tables, fn (array $table): bool => ! in_array($table['id'], $tableIds, true)));
        $this->removeForeignKeysWhere(fn (array $key): bool => in_array($key['fromTable'], $tableIds, true)
            || in_array($key['toTable'], $tableIds, true) || in_array($key['id'], $edgeIds, true));
        $this->persistCurrentData(markCustomized: true);
        $this->syncCanvas($previous);
        $this->dispatch('relational-saved');
    }

    private function removeForeignKeysWhere(callable $matches): void
    {
        $groups = [];
        foreach ($this->foreignKeys as $key) {
            if ($matches($key) && ! empty($key['relationshipName'])) {
                $groups[] = [$key['fromTable'], $key['toTable'], $key['relationshipName']];
            }
        }
        $this->foreignKeys = array_values(array_filter($this->foreignKeys, fn (array $key): bool => ! $matches($key)
            && ! in_array([$key['fromTable'], $key['toTable'], $key['relationshipName'] ?? null], $groups, true)));
        foreach ($this->tables as &$table) {
            foreach ($table['columns'] as &$column) {
                $key = collect($this->foreignKeys)->first(fn (array $key): bool => $key['fromTable'] === $table['id'] && $key['fromColumn'] === $column['name']);
                if ($key === null) {
                    $column['key'] = implode('/', array_filter(explode('/', $column['key'] ?? ''), fn (string $flag): bool => $flag !== 'FK'));
                    $column['references'] = null;
                }
            }
            unset($column);
        }
        unset($table);
    }

    public function save(): void
    {
        $this->persistCurrentData();
        $this->dispatch('relational-saved');
    }

    public function organizeBoard(): void
    {
        $links = array_map(fn (array $foreignKey) => [
            'source' => $foreignKey['fromTable'] ?? '',
            'target' => $foreignKey['toTable'] ?? '',
        ], $this->foreignKeys);
        $positions = BoardLayout::centered(
            $this->tables,
            $links,
            fn (array $table): int => RelationalTableNodeProjector::dimensions($table)['height'],
            RelationalTableNodeProjector::WIDTH,
            self::LAYOUT_COLUMN_GAP,
            self::LAYOUT_ROW_GAP,
        );
        // A tree already has a unique path between tables. Clear corridors
        // only when cycles could cause edges to cross other tables.
        if (BoardLayout::hasUndirectedCycle($this->tables, $links)) {
            $positions = BoardLayout::clearLinkCorridors(
                $this->tables,
                $links,
                $positions,
                fn (array $table): int => RelationalTableNodeProjector::dimensions($table)['height'],
                RelationalTableNodeProjector::WIDTH,
                self::LAYOUT_ROW_GAP,
                36,
            );
        }
        $positions = BoardLayout::alignTerminalNodes(
            $this->tables,
            $links,
            $positions,
            fn (array $table): int => RelationalTableNodeProjector::dimensions($table)['height'],
            RelationalTableNodeProjector::WIDTH,
            self::LAYOUT_COLUMN_GAP,
            fn (array $table): bool => ($table['kind'] ?? '') === 'multivalued',
        );

        foreach ($this->tables as &$table) {
            if (isset($positions[$table['id']])) {
                $table['x'] = $positions[$table['id']]['x'];
                $table['y'] = $positions[$table['id']]['y'];
            }
        }
        unset($table);

        $this->persistCurrentData();
        $this->syncCanvas();
        $this->flowFitView();
    }

    public function getJsonPreviewProperty(): string
    {
        return json_encode([
            '_comment' => 'Projeto: '.$this->sourceDiagramName.' | Modelo: '.$this->diagramName,
            'dialect' => $this->dialect,
            'tables' => $this->tables,
            'foreignKeys' => $this->foreignKeys,
            'warnings' => $this->warnings,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /** Filename used by the modal's JSON download action. */
    protected function jsonFileName(): string
    {
        return 'modelo-relacional.json';
    }

    public function buildNodes(): array
    {
        return array_map(RelationalTableNodeProjector::node(...), $this->tables);
    }

    public function buildEdges(): array
    {
        $edges = (new RelationalForeignKeyEdgeProjector)->build($this->tables, $this->foreignKeys, RelationalTableNodeProjector::WIDTH);
        if ($this->sourceDiagramId === null) {
            $keys = array_column($this->foreignKeys, null, 'id');
            foreach ($edges as &$edge) {
                $name = $keys[$edge['id']]['relationshipName'] ?? '';
                if ($name !== '' && ! str_starts_with($edge['label'], $name.' · ')) {
                    $edge['label'] = $name.' · '.$edge['label'];
                }
            }
        }

        return $edges;
    }

    public function render(RelationalDiagramDriftAnalyzer $drift): View
    {
        $report = $this->sourceDiagramId === null ? ['outdated' => false, 'changes' => []] : $drift->report($this->sourceDiagramData(), [
            'dialect' => $this->dialect,
            'tables' => $this->tables,
            'foreignKeys' => $this->foreignKeys,
            'customized' => $this->isCustomized,
            'sourceFingerprint' => $this->sourceFingerprint,
        ]);

        return view('livewire.relational-board', [
            'nodes' => $this->buildNodes(),
            'edges' => $this->buildEdges(),
            'isOutdated' => $report['outdated'],
            'drift' => $report['changes'],
        ]);
    }

    private function sourceDiagramData(): array
    {
        return Diagram::query()
            ->whereKey($this->sourceDiagramId)
            ->where('type', Diagram::TYPE_ENTITY_RELATIONSHIP)
            ->firstOrFail()
            ->data ?? [];
    }

    private function fillFromData(array $data): void
    {
        $this->tables = array_values($data['tables'] ?? []);
        $this->foreignKeys = array_values($data['foreignKeys'] ?? []);
        $this->warnings = array_values($data['warnings'] ?? []);
        $this->isCustomized = (bool) ($data['customized'] ?? false);
        $this->dialect = DataTypeCatalog::has($data['dialect'] ?? '') ? $data['dialect'] : 'mysql';

        $this->tables = (new RelationalColumnUpdater)->dialect($this->tables, $this->dialect);
    }

    private function persistCurrentData(bool $markCustomized = false): void
    {
        $diagram = Diagram::query()
            ->whereKey($this->diagramId)
            ->where('type', Diagram::TYPE_RELATIONAL)
            ->where('source_diagram_id', $this->sourceDiagramId)
            ->firstOrFail();

        $data = $diagram->data ?? [];
        $data['dialect'] = $this->dialect;
        $data['tables'] = $this->tables;
        $data['foreignKeys'] = $this->foreignKeys;
        $data['warnings'] = $this->warnings;
        $data['customized'] = $markCustomized || (bool) ($data['customized'] ?? false);
        $diagram->update(['data' => $data]);
        $this->isCustomized = $data['customized'];
    }

    private function commitLogicalEdit(): void
    {
        $this->persistCurrentData(markCustomized: true);
        $this->syncCanvas();
        $this->dispatch('relational-saved');
    }

    /** Store the dialect on the ER source that the project exports. */
    private function persistDialectOnSource(): void
    {
        if ($this->sourceDiagramId === null) {
            return;
        }

        $source = Diagram::query()
            ->whereKey($this->sourceDiagramId)
            ->where('type', Diagram::TYPE_ENTITY_RELATIONSHIP)
            ->firstOrFail();
        $data = $source->data ?? [];
        $data['dialect'] = $this->dialect;
        $source->update(['data' => $data]);
    }

    private function syncCanvas(?array $previousTableIds = null): void
    {
        // Replacing all nodes leaves x-for with old references for unchanged IDs.
        // Update those nodes individually to keep their controls reactive.
        $nodes = $this->buildNodes();
        $previousTableIds ??= array_column($this->tables, 'id');
        $currentTableIds = array_column($nodes, 'id');
        $removed = array_values(array_diff($previousTableIds, $currentTableIds));
        if ($removed !== []) {
            $this->flowRemoveNodes($removed);
        }

        $added = array_values(array_filter($nodes, fn (array $node): bool => ! in_array($node['id'], $previousTableIds, true)));
        if ($added !== []) {
            $this->flowAddNodes($added);
        }

        foreach ($nodes as $node) {
            if (! in_array($node['id'], $previousTableIds, true)) {
                continue;
            }
            $this->flowUpdateNode($node['id'], [
                'position' => $node['position'],
                'dimensions' => $node['dimensions'],
                'data' => $node['data'],
            ]);
        }

        // Edges may change handles or disappear after a column edit. Restore
        // them without replacing the live nodes.
        $this->flowFromObject(['edges' => $this->buildEdges()]);
    }

    public function updateColumnCustomType(string $tableId, string $columnId, string $value): void
    {
        try {
            $tables = (new RelationalColumnUpdater)->customType($this->tables, $this->dialect, $tableId, $columnId, $value);
        } catch (InvalidArgumentException $exception) {
            $this->dispatch('relational-edit-rejected', message: $exception->getMessage());

            return;
        }

        $this->applyColumnEdit($tables);
    }

    public function updateColumnEnumValues(string $tableId, string $columnId, string $value): void
    {
        $this->applyColumnEdit((new RelationalColumnUpdater)->enumValues($this->tables, $this->dialect, $tableId, $columnId, $value));
    }
}
