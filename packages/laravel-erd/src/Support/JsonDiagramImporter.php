<?php

namespace Makermind\Erd4dev\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use JsonException;

final class JsonDiagramImporter
{
    public static function parse(string $json): array
    {
        if (strlen($json) > 2_000_000) {
            throw new InvalidArgumentException('The file exceeds the 2 MB limit.');
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Invalid JSON: '.$exception->getMessage());
        }
        if (! is_array($data) || ! array_key_exists('tables', $data)) {
            return ErDiagramImporter::parse($json);
        }

        $validator = Validator::make($data, [
            'dialect' => ['required', 'string', Rule::in(array_keys(DataTypeCatalog::DIALECTS))],
            'tables' => 'present|array|list|max:250',
            'tables.*' => 'required|array',
            'tables.*.id' => 'required|string|max:100|distinct:strict',
            'tables.*.name' => 'required|string|max:80',
            'tables.*.x' => 'sometimes|numeric',
            'tables.*.y' => 'sometimes|numeric',
            'tables.*.columns' => 'present|array|list',
            'tables.*.columns.*' => 'required|array',
            'tables.*.columns.*.id' => 'required|string|max:200',
            'tables.*.columns.*.name' => 'required|string|max:80',
            'tables.*.columns.*.type' => 'required|string|max:80',
            'tables.*.columns.*.key' => ['sometimes', Rule::in(['', 'PK', 'FK', 'UQ', 'PK/FK', 'UQ/FK'])],
            'tables.*.columns.*.nullable' => 'sometimes|boolean',
            'tables.*.primaryKey' => 'sometimes|array|list',
            'tables.*.uniqueKeys' => 'sometimes|array|list',
            'tables.*.uniqueKeys.*' => 'array|list',
            'foreignKeys' => 'present|array|list|max:1000',
            'foreignKeys.*' => 'required|array',
            'foreignKeys.*.id' => 'required|string|max:500|distinct:strict',
            'foreignKeys.*.fromTable' => 'required|string',
            'foreignKeys.*.toTable' => 'required|string',
            'foreignKeys.*.fromColumn' => 'required|string',
            'foreignKeys.*.toColumn' => 'required|string',
            'warnings' => 'sometimes|array|list',
            'warnings.*' => 'string',
        ]);
        if ($validator->fails()) {
            throw new InvalidArgumentException($validator->errors()->first());
        }

        $tables = array_column($data['tables'], null, 'id');
        foreach ($tables as $table) {
            $columns = array_column($table['columns'], null, 'name');
            if (count($columns) !== count($table['columns'])
                || count(array_unique(array_column($table['columns'], 'id'))) !== count($table['columns'])) {
                throw new InvalidArgumentException('Column names and identifiers must be unique within each table.');
            }
            foreach ([$table['primaryKey'] ?? [], ...($table['uniqueKeys'] ?? [])] as $key) {
                foreach ($key as $column) {
                    if (! is_string($column) || ! isset($columns[$column])) {
                        throw new InvalidArgumentException('A table key references a missing column.');
                    }
                }
            }
        }
        foreach ($data['foreignKeys'] as $key) {
            foreach (['from', 'to'] as $end) {
                $table = $tables[$key[$end.'Table']] ?? null;
                $columns = array_column($table['columns'] ?? [], null, 'name');
                $column = $columns[$key[$end.'Column']] ?? null;
                if ($column === null || (isset($key[$end.'ColumnId']) && $key[$end.'ColumnId'] !== $column['id'])) {
                    throw new InvalidArgumentException('A foreign key references a missing table or column.');
                }
            }
        }

        return array_intersect_key($data, array_flip(['dialect', 'tables', 'foreignKeys', 'warnings']));
    }
}
