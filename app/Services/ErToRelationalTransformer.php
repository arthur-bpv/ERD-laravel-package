<?php

namespace App\Services;

use Illuminate\Support\Str;

class ErToRelationalTransformer
{
    private array $warnings = [];

    private array $entityKinds = [];

    public function transform(array $diagram): array
    {
        $this->warnings = [];
        $this->entityKinds = [];

        $tables = [];
        $entityTable = [];
        $relations = array_values(array_filter(
            $diagram['relations'] ?? [],
            fn (array $relation) => ! empty($relation['from']) && ! empty($relation['to']),
        ));

        foreach ($diagram['entities'] ?? [] as $index => $entity) {
            $tableId = (string) $entity['id'];
            $entityTable[$tableId] = $tableId;
            $this->entityKinds[$tableId] = $entity['kind'] ?? 'strong';
            $tables[$tableId] = $this->tableFromEntity($entity, $index);
        }

        foreach ($relations as $relation) {
            if ($this->isMandatoryOneToOne($relation) && $relation['from'] !== $relation['to']) {
                $this->mergeMandatoryOneToOne($tables, $entityTable, $relation);
            }
        }

        foreach ($relations as $relation) {
            if ($this->isMandatoryOneToOne($relation) && $relation['from'] !== $relation['to']) {
                continue;
            }

            $this->mapRelation($tables, $entityTable, $relation);
        }

        foreach ($diagram['entities'] ?? [] as $entity) {
            $this->mapMultivaluedAttributes($tables, $entityTable, $entity);
        }

        return [
            'version' => 1,
            'tables' => array_values($tables),
            'foreignKeys' => $this->foreignKeys($tables),
            'warnings' => array_values(array_unique($this->warnings)),
            'generatedAt' => now()->toIso8601String(),
        ];
    }

    private function tableFromEntity(array $entity, int $index): array
    {
        $columns = [];

        foreach ($entity['attributes'] ?? [] as $attribute) {
            if ($attribute['multivalued'] ?? false) {
                continue;
            }

            foreach ($this->simpleAttributes($attribute) as $simple) {
                $columns[] = $this->columnFromAttribute($simple, $entity['id']);
            }
        }

        return [
            'id' => (string) $entity['id'],
            'name' => (string) $entity['name'],
            'kind' => $entity['kind'] ?? 'strong',
            'x' => (int) ($entity['x'] ?? (($index % 3) * 340 + 40)),
            'y' => (int) ($entity['y'] ?? (intdiv($index, 3) * 280 + 40)),
            'columns' => $columns,
            'primaryKey' => array_values(array_map(
                fn (array $column) => $column['name'],
                array_filter($columns, fn (array $column) => $column['key'] === 'PK'),
            )),
        ];
    }

    private function simpleAttributes(array $attribute): array
    {
        $components = $attribute['components'] ?? [];

        return $components === [] ? [$attribute] : $components;
    }

    private function columnFromAttribute(array $attribute, string $source): array
    {
        return [
            'id' => (string) ($attribute['id'] ?? $source.'.'.Str::snake($attribute['name'])),
            'name' => (string) $attribute['name'],
            'type' => (string) ($attribute['type'] ?? 'varchar'),
            'key' => (string) ($attribute['key'] ?? ''),
            'nullable' => (bool) ($attribute['nullable'] ?? false),
            'references' => null,
            'source' => $source,
        ];
    }

