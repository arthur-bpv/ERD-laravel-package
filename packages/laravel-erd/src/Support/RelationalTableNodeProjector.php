<?php

namespace Makermind\Erd4dev\Support;

/** Builds relational table nodes with dimensions matching their column rows. */
final class RelationalTableNodeProjector
{
    public const WIDTH = 380;

    private const HEADER_HEIGHT = 62;

    private const BODY_PADDING = 12;

    private const COLUMN_HEIGHT = 44;

    public static function dimensions(array $table, bool $editable = false): array
    {
        return [
            'width' => self::WIDTH,
            'height' => self::HEADER_HEIGHT
                + self::BODY_PADDING
                + ($editable ? 40 : 0)
                + 24 * count(array_filter($table['uniqueKeys'] ?? [], fn (array $key): bool => count($key) > 1))
                + array_sum(array_map(
                    fn (array $column): int => self::COLUMN_HEIGHT + (($column['type'] ?? '') === 'enum' ? 34 : 0),
                    $table['columns'] ?? [],
                )),
        ];
    }

    public static function node(array $table, bool $editable = false): array
    {
        return [
            'id' => $table['id'],
            'position' => ['x' => $table['x'], 'y' => $table['y']],
            'dimensions' => self::dimensions($table, $editable),
            'data' => [
                'name' => $table['name'],
                'editable' => $editable,
                'kind' => $table['kind'],
                'columns' => $table['columns'],
                'primaryKey' => $table['primaryKey'],
                'uniqueKeys' => $table['uniqueKeys'] ?? [],
            ],
        ];
    }
}
