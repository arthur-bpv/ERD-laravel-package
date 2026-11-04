<?php

namespace Makermind\Erd4dev\Support;

use InvalidArgumentException;
use JsonException;

class ErDiagramImporter
{
    public static function parse(string $json): array
    {
        $data = self::decode($json);
        $entities = self::validateEntities($data['entities']);
        self::validateRelations($data['relations'], $entities);

        return array_filter([
            // Keep old files compatible and preserve the target dialect in new ones.
            'dialect' => $data['dialect'] ?? null,
            'entities' => array_values($data['entities']),
            'relations' => array_values($data['relations']),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function decode(string $json): array
    {
        if (strlen($json) > 2_000_000) {
            throw new InvalidArgumentException('The file exceeds the 2 MB limit.');
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Invalid JSON: '.$exception->getMessage());
        }

        if (! is_array($data) || ! is_array($data['entities'] ?? null) || ! is_array($data['relations'] ?? null)
            || ! array_is_list($data['entities']) || ! array_is_list($data['relations'])) {
            throw new InvalidArgumentException('The JSON must contain entities and relations lists.');
        }
        if (count($data['entities']) > 250 || count($data['relations']) > 500) {
            throw new InvalidArgumentException('Limit: 250 entities and 500 relationships.');
        }

        if (isset($data['dialect']) && (! is_string($data['dialect']) || ! DataTypeCatalog::has($data['dialect']))) {
            throw new InvalidArgumentException('dialect must identify a supported database.');
        }

        return $data;
    }

    private static function validateEntities(array $data): array
    {
        $entities = [];
        foreach ($data as $index => $entity) {
            self::requireObject($entity, "entities[$index]");
            $id = self::identifier($entity['id'] ?? null, "entities[$index].id");
            self::unique($entities, $id, "entities[$index].id");
            self::name($entity['name'] ?? null, "entities[$index].name");
            self::coordinates($entity, "entities[$index]");
            $entities[$id] = self::attributes($entity['attributes'] ?? null, "entities[$index].attributes");
        }

        return $entities;
    }

    private static function validateRelations(array $data, array $entities): void
    {
        $relations = [];
        foreach ($data as $index => $relation) {
            self::requireObject($relation, "relations[$index]");
            $id = self::identifier($relation['id'] ?? null, "relations[$index].id");
            self::unique($relations, $id, "relations[$index].id");
            if (isset($entities[$id])) {
                throw new InvalidArgumentException("relations[$index].id conflicts with an entity.");
            }
            self::name($relation['name'] ?? null, "relations[$index].name");
            foreach (['from', 'to'] as $end) {
                $entityId = $relation[$end] ?? null;
                if ($entityId !== null && ! isset($entities[$entityId])) {
                    throw new InvalidArgumentException("relations[$index].$end references a missing entity.");
                }
                $attrId = $relation[$end.'Attr'] ?? '';
                if ($attrId !== '' && ! isset($entities[$entityId][$attrId])) {
                    throw new InvalidArgumentException("relations[$index].{$end}Attr does not belong to entity $end.");
                }
            }
            foreach (['childCard', 'parentCard'] as $card) {
                if (! in_array($relation[$card] ?? null, ['cf-one-one', 'cf-zero-one', 'cf-one-many', 'cf-zero-many'], true)) {
                    throw new InvalidArgumentException("relations[$index].$card has an invalid cardinality.");
                }
            }
            foreach (['diamondX', 'diamondY'] as $coordinate) {
                if (isset($relation[$coordinate]) && ! is_numeric($relation[$coordinate])) {
                    throw new InvalidArgumentException("relations[$index].$coordinate must be numeric.");
                }
            }
            self::attributes($relation['attributes'] ?? [], "relations[$index].attributes");
            foreach ($relation['participants'] ?? [] as $participantIndex => $participant) {
                if (! is_array($participant) || ! isset($entities[$participant['entity'] ?? null])) {
                    throw new InvalidArgumentException("relations[$index].participants[$participantIndex] references a missing entity.");
                }
            }
            $relations[$id] = true;
        }
    }

    private static function attributes(mixed $attributes, string $path): array
    {
        if (! is_array($attributes) || ! array_is_list($attributes)) {
            throw new InvalidArgumentException("$path must be a list.");
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
                throw new InvalidArgumentException("{$path}[$index].type is invalid.");
            }
            if (! in_array($attribute['key'] ?? '', ['', 'PK', 'FK', 'UQ'], true)) {
                throw new InvalidArgumentException("{$path}[$index].key is invalid.");
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
            throw new InvalidArgumentException("$path must be an object.");
        }
    }

    private static function identifier(mixed $value, string $path, bool $attribute = false): string
    {
        $pattern = $attribute ? '/^[A-Za-z][A-Za-z0-9_.-]*$/' : '/^[A-Za-z][A-Za-z0-9_-]*$/';
        if (! is_string($value) || strlen($value) > 100 || ! preg_match($pattern, $value)) {
            throw new InvalidArgumentException("$path has an invalid identifier.");
        }

        return $value;
    }

    private static function name(mixed $value, string $path): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 80) {
            throw new InvalidArgumentException("$path requires a name of up to 80 characters.");
        }

        return trim($value);
    }

    private static function coordinates(array $item, string $path): void
    {
        foreach (['x', 'y'] as $coordinate) {
            if (isset($item[$coordinate]) && ! is_numeric($item[$coordinate])) {
                throw new InvalidArgumentException("$path.$coordinate must be numeric.");
            }
        }
    }

    private static function unique(array $seen, string $value, string $path): void
    {
        if (isset($seen[$value])) {
            throw new InvalidArgumentException("$path is duplicated.");
        }
    }
}
