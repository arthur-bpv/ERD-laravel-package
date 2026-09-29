<?php

namespace Tests\Unit\Support;

use App\Support\BoardLayout;
use Tests\TestCase;

class BoardLayoutTest extends TestCase
{
    public function test_centered_layout_puts_the_hub_in_the_middle_and_spreads_branches_to_both_sides(): void
    {
        $positions = BoardLayout::centered(
            [
                ['id' => 'hub', 'height' => 100],
                ['id' => 'branch', 'height' => 100],
                ['id' => 'tip', 'height' => 100],
                ['id' => 'right-a', 'height' => 100],
                ['id' => 'right-b', 'height' => 100],
            ],
            [
                ['source' => 'hub', 'target' => 'branch'],
                ['source' => 'branch', 'target' => 'tip'],
                ['source' => 'hub', 'target' => 'right-a'],
                ['source' => 'hub', 'target' => 'right-b'],
            ],
            fn (array $node): int => $node['height'],
            380,
            180,
            140,
        );

        $this->assertLessThan($positions['hub']['x'], $positions['branch']['x']);
        $this->assertLessThan($positions['branch']['x'], $positions['tip']['x']);
        $this->assertGreaterThan($positions['hub']['x'], $positions['right-a']['x']);
        $this->assertGreaterThan($positions['hub']['x'], $positions['right-b']['x']);
        $this->assertNotSame($positions['right-a']['y'], $positions['right-b']['y']);
    }

    public function test_link_corridors_move_an_obstructing_entity_without_moving_the_endpoints(): void
    {
        $nodes = [
            ['id' => 'left', 'height' => 100],
            ['id' => 'middle', 'height' => 100],
            ['id' => 'right', 'height' => 100],
        ];
        $positions = [
            'left' => ['x' => 0, 'y' => 0],
            'middle' => ['x' => 300, 'y' => 0],
            'right' => ['x' => 600, 'y' => 0],
        ];

        $result = BoardLayout::clearLinkCorridors(
            $nodes,
            [['source' => 'left', 'target' => 'right']],
            $positions,
            fn (array $node): int => $node['height'],
            200,
            80,
        );

        $this->assertSame($positions['left'], $result['left']);
        $this->assertSame($positions['right'], $result['right']);
        $this->assertGreaterThan(100, $result['middle']['y']);
    }
}
