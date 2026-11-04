<?php

namespace Tests\Unit\Support;

use Makermind\Erd4dev\Support\ErSelfRelationshipPortProjector;
use PHPUnit\Framework\TestCase;

class ErSelfRelationshipPortProjectorTest extends TestCase
{
    public function test_horizontal_relationship_uses_top_and_bottom_diamond_tips_on_either_side(): void
    {
        foreach ([-300, 500] as $x) {
            $ports = array_column(ErSelfRelationshipPortProjector::nodes('r1', [
                'x' => 100, 'y' => 200, 'attributes' => [],
            ], ['x' => $x, 'y' => 220]), null, 'id');

            $this->assertSame(['x' => $x + 59, 'y' => 269], $ports['relation-r1-port-diamond-out']['position']);
            $this->assertSame(['x' => $x + 59, 'y' => 213], $ports['relation-r1-port-diamond-in']['position']);
            $entityX = $x > 100 ? 331 : 99;
            $this->assertSame(['x' => $entityX, 'y' => 271], $ports['relation-r1-port-entity-out']['position']);
            $this->assertSame(['x' => $entityX, 'y' => 211], $ports['relation-r1-port-entity-in']['position']);
        }
    }
}
