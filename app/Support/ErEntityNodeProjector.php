<?php

namespace App\Support;

/** Builds the canvas payload for an ER entity from the current model state. */
final class ErEntityNodeProjector
{
    public const WIDTH = 232.0;

    private const BASE_HEIGHT = 84.0;

    private const ROW_HEIGHT = 24.5;

    public static function node(array $entity, array $relations): array
    {
        return [
            'id' => $entity['id'],
            'position' => ['x' => $entity['x'], 'y' => $entity['y']],
            'dimensions' => self::dimensions($entity),
            'data' => self::data($entity, $relations),
        ];
    }

    public static function dimensions(array $entity): array
    {
        return [
            'width' => self::WIDTH,
            'height' => self::BASE_HEIGHT + (count($entity['attributes'] ?? []) * self::ROW_HEIGHT),
        ];
    }

    public static function data(array $entity, array $relations): array
    {
        $usedAttributes = [];
        foreach ($relations as $relation) {
            if ($relation['from'] === $entity['id']) {
                $usedAttributes[] = $relation['fromAttr'];
            }
            if ($relation['to'] === $entity['id']) {
                $usedAttributes[] = $relation['toAttr'];
            }
        }

        $canBeParent = false;
        foreach ($entity['attributes'] as $attribute) {
            if ($attribute['key'] === 'PK' || $attribute['key'] === 'UQ') {
                $canBeParent = true;
                break;
            }
        }

        return [
            'name' => $entity['name'],
            'attributes' => array_values($entity['attributes']),
            'canBeParent' => $canBeParent,
            'usedAttrs' => array_values(array_unique($usedAttributes)),
        ];
    }
}
