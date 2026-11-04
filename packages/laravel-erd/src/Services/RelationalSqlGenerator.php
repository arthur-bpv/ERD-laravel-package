<?php

namespace Makermind\Erd4dev\Services;

use InvalidArgumentException;
use Makermind\Erd4dev\Support\DataTypeCatalog;

final class RelationalSqlGenerator
{
    public function generate(array $data): string
    {
        $dialect = $data['dialect'] ?? 'mysql';
        if (! DataTypeCatalog::has($dialect)) {
            throw new InvalidArgumentException('Invalid target database.');
        }

        $tables = array_values($data['tables'] ?? []);
        if ($tables === []) {
            throw new InvalidArgumentException('Add a table to the relational model before generating SQL.');
        }

        $byId = $this->validateTables($tables);
        $statements = array_map(fn (array $table): string => $this->tableStatement($table, $dialect), $tables);
        array_push($statements, ...$this->foreignKeyStatements($data['foreignKeys'] ?? [], $byId, $dialect));

        $projectName = trim((string) ($data['projectName'] ?? ''));
        $comment = '-- SQL code for: '.DataTypeCatalog::DIALECTS[$dialect]['label']."\n";
        if ($projectName !== '') {
            $comment .= '-- Project: '.preg_replace('/[\r\n]+/u', ' ', $projectName)."\n";
        }

        return $comment."\n".implode("\n\n", $statements)."\n";
    }

    private function validateTables(array $tables): array
    {
        $byId = [];
        $names = [];
        foreach ($tables as $table) {
            $id = (string) ($table['id'] ?? '');
            $name = $this->name($table['name'] ?? null, 'table');
            if ($id === '' || isset($byId[$id])) {
                throw new InvalidArgumentException('The model contains missing or duplicate table identifiers.');
            }
            $nameKey = mb_strtolower($name);
            if (isset($names[$nameKey])) {
                throw new InvalidArgumentException("More than one table is named {$name}.");
            }
            $names[$nameKey] = true;
            $byId[$id] = $table;

            $columnNames = [];
            foreach ($table['columns'] ?? [] as $column) {
                $columnName = $this->name($column['name'] ?? null, 'column');
                $columnKey = mb_strtolower($columnName);
                if (isset($columnNames[$columnKey])) {
                    throw new InvalidArgumentException("Table {$name} contains duplicate columns: {$columnName}.");
                }
                $columnNames[$columnKey] = true;
            }
        }

        return $byId;
    }

    private function tableStatement(array $table, string $dialect): string
    {
        $columns = $table['columns'] ?? [];
        if ($columns === []) {
            throw new InvalidArgumentException("Table {$table['name']} has no columns.");
        }

        $definitions = [];
        $columnNames = array_column($columns, 'name');
        $primary = array_values($table['primaryKey'] ?? []);
        foreach ($primary as $name) {
            if (! in_array($name, $columnNames, true)) {
                throw new InvalidArgumentException("The primary key of {$table['name']} references missing column {$name}.");
            }
        }

        foreach ($columns as $column) {
            $name = $column['name'];
            $isPrimary = in_array($name, $primary, true);
            $sql = '    '.$this->quote($dialect, $name).' '.$this->columnType($dialect, $column);
            $sql .= $isPrimary || ! ($column['nullable'] ?? false) ? ' NOT NULL' : ' NULL';
            if (($column['type'] ?? '') === 'enum' && $dialect !== 'mysql') {
                $sql .= ' CHECK ('.$this->quote($dialect, $name).' IN ('.$this->enumValuesSql($column).'))';
            }
            $definitions[] = $sql;

            if (str_contains((string) ($column['key'] ?? ''), 'UQ') && ! $isPrimary) {
                $definitions[] = '    UNIQUE ('.$this->quote($dialect, $name).')';
            }
        }

        foreach ($table['uniqueKeys'] ?? [] as $key) {
            if (count($key) < 2) {
                continue; // Single-column keys are represented by the UQ flag.
            }
            if (count(array_unique($key)) !== count($key) || array_diff($key, $columnNames) !== []) {
                throw new InvalidArgumentException("Invalid UNIQUE key in table {$table['name']}.");
            }
            $definitions[] = '    UNIQUE ('.implode(', ', array_map(
                fn (string $name): string => $this->quote($dialect, $name), $key,
            )).')';
        }

        if ($primary !== []) {
            $definitions[] = '    PRIMARY KEY ('.implode(', ', array_map(
                fn (string $name): string => $this->quote($dialect, $name),
                $primary,
            )).')';
        }

        return 'CREATE TABLE '.$this->quote($dialect, $table['name'])." (\n"
            .implode(",\n", $definitions)."\n);";
    }

