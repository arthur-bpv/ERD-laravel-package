<?php

namespace App\Services;

use Illuminate\Support\Str;

/** Copies referenced keys into relational tables and records missing-key warnings. */
final class ForeignKeyBuilder
{
    private array $warnings;

    public function __construct(private array $entityKinds, array &$warnings)
    {
        $this->warnings = &$warnings;
    }

    /**
     * @param  array{prefix?:?string,nullable?:bool,unique?:bool,primary?:bool,cardinality?:string,sourceCard?:string,targetCard?:string,parentAttributeId?:?string,childAttributeId?:?string,relationshipName?:?string}  $options
     */
    public function copyPrimaryKey(array $parent, array &$child, array $options = []): void
    {
        $options += [
            'prefix' => null,
            'nullable' => false,
            'unique' => false,
            'primary' => false,
            'cardinality' => 'N:1',
            'sourceCard' => 'cf-zero-many',
            'targetCard' => 'cf-one-one',
            'parentAttributeId' => null,
            'childAttributeId' => null,
            'relationshipName' => null,
        ];
        $keys = $this->referenceColumns($parent, $options['parentAttributeId']);
        if ($keys === []) {
            $this->warnings[] = "A relação {$parent['name']} não possui chave PK/UQ para ser referenciada.";

            return;
        }

        foreach ($keys as $key) {
            $this->copyReferenceColumn($parent, $child, $key, count($keys) === 1, $options);
        }
    }

    private function copyReferenceColumn(array $parent, array &$child, array $key, bool $singleKey, array $options): void
    {
        $prefix = $options['prefix'];
        $nullable = $options['nullable'];
        $unique = $options['unique'];
        $primary = $options['primary'];
        $cardinality = $options['cardinality'];
        $sourceCard = $options['sourceCard'];
        $targetCard = $options['targetCard'];
        $childAttributeId = $options['childAttributeId'];
        $relationshipName = $options['relationshipName'];
        $existingIndex = $singleKey && $childAttributeId
            ? $this->columnIndexById($child, $childAttributeId)
            : null;
        $existingIsPrimary = $existingIndex !== null
            && in_array($child['columns'][$existingIndex]['name'], $child['primaryKey'], true);

        // Em 1:N, reutilizar a PK do lado N transformaria a relação em
        // 1:1 por acidente. Uma PK existente só pode servir como FK no
        // caso de chave primária compartilhada (1:1 obrigatório).
        if ($existingIsPrimary && (! $unique || $nullable)) {
            $existingIndex = null;
            $existingIsPrimary = false;
        }

        $name = $prefix ? Str::camel($prefix).Str::ucfirst($key['name']) : $key['name'];
        if ($existingIndex !== null) {
            $name = $child['columns'][$existingIndex]['name'];
        } elseif ($this->hasColumn($child, $name)) {
            $name = Str::camel($parent['name']).Str::ucfirst($key['name']);
        }

        $column = $existingIndex !== null ? $child['columns'][$existingIndex] : $key;
        $column['id'] = $existingIndex !== null
            ? $column['id']
            : $child['id'].'.'.Str::snake($name);
        $column['name'] = $name;
        $column['type'] = $key['type'];
        $column['key'] = $primary || $existingIsPrimary ? 'PK/FK' : ($unique ? 'UQ/FK' : 'FK');
        $column['nullable'] = $primary || $existingIsPrimary ? false : $nullable;
        $column['references'] = [
            'table' => $parent['id'],
            'column' => $key['name'],
            'columnId' => $key['id'],
            'cardinality' => $cardinality,
            'sourceCard' => $sourceCard,
            'targetCard' => $targetCard,
            'relationshipName' => $relationshipName,
        ];
        $column['source'] = 'foreign-key';
        if ($existingIndex !== null) {
            $child['columns'][$existingIndex] = $column;
        } else {
            $child['columns'][] = $column;
            $existingIndex = array_key_last($child['columns']);
        }

        if ($primary) {
            if (! in_array($name, $child['primaryKey'], true)) {
                $child['primaryKey'][] = $name;
            }
        } elseif (($this->entityKinds[$child['id']] ?? null) === 'weak') {
            if (! in_array($name, $child['primaryKey'], true)) {
                $child['primaryKey'][] = $name;
            }
            $child['columns'][$existingIndex]['key'] = 'PK/FK';
            $child['columns'][$existingIndex]['nullable'] = false;
        }
    }

    private function referenceColumns(array $table, ?string $selectedAttributeId): array
    {
        if ($selectedAttributeId) {
            $selected = collect($table['columns'])->firstWhere('id', $selectedAttributeId);

            if ($selected && str_contains((string) $selected['key'], 'UQ')) {
                return [$selected];
            }

            if ($selected && str_contains((string) $selected['key'], 'PK')) {
                return array_values(array_filter(
                    $table['columns'],
                    fn (array $column) => in_array($column['name'], $table['primaryKey'], true),
                ));
            }
        }

        $primary = array_values(array_filter(
            $table['columns'],
            fn (array $column) => in_array($column['name'], $table['primaryKey'], true),
        ));

        if ($primary !== []) {
            return $primary;
        }

        $unique = collect($table['columns'])->first(
            fn (array $column) => str_contains((string) $column['key'], 'UQ'),
        );

        return $unique ? [$unique] : [];
    }

    private function columnIndexById(array $table, string $id): ?int
    {
        foreach ($table['columns'] as $index => $column) {
            if (($column['id'] ?? null) === $id) {
                return $index;
            }
        }

        return null;
    }

    private function hasColumn(array $table, string $name): bool
    {
        return collect($table['columns'])->contains('name', $name);
    }
}
