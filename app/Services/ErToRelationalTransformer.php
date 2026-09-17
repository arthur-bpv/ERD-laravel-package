<?php

namespace App\Services;

use Illuminate\Support\Str;

class ErToRelationalTransformer
{
    private array $warnings = [];

    private array $entityKinds = [];

    private array $relationshipAttributeUsage = [];

    public function transform(array $diagram): array
    {
        $this->warnings = [];
        $this->entityKinds = [];
        $this->relationshipAttributeUsage = [];

        $tables = [];
        $entityTable = [];
        $relations = array_values(array_filter(
            $diagram['relations'] ?? [],
            fn (array $relation) => ! empty($relation['from']) && ! empty($relation['to']),
        ));

        foreach ($relations as $relation) {
            foreach (['fromAttr', 'toAttr'] as $attributeField) {
                $attributeId = (string) ($relation[$attributeField] ?? '');
                if ($attributeId !== '') {
                    $this->relationshipAttributeUsage[$attributeId] = ($this->relationshipAttributeUsage[$attributeId] ?? 0) + 1;
                }
            }
        }

        foreach ($diagram['entities'] ?? [] as $index => $entity) {
            $tableId = (string) $entity['id'];
            $entityTable[$tableId] = $tableId;
            $this->entityKinds[$tableId] = $entity['kind'] ?? 'strong';
            $tables[$tableId] = $this->tableFromEntity($entity, $index);
        }

        foreach ($relations as $relation) {
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
            $this->removeDedicatedRelationshipForeignKey($tables[$fromId], $relation['fromAttr'] ?? null);
            $this->removeDedicatedRelationshipForeignKey($tables[$toId], $relation['toAttr'] ?? null);
            $this->createAssociativeTable($tables, $fromId, $toId, $relation);

            return;
        }

        if ($relation['from'] === $relation['to']) {
            if (! $fromCard['many'] && ! $toCard['many']) {
                $this->createRecursiveOneToOneTable($tables, $fromId, $relation);

                if ($fromCard['min'] === 1 || $toCard['min'] === 1) {
                    $this->warnings[] = "A participação obrigatória no autorrelacionamento {$relation['name']} exige validação adicional; as FKs garantem o par, mas não que toda linha participe.";
                }

                return;
            }

            $this->mapRecursiveRelation($tables[$fromId], $relation, $fromCard, $toCard);

            return;
        }

        if ($fromCard['many'] xor $toCard['many']) {
            $childId = $fromCard['many'] ? $fromId : $toId;
            $parentId = $fromCard['many'] ? $toId : $fromId;
            $nullable = $fromCard['many'] ? $toCard['min'] === 0 : $fromCard['min'] === 0;
            $childAttributeId = $childId === $fromId ? ($relation['fromAttr'] ?? null) : ($relation['toAttr'] ?? null);
            $parentAttributeId = $parentId === $fromId ? ($relation['fromAttr'] ?? null) : ($relation['toAttr'] ?? null);
            $this->copyPrimaryKey(
                $tables[$parentId],
                $tables[$childId],
                nullable: $nullable,
                sourceCard: $fromCard['many'] ? $relation['childCard'] : $relation['parentCard'],
                targetCard: $fromCard['many'] ? $relation['parentCard'] : $relation['childCard'],
                parentAttributeId: $parentAttributeId,
                childAttributeId: $childAttributeId,
            );
            $this->appendRelationshipAttributes($tables[$childId], $relation, $nullable);

            return;
        }

        // Os marcadores indicam quantos registros existem na ponta oposta.
        // 1:1: a entidade que exige um parceiro recebe a FK; com ambas opcionais,
        // preservamos a direção desenhada (to = pai, from = filho).
        if ($toCard['min'] === 1 && $fromCard['min'] === 0) {
            [$childId, $parentId] = [$fromId, $toId];
        } elseif ($fromCard['min'] === 1 && $toCard['min'] === 0) {
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
            parentAttributeId: $parentId === $fromId ? ($relation['fromAttr'] ?? null) : ($relation['toAttr'] ?? null),
            childAttributeId: $childId === $fromId ? ($relation['fromAttr'] ?? null) : ($relation['toAttr'] ?? null),
        );
        $this->appendRelationshipAttributes($tables[$childId], $relation, $fromCard['min'] === 0 && $toCard['min'] === 0);
    }

