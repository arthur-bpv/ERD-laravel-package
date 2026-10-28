<?php

namespace App\Services;

use App\Support\DataTypeCatalog;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Edits standalone relational schemas while retaining stable canvas identifiers. */
final class RelationalStructureEditor
{
    public function __construct(public array $tables, public array $foreignKeys) {}

    private function name(string $name, array $siblings, ?string $except = null): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80 || preg_match('/[\x00-\x1f]/u', $name)) {
            throw new InvalidArgumentException('Informe um nome de 1 a 80 caracteres, sem quebras de linha.');
        }
        foreach ($siblings as $item) {
            if ($item['id'] !== $except && mb_strtolower($item['name']) === mb_strtolower($name)) {
                throw new InvalidArgumentException('Já existe um item com esse nome.');
            }
        }

        return $name;
    }

    private function table(string $id): int
    {
        $index = array_search($id, array_column($this->tables, 'id'), true);
        if ($index === false) {
            throw new InvalidArgumentException('Selecione uma tabela existente.');
        }

        return $index;
    }

    public function addTable(string $name, string $dialect): void
    {
        if (count($this->tables) >= 250) {
            throw new InvalidArgumentException('Limite: 250 tabelas.');
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
            throw new InvalidArgumentException('Tipo ou chave de coluna inválido.');
        }
        // Extending a referenced primary key would invalidate existing foreign keys.
        if ($key === 'PK' && $this->tables[$index]['primaryKey'] !== [] && collect($this->foreignKeys)->contains('toTable', $tableId)) {
            throw new InvalidArgumentException('Remova os relacionamentos que referenciam esta tabela antes de ampliar sua chave primária.');
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
            throw new InvalidArgumentException('Selecione uma coluna existente.');
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
                throw new InvalidArgumentException('Selecione colunas distintas para o relacionamento.');
            }

            return array_map(function ($id) use ($table): array {
                $column = collect($table['columns'])->firstWhere('id', $id);
                if (! $column) {
                    throw new InvalidArgumentException('Coluna do relacionamento inexistente.');
                }

                return $column;
            }, $ids);
        };
        $sources = $columns($from, $fromIds);
        $targets = $columns($to, $toIds);
        if (count($sources) !== count($targets) || ! $this->isUnique($to, array_column($targets, 'name'))) {
            throw new InvalidArgumentException('Selecione a mesma quantidade de colunas e uma chave PRIMARY ou UNIQUE completa no destino.');
        }
        foreach ($sources as $column) {
            if (collect($this->foreignKeys)->contains(fn ($key) => $key['fromTable'] === $fromId && $key['fromColumn'] === $column['name'])) {
                throw new InvalidArgumentException('Uma coluna de origem já participa de outro relacionamento.');
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
            throw new InvalidArgumentException('Selecione um relacionamento existente.');
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
