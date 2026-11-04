<?php

namespace Tests\Unit\Support;

use Makermind\Erd4dev\Support\RelationalForeignKeyEdgeProjector;
use Tests\TestCase;

class RelationalForeignKeyEdgeProjectorTest extends TestCase
{
    public function test_legacy_duplicate_fk_ids_get_separate_stable_rendered_paths(): void
    {
        $tables = array_map(fn ($id) => [
            'id' => $id, 'x' => 100, 'y' => 100,
            'columns' => [['id' => $id.'.id', 'name' => 'id']],
        ], ['a', 'b', 'c']);
        $keys = array_map(fn ($target) => [
            'id' => 'duplicate', 'fromTable' => 'a', 'fromColumn' => 'id',
            'toTable' => $target, 'toColumn' => 'id',
        ], ['b', 'c', 'a']);
        $projector = new RelationalForeignKeyEdgeProjector;
        $edges = $projector->build($tables, $keys, 380);

        $this->assertSame(['duplicate', 'duplicate-2', 'duplicate-3'], array_column($edges, 'id'));
        $this->assertSame(['b', 'c', 'a'], array_column($edges, 'target'));
        $this->assertSame($edges, $projector->build($tables, $keys, 380));
        $this->assertSame(['duplicate', 'duplicate', 'duplicate'], array_column($keys, 'id'));
    }
}
