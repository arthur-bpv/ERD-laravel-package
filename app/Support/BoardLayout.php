<?php

namespace App\Support;

final class BoardLayout
{
    /**
     * Put the most connected node in the center, balance its graph branches
     * between both sides and move terminal nodes farther towards the edges.
     *
     * @param  array<int, array{id:string}>  $nodes
     * @param  array<int, array{source:string,target:string}>  $links
     * @param  callable(array): float|int  $heightFor
     * @return array<string, array{x:int,y:int}>
     */
    public static function centered(
        array $nodes,
        array $links,
        callable $heightFor,
        float $nodeWidth,
        float $columnGap = 180,
        float $rowGap = 100,
    ): array {
        $nodesById = [];
        $order = [];
        $heights = [];

        foreach ($nodes as $index => $node) {
            $id = (string) ($node['id'] ?? '');
            if ($id === '' || isset($nodesById[$id])) {
                continue;
            }

            $nodesById[$id] = $node;
            $order[$id] = $index;
            $heights[$id] = max(1, (float) $heightFor($node));
        }

        if ($nodesById === []) {
            return [];
        }

        $adjacency = array_fill_keys(array_keys($nodesById), []);
        $degree = array_fill_keys(array_keys($nodesById), 0);
        foreach ($links as $link) {
            $source = (string) ($link['source'] ?? '');
            $target = (string) ($link['target'] ?? '');

            if ($source === $target || ! isset($nodesById[$source], $nodesById[$target])) {
                continue;
            }

            $degree[$source]++;
            $degree[$target]++;
            if (! in_array($target, $adjacency[$source], true)) {
                $adjacency[$source][] = $target;
            }
            if (! in_array($source, $adjacency[$target], true)) {
                $adjacency[$target][] = $source;
            }
        }

        $hubId = array_key_first($nodesById);
        foreach (array_keys($nodesById) as $id) {
            if ($degree[$id] > $degree[$hubId]) {
                $hubId = $id;
            }
        }

        $sortIds = function (array &$ids) use ($degree, $order): void {
            usort($ids, fn (string $left, string $right): int => [
                -$degree[$left], $order[$left],
            ] <=> [
                -$degree[$right], $order[$right],
            ]);
        };
        foreach ($adjacency as &$neighbors) {
            $sortIds($neighbors);
        }
        unset($neighbors);

        $branchOf = [];
        $distance = [];
        $queue = [];
        $hubNeighbors = $adjacency[$hubId];
        $sortIds($hubNeighbors);
        foreach ($hubNeighbors as $neighborId) {
            $branchOf[$neighborId] = $neighborId;
            $distance[$neighborId] = 1;
            $queue[] = $neighborId;
        }
        self::spreadBranches($queue, $hubId, $adjacency, $branchOf, $distance);

        // Components disconnected from the main hub still become balanced
        // branches instead of being piled on top of the center.
        while (count($branchOf) < count($nodesById) - 1) {
            $candidates = array_values(array_filter(
                array_keys($nodesById),
                fn (string $id): bool => $id !== $hubId && ! isset($branchOf[$id]),
            ));
            $sortIds($candidates);
            $rootId = $candidates[0];
            $branchOf[$rootId] = $rootId;
            $distance[$rootId] = 1;
            self::spreadBranches([$rootId], $hubId, $adjacency, $branchOf, $distance);
        }

        $branchNodes = [];
        foreach ($branchOf as $nodeId => $branchId) {
            $branchNodes[$branchId][] = $nodeId;
        }

        $branchIds = array_keys($branchNodes);
        $branchWeight = [];
        foreach ($branchNodes as $branchId => $nodeIds) {
            $branchWeight[$branchId] = array_sum(array_map(
                fn (string $id): float => $heights[$id] + $rowGap,
                $nodeIds,
            ));
        }
        usort($branchIds, fn (string $left, string $right): int => [
            -$branchWeight[$left], $order[$left],
        ] <=> [
            -$branchWeight[$right], $order[$right],
        ]);

        $branchSide = [];
        $sideWeight = ['left' => 0.0, 'right' => 0.0];
        foreach ($branchIds as $branchId) {
            $side = $sideWeight['left'] <= $sideWeight['right'] ? 'left' : 'right';
            $branchSide[$branchId] = $side;
            $sideWeight[$side] += $branchWeight[$branchId];
        }

        $maxDistance = ['left' => 0, 'right' => 0];
        $groups = [];
        $branchRank = array_flip($branchIds);
        foreach ($branchOf as $nodeId => $branchId) {
            $side = $branchSide[$branchId];
            $depth = $distance[$nodeId];
            $groups[$side][$depth][] = $nodeId;
            $maxDistance[$side] = max($maxDistance[$side], $depth);
        }

        foreach ($groups as &$sideGroups) {
            foreach ($sideGroups as &$nodeIds) {
                usort($nodeIds, fn (string $left, string $right): int => [
                    $branchRank[$branchOf[$left]], $order[$left],
                ] <=> [
                    $branchRank[$branchOf[$right]], $order[$right],
                ]);
            }
            unset($nodeIds);
        }
        unset($sideGroups);

        $groupHeights = [];
        $maximumHeight = $heights[$hubId];
        foreach ($groups as $side => $sideGroups) {
            foreach ($sideGroups as $depth => $nodeIds) {
                $height = array_sum(array_map(fn (string $id): float => $heights[$id], $nodeIds))
                    + (max(0, count($nodeIds) - 1) * $rowGap);
                $groupHeights[$side][$depth] = $height;
                $maximumHeight = max($maximumHeight, $height);
            }
        }

        $stepX = $nodeWidth + $columnGap;
        $centerX = 80 + ($maxDistance['left'] * $stepX);
        $positions = [
            $hubId => [
                'x' => (int) round($centerX),
                'y' => (int) round(80 + (($maximumHeight - $heights[$hubId]) / 2)),
            ],
        ];

        foreach ($groups as $side => $sideGroups) {
            foreach ($sideGroups as $depth => $nodeIds) {
                $y = 80 + (($maximumHeight - $groupHeights[$side][$depth]) / 2);
                $direction = $side === 'left' ? -1 : 1;

                foreach ($nodeIds as $nodeId) {
                    $positions[$nodeId] = [
                        'x' => (int) round($centerX + ($direction * $depth * $stepX)),
                        'y' => (int) round($y),
                    ];
                    $y += $heights[$nodeId] + $rowGap;
                }
            }
        }

        return $positions;
    }

