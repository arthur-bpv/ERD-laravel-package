<?php

namespace App\Services;

use App\Support\DataTypeCatalog;
use InvalidArgumentException;

/** Applies column edits to a copy of the relational tables. */
final class RelationalColumnEditor
{
    public function type(array $tables, string $dialect, string $tableId, string $columnId, string $type): ?array
    {
        $position = $this->position($tables, $tableId, $columnId);
        if ($position === null || ! in_array($type, DataTypeCatalog::canonicalFor($dialect), true)) {
            return null;
        }

        [$table, $index] = $position;
        $column = $tables[$table]['columns'][$index];
        $column['type'] = $type;
        $tables[$table]['columns'][$index] = DataTypeCatalog::normalize($dialect, $column);

        return $tables;
    }

    public function size(array $tables, string $dialect, string $tableId, string $columnId, int|string|null $size, int|string|null $scale): ?array
    {
        $position = $this->position($tables, $tableId, $columnId);
        if ($position === null || ! is_numeric($size)) {
            return null;
        }

        [$table, $index] = $position;
        $column = $tables[$table]['columns'][$index];
        match ($kind = DataTypeCatalog::kind($column['type'] ?? '')) {
            'length' => $column['length'] = (int) $size,
            'decimal' => [
                $column['precision'] = (int) $size,
                $column['scale'] = is_numeric($scale) ? (int) $scale : (int) ($column['scale'] ?? 0),
            ],
            default => null,
        };
        if ($kind === null) {
            return null;
        }

        $tables[$table]['columns'][$index] = DataTypeCatalog::normalize($dialect, $column);

        return $tables;
    }

    public function dialect(array $tables, string $dialect): array
    {
        foreach ($tables as $table => $entry) {
            foreach ($entry['columns'] as $index => $column) {
                $column['type'] = DataTypeCatalog::resolve($dialect, $column['type'] ?? 'varchar');
                $tables[$table]['columns'][$index] = DataTypeCatalog::normalize($dialect, $column);
            }
        }

        return $tables;
    }

    public function toggleNullable(array $tables, string $tableId, string $columnId): ?array
    {
        $position = $this->position($tables, $tableId, $columnId);
        if ($position === null) {
            return null;
        }

        [$table, $index] = $position;
        if (str_contains($tables[$table]['columns'][$index]['key'], 'PK')) {
            return null;
        }

        $tables[$table]['columns'][$index]['nullable'] = ! $tables[$table]['columns'][$index]['nullable'];

        return $tables;
    }

    public function customType(array $tables, string $dialect, string $tableId, string $columnId, string $value): ?array
    {
        return $this->specialColumn($tables, $dialect, $tableId, $columnId, 'custom', 'customType', trim($value));
    }

    public function enumValues(array $tables, string $dialect, string $tableId, string $columnId, string $value): ?array
    {
        return $this->specialColumn($tables, $dialect, $tableId, $columnId, 'enum', 'enumValues', preg_split('/[,\r\n]+/', $value) ?: []);
    }

    private function specialColumn(array $tables, string $dialect, string $tableId, string $columnId, string $type, string $field, mixed $value): ?array
    {
        $position = $this->position($tables, $tableId, $columnId);
        if ($position === null) {
            return null;
        }

        [$table, $index] = $position;
        $column = $tables[$table]['columns'][$index];
        if (($column['type'] ?? '') !== $type) {
            return null;
        }

        if ($field === 'customType' && $value === '') {
            throw new InvalidArgumentException('Informe um tipo personalizado.');
        }

        $column[$field] = $value;
        $tables[$table]['columns'][$index] = DataTypeCatalog::normalize($dialect, $column);

        return $tables;
    }

    private function position(array $tables, string $tableId, string $columnId): ?array
    {
        $table = array_search($tableId, array_column($tables, 'id'), true);
        if ($table === false) {
            return null;
        }

        $column = array_search($columnId, array_column($tables[$table]['columns'], 'id'), true);

        return $column === false ? null : [$table, $column];
    }
}