    private function mapRelation(array &$tables, array $entityTable, array $relation): void
    {
        if (($relation['kind'] ?? null) === 'inheritance') {
            $this->warnings[] = "A especialização {$relation['name']} exige participação e disjunção explícitas no modelo ER antes da transformação.";

            return;
        }

        if (($relation['kind'] ?? null) === 'complex' || count($relation['participants'] ?? []) > 2) {
            $this->createComplexTable($tables, $entityTable, $relation);

            return;
        }

        $fromId = $entityTable[$relation['from']] ?? null;
        $toId = $entityTable[$relation['to']] ?? null;

        if (! $fromId || ! $toId || ! isset($tables[$fromId], $tables[$toId])) {
            $this->warnings[] = "O relacionamento {$relation['name']} referencia uma entidade inexistente.";

            return;
        }

        $fromCard = $this->cardinality($relation['childCard'] ?? 'cf-zero-many');
        $toCard = $this->cardinality($relation['parentCard'] ?? 'cf-one-one');

        if ($fromCard['many'] && $toCard['many']) {
            $this->createAssociativeTable($tables, $fromId, $toId, $relation);

            return;
        }

        if ($relation['from'] === $relation['to']) {
            if (! $fromCard['many'] && ! $toCard['many'] && $fromCard['min'] === 0 && $toCard['min'] === 0) {
                $this->createRecursiveOneToOneTable($tables, $fromId, $relation);

                return;
            }

            $this->mapRecursiveRelation($tables[$fromId], $relation, $fromCard, $toCard);

            return;
        }

        if ($fromCard['many'] xor $toCard['many']) {
            $childId = $fromCard['many'] ? $fromId : $toId;
            $parentId = $fromCard['many'] ? $toId : $fromId;
            $nullable = $fromCard['many'] ? $toCard['min'] === 0 : $fromCard['min'] === 0;
            $this->copyPrimaryKey(
                $tables[$parentId],
                $tables[$childId],
                nullable: $nullable,
                sourceCard: $fromCard['many'] ? $relation['childCard'] : $relation['parentCard'],
                targetCard: $fromCard['many'] ? $relation['parentCard'] : $relation['childCard'],
            );
            $this->appendRelationshipAttributes($tables[$childId], $relation);

            return;
        }

        // 1:1: participação obrigatória fica no filho; com ambas opcionais,
        // preservamos a direção desenhada (to = pai, from = filho).
        if ($fromCard['min'] === 1 && $toCard['min'] === 0) {
            [$childId, $parentId] = [$fromId, $toId];
        } elseif ($toCard['min'] === 1 && $fromCard['min'] === 0) {
            [$childId, $parentId] = [$toId, $fromId];
        } else {
            [$childId, $parentId] = [$fromId, $toId];
        }

        $this->copyPrimaryKey(
            $tables[$parentId],
            $tables[$childId],
            nullable: $fromCard['min'] === 0 && $toCard['min'] === 0,
            unique: true,
            cardinality: '1:1',
            sourceCard: $childId === $fromId ? $relation['childCard'] : $relation['parentCard'],
            targetCard: $parentId === $toId ? $relation['parentCard'] : $relation['childCard'],
        );
        $this->appendRelationshipAttributes($tables[$childId], $relation);
    }

    private function mergeMandatoryOneToOne(array &$tables, array &$entityTable, array $relation): void
    {
        $sourceId = $entityTable[$relation['from']] ?? null;
        $targetId = $entityTable[$relation['to']] ?? null;

        if (! $sourceId || ! $targetId || ! isset($tables[$sourceId], $tables[$targetId])) {
            return;
        }

        foreach ($tables[$sourceId]['columns'] as $column) {
            if (! $this->hasColumn($tables[$targetId], $column['name'])) {
                if ($column['key'] === 'PK') {
                    $column['key'] = 'UQ';
                }
                $tables[$targetId]['columns'][] = $column;
            }
        }

        $this->appendRelationshipAttributes($tables[$targetId], $relation);
        unset($tables[$sourceId]);

        foreach ($entityTable as $entityId => $tableId) {
            if ($tableId === $sourceId) {
                $entityTable[$entityId] = $targetId;
            }
        }
    }

    private function createAssociativeTable(array &$tables, string $fromId, string $toId, array $relation): void
    {
        $id = 'relation_'.Str::snake((string) $relation['id']);
        $table = [
            'id' => $id,
            'name' => (string) ($relation['name'] ?: Str::headline($relation['id'])),
            'kind' => 'associative',
            'x' => (int) (($tables[$fromId]['x'] + $tables[$toId]['x']) / 2),
            'y' => (int) (($tables[$fromId]['y'] + $tables[$toId]['y']) / 2 + 220),
            'columns' => [],
            'primaryKey' => [],
        ];

        $this->copyPrimaryKey(
            $tables[$fromId],
            $table,
            primary: true,
            cardinality: 'N:N',
            sourceCard: 'cf-zero-many',
            targetCard: $relation['childCard'] ?? 'cf-zero-many',
        );
        $this->copyPrimaryKey(
            $tables[$toId],
            $table,
            prefix: $fromId === $toId ? 'related' : null,
            primary: true,
            cardinality: 'N:N',
            sourceCard: 'cf-zero-many',
            targetCard: $relation['parentCard'] ?? 'cf-zero-many',
        );
        $this->appendRelationshipAttributes($table, $relation);
        $tables[$id] = $table;
    }

