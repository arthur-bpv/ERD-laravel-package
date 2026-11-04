<?php

namespace Makermind\Erd4dev\Services;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Makermind\Erd4dev\Support\DataTypeCatalog;

/** Edits standalone relational schemas while retaining stable canvas identifiers. */
final class RelationalStructureEditor
{
    public function __construct(public array $tables, public array $foreignKeys) {}

    private function name(string $name, array $siblings, ?string $except = null): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80 || preg_match('/[\x00-\x1f]/u', $name)) {
            throw new InvalidArgumentException('Enter a name between 1 and 80 characters without line breaks.');
        }
        foreach ($siblings as $item) {
            if ($item['id'] !== $except && mb_strtolower($item['name']) === mb_strtolower($name)) {
                throw new InvalidArgumentException('An item with this name already exists.');
            }
        }

        return $name;
    }

    private function table(string $id): int
    {
        $index = array_search($id, array_column($this->tables, 'id'), true);
        if ($index === false) {
            throw new InvalidArgumentException('Select an existing table.');
        }

        return $index;
    }

    public function addTable(string $name, string $dialect): void
    {
        if (count($this->tables) >= 250) {
            throw new InvalidArgumentException('Limit: 250 tables.');
        }
        $name = $this->name($name, $this->tables);
        $id = 't'.Str::uuid();
        $this->tables[] = [
            'id' => $id, 'name' => $name, 'kind' => 'strong',
            'x' => 60 + count($this->tables) % 3 * 460, 'y' => 60 + intdiv(count($this->tables), 3) * 420,
            'columns' => [], 'primaryKey' => [], 'uniqueKeys' => [],
        ];
        $this->addColumn($id, 'id', 'integer', 'PK', $dialect);
    }

    public function renameTable(string $id, string $name): void
    {
        $index = $this->table($id);
        $this->tables[$index]['name'] = $this->name($name, $this->tables, $id);
    }

    public function addColumn(string $tableId, string $name, string $type, string $key, string $dialect): void
    {
        $index = $this->table($tableId);
        $name = $this->name($name, $this->tables[$index]['columns']);
        if (! in_array($type, DataTypeCatalog::canonicalFor($dialect), true) || ! in_array($key, ['', 'PK', 'UQ'], true)) {
            throw new InvalidArgumentException('Invalid column type or key.');
        }
        // Extending a referenced primary key would invalidate existing foreign keys.
        if ($key === 'PK' && $this->tables[$index]['primaryKey'] !== [] && collect($this->foreignKeys)->contains('toTable', $tableId)) {
            throw new InvalidArgumentException('Remove relationships referencing this table before extending its primary key.');
        }
        $this->tables[$index]['columns'][] = DataTypeCatalog::normalize($dialect, [
            'id' => 'c'.Str::uuid(), 'name' => $name, 'type' => $type, 'key' => $key,
            'nullable' => $key !== 'PK', 'references' => null,
        ]);
        if ($key === 'PK') {
            $this->tables[$index]['primaryKey'][] = $name;
        } elseif ($key === 'UQ') {
            $this->tables[$index]['uniqueKeys'][] = [$name];
        }
    }

    public function renameColumn(string $tableId, string $columnId, string $name): void
    {
        $index = $this->table($tableId);
        $columnIndex = array_search($columnId, array_column($this->tables[$index]['columns'], 'id'), true);
        if ($columnIndex === false) {
            throw new InvalidArgumentException('Select an existing column.');
        }
        $name = $this->name($name, $this->tables[$index]['columns'], $columnId);
        $old = $this->tables[$index]['columns'][$columnIndex]['name'];
        $this->tables[$index]['columns'][$columnIndex]['name'] = $name;
        $replace = fn (array $names): array => array_map(fn ($value) => $value === $old ? $name : $value, $names);
        $this->tables[$index]['primaryKey'] = $replace($this->tables[$index]['primaryKey']);
        $this->tables[$index]['uniqueKeys'] = array_map($replace, $this->tables[$index]['uniqueKeys'] ?? []);
        foreach ($this->foreignKeys as &$key) {
            foreach (['from', 'to'] as $end) {
                if ($key[$end.'Table'] === $tableId && $key[$end.'Column'] === $old) {
                    $key[$end.'Column'] = $name;
                }
            }
        }
        unset($key);
        foreach ($this->tables as &$table) {
            foreach ($table['columns'] as &$column) {
                if (($column['references']['table'] ?? null) === $tableId && $column['references']['column'] === $old) {
                    $column['references']['column'] = $name;
                }
            }
            unset($column);
        }
    }

    public function addRelationship(string $name, string $fromId, array $fromIds, string $toId, array $toIds): void
    {
        $from = $this->tables[$this->table($fromId)];
        $to = $this->tables[$this->table($toId)];
        $name = $this->relationshipName($name, $fromId, $toId);
        $columns = function (array $table, array $ids): array {
            if ($ids === [] || count(array_unique($ids)) !== count($ids)) {
                throw new InvalidArgumentException('Select distinct columns for the relationship.');
            }

            return array_map(function ($id) use ($table): array {
                $column = collect($table['columns'])->firstWhere('id', $id);
                if (! $column) {
                    throw new InvalidArgumentException('Relationship column does not exist.');
                }

                return $column;
            }, $ids);
        };
        $sources = $columns($from, $fromIds);
        $targets = $columns($to, $toIds);
        if (count($sources) !== count($targets) || ! $this->isUnique($to, array_column($targets, 'name'))) {
            throw new InvalidArgumentException('Select the same number of columns and a complete PRIMARY or UNIQUE target key.');
        }
        foreach ($sources as $column) {
            if (collect($this->foreignKeys)->contains(fn ($key) => $key['fromTable'] === $fromId && $key['fromColumn'] === $column['name'])) {
                throw new InvalidArgumentException('A source column already belongs to another relationship.');
            }
        }
        $unique = $this->isUnique($from, array_column($sources, 'name'));
        $metadata = [
            'relationshipName' => $name, 'cardinality' => $unique ? '1:1' : 'N:1',
            'sourceCard' => $unique ? 'cf-zero-one' : 'cf-zero-many',
            'targetCard' => collect($sources)->contains('nullable', true) ? 'cf-zero-one' : 'cf-one-one',
        ];
        foreach ($sources as $i => $column) {
            $target = $targets[$i];
            $this->foreignKeys[] = $metadata + [
                'id' => 'fk_'.Str::uuid(), 'fromTable' => $fromId, 'fromColumnId' => $column['id'], 'fromColumn' => $column['name'],
                'toTable' => $toId, 'toColumnId' => $target['id'], 'toColumn' => $target['name'],
            ];
            $tableIndex = $this->table($fromId);
            $columnIndex = array_search($column['id'], array_column($this->tables[$tableIndex]['columns'], 'id'), true);
            $this->tables[$tableIndex]['columns'][$columnIndex]['key'] = $column['key'] ? $column['key'].'/FK' : 'FK';
            $this->tables[$tableIndex]['columns'][$columnIndex]['references'] = $metadata + [
                'table' => $toId, 'column' => $target['name'], 'columnId' => $target['id'],
            ];
        }
    }

    public function setColumnKey(string $tableId, string $columnId, string $key): void
    {
        if (! in_array($key, ['', 'PK', 'UQ'], true)) {
            throw new InvalidArgumentException('Select a valid key.');
        }
        $index = $this->table($tableId);
        $columnIndex = array_search($columnId, array_column($this->tables[$index]['columns'], 'id'), true);
        if ($columnIndex === false) {
            throw new InvalidArgumentException('Column does not exist.');
        }
        $name = $this->tables[$index]['columns'][$columnIndex]['name'];
        $old = $this->tables;
        $table = &$this->tables[$index];
        $table['primaryKey'] = array_values(array_diff($table['primaryKey'], [$name]));
        $table['uniqueKeys'] = array_values(array_filter($table['uniqueKeys'] ?? [], fn ($columns) => $columns !== [$name]));
        if ($key === 'PK') {
            $table['primaryKey'][] = $name;
            $table['columns'][$columnIndex]['nullable'] = false;
        } elseif ($key === 'UQ') {
            $table['uniqueKeys'][] = [$name];
        }
        $isForeign = str_contains($table['columns'][$columnIndex]['key'], 'FK');
        $table['columns'][$columnIndex]['key'] = implode('/', array_filter([$key, $isForeign ? 'FK' : '']));
        if ($key !== 'PK') {
            $table['columns'][$columnIndex]['autoIncrement'] = false;
        }
        unset($table);
        $groups = [];
        foreach ($this->foreignKeys as $fk) {
            if ($fk['toTable'] === $tableId) {
                $groups[$fk['fromTable'].'|'.($fk['relationshipName'] ?? $fk['id'])][] = $fk['toColumn'];
            }
        }
        foreach ($groups as $columns) {
            if (! $this->isUnique($this->tables[$index], $columns)) {
                $this->tables = $old;
                throw new InvalidArgumentException('This change would invalidate an FK. Update the relationship before changing the referenced key.');
            }
        }
        $this->refreshCardinalities();
    }

    public function refreshCardinalities(): void
    {
        $groups = [];
        foreach ($this->foreignKeys as $key) {
            $groups[$key['fromTable'].'|'.$key['toTable'].'|'.($key['relationshipName'] ?? $key['id'])][] = $key;
        }
        foreach ($groups as $members) {
            $first = $members[0];
            $tableIndex = $this->table($first['fromTable']);
            $table = $this->tables[$tableIndex];
            $unique = $this->isUnique($table, array_column($members, 'fromColumn'));
            $names = array_column($members, 'fromColumn');
            $nullable = collect($table['columns'])->contains(fn ($column) => in_array($column['name'], $names, true) && ($column['nullable'] ?? false));
            $metadata = ['cardinality' => $unique ? '1:1' : 'N:1', 'sourceCard' => $unique ? 'cf-zero-one' : 'cf-zero-many', 'targetCard' => $nullable ? 'cf-zero-one' : 'cf-one-one'];
            foreach ($this->foreignKeys as &$key) {
                if (in_array($key['id'], array_column($members, 'id'), true)) {
                    $key = array_replace($key, $metadata);
                }
            }
            unset($key);
            foreach ($this->tables[$tableIndex]['columns'] as &$column) {
                if (in_array($column['name'], $names, true) && is_array($column['references'] ?? null)) {
                    $column['references'] = array_replace($column['references'], $metadata);
                }
            }
            unset($column);
        }
    }

    private function isUnique(array $table, array $names): bool
    {
        $keys = [$table['primaryKey'], ...($table['uniqueKeys'] ?? [])];
        foreach ($table['columns'] as $column) {
            if (str_contains($column['key'], 'UQ')) {
                $keys[] = [$column['name']];
            }
        }
        foreach ($keys as $key) {
            if (count($key) === count($names) && array_diff($key, $names) === []) {
                return true;
            }
        }

        return false;
    }

    private function relationshipName(string $name, string $fromId, string $toId, ?string $old = null): string
    {
        $siblings = [];
        foreach ($this->foreignKeys as $key) {
            if ($key['fromTable'] === $fromId && $key['toTable'] === $toId) {
                $siblings[] = ['id' => $key['relationshipName'] ?? $key['id'], 'name' => $key['relationshipName'] ?? ''];
            }
        }

        return $this->name($name, $siblings, $old);
    }

    public function renameRelationship(string $id, string $name): void
    {
        $selected = collect($this->foreignKeys)->firstWhere('id', $id);
        if (! $selected) {
            throw new InvalidArgumentException('Select an existing relationship.');
        }
        $old = $selected['relationshipName'] ?? '';
        $name = $this->relationshipName($name, $selected['fromTable'], $selected['toTable'], $old ?: null);
        foreach ($this->foreignKeys as &$key) {
            if ($key['id'] === $id || ($old !== '' && $key['fromTable'] === $selected['fromTable'] && $key['toTable'] === $selected['toTable'] && ($key['relationshipName'] ?? '') === $old)) {
                $key['relationshipName'] = $name;
                $index = $this->table($key['fromTable']);
                foreach ($this->tables[$index]['columns'] as &$column) {
                    if ($column['name'] === $key['fromColumn']) {
                        $column['references']['relationshipName'] = $name;
                    }
                }
                unset($column);
            }
        }
    }
}
