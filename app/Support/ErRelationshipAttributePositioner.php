<?php

namespace App\Support;

/** Positions ER relationship attribute balloons around their anchors. */
final class ErRelationshipAttributePositioner
{
    public const RELATION_ATTRIBUTE_WIDTH = 136.0;

    public const RELATION_ATTRIBUTE_HEIGHT = 58.0;

    private const RELATION_ATTRIBUTE_PADDING = 28.0;

    private const RELATION_ATTRIBUTE_COLUMNS = [0, 200, -200, 400, -400, 600, -600];

    private const RELATION_ATTRIBUTE_ROWS = [0, 120, -120, 240, -240, 360, -360];

    /**
     * Initial position for a balloon the user has not placed yet.
     *
     * The candidate is the balloon's top left corner. Subtract half its size
     * from the desired center. Prefer below the edge, where the horizontal
     * corridor between entities usually leaves free space.
     *
     * @return array{x:int,y:int}
     */
    public function defaultOffset(int $index): array
    {
        $candidates = $this->relationshipAttributeOffsetCandidates();
        $candidate = $candidates[min($index, count($candidates) - 1)];

        return [
            'x' => (int) round($candidate['x'] - (self::RELATION_ATTRIBUTE_WIDTH / 2)),
            'y' => (int) round($candidate['y'] - (self::RELATION_ATTRIBUTE_HEIGHT / 2)),
        ];
    }

    /**
     * Balloon positions from most to least desirable.
     *
     * Order by distance from the anchor, not by bands. Band ordering can
     * select a distant side position before a nearby free position above
     * the edge, making the balloon look disconnected from its relationship.
     *
     * O desempate prefere a faixa de baixo (a que sobra livre num corredor
     * horizontal) e, dentro dela, o menor deslocamento lateral.
     *
     * @return array<int, array{x:int,y:int}> candidates relative to the anchor center
     */
    private function relationshipAttributeOffsetCandidates(): array
    {
        static $candidates = null;

        if ($candidates !== null) {
            return $candidates;
        }

        $candidates = [];
        foreach (self::RELATION_ATTRIBUTE_ROWS as $row) {
            foreach (self::RELATION_ATTRIBUTE_COLUMNS as $column) {
                if ($row === 0 && $column === 0) {
                    continue;
                }

                $candidates[] = ['x' => $column, 'y' => $row];
            }
        }

        usort($candidates, fn (array $left, array $right): int => [
            ($left['x'] ** 2) + ($left['y'] ** 2),
            $left['y'] < 0 ? 1 : 0,
            abs($left['x']),
        ] <=> [
            ($right['x'] ** 2) + ($right['y'] ** 2),
            $right['y'] < 0 ? 1 : 0,
            abs($right['x']),
        ]);

        return $candidates;
    }

    /**
     * Reposition attribute balloons after entities move.
     *
     * Rewrite only the offset relative to the relationship anchor. This
     * preserves the attachment through reloads and later entity moves.
     */
    public function place(array $entityBounds, array $relations, array $anchors): array
    {
        $occupied = [];
        foreach ($entityBounds as $bounds) {
            $occupied[] = $this->paddedRectangle(
                $bounds['x'], $bounds['y'], $bounds['width'], $bounds['height'],
            );
        }

        $candidates = $this->relationshipAttributeOffsetCandidates();

        foreach ($relations as $relationIndex => $relation) {
            $anchor = $anchors[$relationIndex];
            $candidatesLeft = $candidates;

            foreach ($relation['attributes'] ?? [] as $index => $attribute) {
                $offset = $this->firstFreeRelationshipAttributeOffset(
                    $anchor,
                    $candidatesLeft,
                    $occupied,
                );

                // Reserve the chosen area so another balloon cannot use it.
                $candidatesLeft = array_values(array_filter(
                    $candidatesLeft,
                    fn (array $candidate): bool => $candidate !== $offset,
                ));
                $occupied[] = $this->paddedRectangle(
                    $anchor['x'] + $offset['x'],
                    $anchor['y'] + $offset['y'],
                    self::RELATION_ATTRIBUTE_WIDTH,
                    self::RELATION_ATTRIBUTE_HEIGHT,
                );

                $relations[$relationIndex]['attributes'][$index]['offsetX'] = $offset['x'];
                $relations[$relationIndex]['attributes'][$index]['offsetY'] = $offset['y'];
            }
        }

        return $relations;
    }

    /**
     * @param  array<int, array{x:int,y:int}>  $candidates  offsets candidatos, em coordenadas de canto superior esquerdo
     * @param  array<int, array{left:float,right:float,top:float,bottom:float}>  $occupied
     * @return array{x:int,y:int}
     */
    private function firstFreeRelationshipAttributeOffset(array $anchor, array $candidates, array $occupied): array
    {
        foreach ($candidates as $candidate) {
            $rectangle = $this->paddedRectangle(
                $anchor['x'] + $candidate['x'],
                $anchor['y'] + $candidate['y'],
                self::RELATION_ATTRIBUTE_WIDTH,
                self::RELATION_ATTRIBUTE_HEIGHT,
            );

            if (! $this->overlapsAny($rectangle, $occupied)) {
                return $candidate;
            }
        }

        // Keep the last candidate if none is free; zero would hide the balloon
        // directly on top of its anchor.
        return $candidates === [] ? ['x' => 0, 'y' => 120] : end($candidates);
    }

    /**
     * @param  array<int, array{left:float,right:float,top:float,bottom:float}>  $rectangles
     * @return array{left:float,right:float,top:float,bottom:float}
     */
    private function paddedRectangle(float $x, float $y, float $width, float $height): array
    {
        $padding = self::RELATION_ATTRIBUTE_PADDING;

        return [
            'left' => $x - $padding,
            'right' => $x + $width + $padding,
            'top' => $y - $padding,
            'bottom' => $y + $height + $padding,
        ];
    }

    /**
     * @param  array{left:float,right:float,top:float,bottom:float}  $rectangle
     * @param  array<int, array{left:float,right:float,top:float,bottom:float}>  $rectangles
     */
    private function overlapsAny(array $rectangle, array $rectangles): bool
    {
        foreach ($rectangles as $other) {
            if ($rectangle['left'] < $other['right']
                && $rectangle['right'] > $other['left']
                && $rectangle['top'] < $other['bottom']
                && $rectangle['bottom'] > $other['top']) {
                return true;
            }
        }

        return false;
    }
}