    private function mapRecursiveRelation(array &$table, array $relation, array $fromCard, array $toCard): void
    {
        $prefix = (string) ($relation['toRole'] ?? Str::camel($relation['name'] ?? 'related'));

        if ($fromCard['many'] || $toCard['many']) {
            $this->copyPrimaryKey(
                $table,
                $table,
                prefix: $prefix,
                nullable: $toCard['min'] === 0,
                sourceCard: $relation['childCard'],
                targetCard: $relation['parentCard'],
            );
            $this->appendRelationshipAttributes($table, $relation);

            return;
        }

        $this->copyPrimaryKey(
            $table,
            $table,
            prefix: $prefix,
            nullable: $fromCard['min'] === 0 || $toCard['min'] === 0,
            unique: true,
            cardinality: '1:1',
            sourceCard: $relation['childCard'],
            targetCard: $relation['parentCard'],
        );
        $this->appendRelationshipAttributes($table, $relation);
    }

    private function createRecursiveOneToOneTable(array &$tables, string $entityId, array $relation): void
    {
        $id = 'relation_'.Str::snake((string) $relation['id']);
        $table = [
            'id' => $id,
            'name' => (string) $relation['name'],
            'kind' => 'recursive',
            'x' => $tables[$entityId]['x'] + 340,
            'y' => $tables[$entityId]['y'] + 120,
            'columns' => [],
            'primaryKey' => [],
        ];

        $this->copyPrimaryKey(
            $tables[$entityId],
            $table,
            prefix: $relation['fromRole'] ?? 'first',
            primary: true,
            cardinality: '1:1',
            sourceCard: 'cf-zero-one',
            targetCard: 'cf-zero-one',
        );
        $this->copyPrimaryKey(
            $tables[$entityId],
            $table,
            prefix: $relation['toRole'] ?? 'second',
            primary: true,
            cardinality: '1:1',
            sourceCard: 'cf-zero-one',
            targetCard: 'cf-zero-one',
        );
        $this->appendRelationshipAttributes($table, $relation);
        $tables[$id] = $table;
    }

    private function createComplexTable(array &$tables, array $entityTable, array $relation): void
    {
        $id = 'relation_'.Str::snake((string) $relation['id']);
        $participants = $relation['participants'] ?? [];
        $resolved = [];

        foreach ($participants as $participant) {
            $tableId = $entityTable[$participant['entity']] ?? null;
            if ($tableId && isset($tables[$tableId])) {
                $resolved[] = [$tables[$tableId], $participant];
            }
        }

        if (count($resolved) < 3) {
            $this->warnings[] = "O relacionamento complexo {$relation['name']} não possui três participantes válidos.";

            return;
        }

        $table = [
            'id' => $id,
            'name' => (string) $relation['name'],
            'kind' => 'complex',
            'x' => (int) (array_sum(array_column(array_column($resolved, 0), 'x')) / count($resolved)),
            'y' => (int) (array_sum(array_column(array_column($resolved, 0), 'y')) / count($resolved) + 240),
            'columns' => [],
            'primaryKey' => [],
        ];

        foreach ($resolved as [$participantTable, $participant]) {
            $this->copyPrimaryKey(
                $participantTable,
                $table,
                prefix: $participant['role'] ?? null,
                primary: (bool) ($participant['many'] ?? true),
            );
        }

        $this->appendRelationshipAttributes($table, $relation);
        $tables[$id] = $table;
    }

