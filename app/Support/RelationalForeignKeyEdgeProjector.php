<?php

namespace App\Support;

/** Builds the WireFlow edge payload from the saved relational model. */
final class RelationalForeignKeyEdgeProjector
{
    private const RECURSIVE_SIDES = ['right', 'left'];

    public function build(array $tables, array $foreignKeys, int $tableWidth): array
    {
        $recursiveSlots = [];
        $tablePositions = collect($tables)->mapWithKeys(fn (array $table) => [
            $table['id'] => ['x' => $table['x'], 'y' => $table['y']],
        ])->all();

        $pairTotals = [];
        foreach ($foreignKeys as $foreignKey) {
            if ($foreignKey['fromTable'] === $foreignKey['toTable']) {
                continue;
            }
            $pairKey = $foreignKey['fromTable'].'::'.$foreignKey['toTable'];
            $pairTotals[$pairKey] = ($pairTotals[$pairKey] ?? 0) + 1;
        }

        $edges = [];
        foreach ($foreignKeys as $foreignKey) {
            $edges[] = $this->edgeFor(
                $foreignKey, $tables, $tablePositions, $pairTotals, $tableWidth, $recursiveSlots,
            );
        }

        return $edges;
    }

    private function edgeFor(
        array $foreignKey,
        array $tables,
        array $tablePositions,
        array $pairTotals,
        int $tableWidth,
        array &$recursiveSlots,
    ): array {
        $isRecursive = $foreignKey['fromTable'] === $foreignKey['toTable'];
        $cardinality = $foreignKey['cardinality'] ?? 'N:1';
        $relationshipName = trim((string) ($foreignKey['relationshipName'] ?? ''));
        $pairKey = $foreignKey['fromTable'].'::'.$foreignKey['toTable'];
        $isParallel = ! $isRecursive && ($pairTotals[$pairKey] ?? 0) > 1;
        $from = $tablePositions[$foreignKey['fromTable']] ?? ['x' => 0, 'y' => 0];
        $to = $tablePositions[$foreignKey['toTable']] ?? ['x' => 0, 'y' => 0];
        // O rótulo é lido da esquerda para a direita; a FK continua sendo
        // a origem da seta, mesmo quando sua tabela está à direita.
        $displayCardinality = ! $isRecursive && $from['x'] > $to['x']
            ? implode(':', array_reverse(explode(':', $cardinality)))
            : $cardinality;

        $edge = [
            'id' => $foreignKey['id'],
            'source' => $foreignKey['fromTable'],
            'target' => $foreignKey['toTable'],
            'label' => match (true) {
                $isRecursive && $relationshipName !== '' => $relationshipName.' · '.$displayCardinality,
                $isParallel => $foreignKey['fromColumn'].' · '.$displayCardinality,
                default => $displayCardinality,
            },
            'color' => '#38bdf8',
            'strokeWidth' => 1.6,
            'markerEnd' => 'arrowclosed',
        ];

        // Toda aresta parte da linha FK e termina na linha que ela referencia.
        $fromColumnId = $this->columnIdForForeignKey($tables, $foreignKey, 'from');
        $toColumnId = $this->columnIdForForeignKey($tables, $foreignKey, 'to');

        if ($fromColumnId === null || $toColumnId === null) {
            return array_merge($edge, ['type' => 'floating', 'pathType' => 'smoothstep', 'class' => 'relational-cardinality']);
        }

        if ($isRecursive) {
            return $this->recursiveEdge($edge, $foreignKey, $fromColumnId, $toColumnId, $recursiveSlots);
        }

        return $this->ordinaryEdge($edge, $fromColumnId, $toColumnId, $from, $to, $isParallel, $tableWidth);
    }

    private function recursiveEdge(array $edge, array $foreignKey, string $fromColumnId, string $toColumnId, array &$slots): array
    {
        $slot = $slots[$foreignKey['fromTable']] ?? 0;
        $slots[$foreignKey['fromTable']] = $slot + 1;
        $side = self::RECURSIVE_SIDES[$slot % count(self::RECURSIVE_SIDES)];

        return array_merge($edge, [
            'type' => 'relational-self-loop',
            'pathType' => 'smoothstep',
            'sourceHandle' => 'col-'.$fromColumnId.'-'.$side,
            'targetHandle' => 'col-'.$toColumnId.'-'.$side,
            'class' => 'relational-cardinality relational-self-reference',
        ]);
    }

    private function ordinaryEdge(array $edge, string $fromColumnId, string $toColumnId, array $from, array $to, bool $isParallel, int $tableWidth): array
    {
        $side = $to['x'] >= $from['x'] ? 'right' : 'left';
        $tablesOverlapHorizontally = abs($to['x'] - $from['x']) < $tableWidth;
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
    }

    private function columnIdForForeignKey(array $tables, array $foreignKey, string $end): ?string
    {
        $tableId = $foreignKey[$end.'Table'];
        $stableId = $foreignKey[$end.'ColumnId'] ?? null;
        $columnName = $foreignKey[$end.'Column'];
        $tableIndex = array_search($tableId, array_column($tables, 'id'), true);

        if ($tableIndex === false) {
            return null;
        }

        foreach ($tables[$tableIndex]['columns'] as $column) {
            if (($stableId !== null && $column['id'] === $stableId) || $column['name'] === $columnName) {
                return $column['id'];
            }
        }

        return null;
    }
}