    private function foreignKeyStatements(array $foreignKeys, array $byId, string $dialect): array
    {
        $statements = [];
        foreach ($this->foreignKeyGroups($foreignKeys, $byId) as $group) {
            $from = $byId[$group['fromTable']];
            $to = $byId[$group['toTable']];
            $fromColumns = [];
            $toColumns = [];
            foreach ($group['keys'] as $key) {
                $fromName = $this->name($key['fromColumn'] ?? null, 'FK source column');
                $toName = $this->name($key['toColumn'] ?? null, 'FK target column');
                if (! in_array($fromName, array_column($from['columns'] ?? [], 'name'), true)
                    || ! in_array($toName, array_column($to['columns'] ?? [], 'name'), true)) {
                    throw new InvalidArgumentException("The FK between {$from['name']} and {$to['name']} references a missing column.");
                }
                $fromColumns[] = $this->quote($dialect, $fromName);
                $toColumns[] = $this->quote($dialect, $toName);
            }

            $statements[] = 'ALTER TABLE '.$this->quote($dialect, $from['name'])
                .' ADD FOREIGN KEY ('.implode(', ', $fromColumns).')'
                .' REFERENCES '.$this->quote($dialect, $to['name'])
                .' ('.implode(', ', $toColumns).');';
        }

        return $statements;
    }

    private function columnType(string $dialect, array $column): string
    {
        $raw = strtolower(trim((string) ($column['type'] ?? 'varchar')));
        if (! preg_match('/^([a-z]+)(?:\s*\(\s*(\d+)\s*(?:,\s*(\d+)\s*)?\))?$/', $raw, $matches)) {
            throw new InvalidArgumentException("Invalid column type: {$raw}.");
        }

        $type = match ($matches[1]) {
            'int' => 'integer',
            'bool' => 'boolean',
            'string' => 'varchar',
            default => $matches[1],
        };

        if ($type === 'custom') {
            return $this->customColumnType($column);
        }
        if ($type === 'enum') {
            return $dialect === 'mysql'
                ? 'ENUM('.$this->enumValuesSql($column).')'
                : DataTypeCatalog::DIALECTS[$dialect]['types']['varchar'].'(255)';
        }

        $known = false;
        foreach (DataTypeCatalog::DIALECTS as $config) {
            if (isset($config['types'][$type])) {
                $known = true;
                break;
            }
        }
        if (! $known) {
            throw new InvalidArgumentException("Type {$raw} of column {$column['name']} is not recognized.");
        }

        $kind = DataTypeCatalog::kind($type);
        if (isset($matches[2])) {
            if ($kind === 'length' && ! isset($matches[3])) {
                $column['length'] ??= (int) $matches[2];
            } elseif ($kind === 'decimal' && isset($matches[3])) {
                $column['precision'] ??= (int) $matches[2];
                $column['scale'] ??= (int) $matches[3];
            } else {
                throw new InvalidArgumentException("The size of type {$raw} is invalid.");
            }
        }

        $column['type'] = DataTypeCatalog::resolve($dialect, $type);
        $column = DataTypeCatalog::normalize($dialect, $column);
        $type = $column['type'];
        $native = DataTypeCatalog::DIALECTS[$dialect]['types'][$type];

        return match (DataTypeCatalog::kind($type)) {
            'length' => $native.'('.$column['length'].')',
            'decimal' => $native.'('.$column['precision'].', '.$column['scale'].')',
            default => $native,
        };
    }

    private function customColumnType(array $column): string
    {
        $customType = trim((string) ($column['customType'] ?? ''));
        if ($customType === '') {
            throw new InvalidArgumentException("Enter the custom type for column {$column['name']}.");
        }

        return $customType;
    }