    private function mapMultivaluedAttributes(array &$tables, array $entityTable, array $entity): void
    {
        $ownerId = $entityTable[$entity['id']] ?? null;
        if (! $ownerId || ! isset($tables[$ownerId])) {
            return;
        }

        foreach ($entity['attributes'] ?? [] as $attribute) {
            if (! ($attribute['multivalued'] ?? false)) {
                continue;
            }

            $id = $ownerId.'_'.Str::snake($attribute['name']);
            $column = $this->columnFromAttribute($attribute, $entity['id']);
            $column['key'] = $attribute['key'] === 'UQ' ? 'UQ' : 'PK';

            $table = [
                'id' => $id,
                'name' => Str::headline($attribute['name']),
                'kind' => 'multivalued',
                'x' => $tables[$ownerId]['x'] + 300,
                'y' => $tables[$ownerId]['y'] + 180,
                'columns' => [$column],
                'primaryKey' => $column['key'] === 'PK' ? [$column['name']] : [],
            ];

            $this->copyPrimaryKey($tables[$ownerId], $table, primary: $column['key'] !== 'UQ');
            $tables[$id] = $table;
        }
    }

    private function copyPrimaryKey(
        array $parent,
        array &$child,
        ?string $prefix = null,
        bool $nullable = false,
        bool $unique = false,
        bool $primary = false,
        string $cardinality = 'N:1',
        string $sourceCard = 'cf-zero-many',
        string $targetCard = 'cf-one-one',
    ): void {
        $keys = array_values(array_filter(
            $parent['columns'],
            fn (array $column) => in_array($column['name'], $parent['primaryKey'], true),
        ));

        if ($keys === []) {
            $this->warnings[] = "A relação {$parent['name']} não possui chave primária para ser referenciada.";

            return;
        }

        foreach ($keys as $key) {
            $name = $prefix ? Str::camel($prefix).Str::ucfirst($key['name']) : $key['name'];
            if ($this->hasColumn($child, $name)) {
                $name = Str::camel($parent['name']).Str::ucfirst($key['name']);
            }

            $column = $key;
            $column['id'] = $child['id'].'.'.Str::snake($name);
            $column['name'] = $name;
            $column['key'] = $primary ? 'PK/FK' : ($unique ? 'UQ/FK' : 'FK');
            $column['nullable'] = $nullable;
            $column['references'] = [
                'table' => $parent['id'],
                'column' => $key['name'],
                'cardinality' => $cardinality,
                'sourceCard' => $sourceCard,
                'targetCard' => $targetCard,
            ];
            $column['source'] = 'foreign-key';
            $child['columns'][] = $column;

            if ($primary) {
                $child['primaryKey'][] = $name;
            } elseif (($this->entityKinds[$child['id']] ?? null) === 'weak') {
                $child['primaryKey'][] = $name;
                $child['columns'][array_key_last($child['columns'])]['key'] = 'PK/FK';
            }
        }
    }

    private function appendRelationshipAttributes(array &$table, array $relation): void
    {
        foreach ($relation['attributes'] ?? [] as $attribute) {
            if (! $this->hasColumn($table, $attribute['name'])) {
                $table['columns'][] = $this->columnFromAttribute($attribute, 'relationship:'.$relation['id']);
            }
        }
    }

    private function foreignKeys(array $tables): array
    {
        $foreignKeys = [];

        foreach ($tables as $table) {
            foreach ($table['columns'] as $column) {
                if (! $column['references']) {
                    continue;
                }

                $foreignKeys[] = [
                    'id' => 'fk_'.$table['id'].'_'.$column['id'],
                    'fromTable' => $table['id'],
                    'fromColumn' => $column['name'],
                    'toTable' => $column['references']['table'],
                    'toColumn' => $column['references']['column'],
                    'cardinality' => $column['references']['cardinality'] ?? 'N:1',
                    'sourceCard' => $column['references']['sourceCard'] ?? 'cf-zero-many',
                    'targetCard' => $column['references']['targetCard'] ?? 'cf-one-one',
                ];
            }
        }

        return $foreignKeys;
    }

    private function cardinality(string $marker): array
    {
        return match ($marker) {
            'cf-one-one' => ['min' => 1, 'many' => false],
            'cf-zero-one' => ['min' => 0, 'many' => false],
            'cf-one-many', 'cf-many' => ['min' => 1, 'many' => true],
            default => ['min' => 0, 'many' => true],
        };
    }

    private function isMandatoryOneToOne(array $relation): bool
    {
        return ($relation['childCard'] ?? null) === 'cf-one-one'
            && ($relation['parentCard'] ?? null) === 'cf-one-one';
    }

    private function hasColumn(array $table, string $name): bool
    {
        return collect($table['columns'])->contains('name', $name);
    }
}
