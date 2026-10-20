<?php

namespace App\Support;

/** Builds relational table nodes with dimensions matching their column rows. */
final class RelationalTableNodeProjector
{
    public const WIDTH = 380;

    private const HEADER_HEIGHT = 62;

    private const BODY_PADDING = 12;

    private const COLUMN_HEIGHT = 44;

    public static function dimensions(array $table): array
    {
        return [
            'width' => self::WIDTH,
            'height' => self::HEADER_HEIGHT
                + self::BODY_PADDING
                + array_sum(array_map(
                    fn (array $column): int => self::COLUMN_HEIGHT + (($column['type'] ?? '') === 'enum' ? 34 : 0),
                    $table['columns'] ?? [],
                )),
        ];
    }

    public static function node(array $table): array
    {
        return [
            'id' => $table['id'],
            'position' => ['x' => $table['x'], 'y' => $table['y']],
            'dimensions' => self::dimensions($table),
            'data' => [
                'name' => $table['name'],
                'kind' => $table['kind'],
                'columns' => $table['columns'],
                'primaryKey' => $table['primaryKey'],
            ],
        ];
    }
}