    /**
     * Fold a wide layout into alternating rows while keeping its graph order.
     * Column count follows table dimensions so the exported image stays close
     * to a landscape rectangle instead of becoming a long strip.
     *
     * @param  array<int, array{id:string}>  $nodes
     * @param  array<string, array{x:int,y:int}>  $positions
     * @param  callable(array): float|int  $heightFor
     * @return array<string, array{x:int,y:int}>
     */
    public static function compactRows(
        array $nodes,
        array $positions,
        callable $heightFor,
        float $nodeWidth,
        float $columnGap,
        float $rowGap,
    ): array {
        $heights = [];
        foreach ($nodes as $node) {
            $id = (string) ($node['id'] ?? '');
            if (isset($positions[$id])) {
                $heights[$id] = max(1, (float) $heightFor($node));
            }
        }

        $ids = array_keys($heights);
        if ($ids === []) {
            return [];
        }

        usort($ids, fn (string $left, string $right): int => [
            $positions[$left]['x'], $positions[$left]['y'],
        ] <=> [
            $positions[$right]['x'], $positions[$right]['y'],
        ]);

        $averageHeight = array_sum($heights) / count($ids);
        $columns = max(2, min(8, (int) round(sqrt(
            count($ids) * ($averageHeight + $rowGap) * 1.6 / ($nodeWidth + $columnGap),
        ))));
        $rows = array_chunk($ids, $columns);
        $compact = [];
        $y = 80.0;

        foreach ($rows as $rowIndex => $rowIds) {
            $rowHeight = max(array_map(fn (string $id): float => $heights[$id], $rowIds));
            foreach ($rowIds as $index => $id) {
                $column = $rowIndex % 2 === 0 ? $index : $columns - 1 - $index;
                $compact[$id] = [
                    'x' => (int) round(80 + $column * ($nodeWidth + $columnGap)),
                    'y' => (int) round($y + ($rowHeight - $heights[$id]) / 2),
                ];
            }
            $y += $rowHeight + $rowGap;
        }

        return $compact;
    }

