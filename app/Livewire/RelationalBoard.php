<?php

namespace App\Livewire;

use App\Livewire\Concerns\InterageComJson;
use App\Models\Diagram;
use App\Services\ErToRelationalTransformer;
use App\Services\RelationalDrift;
use App\Services\RelationalSqlGenerator;
use App\Support\BoardLayout;
use App\Support\DataTypeCatalog;
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
    use InterageComJson;
    use WithWireFlow;

    #[Locked]
    public string $dialect = 'mysql';

    private const RECURSIVE_SIDES = ['right', 'left'];

    private const TABLE_WIDTH = 380;

    /** Altura do cabeçalho e das linhas de coluna da tabela. */
    private const TABLE_HEADER_HEIGHT = 62;

    private const TABLE_BODY_PADDING = 12;

    private const TABLE_COLUMN_HEIGHT = 44;

    private const LAYOUT_COLUMN_GAP = 180;

    private const LAYOUT_ROW_GAP = 140;

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

    /**
     * Impressão digital do ER no momento da última geração.
     *
     * Vive aqui para que a verificação de defasagem não dependa de `$this->tables`
     * (que a edição manual também mexe): o que decide se o ER mudou é o ER.
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

        $this->sourceFingerprint = $data['sourceFingerprint'] ?? null;
        $this->fillFromData($data);
    }

    public function regenerate(ErToRelationalTransformer $transformer): void
    {
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

        if ($this->rejectDuplicateColumnName($tableIndex, $name, $columnId)) {
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

    public function updateColumnType(string $tableId, string $columnId, string $type): void
    {
        $tableIndex = $this->tableIndex($tableId);
        $columnIndex = $tableIndex === null ? null : $this->columnIndex($tableIndex, $columnId);

        if ($columnIndex === null || ! in_array($type, DataTypeCatalog::canonicalFor($this->dialect), true)) {
            return;
        }

        $column = $this->tables[$tableIndex]['columns'][$columnIndex];
        $column['type'] = $type;
        $this->tables[$tableIndex]['columns'][$columnIndex] = DataTypeCatalog::normalize($this->dialect, $column);

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

        $column = $this->tables[$tableIndex]['columns'][$columnIndex];

        // Só faz sentido dimensionar tipos parametrizáveis (length/decimal).
        // Qualquer outro tipo é ignorado para não gravar lixo no estado.
        match ($kind = DataTypeCatalog::kind($column['type'] ?? '')) {
            'length' => $column['length'] = (int) $size,
            'decimal' => [
                $column['precision'] = (int) $size,
                $column['scale'] = is_numeric($scale) ? (int) $scale : (int) ($column['scale'] ?? 0),
            ],
            default => null,
        };

        if ($kind === null) {
            return;
        }

        $this->tables[$tableIndex]['columns'][$columnIndex] = DataTypeCatalog::normalize($this->dialect, $column);
        $this->commitLogicalEdit();
    }

    public function setDialect(string $dialect): void
    {
        if (! DataTypeCatalog::has($dialect) || $dialect === $this->dialect) {
            return;
        }

        $this->dialect = $dialect;

        foreach ($this->tables as $tableIndex => $table) {
            foreach ($table['columns'] as $columnIndex => $column) {
                $column['type'] = DataTypeCatalog::resolve($dialect, $column['type'] ?? 'varchar');
                $this->tables[$tableIndex]['columns'][$columnIndex] = DataTypeCatalog::normalize($dialect, $column);
            }
        }

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

        $this->tables[$tableIndex]['columns'][$columnIndex]['nullable'] = ! $this->tables[$tableIndex]['columns'][$columnIndex]['nullable'];
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

    /**
     * Tamanho de uma tabela, no canvas e no arranjo automático.
     *
     * @return array{width: int, height: int}
     */
    private function tableDimensions(array $table): array
    {
        return [
            'width' => self::TABLE_WIDTH,
            'height' => self::TABLE_HEADER_HEIGHT
                + self::TABLE_BODY_PADDING
                + (count($table['columns'] ?? []) * self::TABLE_COLUMN_HEIGHT),
        ];
    }

    private function sqlData(): array
    {
        return [
            'dialect' => $this->dialect,
            'tables' => $this->tables,
            'foreignKeys' => $this->foreignKeys,
        ];
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
            fn (array $table): int => $this->tableDimensions($table)['height'],
            self::TABLE_WIDTH,
            self::LAYOUT_COLUMN_GAP,
            self::LAYOUT_ROW_GAP,
        );
        $positions = BoardLayout::clearLinkCorridors(
            $this->tables,
            $links,
            $positions,
            fn (array $table): int => $this->tableDimensions($table)['height'],
            self::TABLE_WIDTH,
            self::LAYOUT_ROW_GAP,
            24,
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
            'tables' => $this->tables,
            'foreignKeys' => $this->foreignKeys,
            'warnings' => $this->warnings,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /** Nome do arquivo baixado pelo botão "Baixar .json" do modal. */
    protected function jsonFileName(): string
    {
        return 'modelo-relacional.json';
    }

    public function buildNodes(): array
    {
        return array_map(fn (array $table) => [
            'id' => $table['id'],
            'position' => ['x' => $table['x'], 'y' => $table['y']],
            'dimensions' => $this->tableDimensions($table),
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
        $tablePositions = collect($this->tables)->mapWithKeys(fn (array $table) => [
            $table['id'] => ['x' => $table['x'], 'y' => $table['y']],
        ])->all();

        $pairTotals = [];
        foreach ($this->foreignKeys as $foreignKey) {
            if ($foreignKey['fromTable'] === $foreignKey['toTable']) {
                continue;
            }
            $pairKey = $foreignKey['fromTable'].'::'.$foreignKey['toTable'];
            $pairTotals[$pairKey] = ($pairTotals[$pairKey] ?? 0) + 1;
        }

        return array_map(function (array $foreignKey) use (&$recursiveSlots, $pairTotals, $tablePositions) {
            $isRecursive = $foreignKey['fromTable'] === $foreignKey['toTable'];
            $cardinality = $foreignKey['cardinality'] ?? 'N:1';
            $relationshipName = trim((string) ($foreignKey['relationshipName'] ?? ''));
            $pairKey = $foreignKey['fromTable'].'::'.$foreignKey['toTable'];
            $isParallel = ! $isRecursive && ($pairTotals[$pairKey] ?? 0) > 1;

            $edge = [
                'id' => $foreignKey['id'],
                'source' => $foreignKey['fromTable'],
                'target' => $foreignKey['toTable'],
                'label' => match (true) {
                    $isRecursive && $relationshipName !== '' => $relationshipName.' · '.$cardinality,
                    $isParallel => $foreignKey['fromColumn'].' · '.$cardinality,
                    default => $cardinality,
                },
                'color' => '#38bdf8',
                'strokeWidth' => 1.6,
                'markerEnd' => 'arrowclosed',
            ];

            // Toda aresta parte da linha FK e termina na linha que ela referencia.
            $fromColumnId = $this->columnIdForForeignKey($foreignKey, 'from');
            $toColumnId = $this->columnIdForForeignKey($foreignKey, 'to');

            if ($fromColumnId === null || $toColumnId === null) {
                return array_merge($edge, ['type' => 'floating', 'pathType' => 'smoothstep', 'class' => 'relational-cardinality']);
            }

            if ($isRecursive) {
                $slot = $recursiveSlots[$foreignKey['fromTable']] ?? 0;
                $recursiveSlots[$foreignKey['fromTable']] = $slot + 1;
                $side = self::RECURSIVE_SIDES[$slot % count(self::RECURSIVE_SIDES)];

                return array_merge($edge, [
                    'type' => 'relational-self-loop',
                    'pathType' => 'smoothstep',
                    'sourceHandle' => 'col-'.$fromColumnId.'-'.$side,
                    'targetHandle' => 'col-'.$toColumnId.'-'.$side,
                    'class' => 'relational-cardinality relational-self-reference',
                ]);
            }

            $from = $tablePositions[$foreignKey['fromTable']] ?? ['x' => 0, 'y' => 0];
            $to = $tablePositions[$foreignKey['toTable']] ?? ['x' => 0, 'y' => 0];
            $side = $to['x'] >= $from['x'] ? 'right' : 'left';
            $tablesOverlapHorizontally = abs($to['x'] - $from['x']) < self::TABLE_WIDTH;
            $opposite = $tablesOverlapHorizontally
                ? $side
                : ($side === 'right' ? 'left' : 'right');

            return array_merge($edge, [
                'type' => 'smoothstep',
                'pathType' => 'smoothstep',
                'sourceHandle' => 'col-'.$fromColumnId.'-'.$side,
                'targetHandle' => 'col-'.$toColumnId.'-'.$opposite,
                'class' => 'relational-cardinality'.($isParallel ? ' relational-parallel-reference' : ''),
            ]);
        }, $this->foreignKeys);
    }

    public function render(RelationalDrift $drift): View
    {
        $report = $drift->report($this->sourceDiagramData(), [
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

    private function syncCanvas(?array $previousTableIds = null): void
    {
        // fromObject substitui os nós e o template x-for mantém a referência
        // antiga para IDs iguais. Atualizar cada nó preserva a reatividade dos
        // controles dentro dele, incluindo NULL, nome, tipo e tamanho.
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

        // As arestas podem mudar de handle ou desaparecer quando uma coluna
        // é renomeada/removida; restaurá-las sem `nodes` preserva os nós vivos.
        $this->flowFromObject(['edges' => $this->buildEdges()]);
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

    /**
     * Recusa um nome que já existe na tabela, e avisa por que.
     *
     * A comparação ignora maiúsculas porque o SQL trata identificadores assim
     * em MySQL e PostgreSQL: `Email` e `email` na mesma tabela colidem no
     * banco, mesmo que o quadro as mostre lado a lado.
     *
     * @param  string|null  $exceptColumnId  coluna que está sendo renomeada,
     *                                       e portanto não colide consigo mesma
     */
    private function rejectDuplicateColumnName(int $tableIndex, string $name, ?string $exceptColumnId = null): bool
    {
        $duplicate = collect($this->tables[$tableIndex]['columns'])->contains(
            fn (array $column): bool => $column['id'] !== $exceptColumnId
                && mb_strtolower($column['name']) === mb_strtolower($name),
        );

        if ($duplicate) {
            $this->dispatch('relational-edit-rejected', message: 'Já existe uma coluna com esse nome.');

            return true;
        }

        return false;
    }

    private function columnIdForForeignKey(array $foreignKey, string $end): ?string
    {
        $tableId = $foreignKey[$end.'Table'];
        $stableId = $foreignKey[$end.'ColumnId'] ?? null;
        $columnName = $foreignKey[$end.'Column'];
        $tableIndex = $this->tableIndex($tableId);

        if ($tableIndex === null) {
            return null;
        }

        foreach ($this->tables[$tableIndex]['columns'] as $column) {
            if (($stableId !== null && $column['id'] === $stableId) || $column['name'] === $columnName) {
                return $column['id'];
            }
        }

        return null;
    }
}
