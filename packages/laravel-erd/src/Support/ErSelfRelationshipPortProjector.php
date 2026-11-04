<?php

namespace Makermind\Erd4dev\Support;

/** Positions the four invisible ports connecting an entity to its self relationship. */
final class ErSelfRelationshipPortProjector
{
    public static function nodes(string $relationId, array $entity, array $diamond): array
    {
        $dimensions = ErEntityNodeProjector::dimensions($entity);
        $entityWidth = $dimensions['width'];
        $entityHeight = $dimensions['height'];
        $entityCenterX = $entity['x'] + ($entityWidth / 2);
        $entityCenterY = $entity['y'] + ($entityHeight / 2);
        $diamondCenterX = $diamond['x'] + 60.0;
        $diamondCenterY = $diamond['y'] + 22.0;

        $dx = $diamondCenterX - $entityCenterX;
        $dy = $diamondCenterY - $entityCenterY;
        $horizontal = abs($dx) / ($entityWidth / 2 + 60)
            > abs($dy) / ($entityHeight / 2 + 22);
        if ($horizontal) {
            $x = $entity['x'] + ($dx > 0 ? $entityWidth : 0);
            $entityOut = ['x' => $x, 'y' => $entity['y'] + $entityHeight - 12];
            $entityIn = ['x' => $x, 'y' => $entity['y'] + 12];
        } else {
            $y = $entity['y'] + ($dy > 0 ? $entityHeight : 0);
            $entityOut = ['x' => $entity['x'] + $entityWidth - 12, 'y' => $y];
            $entityIn = ['x' => $entity['x'] + 12, 'y' => $y];
        }
        $points = [
            'entityOut' => $entityOut,
            'entityIn' => $entityIn,
            'diamondOut' => $horizontal
                ? ['x' => $diamondCenterX, 'y' => $diamond['y'] + 50.0]
                : ['x' => $diamond['x'] + 128.0, 'y' => $diamondCenterY],
            'diamondIn' => $horizontal
                ? ['x' => $diamondCenterX, 'y' => $diamond['y'] - 6.0]
                : ['x' => $diamond['x'] - 8.0, 'y' => $diamondCenterY],
        ];

        $prefix = 'relation-'.$relationId.'-port-';
        $ids = [
            'entityOut' => $prefix.'entity-out',
            'entityIn' => $prefix.'entity-in',
            'diamondOut' => $prefix.'diamond-out',
            'diamondIn' => $prefix.'diamond-in',
        ];

        return array_map(
            fn (string $role) => [
                'id' => $ids[$role],
                'position' => [
                    'x' => (int) round($points[$role]['x']) - 1,
                    'y' => (int) round($points[$role]['y']) - 1,
                ],
                'dimensions' => ['width' => 2.0, 'height' => 2.0],
                'data' => [
                    'kind' => 'relationship-port',
                    'relationId' => $relationId,
                    'role' => $role,
                ],
            ],
            array_keys($points),
        );
    }

}
