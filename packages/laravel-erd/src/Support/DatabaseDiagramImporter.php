<?php

namespace Makermind\Erd4dev\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/** Reads schema metadata only. Never reads application rows or executes imported DDL. */
final class DatabaseDiagramImporter
{
    public function connections(): array
    {
        $connections = [];
        foreach (config('database.connections', []) as $name => $settings) {
            if (in_array($settings['driver'] ?? '', ['sqlite', 'mysql', 'mariadb', 'pgsql', 'sqlsrv'], true)) {
                $connections[$name] = $settings['driver'];
            }
        }

        return $connections;
    }

    public function import(string $connection): array
    {
        if (! array_key_exists($connection, $this->connections())) {
            throw new InvalidArgumentException('Select a connection configured in Laravel.');
        }
        $database = $this->read(fn () => DB::connection($connection));
        $dialect = $database->getDriverName() === 'mariadb' ? 'mysql' : $database->getDriverName();
        $schema = $this->read(fn () => $database->getSchemaBuilder());
        $tables = $this->read(fn () => $schema->getTables());
        if (count($tables) > 250) {
            throw new InvalidArgumentException('Limit: 250 tables per import.');
        }
        $names = [];
        foreach ($tables as $table) {
            $namespace = $table['schema'] ?? null;
            $qualified = $table['schema_qualified_name'] ?? $table['name'];
            $names[$qualified] = in_array($namespace, [null, 'main', 'public', 'dbo'], true) || $dialect === 'mysql'
                ? $table['name'] : $qualified;
        }
        $quote = static fn (string $name): string => '"'.str_replace('"', '""', $name).'"';
        $sql = [];
        $metadata = [];
        foreach ($tables as $table) {
            $qualified = $table['schema_qualified_name'] ?? $table['name'];
            $name = $names[$qualified];
            $definitions = [];
            foreach ($this->read(fn () => $schema->getColumns($qualified)) as $column) {
                $definitions[] = $quote($column['name']).' '.($column['type'] ?: 'text').($column['nullable'] ? ' NULL' : ' NOT NULL');
                $metadata[$name][$column['name']] = $column;
            }
            foreach ($this->read(fn () => $schema->getIndexes($qualified)) as $index) {
                if (! ($index['primary'] || $index['unique'])) {
                    continue;
                }
                if (empty($index['columns']) || in_array(null, $index['columns'], true) || in_array('', $index['columns'], true)) {
                    throw new InvalidArgumentException('Expression indexes are not supported by the editor.');
                }
                $definitions[] = ($index['primary'] ? 'PRIMARY KEY' : 'UNIQUE').' ('.implode(', ', array_map($quote, $index['columns'])).')';
            }
            foreach ($this->read(fn () => $schema->getForeignKeys($qualified)) as $key) {
                $target = ($key['foreign_schema'] ?? $table['schema'] ?? null);
                $target = $target ? $target.'.'.$key['foreign_table'] : $key['foreign_table'];
                $target = $names[$target] ?? $names[$key['foreign_table']] ?? $key['foreign_table'];
                $definitions[] = 'FOREIGN KEY ('.implode(', ', array_map($quote, $key['columns'])).') REFERENCES '.$quote($target)
                    .' ('.implode(', ', array_map($quote, $key['foreign_columns'])).')';
            }
            $sql[] = 'CREATE TABLE '.$quote($name).' ('.implode(', ', $definitions).');';
        }
        if ($sql === []) {
            throw new InvalidArgumentException('No tables were found on this connection.');
        }
        $data = (new SqlDiagramImporter)->parse(implode("\n", $sql), $dialect)['relational'];
        foreach ($data['tables'] as &$table) {
            foreach ($table['columns'] as &$column) {
                $native = $metadata[$table['name']][$column['name']];
                $column['autoIncrement'] = (bool) ($native['auto_increment'] ?? false);
                $column['unsigned'] = str_contains(strtolower($native['type']), 'unsigned');
            }
            unset($column);
        }
        unset($table);
        $data['origin'] = ['type' => 'database', 'connection' => $connection, 'importedAt' => now()->toIso8601String()];
        $data['warnings'][] = 'Schema snapshot: data, defaults, checks, views, triggers, ordinary indexes and ON DELETE/UPDATE actions are not represented. Editing the board does not change the source database.';

        return $data;
    }

    private function read(Closure $query): mixed
    {
        try {
            return $query();
        } catch (\Throwable $exception) {
            throw new RuntimeException('Failed to read connection metadata.', 0, $exception);
        }
    }
}
