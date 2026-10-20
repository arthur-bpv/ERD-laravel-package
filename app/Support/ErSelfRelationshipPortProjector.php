<?php

namespace App\Support;

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
        $length = max(1.0, hypot($dx, $dy));
        $ux = $dx / $length;
        $uy = $dy / $length;
        $px = -$uy;
        $py = $ux;

        // Spread the two paths while keeping their ports on the same sides.
        $spread = 0.55;
        $points = [
            'entityOut' => self::rectangleBorderPoint(
                $entityCenterX, $entityCenterY, $entityWidth / 2, $entityHeight / 2,
                $ux + ($px * $spread), $uy + ($py * $spread),
            ),
            'entityIn' => self::rectangleBorderPoint(
                $entityCenterX, $entityCenterY, $entityWidth / 2, $entityHeight / 2,
                $ux - ($px * $spread), $uy - ($py * $spread),
            ),
            'diamondOut' => self::diamondBorderPoint($diamondCenterX, $diamondCenterY, 60.0, 22.0, $px, $py),
            'diamondIn' => self::diamondBorderPoint($diamondCenterX, $diamondCenterY, 60.0, 22.0, -$px, -$py),
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

    /** @return array{x: float, y: float} */
    private static function rectangleBorderPoint(float $cx, float $cy, float $halfWidth, float $halfHeight, float $dx, float $dy): array
    {
        $tx = abs($dx) > 0.0001 ? $halfWidth / abs($dx) : PHP_FLOAT_MAX;
        $ty = abs($dy) > 0.0001 ? $halfHeight / abs($dy) : PHP_FLOAT_MAX;
        $scale = min($tx, $ty);

        return ['x' => $cx + ($dx * $scale), 'y' => $cy + ($dy * $scale)];
    }

    /** @return array{x: float, y: float} */
    private static function diamondBorderPoint(float $cx, float $cy, float $halfWidth, float $halfHeight, float $dx, float $dy): array
    {
        $scale = 1 / max(0.0001, (abs($dx) / $halfWidth) + (abs($dy) / $halfHeight));

        return ['x' => $cx + ($dx * $scale), 'y' => $cy + ($dy * $scale)];
    }
}
