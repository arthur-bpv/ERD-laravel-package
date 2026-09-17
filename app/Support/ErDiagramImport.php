<?php

namespace App\Support;

use InvalidArgumentException;
use JsonException;

class ErDiagramImport
{
    public static function parse(string $json): array
    {
        if (strlen($json) > 2_000_000) {
            throw new InvalidArgumentException('O arquivo ultrapassa o limite de 2 MB.');
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('JSON inválido: '.$exception->getMessage());
        }

        if (! is_array($data) || ! is_array($data['entities'] ?? null) || ! is_array($data['relations'] ?? null)
            || ! array_is_list($data['entities']) || ! array_is_list($data['relations'])) {
            throw new InvalidArgumentException('O JSON precisa conter as listas entities e relations.');
        }
        if (count($data['entities']) > 250 || count($data['relations']) > 500) {
            throw new InvalidArgumentException('Limite: 250 entidades e 500 relacionamentos.');
        }

        $entities = [];
        foreach ($data['entities'] as $index => $entity) {
            self::requireObject($entity, "entities[$index]");
            $id = self::identifier($entity['id'] ?? null, "entities[$index].id");
            self::unique($entities, $id, "entities[$index].id");
            self::name($entity['name'] ?? null, "entities[$index].name");
            self::coordinates($entity, "entities[$index]");
            $entities[$id] = self::attributes($entity['attributes'] ?? null, "entities[$index].attributes");
        }

        $relations = [];
        foreach ($data['relations'] as $index => $relation) {
            self::requireObject($relation, "relations[$index]");
            $id = self::identifier($relation['id'] ?? null, "relations[$index].id");
            self::unique($relations, $id, "relations[$index].id");
            if (isset($entities[$id])) {
                throw new InvalidArgumentException("relations[$index].id conflita com uma entidade.");
            }
            self::name($relation['name'] ?? null, "relations[$index].name");
            foreach (['from', 'to'] as $end) {
                $entityId = $relation[$end] ?? null;
                if ($entityId !== null && ! isset($entities[$entityId])) {
                    throw new InvalidArgumentException("relations[$index].$end referencia uma entidade inexistente.");
                }
                $attrId = $relation[$end.'Attr'] ?? '';
                if ($attrId !== '' && ! isset($entities[$entityId][$attrId])) {
                    throw new InvalidArgumentException("relations[$index].{$end}Attr não pertence à entidade $end.");
                }
            }
            foreach (['childCard', 'parentCard'] as $card) {
                if (! in_array($relation[$card] ?? null, ['cf-one-one', 'cf-zero-one', 'cf-one-many', 'cf-zero-many', 'cf-many'], true)) {
                    throw new InvalidArgumentException("relations[$index].$card tem cardinalidade inválida.");
                }
            }
            foreach (['diamondX', 'diamondY'] as $coordinate) {
                if (isset($relation[$coordinate]) && ! is_numeric($relation[$coordinate])) {
                    throw new InvalidArgumentException("relations[$index].$coordinate deve ser numérico.");
                }
            }
            self::attributes($relation['attributes'] ?? [], "relations[$index].attributes");
            foreach ($relation['participants'] ?? [] as $participantIndex => $participant) {
                if (! is_array($participant) || ! isset($entities[$participant['entity'] ?? null])) {
                    throw new InvalidArgumentException("relations[$index].participants[$participantIndex] referencia uma entidade inexistente.");
                }
            }
            $relations[$id] = true;
        }

        return [
            'entities' => array_values($data['entities']),
            'relations' => array_values($data['relations']),
        ];
    }

    private static function attributes(mixed $attributes, string $path): array
    {
        if (! is_array($attributes) || ! array_is_list($attributes)) {
            throw new InvalidArgumentException("$path deve ser uma lista.");
        }
        $ids = [];
        $names = [];
        foreach ($attributes as $index => $attribute) {
            self::requireObject($attribute, "{$path}[$index]");
            $id = self::identifier($attribute['id'] ?? null, "{$path}[$index].id", true);
            self::unique($ids, $id, "{$path}[$index].id");
            $name = self::name($attribute['name'] ?? null, "{$path}[$index].name");
            self::unique($names, mb_strtolower($name), "{$path}[$index].name");
            if (isset($attribute['type']) && (! is_string($attribute['type']) || mb_strlen($attribute['type']) > 80)) {
                throw new InvalidArgumentException("{$path}[$index].type é inválido.");
            }
            if (! in_array($attribute['key'] ?? '', ['', 'PK', 'FK', 'UQ'], true)) {
                throw new InvalidArgumentException("{$path}[$index].key é inválido.");
            }
            self::coordinates($attribute, "{$path}[$index]");
            $ids[$id] = true;
            $names[mb_strtolower($name)] = true;
        }

        return $ids;
    }

    private static function requireObject(mixed $value, string $path): void
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException("$path deve ser um objeto.");
        }
    }

    private static function identifier(mixed $value, string $path, bool $attribute = false): string
    {
        $pattern = $attribute ? '/^[A-Za-z][A-Za-z0-9_.-]*$/' : '/^[A-Za-z][A-Za-z0-9_-]*$/';
        if (! is_string($value) || strlen($value) > 100 || ! preg_match($pattern, $value)) {
            throw new InvalidArgumentException("$path tem identificador inválido.");
        }

        return $value;
    }

    private static function name(mixed $value, string $path): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 80) {
            throw new InvalidArgumentException("$path precisa ter nome de até 80 caracteres.");
        }

        return trim($value);
    }

    private static function coordinates(array $item, string $path): void
    {
        foreach (['x', 'y'] as $coordinate) {
            if (isset($item[$coordinate]) && ! is_numeric($item[$coordinate])) {
                throw new InvalidArgumentException("$path.$coordinate deve ser numérico.");
            }
        }
    }

    private static function unique(array $seen, string $value, string $path): void
    {
        if (isset($seen[$value])) {
            throw new InvalidArgumentException("$path está duplicado.");
        }
    }
}
