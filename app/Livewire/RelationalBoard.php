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

    private const EDITABLE_COLUMN_TYPES = [
        'bigint', 'integer', 'decimal', 'numeric', 'boolean', 'char', 'varchar', 'text', 'date', 'datetime', 'timestamp', 'json',
    ];

    private const RECURSIVE_HANDLE_PAIRS = [
        ['right', 'top'],
        ['bottom', 'right'],
        ['left', 'bottom'],
        ['top', 'left'],
    ];

    private const PARALLEL_HORIZONTAL_HANDLE_PAIRS = [
        ['top', 'top'],
        ['bottom', 'bottom'],
        ['right', 'left'],
        ['left', 'right'],
    ];

    private const PARALLEL_VERTICAL_HANDLE_PAIRS = [
        ['left', 'left'],
        ['right', 'right'],
        ['bottom', 'top'],
        ['top', 'bottom'],
    ];

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

    #[Locked]
    public bool $isCustomized = false;

    public bool $showJson = false;

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

    public function renameTable(string $tableId, string $name): void
    {
        $name = $this->normalizedName($name);
        $tableIndex = $this->tableIndex($tableId);

        if ($name === null || $tableIndex === null) {
            return;
        }

        $this->tables[$tableIndex]['name'] = $name;
        $this->commitLogicalEdit();
    }

    public function renameColumn(string $tableId, string $columnId, string $name): void
    {
        $name = $this->normalizedName($name);
        $tableIndex = $this->tableIndex($tableId);
        $columnIndex = $tableIndex === null ? null : $this->columnIndex($tableIndex, $columnId);

        if ($name === null || $columnIndex === null) {
            return;
        }

        $duplicate = collect($this->tables[$tableIndex]['columns'])->contains(
            fn (array $column) => $column['id'] !== $columnId && mb_strtolower($column['name']) === mb_strtolower($name),
        );
        if ($duplicate) {
            $this->dispatch('relational-edit-rejected', message: 'Já existe uma coluna com esse nome.');

            return;
        }

        $oldName = $this->tables[$tableIndex]['columns'][$columnIndex]['name'];
        $this->tables[$tableIndex]['columns'][$columnIndex]['name'] = $name;
        $this->tables[$tableIndex]['primaryKey'] = array_map(
            fn (string $primary) => $primary === $oldName ? $name : $primary,
            $this->tables[$tableIndex]['primaryKey'],
        );

        foreach ($this->tables as &$table) {
            foreach ($table['columns'] as &$column) {
                if (($column['references']['table'] ?? null) === $tableId
                    && ($column['references']['column'] ?? null) === $oldName) {
                    $column['references']['column'] = $name;
                }
            }
            unset($column);
        }
        unset($table);

        foreach ($this->foreignKeys as &$foreignKey) {
            if ($foreignKey['fromTable'] === $tableId && $foreignKey['fromColumn'] === $oldName) {
                $foreignKey['fromColumn'] = $name;
            }
            if ($foreignKey['toTable'] === $tableId && $foreignKey['toColumn'] === $oldName) {
                $foreignKey['toColumn'] = $name;
            }
        }
        unset($foreignKey);

        $this->commitLogicalEdit();
    }

    public function addColumn(string $tableId, string $name, string $type = 'varchar'): void
    {
        $name = $this->normalizedName($name);
        $tableIndex = $this->tableIndex($tableId);
        $type = in_array($type, self::EDITABLE_COLUMN_TYPES, true) ? $type : 'varchar';

        if ($name === null || $tableIndex === null) {
            return;
        }

        if (collect($this->tables[$tableIndex]['columns'])->contains(
            fn (array $column) => mb_strtolower($column['name']) === mb_strtolower($name),
        )) {
            $this->dispatch('relational-edit-rejected', message: 'Já existe uma coluna com esse nome.');

            return;
        }

        $sequence = 1;
        $existingIds = array_column($this->tables[$tableIndex]['columns'], 'id');
        while (in_array($tableId.'.manual_'.$sequence, $existingIds, true)) {
            $sequence++;
        }

        $this->tables[$tableIndex]['columns'][] = [
            'id' => $tableId.'.manual_'.$sequence,
            'name' => $name,
            'type' => $type,
            'key' => '',
            'nullable' => false,
            'references' => null,
            'source' => 'manual',
            'length' => in_array($type, ['char', 'varchar'], true) ? ($type === 'char' ? 1 : 255) : null,
            'precision' => in_array($type, ['decimal', 'numeric'], true) ? 10 : null,
            'scale' => in_array($type, ['decimal', 'numeric'], true) ? 2 : null,
        ];

        $this->commitLogicalEdit();
    }

    public function updateColumnType(string $tableId, string $columnId, string $type): void
    {
        $tableIndex = $this->tableIndex($tableId);
        $columnIndex = $tableIndex === null ? null : $this->columnIndex($tableIndex, $columnId);

        if ($columnIndex === null || ! in_array($type, self::EDITABLE_COLUMN_TYPES, true)) {
            return;
        }

        $this->tables[$tableIndex]['columns'][$columnIndex]['type'] = $type;
        $column = &$this->tables[$tableIndex]['columns'][$columnIndex];
        $column['length'] = in_array($type, ['char', 'varchar'], true)
            ? (int) ($column['length'] ?? ($type === 'char' ? 1 : 255))
            : null;
        $column['precision'] = in_array($type, ['decimal', 'numeric'], true)
            ? (int) ($column['precision'] ?? 10)
            : null;
        $column['scale'] = in_array($type, ['decimal', 'numeric'], true)
            ? min((int) ($column['scale'] ?? 2), $column['precision'])
            : null;
        unset($column);
        $this->commitLogicalEdit();
    }

    public function updateColumnSize(
        string $tableId,
        string $columnId,
        int|string|null $size,
        int|string|null $scale = null,
    ): void {
        $tableIndex = $this->tableIndex($tableId);
        $columnIndex = $tableIndex === null ? null : $this->columnIndex($tableIndex, $columnId);

        if ($columnIndex === null || ! is_numeric($size)) {
            return;
        }

        $column = &$this->tables[$tableIndex]['columns'][$columnIndex];
        $type = $column['type'] ?? '';

        if (in_array($type, ['char', 'varchar'], true)) {
            $column['length'] = max(1, min($type === 'char' ? 255 : 65535, (int) $size));
            $column['precision'] = null;
            $column['scale'] = null;
        } elseif (in_array($type, ['decimal', 'numeric'], true)) {
            $column['precision'] = max(1, min(65, (int) $size));
            $requestedScale = is_numeric($scale) ? (int) $scale : (int) ($column['scale'] ?? 0);
            $column['scale'] = max(0, min(30, $column['precision'], $requestedScale));
            $column['length'] = null;
        } else {
            unset($column);

            return;
        }
        unset($column);

        $this->commitLogicalEdit();
    }

    public function toggleColumnNullable(string $tableId, string $columnId): void
    {
        $tableIndex = $this->tableIndex($tableId);
        $columnIndex = $tableIndex === null ? null : $this->columnIndex($tableIndex, $columnId);

        if ($columnIndex === null || str_contains($this->tables[$tableIndex]['columns'][$columnIndex]['key'], 'PK')) {
            $this->dispatch('relational-edit-rejected', message: 'Uma chave primária não pode aceitar NULL.');

            return;
        }

        $current = (bool) $this->tables[$tableIndex]['columns'][$columnIndex]['nullable'];
        $this->tables[$tableIndex]['columns'][$columnIndex]['nullable'] = ! $current;
        $this->commitLogicalEdit();
    }

    public function removeColumn(string $tableId, string $columnId): void
    {
        $tableIndex = $this->tableIndex($tableId);
        $columnIndex = $tableIndex === null ? null : $this->columnIndex($tableIndex, $columnId);

        if ($columnIndex === null) {
            return;
        }

        $columnName = $this->tables[$tableIndex]['columns'][$columnIndex]['name'];
        $pending = [[$tableId, $columnName]];
        $removedForeignKeys = [];
        $removedColumns = [];

        while ($pending !== []) {
            [$currentTableId, $currentColumnName] = array_shift($pending);
            $columnKey = $currentTableId.'::'.$currentColumnName;
            if (isset($removedColumns[$columnKey])) {
                continue;
            }
            $removedColumns[$columnKey] = true;

            $currentTableIndex = $this->tableIndex($currentTableId);
            if ($currentTableIndex === null) {
                continue;
            }

            $currentColumnIndex = array_search(
                $currentColumnName,
                array_column($this->tables[$currentTableIndex]['columns'], 'name'),
                true,
            );
            if ($currentColumnIndex !== false) {
                array_splice($this->tables[$currentTableIndex]['columns'], $currentColumnIndex, 1);
                $this->tables[$currentTableIndex]['primaryKey'] = array_values(array_filter(
                    $this->tables[$currentTableIndex]['primaryKey'],
                    fn (string $primary) => $primary !== $currentColumnName,
                ));
            }

            foreach ($this->foreignKeys as $foreignKey) {
                $usesAsSource = $foreignKey['fromTable'] === $currentTableId
                    && $foreignKey['fromColumn'] === $currentColumnName;
                $usesAsTarget = $foreignKey['toTable'] === $currentTableId
                    && $foreignKey['toColumn'] === $currentColumnName;

                if (! $usesAsSource && ! $usesAsTarget) {
                    continue;
                }

                $removedForeignKeys[$foreignKey['id']] = true;
                if ($usesAsTarget) {
                    $pending[] = [$foreignKey['fromTable'], $foreignKey['fromColumn']];
                }
            }
        }

        $this->foreignKeys = array_values(array_filter(
            $this->foreignKeys,
            fn (array $foreignKey) => ! isset($removedForeignKeys[$foreignKey['id']]),
        ));
        $this->commitLogicalEdit();
    }

    public function toggleJson(): void
    {
        $this->showJson = ! $this->showJson;
    }

    public function save(): void
    {
        $this->persistCurrentData();
        $this->dispatch('relational-saved');
    }

    public function getJsonPreviewProperty(): string
    {
        return json_encode([
            'tables' => $this->tables,
            'foreignKeys' => $this->foreignKeys,
            'warnings' => $this->warnings,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
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
        $recursiveSlots = [];
        $parallelSlots = [];
        $pairTotals = [];
        $tablePositions = collect($this->tables)->mapWithKeys(fn (array $table) => [
            $table['id'] => ['x' => $table['x'], 'y' => $table['y']],
        ])->all();

        foreach ($this->foreignKeys as $foreignKey) {
            if ($foreignKey['fromTable'] === $foreignKey['toTable']) {
                continue;
            }

            $pairKey = $foreignKey['fromTable'].'::'.$foreignKey['toTable'];
            $pairTotals[$pairKey] = ($pairTotals[$pairKey] ?? 0) + 1;
        }

        return array_map(function (array $foreignKey) use (&$recursiveSlots, &$parallelSlots, $pairTotals, $tablePositions) {
            $isRecursive = $foreignKey['fromTable'] === $foreignKey['toTable'];
            $cardinality = $foreignKey['cardinality'] ?? 'N:1';
            $relationshipName = trim((string) ($foreignKey['relationshipName'] ?? ''));
            $pairKey = $foreignKey['fromTable'].'::'.$foreignKey['toTable'];
            $isParallel = ! $isRecursive && ($pairTotals[$pairKey] ?? 0) > 1;
            $edge = [
                'id' => $foreignKey['id'],
                'source' => $foreignKey['fromTable'],
                'target' => $foreignKey['toTable'],
                'type' => 'floating',
                'pathType' => 'smoothstep',
                'label' => match (true) {
                    $isRecursive && $relationshipName !== '' => $relationshipName.' · '.$cardinality,
                    $isParallel => $foreignKey['fromColumn'].' · '.$cardinality,
                    default => $cardinality,
                },
                'color' => '#38bdf8',
                'strokeWidth' => 1.6,
                'markerEnd' => 'arrowclosed',
                'class' => 'relational-cardinality',
            ];

            if (! $isRecursive && ! $isParallel) {
                return $edge;
            }

            if ($isRecursive) {
                $slot = $recursiveSlots[$foreignKey['fromTable']] ?? 0;
                $recursiveSlots[$foreignKey['fromTable']] = $slot + 1;
                [$sourcePosition, $targetPosition] = self::RECURSIVE_HANDLE_PAIRS[
                    $slot % count(self::RECURSIVE_HANDLE_PAIRS)
                ];
            } else {
                $slot = $parallelSlots[$pairKey] ?? 0;
                $parallelSlots[$pairKey] = $slot + 1;
                $from = $tablePositions[$foreignKey['fromTable']] ?? ['x' => 0, 'y' => 0];
                $to = $tablePositions[$foreignKey['toTable']] ?? ['x' => 0, 'y' => 0];
                $pairs = abs($to['x'] - $from['x']) >= abs($to['y'] - $from['y'])
                    ? self::PARALLEL_HORIZONTAL_HANDLE_PAIRS
                    : self::PARALLEL_VERTICAL_HANDLE_PAIRS;
                [$sourcePosition, $targetPosition] = $pairs[$slot % count($pairs)];
            }

            return array_merge($edge, [
                'type' => $isRecursive ? 'relational-self-loop' : 'bezier',
                'sourceHandle' => 'relation-source-'.$sourcePosition,
                'targetHandle' => 'relation-target-'.$targetPosition,
                'class' => 'relational-cardinality '.($isRecursive
                    ? 'relational-self-reference'
                    : 'relational-parallel-reference'),
            ]);
        }, $this->foreignKeys);
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
        $this->isCustomized = (bool) ($data['customized'] ?? false);
    }

    private function persistCurrentData(bool $markCustomized = false): void
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
        $data['customized'] = $markCustomized || (bool) ($data['customized'] ?? false);
        $diagram->update(['data' => $data]);
        $this->isCustomized = $data['customized'];
    }

    private function commitLogicalEdit(): void
    {
        $this->persistCurrentData(markCustomized: true);
        $this->flowFromObject(['nodes' => $this->buildNodes(), 'edges' => $this->buildEdges()]);
        $this->dispatch('relational-saved');
    }

    private function tableIndex(string $tableId): ?int
    {
        $index = array_search($tableId, array_column($this->tables, 'id'), true);

        return $index === false ? null : $index;
    }

    private function columnIndex(int $tableIndex, string $columnId): ?int
    {
        $index = array_search($columnId, array_column($this->tables[$tableIndex]['columns'], 'id'), true);

        return $index === false ? null : $index;
    }

    private function normalizedName(string $name): ?string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');

        return $name !== '' && mb_strlen($name) <= 80 ? $name : null;
    }
}
