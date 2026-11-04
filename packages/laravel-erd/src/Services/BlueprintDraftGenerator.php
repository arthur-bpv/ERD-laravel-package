<?php

namespace Makermind\Erd4dev\Services;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

final class BlueprintDraftGenerator
{
    /** @return array{yaml:string,warnings:array} */
    public function generate(array $data): array
    {
        $tables = $data['tables'] ?? [];
        if ($tables === []) {
            throw new InvalidArgumentException('Add a table before generating a draft.');
        }
        // Reuse the board's schema validation; generating SQL here never executes it.
        (new RelationalSqlGenerator)->generate($data);
        $models = [];
        $byId = array_column($tables, null, 'id');
        $warnings = array_values($data['warnings'] ?? []);
        $foreign = [];
        foreach ($data['foreignKeys'] ?? [] as $key) {
            $group = $key['fromTable'].'|'.$key['toTable'].'|'.($key['relationshipName'] ?? $key['id']);
            $foreign[$group][] = $key;
        }
        $references = [];
        foreach ($foreign as $keys) {
            if (count($keys) > 1) {
                $warnings[] = 'Composite FK '.($keys[0]['relationshipName'] ?? '').': columns preserved, constraint omitted; Blueprint cannot represent this FK natively.';

                continue;
            }
            $key = $keys[0];
            $target = $byId[$key['toTable']] ?? null;
            if (! $target) {
                throw new InvalidArgumentException('A relationship references a missing table.');
            }
            if (str_contains($target['name'], '.')) {
                $warnings[] = 'FK to '.$target['name'].': cross-schema constraint omitted; review the migration manually.';

                continue;
            }
            $references[$key['fromTable']][$key['fromColumn']] = $target['name'].'.'.$key['toColumn'];
        }
        foreach ($tables as $table) {
            $this->identifier($table['name'], true);
            $modelName = Str::studly(Str::singular(str_replace('.', '_', $table['name'])));
            if (isset($models[$modelName]) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $modelName)) {
                throw new InvalidArgumentException('Table names produce invalid or duplicate model names. Rename the tables on the board.');
            }
            $model = ['meta' => ['table' => $table['name']], 'timestamps' => false];
            $columns = $table['columns'] ?? [];
            if ($columns === []) {
                throw new InvalidArgumentException('Table '.$table['name'].' has no columns.');
            }
            if (! in_array('id', array_column($columns, 'name'), true)) {
                $model['id'] = false;
            }
            foreach ($columns as $column) {
                $this->identifier($column['name']);
                if (in_array(strtolower($column['name']), ['meta', 'timestamps', 'timestampstz', 'softdeletes', 'softdeletestz', 'relationships', 'indexes'], true)) {
                    throw new InvalidArgumentException('Column '.$column['name'].' uses a reserved Blueprint name. Rename it before generating the draft.');
                }
                $definition = $this->columnType($column);
                if ($column['nullable'] ?? false) {
                    $definition .= ' nullable';
                }
                if ($column['unsigned'] ?? false) {
                    $definition .= ' unsigned';
                }
                if ($column['autoIncrement'] ?? false) {
                    $definition .= ' autoIncrement';
                }
                if (isset($references[$table['id']][$column['name']])) {
                    $definition .= ' foreign:'.$references[$table['id']][$column['name']].' onDelete:no_action onUpdate:no_action';
                }
                $model[$column['name']] = $definition;
            }
            $indexes = [];
            if (! empty($table['primaryKey'])) {
                $automaticPrimary = count($table['primaryKey']) === 1
                    && (collect($columns)->firstWhere('name', $table['primaryKey'][0])['autoIncrement'] ?? false);
                if (! $automaticPrimary) {
                    $indexes[] = ['primary' => implode(', ', $table['primaryKey'])];
                }
                if (count($table['primaryKey']) > 1) {
                    $warnings[] = 'Composite PK in '.$table['name'].': migration represented; Eloquent does not natively support composite primary keys.';
                } elseif ($table['primaryKey'][0] !== 'id') {
                    $warnings[] = 'Set $primaryKey on model '.$modelName.' to '.$table['primaryKey'][0].'.';
                }
            } else {
                $warnings[] = 'Table '.$table['name'].' has no primary key: review the Eloquent model configuration.';
            }
            $unique = $table['uniqueKeys'] ?? [];
            foreach ($columns as $column) {
                if (str_contains($column['key'] ?? '', 'UQ') && ! in_array([$column['name']], $unique, true)) {
                    $unique[] = [$column['name']];
                }
            }
            foreach ($unique as $key) {
                $indexes[] = ['unique' => implode(', ', $key)];
            }
            if ($indexes !== []) {
                $model['indexes'] = $indexes;
            }
            $models[$modelName] = $model;
        }
        $warnings = array_values(array_unique($warnings));
        $comments = "# Generated by ERD4Dev. Review before running blueprint:build.\n# This draft does not change the database.\n";
        foreach ($warnings as $warning) {
            $comments .= '# WARNING: '.preg_replace('/[\r\n]+/', ' ', $warning)."\n";
        }

        return ['yaml' => $comments.Yaml::dump(['models' => $models], 8, 2), 'warnings' => $warnings];
    }

    private function identifier(string $name, bool $qualified = false): void
    {
        $pattern = $qualified ? '/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/D' : '/^[A-Za-z_][A-Za-z0-9_]*$/D';
        if (! preg_match($pattern, $name)) {
            throw new InvalidArgumentException('Blueprint requires simple identifiers. Rename '.$name.' on the board before generating the draft.');
        }
    }

    private function columnType(array $column): string
    {
        $type = $column['type'] ?? 'varchar';
        if ($type === 'enum') {
            $values = $column['enumValues'] ?? [];
            if ($values === []) {
                throw new InvalidArgumentException('The enum has no values. Add values before generating the draft.');
            }
            foreach ($values as $value) {
                if (preg_match('/[,:\x22\x27\x5c\r\n]/u', $value)) {
                    throw new InvalidArgumentException('This enum contains characters unsupported by the Blueprint format. Simplify its values on the board.');
                }
            }

            return 'enum:'.implode(',', array_map(fn ($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $values));
        }
        $mapped = match ($type) {
            'varchar', 'nvarchar' => 'string', 'nchar' => 'char',
            'tinyint' => 'tinyInteger', 'smallint' => 'smallInteger', 'bigint' => 'bigInteger',
            'numeric' => 'decimal', 'datetime' => 'dateTime',
            'mediumtext' => 'mediumText', 'longtext' => 'longText',
            'blob', 'varbinary' => 'binary',
            'integer', 'decimal', 'float', 'double', 'boolean', 'char', 'text', 'date', 'time', 'timestamp', 'binary', 'uuid', 'json' => $type,
            default => throw new InvalidArgumentException('Tipo '.$type.' is unsupported in the draft. Choose a compatible type on the board.'),
        };
        if (in_array($mapped, ['string', 'char'], true)) {
            $mapped .= ':'.max(1, (int) ($column['length'] ?? 255));
        } elseif ($mapped === 'decimal') {
            $mapped .= ':'.max(1, (int) ($column['precision'] ?? 10)).','.(int) ($column['scale'] ?? 2);
        }

        return $mapped;
    }
}