    /**
     * Move third-party nodes out of the visual corridor of a relationship.
     * Endpoints never move here, so the graph structure chosen by centered()
     * remains stable while cycles stop drawing through another entity.
     *
     * @param  array<int, array{id:string}>  $nodes
     * @param  array<int, array{source:string,target:string}>  $links
     * @param  array<string, array{x:int,y:int}>  $positions
     * @param  callable(array): float|int  $heightFor
     * @return array<string, array{x:int,y:int}>
     */
    public static function clearLinkCorridors(
        array $nodes,
        array $links,
        array $positions,
        callable $heightFor,
        float $nodeWidth,
        float $rowGap = 80,
        float $padding = 20,
    ): array {
        $nodesById = [];
        foreach ($nodes as $node) {
            $id = (string) ($node['id'] ?? '');
            if ($id !== '' && isset($positions[$id])) {
                $nodesById[$id] = $node;
            }
        }

        for ($pass = 0; $pass < max(1, count($nodesById) * 2); $pass++) {
            $changed = false;

            foreach ($links as $link) {
                $sourceId = (string) ($link['source'] ?? '');
                $targetId = (string) ($link['target'] ?? '');
                if ($sourceId === $targetId || ! isset($nodesById[$sourceId], $nodesById[$targetId])) {
                    continue;
                }

                $sourceHeight = max(1, (float) $heightFor($nodesById[$sourceId]));
                $targetHeight = max(1, (float) $heightFor($nodesById[$targetId]));
                $start = [
                    'x' => $positions[$sourceId]['x'] + ($nodeWidth / 2),
                    'y' => $positions[$sourceId]['y'] + ($sourceHeight / 2),
                ];
                $end = [
                    'x' => $positions[$targetId]['x'] + ($nodeWidth / 2),
                    'y' => $positions[$targetId]['y'] + ($targetHeight / 2),
                ];

                foreach ($nodesById as $nodeId => $node) {
                    if ($nodeId === $sourceId || $nodeId === $targetId) {
                        continue;
                    }

                    $height = max(1, (float) $heightFor($node));
                    $rectangle = [
                        'left' => $positions[$nodeId]['x'] - $padding,
                        'right' => $positions[$nodeId]['x'] + $nodeWidth + $padding,
                        'top' => $positions[$nodeId]['y'] - $padding,
                        'bottom' => $positions[$nodeId]['y'] + $height + $padding,
                    ];
                    if (! self::segmentIntersectsRectangle($start, $end, $rectangle)) {
                        continue;
                    }

                    $belowEndpoints = max(
                        $positions[$sourceId]['y'] + $sourceHeight,
                        $positions[$targetId]['y'] + $targetHeight,
                    ) + $rowGap;
                    $positions[$nodeId]['y'] = (int) ceil(max(
                        $belowEndpoints,
                        $positions[$nodeId]['y'] + $rowGap,
                    ));
                    $changed = true;
                }
            }

            if (! $changed) {
                break;
            }
        }

        return $positions;
    }

    /**
     * Liang-Barsky clipping against an axis-aligned rectangle.
     *
     * @param  array{x:float|int,y:float|int}  $start
     * @param  array{x:float|int,y:float|int}  $end
     * @param  array{left:float|int,right:float|int,top:float|int,bottom:float|int}  $rectangle
     */
    private static function segmentIntersectsRectangle(array $start, array $end, array $rectangle): bool
    {
        $deltaX = $end['x'] - $start['x'];
        $deltaY = $end['y'] - $start['y'];
        $minimum = 0.0;
        $maximum = 1.0;
        $boundaries = [
            [-$deltaX, $start['x'] - $rectangle['left']],
            [$deltaX, $rectangle['right'] - $start['x']],
            [-$deltaY, $start['y'] - $rectangle['top']],
            [$deltaY, $rectangle['bottom'] - $start['y']],
        ];

        foreach ($boundaries as [$direction, $distance]) {
            if ($direction == 0.0) {
                if ($distance < 0) {
                    return false;
                }

                continue;
            }

            $ratio = $distance / $direction;
            if ($direction < 0) {
                $minimum = max($minimum, $ratio);
            } else {
                $maximum = min($maximum, $ratio);
            }
            if ($minimum > $maximum) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $queue
     * @param  array<string, array<int, string>>  $adjacency
     * @param  array<string, string>  $branchOf
     * @param  array<string, int>  $distance
     */
    private static function spreadBranches(
        array $queue,
        string $hubId,
        array $adjacency,
        array &$branchOf,
        array &$distance,
    ): void {
        for ($cursor = 0; $cursor < count($queue); $cursor++) {
            $nodeId = $queue[$cursor];
            foreach ($adjacency[$nodeId] as $neighborId) {
                if ($neighborId === $hubId || isset($branchOf[$neighborId])) {
                    continue;
                }

                $branchOf[$neighborId] = $branchOf[$nodeId];
                $distance[$neighborId] = $distance[$nodeId] + 1;
                $queue[] = $neighborId;
            }
        }
    }
}