    private function createAssociativeTable(array &$tables, string $fromId, string $toId, array $relation): void
    {
        $id = 'relation_'.Str::snake((string) $relation['id']);
        $recursive = $fromId === $toId;
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
            prefix: $recursive ? $this->role($relation, 'from') : null,
            primary: true,
            cardinality: 'N:1',
            sourceCard: 'cf-zero-many',
            targetCard: 'cf-one-one',
            parentAttributeId: $relation['fromAttr'] ?? null,
        );
        $this->copyPrimaryKey(
            $tables[$toId],
            $table,
            prefix: $recursive ? $this->role($relation, 'to') : null,
            primary: true,
            cardinality: 'N:1',
            sourceCard: 'cf-zero-many',
            targetCard: 'cf-one-one',
            parentAttributeId: $relation['toAttr'] ?? null,
        );
        $this->appendRelationshipAttributes($table, $relation);
        $tables[$id] = $table;
    }

    private function mapRecursiveRelation(array &$table, array $relation, array $fromCard, array $toCard): void
    {
        if ($fromCard['many'] || $toCard['many']) {
            $manyIsFrom = $fromCard['many'];
            $prefix = $this->role($relation, $manyIsFrom ? 'to' : 'from');
            $this->copyPrimaryKey(
                $table,
                $table,
                prefix: $prefix,
                nullable: ($fromCard['many'] ? $toCard['min'] : $fromCard['min']) === 0,
                sourceCard: $manyIsFrom ? $relation['childCard'] : $relation['parentCard'],
                targetCard: $manyIsFrom ? $relation['parentCard'] : $relation['childCard'],
                parentAttributeId: $manyIsFrom ? ($relation['toAttr'] ?? null) : ($relation['fromAttr'] ?? null),
                relationshipName: $relation['name'] ?? null,
            );
            $this->appendRelationshipAttributes(
                $table,
                $relation,
                ($manyIsFrom ? $toCard['min'] : $fromCard['min']) === 0,
            );

            return;
        }
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
            prefix: $this->role($relation, 'from'),
            primary: true,
            cardinality: '1:1',
            sourceCard: $relation['childCard'],
            targetCard: $relation['parentCard'],
            parentAttributeId: $relation['fromAttr'] ?? null,
        );
        $this->copyPrimaryKey(
            $tables[$entityId],
            $table,
            prefix: $this->role($relation, 'to'),
            unique: true,
            cardinality: '1:1',
            sourceCard: $relation['parentCard'],
            targetCard: $relation['childCard'],
            parentAttributeId: $relation['toAttr'] ?? null,
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
        ?string $parentAttributeId = null,
        ?string $childAttributeId = null,
        ?string $relationshipName = null,
    ): void {
        $keys = $this->referenceColumns($parent, $parentAttributeId);

        if ($keys === []) {
            $this->warnings[] = "A relação {$parent['name']} não possui chave PK/UQ para ser referenciada.";

            return;
        }

        foreach ($keys as $key) {
            $existingIndex = count($keys) === 1 && $childAttributeId
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
    }

    private function appendRelationshipAttributes(array &$table, array $relation, bool $nullable = false): void
    {
        foreach ($relation['attributes'] ?? [] as $attribute) {
            $column = $this->columnFromAttribute($attribute, 'relationship:'.$relation['id']);
            if ($this->hasColumn($table, $column['name'])) {
                $base = Str::snake((string) $relation['name']).'_'.Str::snake($column['name']);
                $candidate = $base;
                $suffix = 2;
                while ($this->hasColumn($table, $candidate)) {
                    $candidate = $base.'_'.$suffix++;
                }
                $this->warnings[] = "O atributo {$column['name']} do relacionamento {$relation['name']} foi renomeado para {$candidate} em {$table['name']} para evitar colisão.";
                $column['name'] = $candidate;
            }
            $column['nullable'] = $nullable || $column['nullable'];
            $table['columns'][] = $column;
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

    private function role(array $relation, string $end): string
    {
        $role = trim((string) ($relation[$end.'Role'] ?? ''));

        return $role !== '' ? $role : ($end === 'from' ? 'first' : 'second');
    }

    private function removeDedicatedRelationshipForeignKey(array &$table, ?string $attributeId): void
    {
        if (! $attributeId || ($this->relationshipAttributeUsage[$attributeId] ?? 0) !== 1) {
            return;
        }

        $table['columns'] = array_values(array_filter(
            $table['columns'],
            fn (array $column) => ($column['id'] ?? null) !== $attributeId || ($column['key'] ?? '') !== 'FK',
        ));
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
                    'relationshipName' => $column['references']['relationshipName'] ?? null,
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

    private function hasColumn(array $table, string $name): bool
    {
        return collect($table['columns'])->contains('name', $name);
    }
}