    /** Valores livres do enum, escapados como literais SQL. */
    private function enumValuesSql(array $column): string
    {
        $values = array_values(array_unique(array_map(
            static fn (mixed $value): string => (string) $value,
            is_array($column['enumValues'] ?? null) ? $column['enumValues'] : [],
        )));
        if ($values === []) {
            throw new InvalidArgumentException("Enter at least one enum value for column {$column['name']}.");
        }

        return implode(', ', array_map(
            static fn (string $value): string => "'".str_replace("'", "''", $value)."'",
            $values,
        ));
    }

    /**
     * Composite PK references are stored as one board edge per column. Join
     * the complete set into one SQL constraint; reject an incomplete set.
     */
    private function foreignKeyGroups(array $foreignKeys, array $tables): array
    {
        $groups = [];
        $compositePairs = [];

        $namedGroups = [];
        foreach ($foreignKeys as $key) {
            if (! empty($key['relationshipName'])) {
                $namedGroups[$key['fromTable'].'|'.$key['toTable'].'|'.$key['relationshipName']][] = $key;
            }
        }
        foreach ($namedGroups as $keys) {
            $first = $keys[0];
            $target = $tables[$first['toTable']] ?? [];
            $names = array_column($keys, 'toColumn');
            sort($names);
            $valid = false;
            foreach ([$target['primaryKey'] ?? [], ...($target['uniqueKeys'] ?? [])] as $candidate) {
                sort($candidate);
                $valid = $valid || ($candidate !== [] && $candidate === $names);
            }
            if ($valid) {
                $groups[] = ['fromTable' => $first['fromTable'], 'toTable' => $first['toTable'], 'keys' => $keys];
            }
        }

        foreach ($foreignKeys as $key) {
            $handled = array_filter($groups, fn (array $group): bool => in_array($key, $group['keys'], true));
            if ($handled !== []) {
                continue;
            }
            $fromId = (string) ($key['fromTable'] ?? '');
            $toId = (string) ($key['toTable'] ?? '');
            if (! isset($tables[$fromId], $tables[$toId])) {
                throw new InvalidArgumentException('An FK references a missing table.');
            }

            $targetPrimary = $tables[$toId]['primaryKey'] ?? [];
            $toColumn = $key['toColumn'] ?? null;
            if (count($targetPrimary) > 1 && in_array($toColumn, $targetPrimary, true)) {
                $compositePairs[$fromId.'|'.$toId][] = $key;

                continue;
            }

            $target = collect($tables[$toId]['columns'] ?? [])->firstWhere('name', $toColumn);
            if ($target === null || (! in_array($toColumn, $targetPrimary, true)
                && ! str_contains((string) ($target['key'] ?? ''), 'UQ'))) {
                throw new InvalidArgumentException("Column {$toColumn} must be PK or UNIQUE to be referenced by an FK.");
            }
            $groups[] = ['fromTable' => $fromId, 'toTable' => $toId, 'keys' => [$key]];
        }

        foreach ($compositePairs as $pair => $keys) {
            [$fromId, $toId] = explode('|', $pair, 2);
            $primary = $tables[$toId]['primaryKey'];
            $ordered = [];
            foreach ($primary as $name) {
                $matching = array_values(array_filter($keys, fn (array $key): bool => ($key['toColumn'] ?? null) === $name));
                if (count($matching) !== 1) {
                    throw new InvalidArgumentException("The FK to {$tables[$toId]['name']} must reference the entire composite primary key exactly once.");
                }
                $ordered[] = $matching[0];
            }
            if (count($ordered) !== count($keys)) {
                throw new InvalidArgumentException("The FK to {$tables[$toId]['name']} contains extra columns.");
            }
            $groups[] = ['fromTable' => $fromId, 'toTable' => $toId, 'keys' => $ordered];
        }

        return $groups;
    }

    private function name(mixed $value, string $kind): string
    {
        $name = is_string($value) ? trim($value) : '';
        if ($name === '') {
            throw new InvalidArgumentException("The name of the {$kind} cannot be empty.");
        }

        return $name;
    }

    private function quote(string $dialect, string $name): string
    {
        return match ($dialect) {
            'mysql' => '`'.str_replace('`', '``', $name).'`',
            'sqlsrv' => '['.str_replace(']', ']]', $name).']',
            default => '"'.str_replace('"', '""', $name).'"',
        };
    }
}
